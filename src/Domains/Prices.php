<?php
/** Registrar cost table and public yearly prices. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Currency;
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

	/**
	 * The public yearly price of an extension in a currency (the current one by default), whole units, or with decimals for an override line that has them (up to two, excluding tax: 95.20 is 119.00 including 25 percent). In the shop's currency it is the override
	 * or the price the markup rule gave. In another currency only an override line of that currency counts (the amount filter answers it, see
	 * Currency::amount()): the euro price is never shown as another currency, so an extension with no line there has no price.
	 */
	public static function price( string $tld, string $currency = '' ): int|float|null {
		$tld = strtolower( ltrim( $tld, '.' ) );
		$currency = Currency::or_current( $currency );
		// sellable() holds the table and, outside the shop's currency, the override line: nothing else is needed to know the price exists.
		if ( ! self::sellable( $tld, $currency ) ) { return null; }
		$override = self::overrides( $currency )[ $tld ] ?? null;
		if ( null !== $override ) { return $override; }
		$table = get_option( self::OPTION, array() );
		return (int) $table[ $tld ]['price'];
	}

	/** The registrar's transfer cost for a full domain, or null if no price is stored. */
	public static function transfer_cost( string $domain ): ?float {
		$table = get_option( self::OPTION, array() );
		if ( ! is_array( $table ) ) { return null; }
		$tlds = array_keys( $table );
		usort( $tlds, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		foreach ( $tlds as $tld ) {
			if ( str_ends_with( $domain, '.' . $tld ) ) { return isset( $table[ $tld ]['transfer'] ) ? (float) $table[ $tld ]['transfer'] : null; }
		}
		return null;
	}

	/**
	 * Did the registrar's last answer state a transfer price for this extension? A missing price is stored as 0.0 too, so the cost alone
	 * cannot tell "free" from "unknown". A row from before the flag existed counts as not stated until the next refresh.
	 */
	public static function transfer_known( string $domain ): bool {
		$tld = self::tld_of( strtolower( $domain ) );
		$table = get_option( self::OPTION, array() );
		return '' !== $tld && true === ( $table[ $tld ]['transfer_known'] ?? false );
	}

	/**
	 * Does moving this domain in cost the customer nothing? The setting is on, the registrar stated a transfer price of exactly 0, a renewal
	 * costs more than 0 (the first payment is the renewal) and the extension is sold. The first yearly payment then falls due at the domain's
	 * own renewal date and no year is bought at the registrar before it is paid.
	 */
	public static function free_transfer( string $domain ): bool {
		$domain = strtolower( $domain );
		$tld = self::tld_of( $domain );
		$free = '' !== $tld && (bool) Settings::get( 'domain_free_transfers' ) && self::transfer_known( $domain ) && 0.0 === self::cost( $domain, 'transfer' ) && ( self::cost( $domain, 'renew' ) ?? 0.0 ) > 0.0 && self::sellable( $tld );
		/**
		 * Whether a transfer of this domain is free. A site can say no, or yes where the registrar's own price is not enough to know.
		 *
		 * @param bool   $free   The plugin's answer.
		 * @param string $domain Full domain name.
		 */
		return (bool) apply_filters( 'beltoft_services_domain_free_transfer', $free, $domain );
	}

	/** The table extension a full domain ends with, or ''. */
	private static function tld_of( string $domain ): string {
		$table = get_option( self::OPTION, array() );
		$tlds = is_array( $table ) ? array_keys( $table ) : array();
		usort( $tlds, static fn( $a, $b ) => strlen( (string) $b ) <=> strlen( (string) $a ) );
		foreach ( $tlds as $tld ) {
			if ( str_ends_with( $domain, '.' . $tld ) ) { return (string) $tld; }
		}
		return '';
	}

	/** The registrar's cost for one action ('create', 'renew' or 'transfer') on a full domain, in EUR, or null if no price is stored. */
	public static function cost( string $domain, string $key ): ?float {
		$table = get_option( self::OPTION, array() );
		if ( ! is_array( $table ) || ! in_array( $key, array( 'create', 'renew', 'transfer' ), true ) ) { return null; }
		$tlds = array_keys( $table );
		usort( $tlds, static fn( $a, $b ) => strlen( (string) $b ) <=> strlen( (string) $a ) );
		foreach ( $tlds as $tld ) {
			if ( str_ends_with( $domain, '.' . $tld ) ) { return is_array( $table[ $tld ] ) && isset( $table[ $tld ][ $key ] ) && is_numeric( $table[ $tld ][ $key ] ) ? (float) $table[ $tld ][ $key ] : null; }
		}
		return null;
	}

	/** Whether an extension is sold: in the table, allowed when restricted, and in a currency other than the shop's only with a price in it. */
	public static function sellable( string $tld, string $currency = '' ): bool {
		$tld = strtolower( ltrim( $tld, '.' ) );
		$currency = Currency::or_current( $currency );
		if ( Currency::base() !== $currency && ! isset( self::overrides( $currency )[ $tld ] ) ) { return false; }
		$table = get_option( self::OPTION, array() );
		if ( ! is_array( $table ) || ! isset( $table[ $tld ] ) ) { return false; }
		if ( empty( $table[ $tld ]['restricted'] ) ) { return true; }
		$raw = Settings::get( 'domain_restricted_allowlist' );
		$raw = is_string( $raw ) ? $raw : 'dk';
		$allowed = array_map( 'trim', preg_split( '/[\s,]+/', strtolower( $raw ) ) );
		return in_array( $tld, $allowed, true );
	}

	/** One override line: an extension, an equals sign and a price: whole, or with a dot and one or two decimals. */
	private const LINE = '/^\s*([a-z0-9][a-z0-9.-]*)\s*=\s*([1-9][0-9]*(?:\.[0-9]{1,2})?)\s*$/iD';

	/** The price of a matching line: an int for a whole amount, a float with decimals. */
	private static function amount_of( string $text ): int|float {
		$value = round( (float) $text, 2 );
		return abs( $value - round( $value ) ) < 0.000001 ? (int) round( $value ) : $value;
	}

	private static function overrides( string $currency = '' ): array {
		$raw = Currency::amount( 'domain_price_overrides', $currency );
		if ( ! is_string( $raw ) ) { return array(); }
		$result = array();
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			if ( preg_match( self::LINE, $line, $match ) ) {
				$result[ strtolower( $match[1] ) ] = self::amount_of( $match[2] );
			}
		}
		return $result;
	}

	/**
	 * The lines of an override text that cannot be read (a comma, more than two decimals, a zero or no price, no extension), for the
	 * settings screen. Empty lines are fine.
	 *
	 * @return string[]
	 */
	public static function bad_override_lines( string $text ): array {
		$bad = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && 1 !== preg_match( self::LINE, $line ) ) { $bad[] = $line; }
		}
		return $bad;
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
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]*$/D', $tld ) ) { return new \WP_Error( 'bad_response', 'A registrar TLD name is invalid.' ); }
		$prices = $entry['prices'] ?? array();
		$values = array();
		$transfer_known = true;
		foreach ( array( 'create' => 'create_price', 'renew' => 'renew_price', 'transfer' => 'transfer_price' ) as $key => $field ) {
			$price = $prices[ $field ]['reseller'] ?? null;
			// A missing transfer price is stored as 0.0 but not as stated: only an explicit price can make a transfer free.
			if ( null === $price && 'transfer' === $key ) { $values[ $key ] = 0.0; $transfer_known = false; continue; }
			// The sandbox lists active TLDs without a create or renewal price; they cannot be sold.
			if ( null === $price ) { return null; }
			if ( ! is_array( $price ) || ! is_numeric( $price['price'] ?? null ) || 'EUR' !== strtoupper( (string) ( $price['currency'] ?? '' ) ) ) {
				return new \WP_Error( 'bad_currency', 'Registrar prices must be numeric and in EUR.' );
			}
			$values[ $key ] = (float) $price['price'];
		}
		if ( $values['create'] < 0 || $values['renew'] < 0 || $values['transfer'] < 0 ) { return new \WP_Error( 'bad_response', 'Registrar price is negative.' ); }
		$restricted = ! empty( $entry['restrictions'] );
		return array( $tld, array( 'create' => $values['create'], 'renew' => $values['renew'], 'transfer' => $values['transfer'], 'transfer_known' => $transfer_known, 'restricted' => $restricted, 'price' => self::rule( $values['create'], $values['renew'] ) ) );
	}
}
