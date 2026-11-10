<?php
/** The cart hooks every server add-on kind shares. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Addon;

use BeltoftServices\Proxmox\DiskField;

defined( 'ABSPATH' ) || exit;

/**
 * What the cart of an extra disk, a snapshot pack and an app licence do the same way: the order line remembers its
 * server, each line gets its own renewal cart, and a line is always one piece. The kinds keep their own validation.
 */
final class AddonCart {
	/**
	 * Hook the per-line cart behaviour of one add-on kind.
	 *
	 * @param Addon $a Add-on kind.
	 */
	public static function register_line_hooks( Addon $a ): void {
		add_action(
			'woocommerce_checkout_create_order_line_item',
			static function ( $item, $cart_item_key, $values, $order ) use ( $a ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
				if ( $item instanceof \WC_Order_Item_Product && is_array( $values ) && isset( $values[ $a->cart_uid() ], $values[ $a->cart_for() ] ) ) {
					$item->add_meta_data( $a->item_uid(), (string) $values[ $a->cart_uid() ], true );
					$item->add_meta_data( $a->item_server(), $values[ $a->cart_for() ], true );
				}
			},
			10,
			4
		);

		add_filter(
			'woocommerce_subscriptions_recurring_cart_key',
			static function ( $key, $cart_item = array() ) use ( $a ): string {
				$uid = is_array( $cart_item ) ? (string) ( $cart_item[ $a->cart_uid() ] ?? '' ) : '';

				return '' === $uid ? (string) $key : $key . $a->recurring_suffix . md5( $uid );
			},
			10,
			2
		);

		add_filter(
			'woocommerce_cart_item_quantity',
			static function ( $html, $cart_item_key = '', $cart_item = array() ) use ( $a ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
				return is_array( $cart_item ) && isset( $cart_item[ $a->cart_uid() ] ) ? '1' : (string) $html;
			},
			10,
			3
		);

		add_action(
			'woocommerce_before_calculate_totals',
			static function ( $cart ) use ( $a ): void {
				foreach ( $cart instanceof \WC_Cart ? $cart->get_cart() : array() as $key => $item ) {
					if ( isset( $item[ $a->cart_uid() ] ) && 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
						$cart->cart_contents[ $key ]['quantity'] = 1;
					}
				}
			},
			5
		);
	}

	/**
	 * Confirm WCS is switching this customer's existing line of the product.
	 *
	 * @param int $product_id Product id.
	 */
	public static function valid_switch( int $product_id ): bool {
		$subscription_id = isset( $_GET['switch-subscription'] ) ? absint( wp_unslash( $_GET['switch-subscription'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WCS validates the switch before this hook.
		$item_id         = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ownership and WCS ability checked below.
		if ( ! $subscription_id || ! $item_id || ! function_exists( 'wcs_get_subscription' ) || ! class_exists( '\\WC_Subscriptions_Switcher' ) ) {
			return false;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		$item         = $subscription instanceof \WC_Subscription ? $subscription->get_item( $item_id ) : null;

		return $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription, get_current_user_id() );
	}

	/**
	 * The server a new line is for: the license id when the nonce is right and the caller owns it, `cart_server` for the
	 * server still in the cart (only for kinds that accept it), else null.
	 *
	 * @param Addon    $a     Add-on kind.
	 * @param array    $data  Cart item data.
	 * @param callable $owned Given the license id: is it the caller's own, fit server?
	 * @return int|string|null
	 */
	public static function link( Addon $a, array $data, callable $owned ) {
		$requested_link = isset( $_REQUEST[ $a->cart_for() ] ) && is_scalar( $_REQUEST[ $a->cart_for() ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $a->cart_for() ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$link           = $data[ $a->cart_for() ] ?? $requested_link;
		if ( $a->accepts_cart_server && 'cart_server' === $link ) {
			return DiskField::cart_server() ? 'cart_server' : null;
		}
		if ( ! is_scalar( $link ) || ! ctype_digit( (string) $link ) ) {
			return null;
		}
		$id              = (int) $link;
		$nonce_key       = 'beltoft_services_' . $a->prefix . '_nonce';
		$requested_nonce = isset( $_REQUEST[ $nonce_key ] ) && is_scalar( $_REQUEST[ $nonce_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		$nonce           = $data[ $nonce_key ] ?? $requested_nonce;
		if ( ! is_scalar( $nonce ) || ! wp_verify_nonce( (string) $nonce, $a->nonce_action . ':' . $id ) ) {
			return null;
		}

		return $owned( $id ) ? $id : null;
	}
}
