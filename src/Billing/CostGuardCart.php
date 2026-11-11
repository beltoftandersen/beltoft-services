<?php
/**
 * A cart whose supplier cost cannot be guarded is refused before it is paid.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * A domain, certificate or licence key is paid to a supplier in euro, and the order is only placed when what the customer paid covers
 * that cost at the guard rate (Currency::to_cost). In a currency with no guard rate the guard refuses AFTER the customer has paid, so the
 * cart is refused first: the customer is told at the cart, the admin once per currency. A cart in the cost currency, or in the shop's own
 * currency, is untouched; so is any product whose driver pays no supplier.
 */
final class CostGuardCart {

	/**
	 * Hooks: the cart page, the classic checkout post and the Store API (block checkout).
	 */
	public static function register(): void {
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'cart' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api' ), 10, 2 );
	}

	/**
	 * The drivers that pay a supplier in the cost currency (and so have a cost guard).
	 *
	 * @return string[]
	 */
	public static function driver_ids(): array {
		/**
		 * The ids of the drivers whose orders are guarded against a supplier cost.
		 *
		 * @param string[] $ids Driver ids: the domain, certificate and licence key drivers.
		 */
		return array_map( 'strval', (array) apply_filters( 'beltoft_services_cost_guard_drivers', array( 'domain', 'ssl', 'licence-key' ) ) );
	}

	/**
	 * The refusal for the current cart, or '' when there is none.
	 */
	public static function problem(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$ids = self::driver_ids();

		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = (int) ( $item['variation_id'] ?? 0 ) > 0 ? (int) $item['variation_id'] : (int) ( $item['product_id'] ?? 0 );
			$driver     = Product::driver_for( $product_id );

			if ( null === $driver || ! in_array( $driver->id(), $ids, true ) ) {
				continue;
			}

			// A line carries the currency its price was worked out in; any other is priced in the cart's.
			$code = Currency::clean( (string) ( $item[ Currency::CART_KEY ] ?? '' ) );
			$code = '' !== $code ? $code : Currency::current();

			if ( Currency::COST === $code || Currency::base() === $code || null !== Currency::guard_rate( $code ) ) {
				continue;
			}

			Alerts::notify(
				/* translators: %s: currency code */
				sprintf( __( 'No guard rate for %s', 'beltoft-services' ), $code ),
				array( sprintf( 'A cart in %1$s holds a domain, certificate or licence key, and the setting currency_guard_rates has no line for %1$s, so the cart was refused before payment. Add a line such as %1$s=7.60 (units of %1$s per %2$s, a little above the real rate).', $code, Currency::COST ) ),
				null,
				'beltoft_services_guard_rate_' . strtolower( $code )
			);

			/* translators: %s: currency code such as DKK */
			return sprintf( __( 'This cannot be bought in %s right now. Remove it from your cart, or contact us.', 'beltoft-services' ), $code );
		}

		return '';
	}

	/**
	 * Action woocommerce_check_cart_items.
	 */
	public static function cart(): void {
		$problem = self::problem();

		if ( '' !== $problem && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $problem, 'error' );
		}
	}

	/**
	 * Action woocommerce_after_checkout_validation.
	 *
	 * @param mixed $data   Posted data.
	 * @param mixed $errors Errors.
	 */
	public static function checkout( $data, $errors ): void {
		unset( $data );
		$problem = $errors instanceof \WP_Error ? self::problem() : '';

		if ( '' !== $problem ) {
			$errors->add( 'beltoft_services_cost_guard', $problem );
		}
	}

	/**
	 * Action woocommerce_store_api_cart_errors.
	 *
	 * @param mixed $errors Errors.
	 * @param mixed $cart   Cart.
	 */
	public static function store_api( $errors, $cart = null ): void {
		unset( $cart );
		$problem = $errors instanceof \WP_Error ? self::problem() : '';

		if ( '' !== $problem ) {
			$errors->add( 'beltoft_services_cost_guard', $problem );
		}
	}
}
