<?php
/**
 * Virtual machine products are out of stock when nothing can be built.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Two WooCommerce filters (woocommerce/includes/abstracts/abstract-wc-product.php:1843 and :1785;
 * variations reach the second through parent::is_purchasable(), class-wc-product-variation.php:574)
 * make a Proxmox product unavailable when the IP pool has no free address, or when no allowed node
 * has the plan's memory free in the capacity cache (no cache counts as no room: fail closed).
 *
 * A renewal, a switch and a resubscribe need no new address, so they are never blocked: a cart item
 * with WooCommerce Subscriptions' keys for this product, or the request arguments that start those
 * flows (`switch-subscription`, the switcher reads it before the item is in the cart; `resubscribe`),
 * lets the product through.
 */
final class Stock {

	private const CART_KEYS = array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe', 'subscription_renewal_early' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'filter' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter' ), 20, 2 );
	}

	/**
	 * Both filters: ( $value, $product ).
	 *
	 * @param mixed $value   Value so far.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function filter( $value, $product ) {
		if ( ! $value || ! $product instanceof \WC_Product ) {
			return $value;
		}

		$driver = Product::driver_for( $product->get_id() );

		if ( ! $driver || 'proxmox' !== $driver->id() || self::existing_customer_flow( $product ) ) {
			return $value;
		}

		return self::available( $product ) ? $value : false;
	}

	/**
	 * Can a new machine be built for this product now?
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function available( \WC_Product $product ): bool {
		if ( 0 === IpPool::free_count() ) {
			return false;
		}

		$plan = Product::plan( $product->get_id() );

		return Capacity::fits( (string) ( $plan['cluster'] ?? '' ), max( 0, (int) ( $plan['memory_mb'] ?? 0 ) ) * 1048576 );
	}

	/**
	 * Is this a renewal, switch or resubscribe?
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function existing_customer_flow( \WC_Product $product ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Only presence is read, to let an existing customer through; WooCommerce Subscriptions verifies its own nonces.
		if ( isset( $_REQUEST['switch-subscription'] ) || isset( $_GET['resubscribe'] ) ) {
			return true;
		}
		// phpcs:enable

		$cart = function_exists( 'WC' ) && isset( WC()->cart ) ? WC()->cart : null;

		if ( ! $cart ) {
			return false;
		}

		// cart_contents, not get_cart(): this runs while the cart is loading from the session.
		foreach ( (array) $cart->cart_contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$same = in_array( $product->get_id(), array( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ), true );

			foreach ( self::CART_KEYS as $key ) {
				if ( $same && isset( $item[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
