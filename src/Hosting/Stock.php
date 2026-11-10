<?php
/**
 * Sold-out hosting plans.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Product;
use BeltoftServices\SwitchLink;

defined( 'ABSPATH' ) || exit;

/**
 * A hosting plan is sold out when its WooCommerce stock status is `outofstock`, set by hand in the product editor.
 * Capacity never stops a sale (owner decision 2026-11-11): hosting is oversold on purpose, so nothing here counts the
 * room of a node when a page is shown, a plan is added to the cart or an order is checked out, and no filter closes
 * `woocommerce_is_purchasable` or `woocommerce_product_is_in_stock`. Where a new account goes is HostingCapacity::place(),
 * and a node over its budget is reported to the admin (HostingCapacity::overage_alerts()).
 *
 * What stays is a rule about the customer, not about capacity: an existing customer's renewal, switch or resubscribe is
 * never blocked by a stored `outofstock` (the in-stock filter only opens, for those flows).
 */
final class Stock {

	/**
	 * Product and variation id of the item being added now when it belongs to a WooCommerce Subscriptions flow.
	 * WooCommerce checks is_purchasable() inside add_to_cart() BEFORE the item is in the cart, and WooCommerce
	 * Subscriptions adds a renewal through add_to_cart() with the flow key in the item data, so the cart cannot
	 * show it yet. Set by the item-data filter, replaced by the next add, cleared once the item is added.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private static $adding = null;

	/**
	 * Hooks the filters.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 20, 2 );
		// After WooCommerce Subscriptions' own item-data filters, so the keys are in place.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'note_flow' ), 999, 3 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'clear_flow' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validation' ), 0 );
		add_action( 'shutdown', array( __CLASS__, 'clear_flow' ) );
		// Paying an existing renewal, switch or resubscribe order.
		add_filter( 'woocommerce_pay_order_product_in_stock', array( __CLASS__, 'pay_order' ), 20, 3 );
	}

	/**
	 * Filter woocommerce_add_cart_item_data: opens the flow flag when the data carries a flow key.
	 *
	 * @param mixed $data         Cart item data.
	 * @param mixed $product_id   Product id.
	 * @param mixed $variation_id Variation id.
	 * @return mixed
	 */
	public static function note_flow( $data, $product_id = 0, $variation_id = 0 ) {
		self::$adding = is_array( $data ) && self::has_flow_key( $data ) ? array( (int) $product_id, (int) $variation_id ) : null;

		return $data;
	}

	/**
	 * Filter woocommerce_add_to_cart_validation, first in line: a new add starts, so a flag left by an add that aborted is closed.
	 *
	 * @param mixed $valid Whether the add is valid so far.
	 * @return mixed
	 */
	public static function validation( $valid ) {
		self::$adding = null;

		return $valid;
	}

	/**
	 * Action woocommerce_add_to_cart: the item is in the cart, close the flag. Also at the end of the request.
	 */
	public static function clear_flow(): void {
		self::$adding = null;
	}

	/**
	 * Filter woocommerce_pay_order_product_in_stock: renewal, switch and resubscribe orders can be paid.
	 *
	 * @param mixed $in_stock Value so far.
	 * @param mixed $product  Product.
	 * @param mixed $order    Order.
	 * @return mixed
	 */
	public static function pay_order( $in_stock, $product = null, $order = null ) {
		if ( $in_stock || ! $product instanceof \WC_Product || ! $order instanceof \WC_Abstract_Order || ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return $in_stock;
		}

		$driver = Product::driver_for( $product->get_id() );

		if ( ! $driver || HostingDriver::ID !== $driver->id() ) {
			return $in_stock;
		}

		return wcs_order_contains_subscription( $order, array( 'renewal', 'switch', 'resubscribe' ) ) ? true : $in_stock;
	}

	/**
	 * Does this data carry one of the flow keys?
	 *
	 * @param array<string, mixed> $data Cart item data or session values.
	 */
	private static function has_flow_key( array $data ): bool {
		return CartFlow::has( $data, CartFlow::ALL );
	}

	/**
	 * Filter woocommerce_product_is_in_stock: a renewal, switch or resubscribe of an existing customer is in stock even
	 * when the stored status is `outofstock`. Never closes anything.
	 *
	 * @param mixed $value   Value so far.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function in_stock( $value, $product ) {
		if ( $value || ! $product instanceof \WC_Product ) {
			return $value;
		}

		$driver = Product::driver_for( $product->get_id() );

		return $driver && HostingDriver::ID === $driver->id() && self::existing_customer_flow( $product ) ? true : $value;
	}

	/**
	 * Is this a renewal, switch or resubscribe?
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function existing_customer_flow( \WC_Product $product ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Only presence is read, to let an existing customer through; WooCommerce Subscriptions verifies its own nonces.
		if ( SwitchLink::requested( true )['subscription'] > 0 || ! empty( $_GET['resubscribe'] ) ) {
			return true;
		}
		// phpcs:enable

		if ( null !== self::$adding && in_array( $product->get_id(), self::$adding, true ) ) {
			return true;
		}

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

			if ( $same && CartFlow::has( $item, CartFlow::ALL ) ) {
				return true;
			}
		}

		return false;
	}
}
