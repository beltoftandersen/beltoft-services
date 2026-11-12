<?php
/**
 * Shared set-up of the credit-at-checkout tests: a card gateway that records what it is asked to charge, products, carts and the
 * checkout's own steps.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\CheckoutCredit;
use BeltoftServices\Credit\Invoice;
use BeltoftServices\Credit\RenewalCredit;
use BeltoftServices\Tests\Usage\UsageCardGateway;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * A gateway that records what it is asked to charge and can be told to refuse the card.
 */
class CheckoutCardGateway extends UsageCardGateway {

	/** @var array<int, array{0: string, 1: int}> Order total and order id of each charge asked for. */
	public array $charges = array();

	/** @var bool Refuse the next charges with an error. */
	public bool $refuse = false;

	/** @var bool Answer as the Stripe gateway does when the bank asks for a next action: success, the order unpaid, a #response redirect the browser finishes. */
	public bool $next_action = false;

	/**
	 * Charges the order total, as the Stripe gateway does: the order, not the cart.
	 *
	 * @param int $order_id Order id.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order           = wc_get_order( $order_id );
		$this->charges[] = array( (string) $order->get_total(), (int) $order_id );

		if ( $this->refuse ) {
			wc_add_notice( 'The card was declined.', 'error' );

			return array( 'result' => 'failure', 'redirect' => '' );
		}

		if ( $this->next_action ) {
			return array( 'result' => 'success', 'redirect' => '#response=' . rawurlencode( base64_encode( wp_json_encode( array( 'order_id' => $order_id, 'status' => 'requires_action' ) ) ) ) );
		}

		$order->payment_complete();

		return array( 'result' => 'success', 'redirect' => $order->get_checkout_order_received_url() );
	}
}

/**
 * Base class.
 */
abstract class CheckoutCreditCase extends CreditTestCase {


	/** @var CheckoutCardGateway */
	protected $card;

	public function set_up(): void {
		parent::set_up();
		delete_option( Balance::OPTION_SPLIT_FROM );
		$this->vat( false );
		$this->settings( array( 'credit_checkout' => 1, 'usage_minimum' => 0 ) );
		$this->card = new CheckoutCardGateway();
		WC()->payment_gateways()->payment_gateways['fakecard'] = $this->card;
		WC()->cart->empty_cart();
		WC()->session->set( 'order_awaiting_payment', null );
		WC()->customer->set_billing_country( 'DK' );
		CheckoutCredit::register();
		CheckoutCredit::reset();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		WC()->session->set( 'order_awaiting_payment', null );
		CheckoutCredit::reset();
		remove_all_actions( 'woocommerce_cart_calculate_fees' );
		parent::tear_down();
	}

	// Helpers.

	/**
	 * A service product priced $price; $promo ticks the promotional credit box.
	 */
	protected function product( bool $promo, string $price = '10', string $driver = 'fake' ): \WC_Product {
		static $n = 0;
		++$n;
		$product = $this->service_product( $driver, array(), array( 'slug' => 'cc-' . $n ) );
		$product->set_regular_price( $price );
		$product->update_meta_data( RenewalCredit::META_PROMO, $promo ? 'yes' : 'no' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Turns a service product into a monthly subscription product (no trial), priced $price.
	 */
	protected function as_subscription( \WC_Product $product, string $price = '10' ): \WC_Product {
		wp_set_object_terms( $product->get_id(), 'subscription', 'product_type' );
		clean_post_cache( $product->get_id() );
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $product->get_id() );
		update_post_meta( $product->get_id(), '_subscription_price', $price );
		update_post_meta( $product->get_id(), '_regular_price', $price );
		update_post_meta( $product->get_id(), '_price', $price );
		update_post_meta( $product->get_id(), '_subscription_period', 'month' );
		update_post_meta( $product->get_id(), '_subscription_period_interval', '1' );
		update_post_meta( $product->get_id(), '_subscription_length', '0' );
		update_post_meta( $product->get_id(), '_subscription_sign_up_fee', '0' );
		update_post_meta( $product->get_id(), '_subscription_trial_length', '0' );
		update_post_meta( $product->get_id(), '_subscription_trial_period', 'day' );
		wc_delete_product_transients( $product->get_id() );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product_Subscription::class, $fresh );

		return $fresh;
	}

	/**
	 * A customer. A card always needs to be there to use credit, so every customer here has a saved card, unless a test removes it.
	 */
	protected function new_user(): int {
		$user = parent::new_user();
		$this->saved_card( $user );

		return $user;
	}

	/**
	 * Saves a card (the default payment method of the gateway that charges subscriptions) for a customer.
	 */
	protected function saved_card( int $user, int $years = 2 ): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 10, false ) );
		$token->set_gateway_id( 'fakecard' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + $years ) );
		$token->set_user_id( $user );
		$token->set_default( true );
		$token->save();
	}

	/**
	 * Removes the customer's saved cards.
	 */
	protected function remove_cards( int $user ): void {
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user ) as $token ) {
			$token->delete();
		}
	}

	protected function paid( int $user, int $cents ): void {
		if ( $cents < 1 ) {
			return;
		}

		$this->assertTrue( Balance::credit( $user, $cents, Balance::TOPUP, 'order_item', 'seed-' . wp_generate_uuid4() ) );
	}

	protected function promo( int $user, int $cents ): void {
		if ( $cents < 1 ) {
			return;
		}

		$this->assertTrue( Balance::credit( $user, $cents, Balance::PROMO, 'code', 'seed-' . wp_generate_uuid4() ) );
	}

	/**
	 * Signs the customer in with these products in the cart, totals done.
	 *
	 * @param \WC_Product[] $products Products.
	 */
	protected function cart( int $user, array $products ): void {
		wp_set_current_user( $user );
		CheckoutCredit::reset();
		WC()->cart->empty_cart();

		foreach ( $products as $product ) {
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id() ) );
		}

		WC()->cart->calculate_totals();
	}

	/**
	 * What the checkout form posts.
	 *
	 * @return array<string, string>
	 */
	protected function posted( string $method = 'fakecard' ): array {
		return array(
			'payment_method'     => $method,
			'billing_email'      => self::MAIL,
			'billing_first_name' => 'Test',
			'billing_last_name'  => 'Customer',
			'billing_phone'      => '+4512345678',
			'billing_address_1'  => 'Teststreet 1',
			'billing_city'       => 'Aarhus',
			'billing_postcode'   => '8000',
			'billing_country'    => 'DK',
		);
	}

	/**
	 * The checkout's own steps up to the payment: the order from the cart, then the action that runs before the decision whether a
	 * payment is needed.
	 */
	protected function place( string $method = 'fakecard' ): \WC_Order {
		$posted = $this->posted( $method );
		$id     = WC()->checkout()->create_order( $posted );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		do_action( 'woocommerce_checkout_order_processed', $id, $posted, wc_get_order( $id ) );

		return wc_get_order( $id );
	}

	/**
	 * @return array<int, \WC_Order_Item_Fee>
	 */
	protected function credit_fees( \WC_Order $order ): array {
		return array_values( array_filter( $order->get_items( 'fee' ), static fn( $f ) => 'yes' === $f->get_meta( Invoice::META_FEE ) ) );
	}

	/**
	 * Cents the order's ledger rows took, by kind.
	 *
	 * @return array<string, int>
	 */
	protected function rows_of( \WC_Order $order ): array {
		$out = array();

		foreach ( array( Balance::INVOICE, Balance::PROMO_USE ) as $kind ) {
			$row = Balance::find( $kind, Invoice::REF_TYPE, (string) $order->get_id() );

			if ( $row ) {
				$out[ $kind ] = -(int) $row['amount_cents'];
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The balance is the sum of its ledger rows.
	 */
	protected function assertLedgerBalanced( int $user ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$sum = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( SUM( amount_cents ), 0 ) FROM ' . Balance::table() . ' WHERE user_id = %d', $user ) );
		$this->assertSame( $sum, Balance::get( $user ), 'The balance equals the ledger sum.' );
	}

	/**
	 * A percent coupon on every product.
	 */
	protected function coupon( string $percent ): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'cc' . wp_generate_password( 6, false ) );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $percent );
		$coupon->save();
		$this->coupon_code = $coupon->get_code();
	}

	/** @var string */
	protected $coupon_code = '';
}
