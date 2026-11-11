<?php
/**
 * Sold-out hosting plans.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Billing\ExistingFlow;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * A hosting plan is sold out when its WooCommerce stock status is `outofstock`, set by hand in the product editor.
 * Capacity never stops a sale (owner decision 2026-11-11): hosting is oversold on purpose, so nothing here counts the
 * room of a node when a page is shown, a plan is added to the cart or an order is checked out, and no filter closes
 * `woocommerce_is_purchasable` or `woocommerce_product_is_in_stock`. Where a new account goes is HostingCapacity::place(),
 * and a node over its budget is reported to the admin (HostingCapacity::overage_alerts()).
 *
 * What stays is a rule about the customer, not about capacity: a renewal, switch or resubscribe of a subscription the
 * logged-in customer owns and that holds this plan is never blocked by a stored `outofstock` (the in-stock filter only
 * opens, for those flows; Billing\ExistingFlow decides which).
 */
final class Stock {

	/**
	 * Hooks the filters.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 20, 2 );
		ExistingFlow::register();
		// Paying an existing renewal, switch or resubscribe order.
		add_filter( 'woocommerce_pay_order_product_in_stock', array( __CLASS__, 'pay_order' ), 20, 3 );
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

		return $driver && HostingDriver::ID === $driver->id() && array() !== ExistingFlow::kinds( $product ) ? true : $value;
	}
}
