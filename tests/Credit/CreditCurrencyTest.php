<?php
/**
 * Account credit by currency: one currency per customer, never summed, never applied across currencies.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Invoice;
use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Topup;
use BeltoftServices\Currency;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Usage\Minimum;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * Slice 5 (W7, W13).
 */
class CreditCurrencyTest extends CreditTestCase {

	use FakeCurrency;

	/** @var string[] Subjects of the admin mails sent. */
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		$this->fake_currency_down();
		WC()->cart->empty_cart();
		parent::tear_down();
	}

	/**
	 * Records and stops a mail.
	 *
	 * @param mixed $pre  Short circuit.
	 * @param array $atts Mail.
	 */
	public function catch_mail( $pre, $atts ) {
		$this->sent[] = (string) $atts['subject'];

		return true;
	}

	private function mails( string $text ): int {
		return count( array_filter( $this->sent, static fn( $s ) => false !== strpos( $s, $text ) ) );
	}

	/** A DKK usage invoice of an amount, as an order with one fee line. */
	private function invoice( int $user, string $currency, float $total ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( $currency );
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Usage' );
		$fee->set_total( (string) $total );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
		$order->set_total( (string) $total );
		$order->save();

		return $order;
	}

	public function test_a_balance_has_one_currency_and_a_write_in_another_is_refused_also_at_zero(): void {
		$u = $this->new_user();

		$this->assertSame( '', Balance::currency( $u ), 'No rows: no currency yet.' );
		$this->assertTrue( Balance::credit( $u, 500, Balance::TOPUP, 'order_item', 'a1' ) );
		$this->assertSame( 'EUR', Balance::currency( $u ) );
		$this->assertSame( 500, Balance::get_in( $u, 'EUR' ) );
		$this->assertSame( 500, Balance::get_in( $u, '' ), 'An empty code is the shop currency.' );
		$this->assertSame( 0, Balance::get_in( $u, 'DKK' ) );

		$this->assertFalse( Balance::credit( $u, 900, Balance::TOPUP, 'order_item', 'a2', '', 'DKK' ), 'Another currency is refused.' );
		$this->assertSame( 500, Balance::get( $u ) );
		$this->assertSame( 1, $this->mails( 'two currencies' ) );

		$this->assertTrue( Balance::debit( $u, 500, Balance::REFUND, 'order_item', 'a3' ) );
		$this->assertSame( 0, Balance::get( $u ) );
		$this->assertFalse( Balance::credit( $u, 900, Balance::TOPUP, 'order_item', 'a4', '', 'DKK' ), 'Also at a zero balance (O5).' );
		$this->assertSame( 'EUR', Balance::currency( $u ) );
	}

	public function test_a_dkk_balance_is_dkk_and_rows_carry_the_currency_with_a_consistent_running_balance(): void {
		$u = $this->new_user();

		$this->assertTrue( Balance::credit( $u, 2500, Balance::TOPUP, 'order_item', 'd1', '', 'DKK' ) );
		$this->assertTrue( Balance::credit( $u, 500, Balance::PROMO, 'code', 'd2', '', 'dkk' ) );
		$this->assertFalse( Balance::credit( $u, 500, Balance::PROMO, 'code', 'd3', '', 'EUR' ) );
		$this->assertFalse( Balance::credit( $u, 500, Balance::PROMO, 'code', 'd4', '', 'EURO' ), 'A value that is not a code writes nothing.' );

		$rows = array_reverse( Balance::history( $u ) );
		$this->assertSame( array( 'DKK', 'DKK' ), array_column( $rows, 'currency' ) );
		$this->assertSame( array( '2500', '3000' ), array_column( $rows, 'balance_after_cents' ) );
		$this->assertSame( 3000, Balance::get_in( $u, 'DKK' ) );
		$this->assertSame( 0, Balance::get_in( $u, 'EUR' ) );
	}

	public function test_the_same_reference_is_written_once_in_any_currency(): void {
		$u = $this->new_user();

		$this->assertTrue( Balance::credit( $u, 700, Balance::TOPUP, 'order_item', 'once', '', 'DKK' ) );
		$this->assertFalse( Balance::credit( $u, 700, Balance::TOPUP, 'order_item', 'once', '', 'DKK' ) );
		$this->assertCount( 1, Balance::history( $u ) );
		$this->assertSame( 700, Balance::get( $u ) );
	}

	public function test_a_euro_balance_never_pays_a_dkk_invoice_and_a_dkk_balance_does(): void {
		$u = $this->new_user();
		Balance::credit( $u, 5000, Balance::TOPUP, 'order_item', 'e1' );
		$order = $this->invoice( $u, 'DKK', 20.0 );

		$this->assertSame( 0, Invoice::apply( $order, $u ) );
		$this->assertEqualsWithDelta( 20.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 5000, Balance::get( $u ), 'The euro balance is untouched.' );
		$this->assertSame( 1, $this->mails( 'another currency' ) );

		$u2 = $this->new_user();
		Balance::credit( $u2, 1500, Balance::TOPUP, 'order_item', 'k1', '', 'DKK' );
		$order2 = $this->invoice( $u2, 'DKK', 20.0 );

		$this->assertSame( 1500, Invoice::apply( $order2, $u2 ) );
		$this->assertSame( 0, Balance::get( $u2 ) );
		$this->assertSame( 'DKK', Balance::history( $u2 )[0]['currency'] );

		Invoice::give_back( $order2->get_id() );
		$this->assertSame( 1500, Balance::get_in( $u2, 'DKK' ), 'The credit comes back in the order currency.' );
	}

	public function test_the_minimum_does_not_count_a_balance_in_another_currency(): void {
		$u = $this->new_user();
		$this->fake_amounts( array( 'DKK' => array( 'usage_minimum' => 40 ) ) );
		Balance::credit( $u, 3000, Balance::TOPUP, 'order_item', 'm1' );

		$this->assertTrue( Minimum::carries( 3000, $u, 'DKK' ), 'A euro balance covers nothing of a DKK invoice: 30 DKK is under the minimum.' );

		$u2 = $this->new_user();
		Balance::credit( $u2, 3000, Balance::TOPUP, 'order_item', 'm2', '', 'DKK' );
		$this->assertFalse( Minimum::carries( 3000, $u2, 'DKK' ), 'A DKK balance covers it: nothing is left for the card.' );
	}

	public function test_a_dkk_top_up_order_credits_dkk_what_was_paid_and_a_euro_customer_is_not_credited(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 2500 );
		$order->set_currency( 'DKK' );
		$order->save();

		$order->payment_complete();

		$this->assertSame( 2500, Balance::get_in( $u, 'DKK' ) );
		$this->assertSame( 'DKK', Balance::currency( $u ) );

		$u2 = $this->new_user();
		Balance::credit( $u2, 100, Balance::TOPUP, 'order_item', 'x1' );
		$other = $this->topup_order( $u2, 2500 );
		$other->set_currency( 'DKK' );
		$other->save();
		$other->payment_complete();

		$this->assertSame( 100, Balance::get( $u2 ), 'Not credited in another currency.' );
		$this->assertSame( 1, $this->mails( 'two currencies' ) );
		$notes = implode( ' ', array_column( wc_get_order_notes( array( 'order_id' => $other->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'not added', $notes );
	}

	public function test_a_refund_a_chargeback_and_a_reinstate_of_a_dkk_top_up_stay_in_dkk(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 4000 );
		$order->set_currency( 'DKK' );
		$order->save();
		$order->payment_complete();

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'on-hold' ); // A chargeback.
		$this->assertSame( 0, Balance::get( $u ) );

		wc_get_order( $order->get_id() )->update_status( 'completed' ); // Paid again: reinstated.
		$this->assertSame( 4000, Balance::get( $u ) );
		$this->assertSame( array( 'DKK' ), array_values( array_unique( array_column( Balance::history( $u ), 'currency' ) ) ) );

		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 10.0, 'reason' => 'test' ) );
		$this->assertSame( 3000, Balance::get_in( $u, 'DKK' ) );
	}

	public function test_a_top_up_validated_in_euro_is_removed_when_the_cart_is_priced_in_dkk(): void {
		$u = $this->new_user();
		wp_set_current_user( $u );
		$this->fake_currency_up( 'EUR' );
		$this->fake_amounts( array( 'DKK' => array( 'credit_min' => 10, 'credit_max' => 5000 ) ) );

		$this->assertTrue( Topup::add_to_cart( 10000 ) );
		WC()->cart->calculate_totals();
		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertSame( 'EUR', array_values( WC()->cart->get_cart() )[0][ Topup::CURRENCY_KEY ] );

		$this->fake_set_currency( 'DKK' );
		WC()->cart->calculate_totals();

		$this->assertCount( 0, WC()->cart->get_cart(), '100.00 validated in EUR is not charged as 100.00 DKK.' );
		$notices = wc_get_notices( 'notice' );
		$this->assertCount( 1, $notices );
		wc_clear_notices();
	}

	public function test_a_customer_with_dkk_credit_cannot_put_a_euro_top_up_in_the_cart(): void {
		$u = $this->new_user();
		wp_set_current_user( $u );
		Balance::credit( $u, 100, Balance::TOPUP, 'order_item', 'c1', '', 'DKK' );

		$this->assertFalse( Topup::add_to_cart( 2500 ) );
	}

	public function test_the_credit_answers_the_user_currency_filter_after_the_bridge_and_before_the_usage_account(): void {
		$u = $this->new_user();
		$this->assertSame( 'EUR', Currency::of_user( $u ) );

		Balance::credit( $u, 100, Balance::TOPUP, 'order_item', 'f1', '', 'DKK' );
		$this->assertSame( 'DKK', Currency::of_user( $u ) );

		$this->fake_user_currencies( array( $u => 'SEK' ) );
		$this->assertSame( 'SEK', Currency::of_user( $u ), 'An earlier listener wins.' );
		$this->assertSame( 10, has_filter( 'beltoft_services_user_currency', array( Balance::class, 'user_currency' ) ) );
	}

	public function test_the_credit_page_is_in_the_customers_credit_currency_with_no_top_up_on_a_page_in_another(): void {
		$u = $this->new_user();
		wp_set_current_user( $u );
		Balance::credit( $u, 2500, Balance::TOPUP, 'order_item', 'p1', '', 'DKK' );

		$data = Page::data();

		$this->assertSame( 'DKK', $data['currency'] );
		$this->assertSame( 2500, $data['balance'] );
		$this->assertFalse( $data['available'], 'The page is in EUR: a DKK top-up cannot be started here.' );
		$this->assertSame( array(), $data['amounts'] );
	}

	public function test_an_mcp_answer_names_the_credit_currency(): void {
		$u = $this->new_user();
		Balance::credit( $u, 2500, Balance::TOPUP, 'order_item', 'q1', '', 'DKK' );

		$out = \BeltoftServices\Mcp\Pricing::account( $u );

		$this->assertSame( 'DKK', $out['credit']['currency'] );
		$this->assertStringContainsString( 'kr.', html_entity_decode( $out['credit']['text'] ) );
	}

	public function test_a_card_only_account_gets_the_customers_currency_or_the_pages_and_fires_the_action_once(): void {
		$card = new \ReflectionMethod( \BeltoftServices\Card\Card::class, 'build_account' );
		$card->setAccessible( true );
		$fired = array();
		$f     = static function ( $user, $sub ) use ( &$fired ) {
			$fired[] = array( $user, $sub->get_currency() );
		};
		add_action( 'beltoft_services_usage_account_created', $f, 10, 2 );

		$a = $this->new_user();
		$card->invoke( null, $a, static function ( $sub ) {}, 'test' );
		$this->assertSame( array( array( $a, 'EUR' ) ), $fired, 'Nobody fixed a currency: the page currency.' );

		$fired = array();
		$b     = $this->new_user();
		$this->fake_user_currencies( array( $b => 'DKK' ) );
		$card->invoke( null, $b, static function ( $sub ) {}, 'test' );
		$this->assertSame( array( array( $b, 'DKK' ) ), $fired, 'The customer\'s own currency through the filter chain.' );

		$fired = array();
		$c     = $this->new_user();
		$this->fake_currency_up( 'EUR' );
		$this->fake_set_currency( 'SEK' );
		$card->invoke( null, $c, static function ( $sub ) {}, 'test' );
		$this->assertSame( array( array( $c, 'SEK' ) ), $fired, 'No fixed currency: the page the card was added on.' );

		remove_action( 'beltoft_services_usage_account_created', $f, 10 );
	}
}
