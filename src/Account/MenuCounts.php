<?php
/**
 * How many services each My Account menu item holds.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Proxmox\Vm\Servers;

defined( 'ABSPATH' ) || exit;

/**
 * The number of a customer's services on each service tab of My Account ("Servers 2"), for the theme to show next to the menu item.
 * A service counts while it exists: an active or a suspended (paused) licence; expired and revoked ones do not. The tabs are the
 * licence plugin's groups, so a service is counted on the tab it is listed on.
 */
final class MenuCounts {

	/** @var array<int, array<string, int>> Counts per user, for the request. */
	private static array $cache = array();

	/**
	 * The counts of a customer: menu item slug => number of services (only items with at least one).
	 *
	 * @param int $user_id Customer.
	 * @return array<string, int>
	 */
	public static function of( int $user_id ): array {
		if ( $user_id < 1 || ! class_exists( '\BeltoftLicenses\Account\Endpoint' ) ) {
			return array();
		}

		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}

		$counts = array();

		foreach ( \BeltoftLicenses\Account\Endpoint::split( \BeltoftLicenses\Account\Endpoint::licenses_for_user( $user_id ) ) as $tab => $licenses ) {
			$slug = '' === $tab ? \BeltoftLicenses\Account\Endpoint::slug() : (string) $tab;
			$n    = self::count( $slug, $licenses );

			if ( $n > 0 ) {
				$counts[ $slug ] = $n;
			}
		}

		return self::$cache[ $user_id ] = $counts;
	}

	/**
	 * The number a tab holds: what its page lists (the same code as the page: ServiceArea::sort(), Servers::sort()) among the
	 * licenses of the tab (an ended one is listed only while a live service is left, see ServiceArea::ended_and_gone()). The tab
	 * of no area (plain licences) counts its active and suspended licenses.
	 *
	 * @param string                           $slug     Menu item slug.
	 * @param array<int, array<string, mixed>> $licenses The licenses on the tab.
	 */
	private static function count( string $slug, array $licenses ): int {
		$live = array_values( array_filter( $licenses, static fn( $l ) => in_array( (string) ( $l['status'] ?? '' ), array( 'active', 'suspended' ), true ) ) );
		$view = static fn( array $license ): array => array(
			'id'      => (int) $license['id'],
			'license' => $license,
		);

		if ( ServerLinks::slug() === $slug ) {
			$sorted = Servers::sort( array_map( $view, $licenses ) );

			return count( $sorted['servers'] ) + count( $sorted['others'] );
		}

		foreach ( ServiceArea::all() as $area ) {
			if ( $area->slug() === $slug ) {
				return count( $area->sort( array_map( $view, $licenses ) )['members'] );
			}
		}

		return count( $live );
	}

	/**
	 * The count of one menu item for the current customer, 0 for none. For the theme's menu.
	 *
	 * @param string $slug Menu item (endpoint) slug.
	 */
	public static function current( string $slug ): int {
		return (int) ( self::of( get_current_user_id() )[ $slug ] ?? 0 );
	}

	/**
	 * Forgets the counts (tests).
	 */
	public static function reset(): void {
		self::$cache = array();
	}
}
