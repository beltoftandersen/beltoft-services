<?php
/** A domain line and one service line in one cart. @package BeltoftServices */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * A domain line and one service line (a Business email plan or a WordPress hosting plan) may sit together, in both
 * directions: the service is set up on a domain registered in the same order. Which products are such a service is
 * answered by the features through the filter `beltoft_services_domain_service_product` (Mail and Hosting hook it).
 * A new domain still replaces the old domain, and a new service line replaces the old service line.
 */
final class CartPair {

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_cart_companion', array( __CLASS__, 'answer' ), 10, 3 );
	}

	/**
	 * Is the product a service that is set up on a domain of the same order?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function is_service_product( int $product_id ): bool {
		/**
		 * Filter: is this product a service that can sit in the cart beside a domain line?
		 *
		 * @param bool $is         Whether it is.
		 * @param int  $product_id Product or variation id.
		 */
		return $product_id > 0 && true === apply_filters( 'beltoft_services_domain_service_product', false, $product_id );
	}

	/**
	 * Filter beltoft_services_cart_companion.
	 *
	 * @param mixed $keep Earlier answer.
	 * @param mixed $line Existing cart line.
	 * @param mixed $new  Line being added.
	 */
	public static function answer( $keep, $line = array(), $new = array() ): bool {
		if ( true === $keep || ! is_array( $line ) || ! is_array( $new ) ) {
			return true === $keep;
		}

		$new_is_domain  = isset( $new['beltoft_services_domain'] );
		$line_is_domain = isset( $line['beltoft_services_domain'] );

		if ( $new_is_domain && ! $line_is_domain && self::is_service_product( (int) ( $line['product_id'] ?? 0 ) ) ) {
			return true;
		}

		return ! $new_is_domain && $line_is_domain && self::is_service_product( (int) ( $new['product_id'] ?? 0 ) );
	}
}
