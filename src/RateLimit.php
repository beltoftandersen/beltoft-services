<?php
/**
 * A small per-user rate limiter that other plugins can reuse.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per user and key in fixed windows.
 */
final class RateLimit {

	/**
	 * Object cache group of the counters.
	 */
	private const GROUP = 'beltoft_services_rl';

	/**
	 * Counts this call. True when it is over the limit. A limit of 0 or less turns the check
	 * off. Counters live in the persistent object cache when there is one, otherwise in
	 * transients; if counting fails the call is let through. Best-effort without a persistent
	 * object cache (the transient path reads then writes, so concurrent calls can be missed). The
	 * window is fixed, not sliding: it resets at each multiple of the window length. Callers must
	 * namespace $key with their plugin slug, for example 'odoowoo-portal:verify', so plugins do
	 * not share counters.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     What is being limited (a route, an action): counted apart per user.
	 * @param int    $limit   Calls allowed in one window.
	 * @param int    $window  Window length in seconds.
	 * @return bool Whether the limit is exceeded.
	 */
	public static function exceeded( int $user_id, string $key, int $limit, int $window = MINUTE_IN_SECONDS ): bool {
		if ( $limit <= 0 ) {
			return false;
		}

		$window = max( 1, $window );
		$name   = 'rl_' . md5( $user_id . '|' . $key . '|' . (int) floor( time() / $window ) );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, self::GROUP, $window );
			$count = wp_cache_incr( $name, 1, self::GROUP );
		} else {
			$count = (int) get_transient( 'beltoft_services_' . $name ) + 1;
			set_transient( 'beltoft_services_' . $name, $count, $window );
		}

		return false !== $count && (int) $count > $limit;
	}
}
