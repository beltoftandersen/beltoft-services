<?php
/**
 * What the per-cart-line checkout fields share.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Checkout;

use BeltoftServices\Billing\CartFlow;

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
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( $code, esc_html( $message ), 400 );
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
}
