<?php
/**
 * My Account → Domains: adding a domain for DNS hosting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Api\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the claim section above the license list of the Domains tab and loads its script. The
 * script talks to DnsClaimRoutes; nothing here reads a claim.
 */
final class DnsClaimPanel {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'beltoft-services-dns-claim';

	/**
	 * Endpoint slug of the tab.
	 */
	private const TAB = 'domains';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		add_action( 'woocommerce_account_' . self::TAB . '_endpoint', array( __CLASS__, 'render' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Is the section on for this request?
	 */
	private static function active(): bool {
		return is_user_logged_in() && DnsClaimsFeature::enabled();
	}

	/**
	 * The Domains tab for a customer who has no domain license yet, before Licenses and Log out.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		if ( ! is_array( $items ) || isset( $items[ self::TAB ] ) || ! self::active() ) {
			return $items;
		}

		$before = array( Endpoint::slug(), 'customer-logout' );
		$out    = array();
		$placed = false;

		foreach ( $items as $key => $label ) {
			if ( ! $placed && in_array( $key, $before, true ) ) {
				$out[ self::TAB ] = __( 'Domains', 'beltoft-services' );
				$placed           = true;
			}
			$out[ $key ] = $label;
		}

		if ( ! $placed ) {
			$out[ self::TAB ] = __( 'Domains', 'beltoft-services' );
		}

		return $out;
	}

	/**
	 * Prints the section.
	 */
	public static function render(): void {
		if ( ! self::active() ) {
			return;
		}

		wc_get_template(
			'account/dns-claim.php',
			array(),
			'beltoft-services/',
			BELTOFT_SERVICES_PATH . 'templates/'
		);
	}

	/**
	 * The script and stylesheet, only on the Domains tab.
	 */
	public static function assets(): void {
		if ( ! self::active() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::TAB ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/dns-claim.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/dns-claim.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/dns-claim.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/dns-claim.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftDnsClaims',
			array(
				'root'     => esc_url_raw( rest_url( Routes::NAMESPACE . '/domains/dns-claims' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'interval' => 3000,
				'attempts' => 40,
				'strings'  => self::strings(),
			)
		);
	}

	/**
	 * Texts for the script.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'copy'          => __( 'Copy', 'beltoft-services' ),
			'copied'        => __( 'Copied', 'beltoft-services' ),
			'type'          => __( 'Type', 'beltoft-services' ),
			'name'          => __( 'Name', 'beltoft-services' ),
			'value'         => __( 'Value', 'beltoft-services' ),
			'ttl'           => __( 'TTL', 'beltoft-services' ),
			/* translators: %s: date and time. */
			'validUntil'    => __( 'Valid until %s', 'beltoft-services' ),
			'checkNow'      => __( 'Check now', 'beltoft-services' ),
			'cancel'        => __( 'Cancel', 'beltoft-services' ),
			'working'       => __( 'Working…', 'beltoft-services' ),
			'checking'      => __( 'Checking your name servers…', 'beltoft-services' ),
			'stillChecking' => __( 'Still checking. Reload the page in a minute.', 'beltoft-services' ),
			'verified'      => __( 'Verified', 'beltoft-services' ),
			'support'       => __( 'This domain needs a manual check. Contact support.', 'beltoft-services' ),
			'bring'         => __( 'Bring your records', 'beltoft-services' ),
			'findRecords'   => __( 'Find my current records', 'beltoft-services' ),
			'scanning'      => __( 'Looking for your records…', 'beltoft-services' ),
			'zoneLabel'     => __( 'Or paste a zone file exported from your current DNS provider', 'beltoft-services' ),
			'readZone'      => __( 'Read zone file', 'beltoft-services' ),
			'noRecords'     => __( 'No records found.', 'beltoft-services' ),
			'notImported'   => __( 'Not imported', 'beltoft-services' ),
			/* translators: %d: line number in the zone file. */
			'line'          => __( 'Line %d', 'beltoft-services' ),
			'saveRecords'   => __( 'Save chosen records', 'beltoft-services' ),
			/* translators: %d: number of records. */
			'saved'         => __( 'Saved: %d records', 'beltoft-services' ),
			'checkout'      => __( 'Continue to checkout', 'beltoft-services' ),
			'dnssec'        => __( 'This domain uses DNSSEC at your registrar. Remove the DS record there before you change the name servers, then wait a day.', 'beltoft-services' ),
			'adding'        => __( 'Adding…', 'beltoft-services' ),
			'alreadyYours'  => __( 'This domain is already in your account.', 'beltoft-services' ),
			'showIt'        => __( 'Show it', 'beltoft-services' ),
			'failed'        => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
			'expired'       => __( 'Your session has expired. Please reload the page.', 'beltoft-services' ),
		);
	}
}
