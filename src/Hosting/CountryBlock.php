<?php
/**
 * The country block of a hosting website: lists, names, the stored setting and how the page reads it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * A website can refuse visitors from chosen countries. nginx in the customer's container does it (the node agent's
 * `site.country_block`), using MaxMind's GeoLite2 Country database that the operator's server downloads once and copies to
 * the node. The setting is kept in the website's flags (`country_block`: countries, scope, active, at) and by the agent with
 * the website's facts, so every later change of the website's nginx keeps it.
 *
 * Scopes: `site` (every request) and `login` (wp-login.php, xmlrpc.php, the WordPress admin area except admin-ajax.php and
 * admin-post.php, the one-time login address and the tools). Country names come from WooCommerce's country list. The ready
 * lists are data and can be changed with the filter `beltoft_services_hosting_country_lists`.
 */
final class CountryBlock {

	/** Where the block applies. */
	public const SCOPES = array( 'site', 'login' );

	/** The line MaxMind's licence asks for. */
	public const ATTRIBUTION = 'This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com';

	/**
	 * The ready lists: id => label and ISO 3166-1 alpha-2 codes.
	 *
	 * @return array<string, array{label: string, countries: string[]}>
	 */
	public static function lists(): array {
		$default = array(
			'sanctioned' => array(
				'label'     => __( 'Sanctioned countries', 'beltoft-services' ),
				'countries' => array( 'RU', 'BY', 'IR', 'KP', 'SY', 'CU' ),
			),
			'login_risk' => array(
				'label'     => __( 'High-risk for logins', 'beltoft-services' ),
				'countries' => array( 'CN', 'VN', 'IN', 'PK', 'TH', 'KZ', 'SC', 'SG', 'MA' ),
			),
		);

		/**
		 * The ready lists of countries a customer can add with one click.
		 *
		 * @param array<string, array{label: string, countries: string[]}> $default Lists.
		 */
		$lists = (array) apply_filters( 'beltoft_services_hosting_country_lists', $default );
		$names = self::names();
		$out   = array();

		foreach ( $lists as $id => $list ) {
			$codes = array_values( array_filter( array_map( static fn( $c ) => strtoupper( (string) $c ), (array) ( $list['countries'] ?? array() ) ), static fn( $c ) => isset( $names[ $c ] ) ) );

			if ( array() !== $codes && is_string( $id ) && '' !== (string) ( $list['label'] ?? '' ) ) {
				$out[ $id ] = array( 'label' => (string) $list['label'], 'countries' => $codes );
			}
		}

		return $out;
	}

	/**
	 * Every country that can be chosen, code => name, in WooCommerce's order.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		$names = array();

		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
			foreach ( (array) WC()->countries->get_countries() as $code => $name ) {
				if ( 1 === preg_match( '/^[A-Z]{2}$/D', (string) $code ) ) {
					$names[ (string) $code ] = html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' );
				}
			}
		}

		return $names;
	}

	/**
	 * The country's name, or its code when it is not known.
	 *
	 * @param string $code Code.
	 */
	public static function name( string $code ): string {
		return self::names()[ $code ] ?? $code;
	}

	/**
	 * A list of codes cleaned: upper case, each once, only countries that can be chosen, sorted.
	 *
	 * @param mixed $codes Codes.
	 * @return string[]|null Null when one is not a country.
	 */
	public static function clean( $codes ): ?array {
		if ( ! is_array( $codes ) || count( $codes ) > 250 ) {
			return null;
		}

		$names = self::names();
		$out   = array();

		foreach ( $codes as $code ) {
			$code = strtoupper( trim( (string) ( is_scalar( $code ) ? $code : '' ) ) );

			if ( ! isset( $names[ $code ] ) ) {
				return null;
			}

			$out[ $code ] = true;
		}

		$out = array_keys( $out );
		sort( $out );

		return $out;
	}

	/**
	 * The country of the person asking, or '' when it is not known. The filter `beltoft_services_visitor_country` can give it
	 * (a site that has the country in its web server's environment passes it here); otherwise the server variable
	 * BELTOFT_VISITOR_COUNTRY is used when the web server sets one, then WooCommerce's own database when the shop has one.
	 * Nothing is sent to an outside service.
	 */
	public static function visitor_country(): string {
		$given = (string) apply_filters( 'beltoft_services_visitor_country', '' );

		if ( 1 !== preg_match( '/^[A-Za-z]{2}$/D', $given ) && isset( $_SERVER['BELTOFT_VISITOR_COUNTRY'] ) ) {
			$given = sanitize_text_field( wp_unslash( (string) $_SERVER['BELTOFT_VISITOR_COUNTRY'] ) );
		}

		if ( 1 !== preg_match( '/^[A-Za-z]{2}$/D', $given ) && class_exists( '\WC_Geolocation' ) ) {
			$found = \WC_Geolocation::geolocate_ip( '', true, false );
			$given = (string) ( $found['country'] ?? '' );
		}

		return 1 === preg_match( '/^[A-Za-z]{2}$/D', $given ) ? strtoupper( $given ) : '';
	}

	/**
	 * The stored setting of a site.
	 *
	 * @param array<string, mixed> $site Site from Sites.
	 * @return array{countries: string[], scope: string, active: bool|null}
	 */
	public static function stored( array $site ): array {
		$block = (array) ( $site['flags']['country_block'] ?? array() );
		$codes = self::clean( (array) ( $block['countries'] ?? array() ) );
		$scope = in_array( ( $block['scope'] ?? '' ), self::SCOPES, true ) ? (string) $block['scope'] : 'site';

		return array(
			'countries' => $codes ?? array(),
			'scope'     => $scope,
			'active'    => isset( $block['active'] ) ? (bool) $block['active'] : null,
		);
	}

	/**
	 * What the page and the routes show about a site's block.
	 *
	 * @param array<string, mixed> $site Site from Sites.
	 * @return array<string, mixed>
	 */
	public static function view( array $site ): array {
		$stored = self::stored( $site );
		$names  = self::names();
		$out    = array();

		foreach ( $stored['countries'] as $code ) {
			$out[] = array( 'code' => $code, 'name' => $names[ $code ] ?? $code );
		}

		return array(
			'enabled'   => array() !== $stored['countries'],
			'countries' => $out,
			'scope'     => $stored['scope'],
			'active'    => $stored['active'],
		);
	}
}
