<?php
/**
 * Billing details that services need at checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftServices\Drivers\NeedsBilling;

defined( 'ABSPATH' ) || exit;

/**
 * A cart with a service whose driver needs billing details (see NeedsBilling) makes the
 * billing name, email, address, country and phone required. WooCommerce checks required
 * fields on the server for the classic checkout; the Store API gets the same rule here.
 */
final class BillingRequirements {

	/**
	 * Billing fields a service order must have.
	 */
	private const FIELDS = array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'billing_address_1', 'billing_city', 'billing_postcode', 'billing_country' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'require_fields' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'check_store_api' ), 10, 2 );
	}

	/**
	 * Does the cart hold a new purchase of a service that needs billing details? Renewals and
	 * switches keep the details already on the subscription.
	 *
	 * @param \WC_Cart|null $cart Cart; the current cart when null.
	 */
	public static function cart_needs_billing( ?\WC_Cart $cart = null ): bool {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );

		if ( ! $cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['subscription_renewal'] ) || isset( $item['subscription_switch'] ) ) {
				continue;
			}

			if ( Product::driver_for( (int) ( $item['product_id'] ?? 0 ) ) instanceof NeedsBilling ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Marks the billing fields required.
	 *
	 * @param mixed $fields Checkout fields.
	 * @return mixed
	 */
	public static function require_fields( $fields ) {
		if ( ! is_array( $fields ) || ! isset( $fields['billing'] ) || ! self::cart_needs_billing() ) {
			return $fields;
		}

		foreach ( self::FIELDS as $key ) {
			if ( isset( $fields['billing'][ $key ] ) ) {
				$fields['billing'][ $key ]['required'] = true;
			}
		}

		return $fields;
	}

	/**
	 * Store API checkout: refuses a service order with incomplete billing details.
	 *
	 * @param \WC_Order        $order   Order being built.
	 * @param \WP_REST_Request $request Request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When billing details are missing.
	 */
	public static function check_store_api( $order, $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( ! $order instanceof \WC_Order || ! self::cart_needs_billing() ) {
			return;
		}

		foreach ( self::FIELDS as $key ) {
			$getter = 'get_' . $key;

			if ( '' === trim( (string) $order->$getter() ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'beltoft_services_billing_required',
					esc_html__( 'Enter your full billing details (name, address, country and phone) to order this service.', 'beltoft-services' ),
					400
				);
			}
		}
	}
}
