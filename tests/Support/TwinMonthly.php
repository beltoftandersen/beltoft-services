<?php
/**
 * Test fixtures built as twins (a monthly variation and its pay as you go sibling) get the monthly figure on the pay as you go
 * variation, the way the migration of part D copies it: `_beltoft_services_usage_monthly[_CODE]`. Part C rebuilds the fixtures
 * without twins and removes this.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Currency;
use BeltoftServices\Usage\Rates;

/**
 * Keeps the figure in step with the twin's price on every save of a variation.
 */
final class TwinMonthly {

	/**
	 * Hooks it up (called from TestCase::set_up()).
	 */
	public static function on(): void {
		remove_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'saved' ), 99 );
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'saved' ), 99 );
		remove_action( 'woocommerce_variable_product_sync_data', array( __CLASS__, 'synced' ), 99 );
		add_action( 'woocommerce_variable_product_sync_data', array( __CLASS__, 'synced' ), 99 );
	}

	/**
	 * A variation was saved.
	 *
	 * @param mixed $variation Variation.
	 */
	public static function saved( $variation ): void {
		if ( $variation instanceof \WC_Product_Variation ) {
			self::refresh( $variation->get_parent_id(), $variation );
		}
	}

	/**
	 * A variable product was synced.
	 *
	 * @param mixed $product Product.
	 */
	public static function synced( $product ): void {
		if ( $product instanceof \WC_Product ) {
			self::refresh( $product->get_id() );
		}
	}

	/**
	 * Writes the figure of every pay as you go variation of a parent that has a monthly twin.
	 *
	 * @param int              $parent_id Parent id.
	 * @param \WC_Product|null $own       The variation being saved: its object (a test keeps it) gets the figure as well.
	 */
	public static function refresh( int $parent_id, ?\WC_Product $own = null ): void {
		if ( $parent_id < 1 ) {
			return;
		}

		wc_delete_product_transients( $parent_id ); // The children list is kept per request and per transient: a variation saved a moment ago is not in it yet.
		$parent = wc_get_product( $parent_id );

		if ( ! $parent instanceof \WC_Product_Variable ) {
			return;
		}

		foreach ( $parent->get_children() as $id ) {
			$v = $own && $own->get_id() === (int) $id ? $own : wc_get_product( $id );

			if ( ! $v instanceof \WC_Product_Variation || ! Rates::is_usage( $v ) ) {
				continue;
			}

			$twin = Rates::sibling( $v, false );

			if ( ! $twin ) {
				continue;
			}

			if ( '' !== (string) $twin->get_price() && '' === (string) get_post_meta( $id, Rates::META_MONTHLY, true ) ) {
				update_post_meta( $id, Rates::META_MONTHLY, (string) wc_get_price_excluding_tax( $twin ) );
			}

			foreach ( (array) get_post_meta( $twin->get_id() ) as $key => $values ) {
				if ( 0 === strpos( (string) $key, '_test_price_' ) ) {
					$code = substr( (string) $key, strlen( '_test_price_' ) );

					if ( Currency::base() !== $code && '' !== (string) ( $values[0] ?? '' ) && '' === (string) get_post_meta( $id, Rates::META_MONTHLY . '_' . $code, true ) ) {
						update_post_meta( $id, Rates::META_MONTHLY . '_' . $code, (string) $values[0] );
					}
				}
			}

			if ( $own && $own->get_id() === (int) $id ) {
				$own->read_meta_data( true ); // A test keeps the object it saved: it learns the figures without a second row.
			}
		}
	}
}
