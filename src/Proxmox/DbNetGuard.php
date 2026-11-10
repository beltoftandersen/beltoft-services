<?php
/**
 * The shop refuses a new database when no private network can be given.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Network\PrivateNets;

defined( 'ABSPATH' ) || exit;

/**
 * Every database gets a private network (PrivateNets::reserve(), the build's net step). When the customer has none
 * with a free address and the pool has no free network, a build would fail after the customer paid. So the website says so
 * before: at add to cart, on the cart and checkout, and in the Store API. Renewals and switches build nothing new.
 * The plan lists and the MCP use Stock (in_stock) for the same rule.
 */
final class DbNetGuard {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_cart' ), 5, 4 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'cart_check' ), 5 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
	}

	/**
	 * The cluster of a database product, or null when it is not one.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 */
	private static function cluster( int $product_id, int $variation_id = 0 ): ?string {
		$driver = Product::driver_for( $product_id );

		if ( ! $driver || DbDriver::ID !== $driver->id() ) {
			return null;
		}

		return (string) ( Product::plan( $product_id, $variation_id > 0 ? $variation_id : null )['cluster'] ?? '' );
	}

	/**
	 * Add to cart: the database is not added when no private network can be given.
	 *
	 * @param mixed $valid        Whether it is valid so far.
	 * @param mixed $product_id   Product id.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation id.
	 */
	public static function add_to_cart( $valid, $product_id = 0, $quantity = 1, $variation_id = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( ! $valid ) {
			return false;
		}

		$cluster = self::cluster( (int) $product_id, (int) $variation_id );

		if ( null !== $cluster && ! PrivateNets::available_for( get_current_user_id(), $cluster ) ) {
			wc_add_notice( PrivateNets::unavailable_message(), 'error' );

			return false;
		}

		return true;
	}

	/**
	 * Does a cart hold a new database that cannot get a network?
	 *
	 * @param \WC_Cart|null $cart Cart; the current one when null.
	 */
	private static function blocked( ?\WC_Cart $cart = null ): bool {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );

		foreach ( $cart ? $cart->get_cart() : array() as $item ) {
			if ( CartFlow::has( $item, CartFlow::ALL ) ) {
				continue;
			}

			$cluster = self::cluster( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );

			if ( null !== $cluster && ! PrivateNets::available_for( get_current_user_id(), $cluster ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cart and classic checkout: an error stops the order.
	 */
	public static function cart_check(): void {
		if ( self::blocked() && ! wc_has_notice( PrivateNets::unavailable_message(), 'error' ) ) {
			wc_add_notice( PrivateNets::unavailable_message(), 'error' );
		}
	}

	/**
	 * Block checkout (Store API).
	 *
	 * @param mixed $errors Cart errors.
	 * @param mixed $cart   Cart.
	 */
	public static function store_api_errors( $errors, $cart = null ): void {
		if ( $errors instanceof \WP_Error && self::blocked( $cart instanceof \WC_Cart ? $cart : null ) ) {
			$errors->add( 'beltoft_services_db_no_network', PrivateNets::unavailable_message() );
		}
	}
}
