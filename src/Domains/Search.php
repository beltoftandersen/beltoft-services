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
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$id = (int) sprintf( '%u', crc32( $ip ) );
		if ( RateLimit::exceeded( $id, 'domain-search', 30 ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many searches. Please wait a minute.', 'beltoft-services' ), array( 'status' => 429 ) );
		}
		$result = self::find( (string) $request['name'] );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
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

	/** @return array|\WP_Error */
	public static function find( string $input ): array|\WP_Error {
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
			$reply = Clients::registrar()->request( 'POST', '/domains/check', array( 'domains' => $check, 'with_price' => false ) );
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
			$results[] = array( 'domain' => $entry['domain'], 'status' => $status, 'price' => 'free' === $status ? Prices::price( $entry['tld'] ) : null, 'transfer' => 'taken' === $status );
		}
		return array( 'results' => $results );
	}

	public static function shortcode(): string {
		wp_enqueue_script( 'beltoft-services-domain-search', plugins_url( 'assets/domain-search.js', BELTOFT_SERVICES_FILE ), array(), BELTOFT_SERVICES_VERSION, true );
		wp_enqueue_style( 'beltoft-services-domain-search', plugins_url( 'assets/domain-search.css', BELTOFT_SERVICES_FILE ), array(), BELTOFT_SERVICES_VERSION );
		return '<div class="beltoft-domain-search" data-check="' . esc_url( rest_url( Routes::NAMESPACE . '/domains/check' ) ) . '" data-cart="' . esc_url( rest_url( Routes::NAMESPACE . '/domains/cart' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wc_store_api' ) ) . '"><label for="beltoft-domain-name">' . esc_html__( 'Find a domain', 'beltoft-services' ) . '</label><input id="beltoft-domain-name" type="search" autocomplete="off"><button type="button">' . esc_html__( 'Search', 'beltoft-services' ) . '</button><div class="beltoft-domain-results" aria-live="polite"></div></div>';
	}
}
