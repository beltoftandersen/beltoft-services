<?php
/**
 * Hosting is ordered on its own: not in one cart with Pay as you go items.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Adding a hosting plan to a cart that holds Pay as you go items removes those items, and adding a Pay as you go item
 * to a cart that holds hosting removes the hosting lines. What goes is named in a notice, so the customer sees what
 * changed. Whatever else WooCommerce Subscriptions does to the cart is left to it. Renewals and plan switches are not
 * touched, and the hidden usage account line follows the units (Usage\Cart::sync_account_line).
 */
final class CartRule {

	/**
	 * Hooks. Before WooCommerce Subscriptions empties the cart without naming anything (priority 10).
	 */
	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 5, 4 );
	}

	/**
	 * Filter woocommerce_add_to_cart_validation.
	 *
	 * @param mixed $valid        Whether the add is valid so far.
	 * @param mixed $product_id   Product being added.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation being added.
	 */
	public static function validate( $valid, $product_id = 0, $quantity = 1, $variation_id = 0 ) {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $valid || ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return $valid;
		}

		if ( SiteField::is_hosting_product( (int) $product_id ) ) {
			self::clear( $cart, false );
		} elseif ( (int) $variation_id > 0 && Rates::is_usage( (int) $variation_id ) ) {
			self::clear( $cart, true );
		}

		return $valid;
	}

	/**
	 * Removes the lines that cannot stay and says which.
	 *
	 * @param \WC_Cart $cart          Cart.
	 * @param bool     $hosting_lines True to remove the hosting lines, false to remove the Pay as you go lines.
	 */
	private static function clear( \WC_Cart $cart, bool $hosting_lines ): void {
		$account = (int) get_option( Account::OPTION_PRODUCT );
		$names   = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( isset( $item['subscription_renewal'] ) || isset( $item['subscription_switch'] ) || (int) ( $item['product_id'] ?? 0 ) === $account ) {
				continue;
			}

			$match = $hosting_lines
				? SiteField::is_hosting_product( (int) ( $item['product_id'] ?? 0 ) )
				: ( ! empty( $item['variation_id'] ) && Rates::is_usage( (int) $item['variation_id'] ) );

			if ( $match ) {
				$names[ (string) $key ] = is_object( $item['data'] ?? null ) && method_exists( $item['data'], 'get_name' ) ? wp_strip_all_tags( (string) $item['data']->get_name() ) : __( 'An item', 'beltoft-services' );
			}
		}

		if ( array() === $names ) {
			return;
		}

		foreach ( array_keys( $names ) as $key ) {
			$cart->remove_cart_item( (string) $key );
		}

		$list = implode( ', ', array_unique( $names ) );

		if ( $hosting_lines ) {
			/* translators: %s: names of the hosting plans taken out of the cart. */
			$message = sprintf( __( 'Your cart had hosting (%s). It was removed, because hosting is ordered on its own.', 'beltoft-services' ), $list );
		} else {
			/* translators: %s: names of the Pay as you go items taken out of the cart. */
			$message = sprintf( __( 'Your cart had Pay as you go items (%s). They were removed, because hosting is ordered on its own.', 'beltoft-services' ), $list );
		}

		if ( function_exists( 'wc_add_notice' ) && ! wc_has_notice( $message, 'notice' ) ) {
			wc_add_notice( $message, 'notice' );
		}
	}
}
