<?php
/**
 * What the per-cart-line checkout fields share.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Checkout;

use BeltoftLicenses\Licenses;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The cart lines of one product kind, the block checkout refusal and the reading of one line's posted values, for the
 * fields that ask a question per cart line (email, hosting, certificate and licence key).
 */
final class LineField {

	/**
	 * The cart lines that start a new service of one kind: a renewal or a switch pays for a service that exists, so it
	 * is skipped.
	 *
	 * @param callable $is_product Takes a product or variation id, true for the field's own products.
	 * @return array<string, array<string, mixed>> Cart item key => cart item.
	 */
	public static function cart_lines( callable $is_product ): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$out  = array();

		if ( ! $cart ) {
			return $out;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( CartFlow::has( $item, CartFlow::EXISTING_SERVICE ) ) {
				continue;
			}

			if ( $is_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				$out[ (string) $key ] = $item;
			}
		}

		return $out;
	}

	/**
	 * The block checkout cannot ask the question, so a cart with a line of the kind is refused there.
	 *
	 * @param callable $is_product Takes a product or variation id, true for the field's own products.
	 * @param string   $code       Error code.
	 * @param string   $message    Message for the customer.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the cart has such a line.
	 */
	public static function refuse_store_api( callable $is_product, string $code, string $message ): void {
		if ( array() !== self::cart_lines( $is_product ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $code ), esc_html( $message ), 400 );
		}
	}

	/**
	 * Everything the form posted for one cart line (not trimmed, may be nested).
	 *
	 * @param string $post_key Form field name.
	 * @param string $line_key Cart item key.
	 * @return array<mixed>
	 */
	public static function posted_line( string $post_key, string $line_key ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce (or the form is only refilled); every value is checked by the field before it is used.
		$all = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : array();
		// phpcs:enable

		return is_array( $all ) && isset( $all[ $line_key ] ) && is_array( $all[ $line_key ] ) ? $all[ $line_key ] : array();
	}

	/**
	 * The posted values of one line as plain strings: the listed fields trimmed, '' for anything missing or not a string.
	 *
	 * @param string   $post_key Form field name.
	 * @param string   $line_key Cart item key.
	 * @param string[] $fields   Field names to read.
	 * @return array<string, string>
	 */
	public static function read_post( string $post_key, string $line_key, array $fields ): array {
		$one = self::posted_line( $post_key, $line_key );
		$out = array();

		foreach ( $fields as $field ) {
			$out[ $field ] = isset( $one[ $field ] ) && is_string( $one[ $field ] ) ? trim( $one[ $field ] ) : '';
		}

		return $out;
	}

	/**
	 * The order lines a new license of a multi line order may take, and what its sibling licenses of the same driver took
	 * already: the order's product lines of the license's product (and variation), and the keys the siblings hold. The caller
	 * picks the first line whose data is not yet taken.
	 *
	 * @param array<string, mixed> $license   License row.
	 * @param string               $driver_id Driver of the field.
	 * @param callable             $key_of    Takes a sibling Resource, gives the key it holds (null: it holds none).
	 * @return array{used: array<int, mixed>, items: array<int, \WC_Order_Item_Product>}|null Null when the license has no order.
	 */
	public static function siblings( array $license, string $driver_id, callable $key_of ): ?array {
		$order_id = (int) ( $license['order_id'] ?? 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$product_id = (int) ( $license['product_id'] ?? 0 );
		$variation  = (int) ( $license['variation_id'] ?? 0 );
		$used       = array();

		foreach ( Licenses::where( 'order_id', $order_id ) as $other ) {
			if ( (int) $other['id'] === (int) ( $license['id'] ?? 0 ) ) {
				continue;
			}

			$resource = Resource::for_license( (int) $other['id'] );

			if ( ! $resource || $driver_id !== $resource->get( 'driver' ) ) {
				continue;
			}

			$key = $key_of( $resource );

			if ( null !== $key ) {
				$used[] = $key;
			}
		}

		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && ( 0 === $variation || (int) $item->get_variation_id() === $variation ) ) {
				$items[] = $item;
			}
		}

		return array( 'used' => $used, 'items' => $items );
	}
}
