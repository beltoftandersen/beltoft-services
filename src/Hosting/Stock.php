<?php
/**
 * Sold-out hosting plans.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * A hosting plan is sold out when no node of its group has room (HostingCapacity). Unlike the virtual machine
 * stock there is nothing remote to cache: the room is counted from the records and the node sizes, so the filters
 * answer live and no stored status is written. Hosting needs no public address per customer, so the address pool
 * plays no part. Like Proxmox\Stock it only ever closes, and an existing customer's renewal, switch or
 * resubscribe is never blocked.
 */
final class Stock {

	private const CART_KEYS = array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe', 'subscription_renewal_early' );

	/**
	 * Hooks the filters.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'filter' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter' ), 20, 2 );
	}

	/**
	 * Both filters: ( $value, $product ). Only ever closes (a false value stays false).
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

		if ( ! $driver || HostingDriver::ID !== $driver->id() || self::existing_customer_flow( $product ) ) {
			return $value;
		}

		return self::available( $product ) ? $value : false;
	}

	/**
	 * Can a new account be built for this product or variation now?
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function available( \WC_Product $product ): bool {
		return HostingCapacity::available( Plan::typed( Product::plan( $product->get_id() ) ) );
	}

	/**
	 * Is this a renewal, switch or resubscribe?
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function existing_customer_flow( \WC_Product $product ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Only presence is read, to let an existing customer through; WooCommerce Subscriptions verifies its own nonces.
		if ( ! empty( $_REQUEST['switch-subscription'] ) || ! empty( $_GET['resubscribe'] ) ) {
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
