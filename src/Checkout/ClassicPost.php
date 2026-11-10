<?php
/**
 * Reading a classic checkout field from the posted form.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that reads the classic checkout post.
 */
final class ClassicPost {

	/**
	 * The posted value, only inside WooCommerce's own checkout run (it verified its nonce before
	 * firing woocommerce_checkout_process). A form that does not carry the field at all (the step
	 * was not shown) gives the fallback; a posted value, even an empty one, wins. Callers trim.
	 *
	 * @param string $post_key Form field name.
	 * @param mixed  $fallback Used when the form does not carry the field.
	 */
	public static function raw( string $post_key, $fallback = '' ): string {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce; every caller checks the value strictly (a listed choice, a parsed key or a normalised name) before using it.
		$value = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : $fallback;

		return is_string( $value ) ? $value : '';
	}
}
