<?php
/**
 * The address of the IPv6 test of the checkout: a page that only has an IPv6 address, which the visitor's browser tries to load.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The setting `ipv6_test_url` names the page. The test is offered only when the name has an AAAA record (checked here, kept for an
 * hour), so a name that is not made yet turns the test off instead of telling every visitor that they have no IPv6.
 */
final class Ipv6Test {

	private const TRANSIENT = 'beltoft_services_ipv6_test_aaaa';

	/**
	 * The https address to test with, or '' when there is no test.
	 */
	public static function url(): string {
		$url  = (string) Settings::get( 'ipv6_test_url' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! self::has_aaaa( $host ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Does the name have an AAAA record? The answer is kept for an hour (a missing record for five minutes, so a record that is added
	 * starts the test soon).
	 *
	 * @param string $host Host name.
	 */
	public static function has_aaaa( string $host ): bool {
		$key    = self::TRANSIENT . '_' . md5( $host );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return '1' === (string) $cached;
		}

		/**
		 * Filters the AAAA lookup of the IPv6 test host (tests answer without the network).
		 *
		 * @param bool|null $found Null: look it up.
		 * @param string    $host  Host name.
		 */
		$found = apply_filters( 'beltoft_services_ipv6_test_has_aaaa', null, $host );

		if ( null === $found ) {
			$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an answer: no record.
			$found   = is_array( $records ) && array() !== $records;
		}

		set_transient( $key, $found ? '1' : '0', $found ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );

		return (bool) $found;
	}

	/**
	 * Loads the test script with its address (once per page); false when there is no test.
	 */
	public static function enqueue(): bool {
		static $done = false;

		if ( '' === self::url() ) {
			return false;
		}

		if ( ! $done ) {
			wp_enqueue_script( 'beltoft-services-ipv6-test', plugins_url( 'assets/ipv6-test.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/ipv6-test.js' ), true );
			wp_localize_script( 'beltoft-services-ipv6-test', 'beltoftServicesIpv6', array( 'url' => self::url(), 'timeout' => 5000 ) );
			$done = true;
		}

		return true;
	}

	/**
	 * The "Check my connection" button with the words it answers in, for a server or a database.
	 *
	 * @param string $kind   server or database.
	 * @param string $label  The button's text.
	 */
	public static function button( string $kind, string $label ): string {
		if ( ! self::enqueue() ) {
			return '';
		}

		$yes = 'database' === $kind ? __( 'Your connection has IPv6. You can connect to this database.', 'beltoft-services' ) : __( 'Your connection has IPv6. You can connect to this server.', 'beltoft-services' );
		$no  = 'database' === $kind ? __( 'Your connection has no IPv6. Buy an IPv4 address, or connect from your own server over the private network.', 'beltoft-services' ) : __( 'Your connection has no IPv6. Buy an IPv4 address, or use the browser console.', 'beltoft-services' );

		return sprintf(
			'<span class="beltoft-services-ipv6check"><button type="button" class="button woocommerce-button" data-ipv6-check data-checking="%1$s" data-ipv6="%2$s" data-none="%3$s" data-unknown="%4$s">%5$s</button> <span data-ipv6-result role="status"></span></span>',
			esc_attr__( 'Checking your connection', 'beltoft-services' ),
			esc_attr( $yes ),
			esc_attr( $no ),
			esc_attr__( 'We could not check. Your browser says it has no network.', 'beltoft-services' ),
			esc_html( $label )
		);
	}
}
