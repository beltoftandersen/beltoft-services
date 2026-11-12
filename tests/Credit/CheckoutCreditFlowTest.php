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
	public function test_credit_covering_the_order_asks_for_no_payment(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$product = $this->product( false );

		$end = $this->in_checkout(
			$user,
			function () use ( $product ) {
				$this->fill( array( $product ) );
				$this->assertFalse( WC()->cart->needs_payment(), 'The checkout asks for no payment method.' );

				return $this->submit( array( 'payment_method' => '' ) );
			}
		);

		$this->assertSame( 'success', $end['result'], implode( ' ', $end['messages'] ) );
		$this->assertSame( array(), $this->card->charges, 'No gateway is asked.' );

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
	public function test_a_declined_card_then_a_new_try_takes_the_credit_once(): void {
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

				// The forecast on the page still shows the whole credit: it will be given back and taken again.
				$preview = CheckoutCredit::preview();
				$this->assertSame( 400, $preview['credit'] );

				// The customer tries again with a card that works.
				$this->card->refuse = false;
				$two                = $this->submit();
				$this->assertSame( 'success', $two['result'], implode( ' ', $two['messages'] ) );
				$ids[]              = $two['order_id'];
			}
		);

		$this->assertNotSame( $ids[0], $ids[1], 'The first order is not resumed.' );
		$this->assertSame( '6.00', $this->card->charges[1][0], 'The second charge is the same amount, with the credit taken again.' );

		$first  = wc_get_order( $ids[0] );
		$second = wc_get_order( $ids[1] );
		$this->assertSame( array(), $this->credit_fees( $first ) );
		$this->assertTrue( Balance::exists( Balance::ADJUST, Invoice::REF_RETURN, (string) $first->get_id() ), 'Given back once.' );
		$this->assertSame( 400, Invoice::used( $second ) );
		$this->assertTrue( $second->is_paid() );
		$this->assertSame( 0, Balance::get( $user ), 'The credit is spent once, on the order that was paid.' );
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
}
