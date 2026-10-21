<?php
/**
 * Checkout trial rule.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Checkout;

/**
 * Trials of services need a payment method and are limited to one per customer.
 */
class CheckoutTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// The store lets $0 initial checkouts skip the payment method, so only our rule can require it.
		update_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment', 'yes' );
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		delete_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment' );

		parent::tear_down();
	}

	/**
	 * Turns a service product into a subscription product, with or without a trial.
	 *
	 * @param \WC_Product $product      Product.
	 * @param int         $trial_length Trial days (0 for none).
	 */
	private function as_subscription( \WC_Product $product, int $trial_length ): \WC_Product {
		wp_set_object_terms( $product->get_id(), 'subscription', 'product_type' );
		clean_post_cache( $product->get_id() );
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $product->get_id() );
		update_post_meta( $product->get_id(), '_subscription_price', '10' );
		update_post_meta( $product->get_id(), '_subscription_period', 'month' );
		update_post_meta( $product->get_id(), '_subscription_period_interval', '1' );
		update_post_meta( $product->get_id(), '_subscription_length', '0' );
		update_post_meta( $product->get_id(), '_subscription_sign_up_fee', '0' );
		update_post_meta( $product->get_id(), '_subscription_trial_length', (string) $trial_length );
		update_post_meta( $product->get_id(), '_subscription_trial_period', 'day' );
		wc_delete_product_transients( $product->get_id() );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product_Subscription::class, $fresh );

		return $fresh;
	}

	/**
	 * Puts a product in the cart and totals it.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function cart( \WC_Product $product ): void {
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id() );
		WC()->cart->calculate_totals();
	}

	public function test_trial_cart_needs_payment_for_services(): void {
		$this->fake_driver();
		$product = $this->as_subscription( $this->service_product( 'fake', array(), array( 'trial_requires_payment' => 'yes' ) ), 7 );
		$this->cart( $product );

		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ), 'The trial makes the cart total 0.' );
		$this->assertTrue( WC()->cart->needs_payment() );

		$free = $this->as_subscription( $this->service_product( 'fake', array(), array( 'trial_requires_payment' => 'no' ) ), 7 );
		$this->cart( $free );

		$this->assertFalse( WC()->cart->needs_payment(), 'A service that does not require payment for trials stays free.' );
	}

	public function test_trial_cart_untouched_for_other_products(): void {
		$product = $this->as_subscription( $this->service_product( '' ), 7 );
		$this->cart( $product );

		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		$this->assertFalse( WC()->cart->needs_payment(), 'Not a service: the store setting decides.' );
	}

	public function test_paid_purchase_is_not_affected(): void {
		$this->fake_driver();

		$no_trial = $this->as_subscription( $this->service_product( 'fake' ), 0 );
		$this->cart( $no_trial );
		$this->assertGreaterThan( 0, (float) WC()->cart->get_total( 'edit' ) );
		$this->assertTrue( WC()->cart->needs_payment(), 'A paid cart needs payment as always.' );

		// A customer who already has a license can still buy the service: only a trial is refused.
		$user = self::factory()->user->create();
		$this->service_license( $no_trial, array( 'user_id' => $user ) );
		wp_set_current_user( $user );

		$errors = new \WP_Error();
		Checkout::validate( array( 'billing_email' => 'x@example.com' ), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_second_trial_is_refused_by_user_and_by_email(): void {
		$this->fake_driver();
		$product = $this->as_subscription( $this->service_product( 'fake', array(), array( 'slug' => 'trial-svc' ) ), 7 );
		$this->cart( $product );

		$errors = new \WP_Error();
		Checkout::validate( array( 'billing_email' => 'new@example.com' ), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'A first trial is fine.' );

		$user = self::factory()->user->create();
		$this->service_license( $product, array( 'user_id' => $user, 'email' => 'owner@example.com' ) );

		wp_set_current_user( $user );
		$errors = new \WP_Error();
		Checkout::validate( array( 'billing_email' => 'other@example.com' ), $errors );
		$this->assertSame( array( 'beltoft_services_trial_used' ), $errors->get_error_codes(), 'Refused by user id.' );
		$this->assertSame( sprintf( 'You have already used the trial for %s.', $product->get_name() ), $errors->get_error_message() );

		wp_set_current_user( 0 );
		$errors = new \WP_Error();
		Checkout::validate( array( 'billing_email' => 'Owner@Example.com' ), $errors );
		$this->assertSame( array( 'beltoft_services_trial_used' ), $errors->get_error_codes(), 'Refused by billing email for a guest.' );

		$errors = new \WP_Error();
		Checkout::validate( array( 'billing_email' => 'stranger@example.com' ), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_hooks_are_registered(): void {
		$this->assertNotFalse( has_filter( 'woocommerce_cart_needs_payment', array( Checkout::class, 'cart_needs_payment' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_checkout_validation', array( Checkout::class, 'validate' ) ) );
	}
}
