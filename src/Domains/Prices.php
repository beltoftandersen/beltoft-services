<?php
/** Registrar cost table and public yearly prices. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Prices {
	public const OPTION = 'beltoft_services_domain_prices';
	private const PAGE_SIZE = 100;

	public static function rule( float $create, float $renew ): int {
		$cost = max( $create, $renew );
		$percent = Settings::get( 'domain_markup_percent' );
		$margin = Settings::get( 'domain_min_margin' );
		$percent = is_numeric( $percent ) ? (float) $percent : 30.0;
		$margin = is_numeric( $margin ) ? (float) $margin : 4.0;
		return (int) ceil( max( $cost * ( 1.0 + $percent / 100.0 ), $cost + $margin ) );
	}

	public static function price( string $tld ): ?int {
		$tld = strtolower( ltrim( $tld, '.' ) );
		$table = get_option( self::OPTION, array() );
		if ( ! is_array( $table ) || ! isset( $table[ $tld ] ) || ! self::sellable( $tld ) ) { return null; }
		$overrides = self::overrides();
		return $overrides[ $tld ] ?? (int) $table[ $tld ]['price'];
	}

	public static function sellable( string $tld ): bool {
		$tld = strtolower( ltrim( $tld, '.' ) );
		$table = get_option( self::OPTION, array() );
		if ( ! is_array( $table ) || ! isset( $table[ $tld ] ) ) { return false; }
		if ( empty( $table[ $tld ]['restricted'] ) ) { return true; }
		$raw = Settings::get( 'domain_restricted_allowlist' );
		$raw = is_string( $raw ) ? $raw : 'dk';
		$allowed = array_map( 'trim', preg_split( '/[\s,]+/', strtolower( $raw ) ) );
		return in_array( $tld, $allowed, true );
	}

	private static function overrides(): array {
		$raw = Settings::get( 'domain_price_overrides' );
		if ( ! is_string( $raw ) ) { return array(); }
		$result = array();
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			if ( preg_match( '/^\s*([a-z0-9.-]+)\s*=\s*([1-9][0-9]*)\s*$/i', $line, $match ) ) {
				$result[ strtolower( $match[1] ) ] = (int) $match[2];
			}
		}
		return $result;
	}

	/** Complete fetch before replacing the saved table. */
	public static function sync(): int|\WP_Error {
		$client = Clients::registrar();
		$table = array();
		for ( $offset = 0; $offset < 10000; $offset += self::PAGE_SIZE ) {
			$page = $client->request( 'GET', '/tlds', array(), array( 'with_price' => 'true', 'with_restrictions' => 'true', 'is_active' => 'true', 'only_names' => 'false', 'limit' => self::PAGE_SIZE, 'offset' => $offset ) );
			if ( is_wp_error( $page ) ) { return $page; }
			if ( ! isset( $page['results'] ) || ! is_array( $page['results'] ) ) {
				return new \WP_Error( 'bad_response', 'Registrar TLD list is missing results.' );
			}
			foreach ( $page['results'] as $entry ) {
				$parsed = self::parse( $entry );
				if ( is_wp_error( $parsed ) ) { return $parsed; }
				if ( null !== $parsed ) { $table[ $parsed[0] ] = $parsed[1]; }
			}
			if ( count( $page['results'] ) < self::PAGE_SIZE ) {
				update_option( self::OPTION, $table, false );
				return count( $table );
			}
		}
		return new \WP_Error( 'too_many_pages', 'Registrar TLD list exceeded the page limit.' );
	}

	/** @return array{0:string,1:array}|\WP_Error|null */
	private static function parse( $entry ): array|\WP_Error|null {
		if ( ! is_array( $entry ) || empty( $entry['name'] ) ) { return new \WP_Error( 'bad_response', 'A registrar TLD entry has no name.' ); }
		if ( ( isset( $entry['is_active'] ) && ! $entry['is_active'] ) || ( isset( $entry['status'] ) && 'ACT' !== $entry['status'] ) ) { return null; }
		$tld = strtolower( ltrim( (string) $entry['name'], '.' ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]*$/', $tld ) ) { return new \WP_Error( 'bad_response', 'A registrar TLD name is invalid.' ); }
		$prices = $entry['prices'] ?? array();
		$values = array();
		foreach ( array( 'create' => 'create_price', 'renew' => 'renew_price', 'transfer' => 'transfer_price' ) as $key => $field ) {
			$price = $prices[ $field ]['reseller'] ?? null;
			if ( null === $price && 'transfer' === $key ) { $values[ $key ] = 0.0; continue; }
			if ( ! is_array( $price ) || ! is_numeric( $price['price'] ?? null ) || 'EUR' !== strtoupper( (string) ( $price['currency'] ?? '' ) ) ) {
				return new \WP_Error( 'bad_currency', 'Registrar prices must be numeric and in EUR.' );
			}
			$values[ $key ] = (float) $price['price'];
		}
		if ( $values['create'] < 0 || $values['renew'] < 0 || $values['transfer'] < 0 ) { return new \WP_Error( 'bad_response', 'Registrar price is negative.' ); }
		$restricted = ! empty( $entry['restrictions'] );
		return array( $tld, array( 'create' => $values['create'], 'renew' => $values['renew'], 'transfer' => $values['transfer'], 'restricted' => $restricted, 'price' => self::rule( $values['create'], $values['renew'] ) ) );
	}
}
