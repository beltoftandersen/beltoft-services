<?php
/**
 * The operating systems a dedicated server can be ordered with: exactly what the supplier lists for the product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Documentation: Robot webservice, "Server ordering", GET /order/server/product: every product has `dist` ("Available distributions"),
 * and POST /order/server/transaction takes `dist` ("Distribution name which should be preinstalled"). The list is read with GET only (a
 * read, allowed live), kept in an option for a day, and a stale copy is used when the supplier does not answer (a retry no sooner than
 * ten minutes). The customer chooses one string of that list and the order carries it unchanged: no mapping, no rewriting.
 *
 * "Rescue system" is on the list of the documentation example. It is not an operating system, so it is not offered (open question,
 * plan 2026-11-18-dedicated-controls).
 */
final class DistCatalog {

	public const OPTION = 'beltoft_services_dedicated_dist';
	public const TTL    = DAY_IN_SECONDS;
	public const RETRY  = 600;

	/**
	 * The entries not offered as a system.
	 */
	private const SKIP = array( 'Rescue system' );

	/**
	 * The list for a product, in the supplier's order: the exact strings.
	 *
	 * @param string $product_id Supplier product id (the plan field server_ref).
	 * @return string[]
	 */
	public static function for_product( string $product_id ): array {
		$all  = self::all();
		$list = $all[ $product_id ] ?? array();

		return array_values( array_filter( array_map( 'strval', (array) $list ), static fn( string $d ): bool => '' !== $d && ! in_array( $d, self::SKIP, true ) ) );
	}

	/**
	 * Every product's list, product id => distributions. Reads the supplier when the copy is older than a day.
	 *
	 * @param bool $force Read now.
	 * @return array<string, string[]>
	 */
	public static function all( bool $force = false ): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$at    = (int) ( $state['at'] ?? 0 );
		$tried = (int) ( $state['tried'] ?? 0 );

		if ( ! $force && $at > 0 && time() - $at < self::TTL ) {
			return (array) ( $state['products'] ?? array() );
		}

		if ( ! $force && time() - $tried < self::RETRY ) {
			return (array) ( $state['products'] ?? array() );
		}

		$state['tried'] = time();
		update_option( self::OPTION, $state, false );

		$products = DedicatedClients::for()->products();

		if ( is_wp_error( $products ) ) {
			return (array) ( $state['products'] ?? array() );
		}

		$map = array();

		foreach ( $products as $product ) {
			$id = (string) ( $product['id'] ?? '' );

			if ( '' !== $id && is_array( $product['dist'] ?? null ) ) {
				$map[ $id ] = array_values( array_map( 'strval', $product['dist'] ) );
			}
		}

		update_option( self::OPTION, array( 'at' => time(), 'tried' => time(), 'products' => $map ), false );

		return $map;
	}

	/**
	 * Is this exact string offered for the product now?
	 *
	 * @param string $product_id Supplier product id.
	 * @param string $dist       The string.
	 */
	public static function offers( string $product_id, string $dist ): bool {
		return in_array( $dist, self::for_product( $product_id ), true );
	}

	/**
	 * Every string of every product (the block checkout select is registered once, before any cart).
	 *
	 * @return string[]
	 */
	public static function every(): array {
		$out = array();

		foreach ( array_keys( self::all() ) as $id ) {
			$out = array_merge( $out, self::for_product( (string) $id ) );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Tile entries for strings such as "Debian 12.5 minimal": the family is the words before the first word that starts with a digit or is
	 * "latest", the select shows the rest. The value stays the whole string.
	 *
	 * @param string[] $dists Exact strings.
	 * @return array<int, array{value: string, family: string, short: string}>
	 */
	public static function entries( array $dists ): array {
		$out = array();

		foreach ( $dists as $dist ) {
			$words  = preg_split( '/\s+/', trim( $dist ) ) ?: array();
			$family = array();

			foreach ( $words as $i => $word ) {
				if ( $i > 0 && ( preg_match( '/^\d/', $word ) || 'latest' === strtolower( $word ) ) ) {
					break;
				}

				$family[] = $word;
			}

			$name = implode( ' ', $family );
			$rest = trim( substr( trim( $dist ), strlen( $name ) ) );

			$out[] = array( 'value' => $dist, 'family' => $name, 'short' => '' !== $rest ? $rest : $dist );
		}

		return $out;
	}
}
