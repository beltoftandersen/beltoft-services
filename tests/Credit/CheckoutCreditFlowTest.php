<?php
/**
 * Account credit at checkout through WooCommerce's own process_checkout(): the order of operations, the amount the gateway is asked to
 * charge, a payment that is not needed, a subscription that still gets a payment method, and a retry.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\CheckoutCredit;
use BeltoftServices\Credit\Invoice;
use BeltoftServices\Mcp\Sandbox;

require_once __DIR__ . '/CheckoutCreditCase.php';

/**
 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: every test here runs in a process of its own.
 *
 * Sandbox::run() gives the checkout a session in memory and Sandbox::checkout() runs process_checkout() and catches the end of the
 * request (the JSON WooCommerce prints and the exit it makes).
 */
class CheckoutCreditFlowTest extends CheckoutCreditCase {

	/**
	 * Runs the checkout for the customer with the cart the callback fills; more than one checkout can be run in the callback (one session).
	 *
	 * @param callable(): mixed $fn Runs inside the sandbox.
	 * @return mixed
	 */
	protected function in_checkout( int $user, callable $fn ) {
		wp_set_current_user( $user );

		return Sandbox::run( $user, $this->posted(), $fn );
	}

	/**
	 * Submits the checkout form once.
	 *
	 * @param array<string, string> $over Posted values that differ.
	 * @return array{result: string, order_id: int, messages: string[]}
	 */
	protected function submit( array $over = array() ): array {
		$form = array_merge( array( 'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ), 'terms' => 'on', 'terms-field' => '1' ), $this->posted(), $over );

		$_POST    = $form;
		$_REQUEST = $form;

		return Sandbox::checkout();
	}

	/**
	 * Puts products in the cart of the sandbox.
	 *
	 * @param \WC_Product[] $products Products.
	 */
	protected function fill( array $products ): void {
		WC()->cart->empty_cart( false );

		foreach ( $products as $product ) {
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id() ) );
		}

		WC()->cart->calculate_totals();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_gateway_is_asked_for_the_order_total_after_credit(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$product = $this->product( false );

		$end = $this->in_checkout(
			$user,
			function () use ( $product ) {
				$this->fill( array( $product ) );
				$preview = CheckoutCredit::preview();
				$this->assertSame( 600, $preview['due'] );

				return $this->submit();
			}
		);

		$this->assertSame( 'success', $end['result'], implode( ' ', $end['messages'] ) );
		$this->assertCount( 1, $this->card->charges );
		$this->assertSame( '6.00', $this->card->charges[0][0], 'The gateway charges the order total, which is the cart total less credit.' );

		$order = wc_get_order( $end['order_id'] );
		$this->assertSame( 600, \BeltoftServices\Credit\Balance::cents( $order->get_total() ) );
		$this->assertSame( 400, Invoice::used( $order ) );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertTrue( $order->is_paid() );
		$this->assertLedgerBalanced( $user );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_credit_covering_a_plain_order_with_a_saved_card_charges_nothing(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$product = $this->product( false );

		$end = $this->in_checkout(
			$user,
			function () use ( $product ) {
				$this->fill( array( $product ) );
				$this->assertTrue( WC()->cart->needs_payment(), 'The checkout still asks for a payment method.' );

				return $this->submit();
			}
		);

		$this->assertSame( 'success', $end['result'], implode( ' ', $end['messages'] ) );
		$this->assertSame( array(), $this->card->charges, 'An order of 0 that needs no card is completed by WooCommerce: the gateway is not asked and nothing is charged.' );

		$order = wc_get_order( $end['order_id'] );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertTrue( $order->is_paid(), 'Paid by credit: ' . $order->get_status() );
		$this->assertSame( 1000, Invoice::used( $order ) );
		$this->assertSame( 4000, Balance::get( $user ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_credit_covering_a_subscription_still_saves_a_payment_method(): void {
		$user = $this->new_user();
		$this->remove_cards( $user );
		$this->paid( $user, 5000 );
		$product = $this->as_subscription( $this->product( false ) );

		$end = $this->in_checkout(
			$user,
			function () use ( $product ) {
				$this->fill( array( $product ) );
				$this->assertTrue( WC()->cart->needs_payment(), 'The customer still chooses a payment method.' );

				return $this->submit();
			}
		);

		$this->assertSame( 'success', $end['result'], implode( ' ', $end['messages'] ) );
		$this->assertCount( 1, $this->card->charges, 'The gateway is asked, with an order of 0 (it saves the card).' );
		$this->assertSame( '0.00', $this->card->charges[0][0] );

		$order = wc_get_order( $end['order_id'] );
		$this->assertSame( 1000, Invoice::used( $order ) );
		$this->assertTrue( $order->is_paid() );

		$subs = wcs_get_subscriptions_for_order( $order );
		$this->assertCount( 1, $subs );
		$sub = array_values( $subs )[0];
		$this->assertSame( 'active', $sub->get_status() );
		$this->assertSame( 'fakecard', $sub->get_payment_method(), 'The payment method is on the subscription.' );
		$this->assertGreaterThan( time(), $sub->get_time( 'next_payment' ), 'The next payment is dated.' );
		$this->assertEqualsWithDelta( 10.0, (float) $sub->get_total(), 0.001, 'The subscription renews at its full price.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_promotional_credit_on_an_unmarked_product_leaves_the_card_to_pay_in_full(): void {
		$user = $this->new_user();
		$this->promo( $user, 5000 );
		$product = $this->product( false );

		$end = $this->in_checkout(
			$user,
			function () use ( $product ) {
				$this->fill( array( $product ) );

				return $this->submit();
			}
		);

		$this->assertSame( 'success', $end['result'], implode( ' ', $end['messages'] ) );
		$this->assertSame( '10.00', $this->card->charges[0][0] );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_declined_card_then_a_new_try_of_the_same_cart_keeps_one_debit_and_asks_the_same_amount(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$product = $this->product( false );
		$ids     = array();

		$this->in_checkout(
			$user,
			function () use ( $product, $user, &$ids ) {
				$this->fill( array( $product ) );

				// The card is declined: the order stays pending and holds the credit.
				$this->card->refuse = true;
				$one                = $this->submit();
				$this->assertSame( 'failure', $one['result'] );
				$ids[]              = $one['order_id'];
				$this->assertSame( 0, Balance::get( $user ), 'The credit is held by the unpaid order.' );
				$this->assertSame( '6.00', $this->card->charges[0][0] );

				// The forecast on the page is what that order holds.
				$preview = CheckoutCredit::preview();
				$this->assertSame( 400, $preview['credit'] );
				$this->assertSame( 600, $preview['due'] );

				// The customer tries again with a card that works.
				$this->card->refuse = false;
				$two                = $this->submit();
				$this->assertSame( 'success', $two['result'], implode( ' ', $two['messages'] ) );
				$ids[]              = $two['order_id'];
			}
		);

		$this->assertSame( $ids[0], $ids[1], 'The order is resumed, not replaced.' );
		$this->assertSame( array( '6.00', '6.00' ), array_column( $this->card->charges, 0 ), 'The gateway is asked the same amount twice.' );

		$order = wc_get_order( $ids[1] );
		$this->assertCount( 1, $this->credit_fees( $order ) );
		$this->assertSame( array( Balance::INVOICE => 400 ), $this->rows_of( $order ), 'One debit.' );
		$this->assertSame( 600, Balance::cents( $order->get_total() ) );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertFalse( Balance::exists( Balance::ADJUST, Invoice::REF_RETURN, (string) $order->get_id() ) );
		$this->assertLedgerBalanced( $user );
	}

	/**
	 * The Stripe gateway answers success with a #response redirect and an unpaid order when the bank asks for a next action; the
	 * browser finishes it with the intent's client secret and on the return URL, not by submitting the form again. A customer who submits
	 * the form once more meanwhile resumes the same order, and the payment that completes later is the order that kept its credit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_next_action_is_finished_on_the_order_that_kept_its_credit(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$product = $this->product( false );
		$ids     = array();

		$this->in_checkout(
			$user,
			function () use ( $product, &$ids ) {
				$this->fill( array( $product ) );
				$this->card->next_action = true;

				$one   = $this->submit();
				$ids[] = $one['order_id'];
				$this->assertSame( 'success', $one['result'], implode( ' ', $one['messages'] ) );
				$this->assertSame( 'pending', wc_get_order( $one['order_id'] )->get_status(), 'Waiting for the browser to finish the payment.' );

				// A second submit while the bank's window is open.
				$two   = $this->submit();
				$ids[] = $two['order_id'];
			}
		);

		$this->assertSame( $ids[0], $ids[1], 'The same order: it is not released in the middle of its own payment.' );
		$order = wc_get_order( $ids[0] );
		$this->assertSame( array( '6.00', '6.00' ), array_column( $this->card->charges, 0 ) );
		$this->assertSame( 400, Invoice::used( $order ) );

		// The browser finishes: the return URL's handler completes the order named in the intent.
		$order->payment_complete( 'pi_test' );

		$order = wc_get_order( $ids[0] );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 400, Invoice::used( $order ) );
		$this->assertSame( array( Balance::INVOICE => 400 ), $this->rows_of( $order ) );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertSame( '', (string) $order->get_meta( CheckoutCredit::META_GUARDED ), 'Not held: it kept its credit.' );
		$this->assertLedgerBalanced( $user );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_that_fails_later_is_whole_again_and_not_resumed(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$product = $this->product( false );
		$ids     = array();

		$this->in_checkout(
			$user,
			function () use ( $product, $user, &$ids ) {
				$this->fill( array( $product ) );
				$this->card->refuse = true;
				$one                = $this->submit();
				$ids[]              = $one['order_id'];
				$this->assertSame( 0, Balance::get( $user ) );

				// What the payment webhook does when the charge fails.
				wc_get_order( $one['order_id'] )->update_status( 'failed', 'Charge failed.' );
				$this->assertSame( 400, Balance::get( $user ), 'Given back.' );

				$this->card->refuse = false;
				$two                = $this->submit();
				$this->assertSame( 'success', $two['result'], implode( ' ', $two['messages'] ) );
				$ids[]              = $two['order_id'];
			}
		);

		$first = wc_get_order( $ids[0] );
		$this->assertNotSame( $ids[0], $ids[1] );
		$this->assertEqualsWithDelta( 10.0, (float) $first->get_total(), 0.001, 'The failed order can be paid in full.' );
		$this->assertSame( 400, Invoice::used( wc_get_order( $ids[1] ) ) );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertLedgerBalanced( $user );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_first_order_is_released_and_cannot_be_paid(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$product = $this->product( false );
		$ids     = array();

		$this->in_checkout(
			$user,
			function () use ( $product, &$ids ) {
				$this->fill( array( $product ) );
				$this->card->refuse = true;
				$one                = $this->submit();
				$ids[]              = $one['order_id'];
				wc_get_order( $one['order_id'] )->update_status( 'failed', 'Charge failed.' );
			}
		);

		$order = wc_get_order( $ids[0] );
		$this->assertSame( 400, Balance::get( $user ), 'The credit went back.' );
		$this->assertNotSame( '', (string) $order->get_meta( CheckoutCredit::META_RELEASED ) );
		$this->assertSame( '400', (string) $order->get_meta( CheckoutCredit::META_RELEASED_CENTS ) );
		$this->assertFalse( $order->needs_payment(), 'The Pay link and the order-pay page refuse it.' );
		$this->assertSame( array(), apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), $order ) );
	}
}
