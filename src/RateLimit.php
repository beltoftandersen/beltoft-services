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
	 * @param int|string $user_id User id, or a visitor key from visitor() for a public route.
	 * @param string     $key     What is being limited (a route, an action): counted apart per user.
	 * @param int    $limit   Calls allowed in one window.
	 * @param int    $window  Window length in seconds.
	 * @return bool Whether the limit is exceeded.
	 */
	public static function exceeded( int|string $user_id, string $key, int $limit, int $window = MINUTE_IN_SECONDS ): bool {
		if ( $limit <= 0 ) {
			return false;
		}

		$window = max( 1, $window );
		$name   = 'rl_' . substr( hash( 'sha256', $user_id . '|' . $key . '|' . (int) floor( time() / $window ) ), 0, 40 );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, self::GROUP, $window );
			$count = wp_cache_incr( $name, 1, self::GROUP );
		} else {
			$count = (int) get_transient( 'beltoft_services_' . $name ) + 1;
			set_transient( 'beltoft_services_' . $name, $count, $window );
		}

		return false !== $count && (int) $count > $limit;
	}

	/**
	 * The key that stands for a public visitor. It is built from REMOTE_ADDR, which is the only address PHP can trust: behind a
	 * reverse proxy or CDN it is the proxy's address (every visitor shares one bucket) unless the server restores the real one
	 * (for example nginx real_ip). A site that knows its proxy can return the real client address from the filter
	 * beltoft_services_client_ip, and must only do so from a header its own proxy sets, because any other header is sent by the visitor.
	 * An IPv6 address is bucketed by its /64, as one subscriber holds a whole /64; an IPv4 address is one bucket.
	 *
	 * @return string A hash, safe in a cache or transient name.
	 */
	public static function visitor(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The address a public visitor is counted by. Unverified behind a proxy: see RateLimit::visitor().
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip     = (string) apply_filters( 'beltoft_services_client_ip', $ip );
		$packed = '' !== $ip ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return 'v_' . hash( 'sha256', 'unknown|' . $ip );
		}
		if ( 16 === strlen( $packed ) ) {
			$mapped = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
			// An IPv4-mapped address is that IPv4 address.
			$packed = 0 === strncmp( $packed, $mapped, 12 ) ? substr( $packed, 12 ) : substr( $packed, 0, 8 ) . "\0\0\0\0\0\0\0\0";
		}
		return 'v_' . hash( 'sha256', bin2hex( $packed ) );
	}
}
