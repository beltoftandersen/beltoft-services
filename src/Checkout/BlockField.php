<?php
/**
 * Registering a block checkout field.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * One place that registers an additional block checkout field safely.
 */
final class BlockField {

	/**
	 * Registers the field when this WooCommerce has the API. A refusal is swallowed: the classic
	 * checkout still works, and WooCommerce already reported why.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 */
	public static function register( array $args ): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field( $args );
		} catch ( \Exception $e ) {
			unset( $e ); // The classic checkout still works; WooCommerce already reported why.
		}
	}
}
