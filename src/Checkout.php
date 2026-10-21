<?php
/**
 * The checkout rules for service trials.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * A trial of a service needs a payment method (when the product says so) and is limited to
 * one per customer. WooCommerce Subscriptions decides what a trial is; this class only adds
 * the per-product rule on top of its own hooks.
 */
final class Checkout {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// After WooCommerce Subscriptions, whose $0 checkout setting may unhook its own rule.
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
	}

	/**
	 * Is this product (or variation) in a free trial and a service?
	 *
	 * @param \WC_Product|false $product Product.
	 */
	private static function is_service_trial( $product ): bool {
		if ( ! $product instanceof \WC_Product || ! class_exists( \WC_Subscriptions_Product::class ) ) {
			return false;
		}

		return Product::is_service( $product->get_id() ) && \WC_Subscriptions_Product::get_trial_length( $product ) > 0;
	}

	/**
	 * Service trials being bought in the cart. Renewals, switches and resubscribes are not a
	 * new trial, so they are skipped (WooCommerce Subscriptions marks those cart items).
	 *
	 * @param \WC_Cart|null $cart Cart; the current cart when null.
	 * @return \WC_Product[]
	 */
	private static function trials_in_cart( ?\WC_Cart $cart = null ): array {
		$trials = array();
		$cart   = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );

		if ( ! $cart ) {
			return $trials;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['subscription_renewal'] ) || isset( $item['subscription_switch'] ) || isset( $item['subscription_resubscribe'] ) ) {
				continue;
			}

			$product = $item['data'] ?? false;

			if ( self::is_service_trial( $product ) ) {
				$trials[] = $product;
			}
		}

		return $trials;
	}

	/**
	 * Requires a payment method for a $0 cart that is a trial of a service whose product asks
	 * for it.
	 *
	 * @param bool          $needs_payment Whether the cart needs payment.
	 * @param \WC_Cart|null $cart          Cart.
	 */
	public static function cart_needs_payment( $needs_payment, $cart = null ): bool {
		if ( $needs_payment ) {
			return true;
		}

		$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart || (float) $cart->get_total( 'edit' ) > 0 ) {
			return false;
		}

		foreach ( self::trials_in_cart( $cart ) as $product ) {
			if ( Product::trial_requires_payment( $product->get_id() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classic checkout: refuses a second trial. WooCommerce has verified the checkout nonce.
	 *
	 * @param array<string, mixed> $data   Posted checkout data.
	 * @param \WP_Error            $errors Validation errors.
	 */
	public static function validate( $data, $errors ): void {
		self::check( is_array( $data ) ? (string) ( $data['billing_email'] ?? '' ) : '', $errors, null );
	}

	/**
	 * Block checkout (Store API): the same rule, with the customer's billing email.
	 *
	 * @param \WP_Error $errors Cart errors.
	 * @param \WC_Cart  $cart   Cart.
	 */
	public static function store_api_errors( $errors, $cart = null ): void {
		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';

		self::check( $email, $errors, $cart instanceof \WC_Cart ? $cart : null );
	}

	/**
	 * Refuses a trial of a service the customer already holds a license for, matched by user
	 * id and by billing email (so a guest trial with the same email counts).
	 *
	 * @param string        $email  Billing email.
	 * @param \WP_Error     $errors Errors.
	 * @param \WC_Cart|null $cart   Cart.
	 */
	private static function check( string $email, $errors, ?\WC_Cart $cart ): void {
		$trials = self::trials_in_cart( $cart );
		if ( ! $trials || ! $errors instanceof \WP_Error ) {
			return;
		}

		$user_id = get_current_user_id();
		$email   = sanitize_email( $email );

		$licenses = $user_id > 0 ? \BeltoftLicenses\Licenses::where( 'user_id', $user_id ) : array();
		if ( '' !== $email ) {
			$licenses = array_merge( $licenses, \BeltoftLicenses\Licenses::where( 'email', $email ) );
		}

		if ( ! $licenses ) {
			return;
		}

		$held     = array_column( $licenses, 'product_slug' );
		$reported = array();

		foreach ( $trials as $product ) {
			$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
			$slug   = $parent ? sanitize_title( (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ) ) : '';

			if ( '' !== $slug && ! isset( $reported[ $slug ] ) && in_array( $slug, $held, true ) ) {
				$reported[ $slug ] = true;
				$errors->add(
					'beltoft_services_trial_used',
					/* translators: %s: product name. */
					sprintf( __( 'You have already used the trial for %s.', 'beltoft-services' ), $parent->get_name() )
				);
			}
		}
	}
}
