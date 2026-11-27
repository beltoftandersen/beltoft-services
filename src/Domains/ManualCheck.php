<?php
/**
 * The limit on a customer's Check now button, shared by every guide that has one.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A gap between two presses and a most per rolling hour, kept per resource in one meta list of times. The count is
 * made under the resource lock, so two presses at once make one look. The app box (AppOwnDomain) and the email guide
 * (Mail\MailRoutes) use it; each passes its own meta key and its own limits.
 */
final class ManualCheck {

	/**
	 * The moment the customer may press again, 0 when now.
	 *
	 * @param Resource $r        Resource.
	 * @param string   $meta     Meta key that holds the times.
	 * @param int      $gap      Least seconds between two presses.
	 * @param int      $per_hour Most presses in a rolling hour.
	 * @param int|null $now      Time to judge at.
	 */
	public static function next( Resource $r, string $meta, int $gap, int $per_hour, ?int $now = null ): int {
		$now   = $now ?? time();
		$times = array_values( array_filter( array_map( 'intval', (array) $r->get( $meta ) ), static fn( int $t ): bool => $t > $now - HOUR_IN_SECONDS && $t <= $now ) );

		if ( array() === $times ) {
			return 0;
		}

		sort( $times );
		$next = end( $times ) + $gap;

		if ( count( $times ) >= $per_hour ) {
			$next = max( $next, $times[ count( $times ) - $per_hour ] + HOUR_IN_SECONDS );
		}

		return $next > $now ? $next : 0;
	}

	/**
	 * Counts a press, unless the limit says wait.
	 *
	 * @param Resource $r        Resource.
	 * @param string   $meta     Meta key.
	 * @param int      $gap      Least seconds between two presses.
	 * @param int      $per_hour Most presses in a rolling hour.
	 * @return int|null The moment the customer may press again when the limit holds (the press is not counted), 0 when it was counted, null when the resource lock was busy.
	 */
	public static function claim( Resource $r, string $meta, int $gap, int $per_hour ): ?int {
		$next  = 0;
		$noted = $r->locked(
			static function () use ( $r, $meta, $gap, $per_hour, &$next ): bool {
				$fresh = Resource::find( $r->id() );

				if ( ! $fresh ) {
					return false;
				}

				$next = self::next( $fresh, $meta, $gap, $per_hour );

				if ( $next > 0 ) {
					return true;
				}

				$now     = time();
				$times   = array_values( array_filter( array_map( 'intval', (array) $fresh->get( $meta ) ), static fn( int $t ): bool => $t > $now - HOUR_IN_SECONDS ) );
				$times[] = $now;

				return $fresh->set( array( $meta => array_slice( $times, -$per_hour ) ) );
			}
		);

		return true === $noted ? $next : null;
	}

	/**
	 * The words on a limited button.
	 *
	 * @param int $next The moment the customer may press again.
	 */
	public static function text( int $next ): string {
		return sprintf( /* translators: %s: time of day such as 09:41 */ __( 'You can check again at %s', 'beltoft-services' ), wp_date( get_option( 'time_format' ), $next ) );
	}
}
