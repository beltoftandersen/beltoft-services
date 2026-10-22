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

	/**
	 * Cart item data keys of WooCommerce Subscriptions' flows: the `$cart_item_key` properties of
	 * WCS_Cart_Renewal (core/class-wcs-cart-renewal.php:18), WCS_Cart_Switch (switching/class-wcs-cart-switch.php:11)
	 * and WCS_Cart_Resubscribe (core/class-wcs-cart-resubscribe.php:19). Early renewal is a renewal cart too.
	 */
	private const CART_KEYS = array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe', 'subscription_renewal_early' );

	/**
	 * Product and variation id of the item being added now when it belongs to one of those flows.
	 * WooCommerce checks is_purchasable() inside add_to_cart() BEFORE the item is in the cart
	 * (class-wc-cart.php:1367), and WCS adds a renewal through WC()->cart->add_to_cart() with the
	 * keys in the item data (class-wcs-cart-renewal.php:364-370). The flag is set by the item-data
	 * filter, replaced by the next add, and cleared once the item is added.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private static $adding = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'filter' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter' ), 20, 2 );
		// After WCS's own item-data filters (priority 10, e.g. the switcher's), so the keys are in place.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'note_flow' ), 999, 3 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'clear_flow' ) );
		// A cart loaded from the session checks is_purchasable() before the item data is applied (class-wc-cart-session.php:198).
		add_filter( 'woocommerce_cart_item_is_purchasable', array( __CLASS__, 'session_item' ), 20, 4 );
		// Paying an existing renewal, switch or resubscribe order (woocommerce/includes/shortcodes/class-wc-shortcode-checkout.php:154).
		add_filter( 'woocommerce_pay_order_product_in_stock', array( __CLASS__, 'pay_order' ), 20, 3 );
	}

	/**
	 * Filter woocommerce_add_cart_item_data: opens the flow flag when the data carries a WCS flow key.
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
	 * Action woocommerce_add_to_cart: the item is in the cart, close the flag.
	 */
	public static function clear_flow(): void {
		self::$adding = null;
	}

	/**
	 * Filter woocommerce_cart_item_is_purchasable: an item restored from the session that carries a
	 * flow key stays.
	 *
	 * @param mixed $purchasable Value so far.
	 * @param mixed $key         Cart item key.
	 * @param mixed $values      Session values of the item.
	 * @param mixed $product     Product.
	 * @return mixed
	 */
	public static function session_item( $purchasable, $key = '', $values = array(), $product = null ) {
		if ( $purchasable || ! $product instanceof \WC_Product || ! is_array( $values ) || ! self::has_flow_key( $values ) ) {
			return $purchasable;
		}

		$driver = Product::driver_for( $product->get_id() );

		return $driver && 'proxmox' === $driver->id() ? true : $purchasable;
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

		if ( ! $driver || 'proxmox' !== $driver->id() ) {
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
		foreach ( self::CART_KEYS as $key ) {
			if ( isset( $data[ $key ] ) ) {
				return true;
			}
		}

		return false;
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
	 * Does the current user own this subscription? A made-up id in the address proves nothing.
	 *
	 * @param int $subscription_id Subscription id.
	 */
	private static function owns( int $subscription_id ): bool {
		$user = get_current_user_id();

		if ( $user <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}

		$subscription = wcs_get_subscription( $subscription_id );

		return $subscription && (int) $subscription->get_user_id() === $user;
	}

	/**
	 * Is this a renewal, switch or resubscribe?
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function existing_customer_flow( \WC_Product $product ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Only presence is read, to let an existing customer through; WooCommerce Subscriptions verifies its own nonces.
		$named = array(
			isset( $_REQUEST['switch-subscription'] ) ? absint( wp_unslash( $_REQUEST['switch-subscription'] ) ) : 0,
			isset( $_GET['resubscribe'] ) ? absint( wp_unslash( $_GET['resubscribe'] ) ) : 0,
		);
		// phpcs:enable

		foreach ( $named as $subscription_id ) {
			if ( $subscription_id > 0 && self::owns( $subscription_id ) ) {
				return true;
			}
		}

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

			foreach ( self::CART_KEYS as $key ) {
				if ( $same && isset( $item[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
