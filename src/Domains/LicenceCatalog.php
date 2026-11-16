<?php
/**
 * What both licence families read from settings: "key=value" lines, the vendor items and the costs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one reader of the licence settings of the registrar's licence API. The Plesk app tiers (`plesk_items`,
 * `plesk_costs`) and the standalone keys (`licence_items`, `licence_costs`) are two settings with the same shape; each
 * facade passes its own setting and filter names. It names no feature.
 */
final class LicenceCatalog {

	/**
	 * The Plesk editions and their Openprovider items (monthly), the virtual server ones first, then the physical server
	 * ones. Read from the live catalogue (GET /licenses/items) and checked against the sandbox: these are the `item` ids the
	 * licence API takes. The price list's SKUs (PLSKVPS-ADM-M and so on) are the same editions' billing names, and the API
	 * refuses a SKU as an item ("License request has invalid items").
	 */
	public const EDITION_ITEMS = array(
		'admin'          => 'PLESK-12-VPS-WEB-ADMIN-1M',
		'pro'            => 'PLESK-12-VPS-WEB-PRO-1M',
		'host'           => 'PLESK-12-VPS-WEB-HOST-1M',
		'admin-physical' => 'PLESK-12-WEB-ADMIN-1M',
		'pro-physical'   => 'PLESK-12-WEB-PRO-1M',
		'host-physical'  => 'PLESK-12-WEB-HOST-1M',
	);

	/**
	 * The editions of a virtual server only (what a Plesk app on one of our servers uses).
	 *
	 * @return array<string, string>
	 */
	public static function virtual_editions(): array {
		return array_filter( self::EDITION_ITEMS, static fn( $key ) => ! str_ends_with( (string) $key, '-physical' ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * "key=value" lines as a map. A key is 1 to 40 characters of a-z, 0-9, underscore and dash.
	 *
	 * @param mixed $raw Setting text.
	 * @return array<string, string>
	 */
	public static function lines( $raw ): array {
		$out = array();

		foreach ( preg_split( '/\R/', is_string( $raw ) ? $raw : '' ) ?: array() as $line ) {
			$parts = explode( '=', $line, 2 );
			$key   = strtolower( trim( $parts[0] ) );

			if ( 2 === count( $parts ) && 1 === preg_match( '/^[a-z0-9_-]{1,40}$/D', $key ) && '' !== trim( $parts[1] ) ) {
				$out[ $key ] = trim( $parts[1] );
			}
		}

		return $out;
	}

	/**
	 * Key => Openprovider item id: the setting's lines over the defaults, a malformed item dropped, then the filter.
	 *
	 * @param string                $setting  Setting name.
	 * @param string                $filter   Filter name (receives and returns key => item id).
	 * @param array<string, string> $defaults Defaults.
	 * @return array<string, string>
	 */
	public static function items( string $setting, string $filter, array $defaults ): array {
		$items = array_filter( self::lines( Settings::get( $setting ) ) + $defaults, static fn( $item ) => 1 === preg_match( '/^[A-Za-z0-9._-]{3,80}$/D', (string) $item ) );

		$filtered = apply_filters( $filter, $items ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- the facades pass their own documented hook names.

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $items;
	}

	/**
	 * Key => what Openprovider charges per month in EUR excluding VAT. A key with no entry has an unknown cost.
	 *
	 * @param string $setting Setting name.
	 * @param string $filter  Filter name (receives and returns key => cost).
	 * @return array<string, float>
	 */
	public static function costs( string $setting, string $filter ): array {
		$costs = array();

		foreach ( self::lines( Settings::get( $setting ) ) as $key => $value ) {
			if ( is_numeric( $value ) && (float) $value >= 0 ) {
				$costs[ $key ] = (float) $value;
			}
		}

		$filtered = apply_filters( $filter, $costs ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- the facades pass their own documented hook names.

		return is_array( $filtered ) ? array_map( 'floatval', $filtered ) : $costs;
	}
}
