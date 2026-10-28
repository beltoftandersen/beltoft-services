<?php
/** Public availability search. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\RateLimit;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Search {
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( 'beltoft_domain_search', array( __CLASS__, 'shortcode' ) );
		add_action( Sync::HOOK, array( __CLASS__, 'cleanup' ) );
	}

	/** Daily: delete the expired cached availability answers. Without an object cache they are transients in the options table, which only a read removes. */
	public static function cleanup(): int {
		global $wpdb;
		if ( wp_using_ext_object_cache() ) { return 0; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- options table housekeeping, always fresh.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 500", $wpdb->esc_like( '_transient_timeout_beltoft_services_dsearch_' ) . '%', time() ) );
		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_timeout_' ) ) );
		}
		return count( (array) $names );
	}

	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/domains/check', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'rest_check' ),
			'permission_callback' => '__return_true',
			'args' => array( 'name' => array( 'type' => 'string', 'required' => true ) ),
		) );
	}

	public static function rest_check( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = RateLimit::visitor();
		/** Searches allowed per visitor: per minute and per hour. */
		$per_minute = (int) apply_filters( 'beltoft_services_domain_search_limit', 20 );
		$per_hour   = (int) apply_filters( 'beltoft_services_domain_search_hourly_limit', 200 );
		if ( RateLimit::exceeded( $id, 'domain-search', $per_minute ) || RateLimit::exceeded( $id, 'domain-search-hour', $per_hour, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many searches. Please wait a minute.', 'beltoft-services' ), array( 'status' => 429 ) );
		}
		if ( strlen( (string) $request['name'] ) > 253 ) {
			return new \WP_Error( 'bad_domain', 'A valid domain name is required.', array( 'status' => 400 ) );
		}
		$result = self::find( (string) $request['name'] );
		if ( is_wp_error( $result ) ) { return $result; }
		$response = new \WP_REST_Response( $result, 200 );
		// Only this browser may keep it, briefly: shared caches must not hold availability.
		$response->header( 'Cache-Control', 'private, max-age=60' );
		return $response;
	}

	/** IDNA and DNS label validation for a full name. */
	public static function normalise( string $name ): string|\WP_Error {
		$name = trim( rtrim( $name, '.' ) );
		if ( '' === $name || ! function_exists( 'idn_to_ascii' ) ) { return new \WP_Error( 'bad_domain', 'A valid domain name is required.' ); }
		$ascii = idn_to_ascii( $name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		if ( false === $ascii ) { return new \WP_Error( 'bad_domain', 'A valid domain name is required.' ); }
		$ascii = strtolower( $ascii );
		if ( strlen( $ascii ) > 253 || ! str_contains( $ascii, '.' ) ) { return new \WP_Error( 'bad_domain', 'A valid domain name is required.' ); }
		foreach ( explode( '.', $ascii ) as $label ) {
			if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) { return new \WP_Error( 'bad_domain', 'A valid domain name is required.' ); }
		}
		return $ascii;
	}

	/**
	 * The registrar's answer for these names. Availability is cached for a few minutes (prices are
	 * not: they are read from the price table each time), a lookup that has to reach the registrar
	 * (also a fresh one) counts against a site-wide hourly cap, and an order always asks again ($fresh) so a cached
	 * answer is never what a domain is sold on. Failures are not cached.
	 *
	 * @param array<int, array<string, string>> $check Names and extensions.
	 * @param bool                               $fresh Skip the cache.
	 */
	private static function availability( array $check, bool $fresh ): array|\WP_Error {
		/** Seconds an availability answer is kept; 0 turns the cache off. */
		$ttl = (int) apply_filters( 'beltoft_services_domain_search_cache_ttl', 5 * MINUTE_IN_SECONDS );
		// Every cached answer expires (never more than an hour), so none stays behind.
		$ttl = max( 0, min( $ttl, HOUR_IN_SECONDS ) );
		$key = 'beltoft_services_dsearch_' . md5( (string) wp_json_encode( $check ) );

		if ( ! $fresh && $ttl > 0 ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) { return $cached; }
		}

		/** Registrar lookups allowed per hour for the whole site; 0 means no cap. */
		$cap = (int) apply_filters( 'beltoft_services_domain_lookup_limit', 600 );
		// Public searches stop at 80% of the cap; an order's own lookup has a bucket of its own (and a per-visitor limit), so paying customers keep headroom.
		if ( $fresh ) {
			/** Order lookups allowed per hour for the whole site, and per visitor; 0 means no limit. */
			$order_cap = (int) apply_filters( 'beltoft_services_domain_order_lookup_limit', 600 );
			$per_visit = (int) apply_filters( 'beltoft_services_domain_order_lookup_visitor_limit', 60 );
			$busy      = RateLimit::exceeded( 0, 'domain-order-lookups', $order_cap, HOUR_IN_SECONDS ) || RateLimit::exceeded( RateLimit::visitor(), 'domain-order-lookups', $per_visit, HOUR_IN_SECONDS );
		} else {
			$busy = $cap > 0 && RateLimit::exceeded( 0, 'domain-registrar-lookups', (int) ceil( $cap * 0.8 ), HOUR_IN_SECONDS );
		}
		if ( $busy ) {
			return new \WP_Error( 'search_busy', __( 'Domain search is busy. Please try again in a few minutes.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		$reply = Clients::registrar()->request( 'POST', '/domains/check', array( 'domains' => $check, 'with_price' => false ) );
		// The registrar's own codes and explanations are for staff only: visitors get one generic answer.
		if ( is_wp_error( $reply ) ) { return new \WP_Error( 'registrar_unavailable', __( 'Domain search is not available right now. Try again in a few minutes.', 'beltoft-services' ), array( 'status' => 503 ) ); }
		if ( $ttl > 0 ) { set_transient( $key, $reply, $ttl ); }

		return $reply;
	}

	/**
	 * A short plain note shown with an extension in the search results, where it matters to the buyer.
	 * Sites can change or add notes with the beltoft_services_domain_tld_notes filter.
	 */
	public static function note( string $tld ): string {
		$notes = (array) apply_filters( 'beltoft_services_domain_tld_notes', array(
			'dev' => __( 'Websites on this extension need HTTPS.', 'beltoft-services' ),
			'app' => __( 'Websites on this extension need HTTPS.', 'beltoft-services' ),
			'eu'  => __( 'The registrant must be in the EU.', 'beltoft-services' ),
			'dk'  => __( 'A business needs a VAT number to register this extension.', 'beltoft-services' ),
		) );
		return (string) ( $notes[ strtolower( ltrim( $tld, '.' ) ) ] ?? '' );
	}

	/** @return array|\WP_Error */
	public static function find( string $input, bool $fresh = false ): array|\WP_Error {
		$input = trim( $input );
		$featured = Settings::get( 'domain_featured_tlds' );
		$featured = is_string( $featured ) ? $featured : 'com,net,org,eu';
		$tlds = array_values( array_filter( array_map( static fn( $s ) => strtolower( trim( $s, ". \t\r\n" ) ), explode( ',', $featured ) ) ) );
		if ( str_contains( $input, '.' ) ) {
			$full = self::normalise( $input );
			if ( is_wp_error( $full ) ) { return $full; }
			$parts = explode( '.', $full );
			$label = $parts[0];
			$typed_tld = implode( '.', array_slice( $parts, 1 ) );
			$tlds = array_values( array_unique( array_merge( array( $typed_tld ), $tlds ) ) );
		} else {
			$ascii = function_exists( 'idn_to_ascii' ) ? idn_to_ascii( $input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;
			$label = false === $ascii ? '' : strtolower( $ascii );
			if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) { return new \WP_Error( 'bad_domain', 'A valid domain label is required.' ); }
		}
		$domains = array();
		$check = array();
		foreach ( $tlds as $tld ) {
			$full = self::normalise( $label . '.' . $tld );
			if ( is_wp_error( $full ) ) { continue; }
			$domains[] = array( 'domain' => $full, 'tld' => $tld );
			if ( Prices::sellable( $tld ) && ( 'dk' !== $tld || DkAgreement::ready() ) ) { $check[] = array( 'name' => $label, 'extension' => $tld ); }
		}
		if ( ! $domains ) { return new \WP_Error( 'bad_domain', 'A valid domain name is required.' ); }
		$found = array();
		if ( $check ) {
			$reply = self::availability( $check, $fresh );
			if ( is_wp_error( $reply ) ) { return $reply; }
			foreach ( (array) ( $reply['results'] ?? array() ) as $item ) {
				if ( is_array( $item ) && isset( $item['domain'] ) ) { $found[ strtolower( (string) $item['domain'] ) ] = $item; }
			}
		}
		$results = array();
		foreach ( $domains as $entry ) {
			$available = Prices::sellable( $entry['tld'] ) && ( 'dk' !== $entry['tld'] || DkAgreement::ready() );
			$item = $found[ $entry['domain'] ] ?? array();
			$status = ! $available ? 'not_sold' : ( ! empty( $item['is_premium'] ) ? 'premium' : ( 'free' === ( $item['status'] ?? '' ) ? 'free' : 'taken' ) );
			$results[] = array( 'domain' => $entry['domain'], 'status' => $status, 'price' => in_array( $status, array( 'free', 'taken' ), true ) ? Prices::price( $entry['tld'] ) : null, 'transfer' => 'taken' === $status, 'note' => self::note( $entry['tld'] ) );
		}
		return array( 'results' => $results );
	}

	public static function shortcode(): string {
		$asset_dir = plugin_dir_path( BELTOFT_SERVICES_FILE ) . 'assets/';
		wp_enqueue_script( 'beltoft-services-domain-search', plugins_url( 'assets/domain-search.js', BELTOFT_SERVICES_FILE ), array(), (string) filemtime( $asset_dir . 'domain-search.js' ), true );
		wp_enqueue_style( 'beltoft-services-domain-search', plugins_url( 'assets/domain-search.css', BELTOFT_SERVICES_FILE ), array(), (string) filemtime( $asset_dir . 'domain-search.css' ) );
		return '<div class="beltoft-domain-search" data-check="' . esc_url( rest_url( Routes::NAMESPACE . '/domains/check' ) ) . '" data-cart="' . esc_url( rest_url( Routes::NAMESPACE . '/domains/cart' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wc_store_api' ) ) . '" data-rest-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"><label for="beltoft-domain-name">' . esc_html__( 'Find a domain', 'beltoft-services' ) . '</label><input id="beltoft-domain-name" type="search" autocomplete="off"><button type="button">' . esc_html__( 'Search', 'beltoft-services' ) . '</button><div class="beltoft-domain-results" aria-live="polite"></div></div>';
	}
}
