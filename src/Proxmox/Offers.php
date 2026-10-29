<?php
/**
 * What the server picker offers: products, plans, prices, stock and the backups option.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Everything comes from the product, its variations and the cluster settings, nothing is written in code: a product is
 * offered when it is a published variable VM product whose "Show in the server picker" setting is not off (and a filter
 * agrees); its plans are the variations without backups; the numbers are the plan fields; the price is the display
 * price excluding tax; stock is the one the shop uses (variation stock status and Stock::available, which read the
 * cached capacity and the IP pool, never Proxmox). Reading is cheap and cached for the request.
 */
final class Offers {

	/**
	 * Per-request cache of the offers.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static $memo = null;

	/**
	 * Forgets the cached offers (a test, or code that changed a product in the same request).
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * Is a product shown in the picker? An explicit "no" turns it off; a VM product that never saved the setting is on.
	 *
	 * @param int $product_id Product id.
	 */
	public static function is_offered( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );

		if ( ! $driver || SshKeyField::DRIVER !== $driver->id() || 'publish' !== get_post_status( $product_id ) || 'no' === get_post_meta( $product_id, Product::META_PICKER, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Ids of the offered products.
	 *
	 * @return int[]
	 */
	public static function product_ids(): array {
		// get_posts, not wc_get_products: the latter hides subscription product types in some contexts (WP-CLI).
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 30,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => Product::META_DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the catalogue of servers is small and bounded.
				'meta_value'     => SshKeyField::DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$ids = array_values( array_filter( array_map( 'intval', $ids ), array( __CLASS__, 'is_offered' ) ) );

		/**
		 * The products the server picker offers (ids), after the product setting. A site can narrow or reorder them.
		 *
		 * @param int[] $ids Product ids.
		 */
		$filtered = apply_filters( 'beltoft_services_picker_products', $ids );

		return array_values( array_filter( array_map( 'intval', is_array( $filtered ) ? $filtered : $ids ), array( __CLASS__, 'is_offered' ) ) );
	}

	/**
	 * The offered products with their plans.
	 *
	 * @return array<int, array{product_id: int, name: string, plans: array<int, array<string, mixed>>}>
	 */
	public static function products(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$out = array();

		foreach ( self::product_ids() as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product instanceof \WC_Product_Variable ) {
				continue;
			}

			$plans = array();

			foreach ( $product->get_children() as $child ) {
				$plan = self::plan( $product, (int) $child );

				if ( null !== $plan ) {
					$plans[ $plan['variation_id'] ] = $plan;
				}
			}

			if ( array() !== $plans ) {
				$out[ $id ] = array(
					'product_id' => $id,
					'name'       => $product->get_name(),
					'plans'      => $plans,
				);
			}
		}

		self::$memo = $out;

		return $out;
	}

	/**
	 * Every offered plan by variation id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function plans(): array {
		$out = array();

		foreach ( self::products() as $product ) {
			$out += $product['plans'];
		}

		return $out;
	}

	/**
	 * One offered plan, or null when the variation is not an offered plan (unknown, a backups variation, a hidden product).
	 *
	 * @param int $variation_id Variation id.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $variation_id ): ?array {
		return self::plans()[ $variation_id ] ?? null;
	}

	/**
	 * The plan of one variation: null when it is not a plan (unpublished, with backups, not a VM plan).
	 *
	 * @param \WC_Product_Variable $product Parent.
	 * @param int                  $id      Variation id.
	 * @return array<string, mixed>|null
	 */
	private static function plan( \WC_Product_Variable $product, int $id ): ?array {
		$variation = wc_get_product( $id );

		if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
			return null;
		}

		$numbers = Product::plan( $product->get_id(), $id );

		// A variation with backups is the option of its plan, not a plan.
		if ( (int) ( $numbers['backup_days'] ?? 0 ) > 0 || '' === (string) $variation->get_price() ) {
			return null;
		}

		$sibling = '' !== (string) ( Clusters::get( (string) ( $numbers['cluster'] ?? '' ) )['backup_storage'] ?? '' ) ? Backups::sibling( $variation, true, true ) : null;
		$backups = null;

		if ( null !== $sibling ) {
			$backups = array(
				'variation_id' => $sibling->get_id(),
				'extra'        => max( 0.0, (float) wc_get_price_excluding_tax( $sibling ) - (float) wc_get_price_excluding_tax( $variation ) ),
				'days'         => (int) ( Product::plan( $product->get_id(), $sibling->get_id() )['backup_days'] ?? 0 ),
			);
		}

		return array(
			'product_id'   => $product->get_id(),
			'variation_id' => $id,
			'name'         => self::label( $product, $variation ),
			'price'        => (float) wc_get_price_excluding_tax( $variation ),
			'period'       => self::period( $variation ),
			'cores'        => (int) ( $numbers['cores'] ?? 0 ),
			'memory_mb'    => (int) ( $numbers['memory_mb'] ?? 0 ),
			'disk_gb'      => (int) ( $numbers['disk_gb'] ?? 0 ),
			'cluster'      => (string) ( $numbers['cluster'] ?? '' ),
			'in_stock'     => self::in_stock( $variation ),
			'unknown'      => Stock::unknown( $variation ),
			'backups'      => $backups,
			'note'         => self::note( $product, $variation ),
		);
	}

	/**
	 * A short line under the plan name, such as what the plan suits. Empty unless the site supplies one.
	 *
	 * @param \WC_Product_Variable  $product   Parent.
	 * @param \WC_Product_Variation $variation Plan.
	 */
	private static function note( \WC_Product_Variable $product, \WC_Product_Variation $variation ): string {
		/**
		 * Filters the short line printed under a plan's name in the server picker.
		 *
		 * @param string                $note      Empty by default.
		 * @param \WC_Product_Variation $variation The plan.
		 * @param \WC_Product_Variable  $product   Its product.
		 */
		return trim( wp_strip_all_tags( (string) apply_filters( 'beltoft_services_picker_plan_note', '', $variation, $product ) ) );
	}

	/**
	 * Can the plan be bought now? The shop's own stock status and purchasability, and room for a machine.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 */
	public static function in_stock( \WC_Product_Variation $variation ): bool {
		return $variation->is_purchasable() && $variation->is_in_stock() && ( Stock::available( $variation ) || Stock::unknown( $variation ) );
	}

	/**
	 * "month" or "3 months": what the price is per, from the subscription, empty for a product that is not one.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 */
	private static function period( \WC_Product_Variation $variation ): string {
		if ( ! class_exists( '\WC_Subscriptions_Product' ) || ! function_exists( 'wcs_get_subscription_period_strings' ) || ! \WC_Subscriptions_Product::is_subscription( $variation ) ) {
			return '';
		}

		$period = (string) \WC_Subscriptions_Product::get_period( $variation );

		return '' === $period ? '' : (string) wcs_get_subscription_period_strings( max( 1, (int) \WC_Subscriptions_Product::get_interval( $variation ) ), $period );
	}

	/**
	 * The name of a plan: the values of the variation's attributes, leaving out the one that makes the backups sibling
	 * (so "Starter", not "Starter, None").
	 *
	 * @param \WC_Product_Variable  $product   Parent.
	 * @param \WC_Product_Variation $variation Variation.
	 */
	private static function label( \WC_Product_Variable $product, \WC_Product_Variation $variation ): string {
		$mine    = $variation->get_attributes();
		$sibling = Backups::sibling( $variation, true, false );
		$skip    = array();

		if ( null !== $sibling ) {
			$theirs = $sibling->get_attributes();

			foreach ( $mine as $key => $value ) {
				if ( (string) ( $theirs[ $key ] ?? '' ) !== (string) $value ) {
					$skip[ $key ] = true;
				}
			}
		}

		$words = array();

		foreach ( $mine as $key => $value ) {
			if ( isset( $skip[ $key ] ) || '' === (string) $value ) {
				continue;
			}

			$words[] = self::pretty( $product, (string) $key, (string) $value );
		}

		return array() === $words ? $product->get_name() : implode( ', ', $words );
	}

	/**
	 * The name of an attribute value as the product lists it ("starter" is "Starter").
	 *
	 * @param \WC_Product_Variable $product Parent.
	 * @param string               $key     Attribute key (taxonomy or sanitized name).
	 * @param string               $value   Stored value.
	 */
	private static function pretty( \WC_Product_Variable $product, string $key, string $value ): string {
		if ( taxonomy_exists( $key ) ) {
			$term = get_term_by( 'slug', $value, $key );

			return $term instanceof \WP_Term ? $term->name : $value;
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() && sanitize_title( $attribute->get_name() ) === sanitize_title( $key ) ) {
				foreach ( $attribute->get_options() as $option ) {
					if ( sanitize_title( (string) $option ) === sanitize_title( $value ) ) {
						return (string) $option;
					}
				}
			}
		}

		return $value;
	}
}
