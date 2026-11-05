<?php
/** Certificate products and their reseller cost, read from the registrar. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Domains\Clients;

defined( 'ABSPATH' ) || exit;

/**
 * The cost table is the one thing the spend guard trusts: it is read from the registrar's live product list
 * (`GET /ssl/products?with_price=true`) and replaced only after a complete, valid fetch. Prices customers pay live on the
 * WooCommerce variations; nothing here sets a price.
 */
final class SslCatalog {
	public const OPTION = 'beltoft_services_ssl_costs';
	private const PAGE_SIZE = 100;

	/**
	 * The certificate products of the launch catalog, as key => registrar product id, for the product CLI.
	 * A site changes them with the filter; ids come from the registrar's product list.
	 *
	 * @return array<string, int>
	 */
	public static function launch(): array {
		$ids = array( 'dv' => 41, 'dv_wildcard' => 42, 'ov' => 20, 'ev' => 24 );

		/**
		 * The registrar product ids the SSL product CLI creates variations for.
		 *
		 * @param array<string, int> $ids Key => registrar product id.
		 */
		$filtered = apply_filters( 'beltoft_services_ssl_launch_products', $ids );

		return is_array( $filtered ) && array() !== $filtered ? array_map( 'intval', $filtered ) : $ids;
	}

	/**
	 * All stored products, by registrar id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$table = get_option( self::OPTION, array() );

		return is_array( $table ) ? $table : array();
	}

	/**
	 * One stored product, or null when the registrar list did not hold it (or has not been read).
	 *
	 * @param int $product_id Registrar product id.
	 */
	public static function get( int $product_id ): ?array {
		$all = self::all();

		return isset( $all[ $product_id ] ) && is_array( $all[ $product_id ] ) ? $all[ $product_id ] : null;
	}

	/**
	 * Reseller cost of one year, in EUR, or null when unknown.
	 *
	 * @param int $product_id Registrar product id.
	 */
	public static function cost( int $product_id ): ?float {
		$row = self::get( $product_id );

		return $row && isset( $row['cost_1y'] ) && is_numeric( $row['cost_1y'] ) ? (float) $row['cost_1y'] : null;
	}

	/**
	 * Is this registrar product a wildcard one?
	 *
	 * @param int $product_id Registrar product id.
	 */
	public static function is_wildcard( int $product_id ): bool {
		$row = self::get( $product_id );

		return (bool) ( $row['wildcard'] ?? false );
	}

	/**
	 * dv, ov or ev; '' when the product is unknown.
	 *
	 * @param int $product_id Registrar product id.
	 */
	public static function level( int $product_id ): string {
		$row = self::get( $product_id );

		return (string) ( $row['level'] ?? '' );
	}

	/**
	 * Reads the product list and replaces the table after every page was read and valid.
	 *
	 * @return int|\WP_Error Products stored.
	 */
	public static function sync(): int|\WP_Error {
		$client = Clients::registrar();
		$table  = array();

		for ( $offset = 0; $offset < 1000; $offset += self::PAGE_SIZE ) {
			$page = $client->request( 'GET', '/ssl/products', array(), array( 'with_price' => 'true', 'with_supported_software' => 'true', 'limit' => self::PAGE_SIZE, 'offset' => $offset ) );

			if ( is_wp_error( $page ) ) {
				return $page;
			}

			if ( ! isset( $page['results'] ) || ! is_array( $page['results'] ) ) {
				return new \WP_Error( 'bad_response', 'Registrar SSL product list is missing results.' );
			}

			foreach ( $page['results'] as $entry ) {
				$parsed = self::parse( $entry );

				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}

				if ( null !== $parsed ) {
					$table[ $parsed['id'] ] = $parsed;
				}
			}

			if ( count( $page['results'] ) < self::PAGE_SIZE ) {
				update_option( self::OPTION, $table, false );

				return count( $table );
			}
		}

		return new \WP_Error( 'too_many_pages', 'Registrar SSL product list exceeded the page limit.' );
	}

	/**
	 * One list entry. A product without a one year reseller price is skipped (it cannot be sold safely); a malformed entry
	 * or a price in another currency is an error, so nothing is stored from a list that cannot be read.
	 *
	 * @param mixed $entry Registrar entry.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private static function parse( $entry ): array|\WP_Error|null {
		if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || ! is_numeric( $entry['id'] ) || (int) $entry['id'] < 1 ) {
			return new \WP_Error( 'bad_response', 'A registrar SSL product has no id.' );
		}

		$cost  = null;
		$extra = 0.0;

		foreach ( (array) ( $entry['prices'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || 1 !== (int) ( $row['period'] ?? 0 ) ) {
				continue;
			}

			$reseller = $row['price']['reseller'] ?? null;

			if ( ! is_array( $reseller ) || ! is_numeric( $reseller['price'] ?? null ) ) {
				continue;
			}

			if ( 'EUR' !== strtoupper( (string) ( $reseller['currency'] ?? '' ) ) ) {
				return new \WP_Error( 'bad_currency', 'Registrar SSL prices must be in EUR.' );
			}

			$cost  = (float) $reseller['price'];
			$more  = $row['extra_domain_price']['reseller']['price'] ?? 0;
			$extra = is_numeric( $more ) ? (float) $more : 0.0;
		}

		if ( null === $cost || $cost < 0 ) {
			return null;
		}

		$category = strtolower( (string) ( $entry['category'] ?? '' ) );
		$level    = ! empty( $entry['is_extended_validation_supported'] ) || false !== strpos( $category, 'extended' ) ? 'ev' : ( false !== strpos( $category, 'organization' ) ? 'ov' : 'dv' );

		return array(
			'id'           => (int) $entry['id'],
			'name'         => sanitize_text_field( (string) ( $entry['name'] ?? '' ) ),
			'brand'        => sanitize_text_field( (string) ( $entry['brand_name'] ?? '' ) ),
			'level'        => $level,
			'wildcard'     => ! empty( $entry['is_wildcard_supported'] ),
			'delivery'     => sanitize_text_field( (string) ( $entry['delivery_time'] ?? '' ) ),
			'refund'       => sanitize_text_field( (string) ( $entry['free_refund_period'] ?? '' ) ),
			'max_domains'  => (int) ( $entry['max_domains'] ?? 1 ),
			'cost_1y'      => $cost,
			'extra_domain' => $extra,
		);
	}
}
