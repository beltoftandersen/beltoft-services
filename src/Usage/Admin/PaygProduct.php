<?php
/**
 * What the product editor asks about a pay as you go product: its kind, its driver's support, its plans.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Admin;

use BeltoftServices\Currency;
use BeltoftServices\Drivers\CountsUsage;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\UsageItems;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. The kind sits on the parent product (blank = monthly subscription); the monthly price a rate is taken from sits on each
 * variation, one meta per currency.
 */
final class PaygProduct {

	/**
	 * What the driver of a product can be billed by.
	 *
	 * @param string $driver_id Driver id.
	 * @return array{hourly: bool, counted: bool}
	 */
	public static function support( string $driver_id ): array {
		$driver = '' === $driver_id ? null : Registry::get( $driver_id );

		return array(
			'hourly'  => $driver instanceof UsageItems,
			'counted' => $driver instanceof CountsUsage,
		);
	}

	/**
	 * Can this product be billed by use at all? The one gate of the panel, the checks and WP-CLI.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function offers( \WC_Product $product ): bool {
		$support = self::support( (string) $product->get_meta( Product::META_DRIVER ) );

		return $support['hourly'] || $support['counted'];
	}

	/**
	 * The kind of a product: usage, counted, or '' for a monthly subscription.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function kind( \WC_Product $product ): string {
		$kind = (string) $product->get_meta( Rates::META_BILLING );

		return in_array( $kind, array( Rates::KIND_HOURLY, Rates::KIND_COUNTED ), true ) ? $kind : '';
	}

	/**
	 * The variations of a variable product.
	 *
	 * @param \WC_Product $product Product.
	 * @return \WC_Product_Variation[]
	 */
	public static function variations( \WC_Product $product ): array {
		$out = array();

		if ( ! $product instanceof \WC_Product_Variable ) {
			return $out;
		}

		foreach ( $product->get_children() as $id ) {
			$v = wc_get_product( $id );

			if ( $v instanceof \WC_Product_Variation ) {
				$out[] = $v;
			}
		}

		return $out;
	}

	/**
	 * The meta key of a per currency field.
	 *
	 * @param string $base Base meta key (the shop currency's).
	 * @param string $code Currency code.
	 */
	public static function key( string $base, string $code ): string {
		return Currency::base() === $code ? $base : $base . '_' . $code;
	}

	/**
	 * The monthly price a variation's hourly rate is taken from, in a currency, excluding VAT. Null when there is none above 0.
	 *
	 * @param \WC_Product $v    Variation.
	 * @param string      $code Currency code.
	 */
	public static function monthly( \WC_Product $v, string $code ): ?float {
		$meta = (string) $v->get_meta( self::key( Rates::META_MONTHLY, $code ) );

		return is_numeric( $meta ) && (float) $meta > 0 ? (float) $meta : null;
	}

	/**
	 * The plan of a variation in words: its attribute values, the first as it is and the others with their label ("Starter, Backups: None").
	 *
	 * @param \WC_Product_Variation $v Variation.
	 */
	public static function label( \WC_Product_Variation $v ): string {
		$values = array();

		foreach ( $v->get_attributes() as $key => $value ) {
			if ( '' !== (string) $value ) {
				$values[ (string) $key ] = (string) $value;
			}
		}

		if ( ! $values ) {
			$parent = wc_get_product( $v->get_parent_id() );

			return $parent ? $parent->get_name() : '#' . $v->get_id();
		}

		$parts = array();
		$first = true;

		foreach ( $values as $key => $value ) {
			$parts[] = $first || 1 === count( $values ) ? $value : wc_attribute_label( preg_replace( '/^attribute_/', '', $key ), $v ) . ': ' . $value;
			$first   = false;
		}

		return implode( ', ', $parts );
	}

	/**
	 * How many live services run on these variations.
	 *
	 * @param int[] $variation_ids Variation ids.
	 */
	public static function running( array $variation_ids ): int {
		return Resource::count_live_on_variations( $variation_ids );
	}
}
