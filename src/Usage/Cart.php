<?php
/**
 * The checkout rules of pay-as-you-go services.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A pay-as-you-go variation (meta `_beltoft_services_billing` = usage) is not a subscription product to
 * WooCommerce Subscriptions or beltoft-licenses (the filter `woocommerce_is_subscription`), so its order
 * line makes an ordinary license with no expiry, one per server, and no subscription of its own. The card
 * is required: the cart gets the hidden "Usage account" subscription product when the customer has no
 * account (it makes WooCommerce Subscriptions and the Stripe gateway save the payment method, with a setup
 * intent when the total is 0), and a payment method that cannot be charged later is refused. An account
 * on hold takes no new service, and an account with no paid usage invoice is limited in how many it runs.
 */
final class Cart {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_is_subscription', array( __CLASS__, 'is_subscription' ), 20, 3 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'sync_account_line' ) );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'sync_account_line' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'sync_account_line' ) );
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'needs_payment' ), 30, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'check_store_api' ), 10, 2 );
	}

	/**
	 * A pay-as-you-go variation is not a subscription product.
	 *
	 * @param mixed $is         Whether it is.
	 * @param mixed $product_id Product id.
	 * @param mixed $product    Product.
	 */
	public static function is_subscription( $is, $product_id = 0, $product = null ): bool {
		return $is && ! ( $product instanceof \WC_Product && Rates::is_usage( $product ) );
	}

	/**
	 * Pay-as-you-go units in a cart (renewals and switches excluded).
	 *
	 * @param \WC_Cart|null $cart Cart; the current one when null.
	 */
	public static function usage_units( ?\WC_Cart $cart = null ): int {
		$cart  = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$units = 0;

		foreach ( $cart ? $cart->get_cart() : array() as $item ) {
			if ( isset( $item['subscription_renewal'] ) || isset( $item['subscription_switch'] ) ) {
				continue;
			}

			if ( ! empty( $item['variation_id'] ) && Rates::is_usage( (int) $item['variation_id'] ) ) {
				$units += (int) $item['quantity'];
			}
		}

		return $units;
	}

	/**
	 * The cart key of the hidden account line, or ''.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	private static function account_key( \WC_Cart $cart ): string {
		$id = (int) get_option( Account::OPTION_PRODUCT );

		foreach ( $id > 0 ? $cart->get_cart() : array() as $key => $item ) {
			if ( (int) $item['product_id'] === $id ) {
				return (string) $key;
			}
		}

		return '';
	}

	/**
	 * A cart with pay-as-you-go units and no account for the customer holds the hidden account line; a cart
	 * without units does not.
	 */
	public static function sync_account_line(): void {
		static $busy = false;

		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( $busy || ! $cart instanceof \WC_Cart ) {
			return;
		}

		$busy = true;

		try {
			$key  = self::account_key( $cart );
			$need = self::usage_units( $cart ) > 0 && null === Account::for_user( get_current_user_id(), Account::ESTABLISHED );

			if ( $need && '' === $key ) {
				$cart->add_to_cart( Account::product_id() );
			} elseif ( ! $need && '' !== $key ) {
				$cart->remove_cart_item( $key );
			}
		} finally {
			$busy = false;
		}
	}

	/**
	 * A cart with the account line needs a payment method even when it totals 0.
	 *
	 * @param mixed         $needs Whether it needs payment.
	 * @param \WC_Cart|null $cart  Cart.
	 */
	public static function needs_payment( $needs, $cart = null ): bool {
		if ( $needs ) {
			return true;
		}

		$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );

		return $cart instanceof \WC_Cart && '' !== self::account_key( $cart ) && self::usage_units( $cart ) > 0;
	}

	/**
	 * Why this order of pay-as-you-go services is refused: a list of plain messages, empty when it may go on.
	 *
	 * @param int    $user_id  Customer (0 for a guest who is registering).
	 * @param string $gateway  Chosen payment method id.
	 * @param int    $units    Pay-as-you-go units ordered now.
	 * @return string[]
	 */
	public static function problems( int $user_id, string $gateway, int $units ): array {
		if ( $units < 1 ) {
			return array();
		}

		$problems = array();
		$account  = Account::for_user( $user_id, Account::ESTABLISHED );

		if ( $account ) {
			if ( $account->has_status( 'on-hold' ) ) {
				$problems[] = __( 'Your usage account has an unpaid invoice. Pay it before you order more pay as you go services.', 'beltoft-services' );
			} elseif ( ! Account::has_card( $account ) ) {
				$problems[] = __( 'Pay as you go needs a card saved on your usage account. Add one under your payment methods first.', 'beltoft-services' );
			}
		} else {
			$methods = function_exists( 'WC' ) ? WC()->payment_gateways()->payment_gateways() : array();

			if ( '' === $gateway || ! isset( $methods[ $gateway ] ) || ! $methods[ $gateway ]->supports( 'subscriptions' ) ) {
				$problems[] = __( 'Pay as you go needs a card that can be charged later. Choose a card as the payment method.', 'beltoft-services' );
			}
		}

		$limit = (int) Settings::get( 'usage_new_limit' );

		if ( $limit > 0 && ! Account::charged( $account ) && count( Account::resources( $user_id ) ) + $units > $limit ) {
			$problems[] = sprintf(
				/* translators: %d: number of services. */
				_n( 'A new account can run %d pay as you go service until its first usage invoice is paid.', 'A new account can run %d pay as you go services until its first usage invoice is paid.', $limit, 'beltoft-services' ),
				$limit
			);
		}

		return $problems;
	}

	/**
	 * Classic checkout. WooCommerce has verified the checkout nonce.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors Validation errors.
	 */
	public static function validate( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! is_array( $data ) ) {
			return;
		}

		foreach ( self::problems( get_current_user_id(), (string) ( $data['payment_method'] ?? '' ), self::usage_units() ) as $message ) {
			$errors->add( 'beltoft_services_usage', $message );
		}
	}

	/**
	 * Block checkout (Store API): the same rules.
	 *
	 * @param mixed $order   Order being built.
	 * @param mixed $request Request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the order is refused.
	 */
	public static function check_store_api( $order, $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$problems = self::problems( (int) $order->get_customer_id(), (string) $order->get_payment_method(), self::usage_units() );

		if ( $problems ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'beltoft_services_usage', esc_html( $problems[0] ), 400 );
		}
	}
}
