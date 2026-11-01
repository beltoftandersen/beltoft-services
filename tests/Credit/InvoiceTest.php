<?php
/**
 * Account credit paid onto Pay as you go usage invoices.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Invoice;
use BeltoftServices\Credit\Page;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Threshold;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * The fee line, the debit, a total of 0, and the credit coming back.
 */
class InvoiceTest extends CreditTestCase {

	/**
	 * Gateway charges.
	 *
	 * @var array<int, array{0: mixed, 1: \WC_Order}>
	 */
	private $charges = array();

	public function set_up(): void {
		parent::set_up();
		$this->charges = array();
		$this->vat( false );
		add_action(
			'woocommerce_scheduled_subscription_payment_fakecard',
			function ( $amount, $order ) {
				$this->charges[] = array( $amount, $order );
			},
			10,
			2
		);
	}

	private function hours( int $resource, int $user, int $hours, float $rate = 0.02, string $item = 'server' ): void {
		// Early in a month the rows would reach into the month before and split into two lines.
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, $item, 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS );
		}
	}

	private function top_up( int $user, int $cents ): void {
		$this->assertTrue( Balance::credit( $user, $cents, Balance::TOPUP, 'order_item', 'seed-' . wp_generate_uuid4() ) );
	}

	public function test_credit_is_a_payment_the_vat_on_the_usage_stays(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.00 net, 0.50 VAT, 2.50 due.
		$this->top_up( $user, 1000 );

		$order = wcs_create_renewal_order( $sub );

		$this->assertEqualsWithDelta( 0.5, (float) $order->get_total_tax(), 0.001, 'VAT on the usage is unchanged.' );
		$credit = array_values( array_filter( $order->get_items( 'fee' ), static fn( $f ) => 'Paid from account credit' === $f->get_name() ) );
		$this->assertCount( 1, $credit );
		$this->assertSame( 'none', $credit[0]->get_tax_status() );
		$this->assertEqualsWithDelta( -2.5, (float) $credit[0]->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.0, (float) $credit[0]->get_total_tax(), 0.001 );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.5, (float) $order->get_total_tax(), 0.001, 'Tax lines stay intact.' );
		$this->assertCount( 1, $order->get_taxes() );

		$this->assertSame( 750, Balance::get( $user ) );
		$row = Balance::history( $user )[0];
		$this->assertSame( 'invoice', $row['kind'] );
		$this->assertSame( '-250', $row['amount_cents'] );
		$this->assertSame( (string) $order->get_id(), $row['ref_id'] );
		$this->assertSame( 'Used on invoice #' . $order->get_order_number(), $row['note'] );
	}

	public function test_partial_credit_leaves_the_rest_to_the_gateway(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.50 due.
		$this->top_up( $user, 100 );

		$order = wcs_create_renewal_order( $sub );

		$this->assertEqualsWithDelta( 1.5, (float) $order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.5, (float) $order->get_total_tax(), 0.001 );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertFalse( Invoice::settle( $order ), 'Not paid in full.' );
	}

	public function test_no_balance_no_fee_and_off_no_fee(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );

		$order = wcs_create_renewal_order( $sub );
		$this->assertCount( 1, $order->get_items( 'fee' ) );
		$this->assertSame( array(), Balance::history( $user ) );

		$user2 = $this->new_user();
		$sub2  = $this->usage_account( $user2 );
		$this->hours( 502, $user2, 100 );
		$this->top_up( $user2, 1000 );
		$this->settings( array( 'credit_enabled' => 0 ) );
		$order2 = wcs_create_renewal_order( $sub2 );
		$this->assertCount( 1, $order2->get_items( 'fee' ) );
		$this->assertSame( 1000, Balance::get( $user2 ) );
	}

	public function test_apply_is_idempotent(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );
		$this->top_up( $user, 1000 );

		$order = wcs_create_renewal_order( $sub );
		$this->assertSame( 0, Invoice::apply( $order, $user ) );
		$this->assertSame( 0, Invoice::apply( wc_get_order( $order->get_id() ), $user ) );
		$this->assertSame( 800, Balance::get( $user ) );
		$this->assertCount( 2, wc_get_order( $order->get_id() )->get_items( 'fee' ) );
	}

	public function test_a_total_of_zero_is_completed_without_a_gateway_charge(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );
		$this->top_up( $user, 1000 );

		do_action( 'woocommerce_scheduled_subscription_payment', $sub->get_id() );

		$order = wcs_get_subscription( $sub->get_id() )->get_last_order( 'all', 'renewal' );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( array(), $this->charges, 'No gateway charge of 0.' );
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ), 'Paid: ' . $order->get_status() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 800, Balance::get( $user ) );
	}

	public function test_a_partial_invoice_is_charged_for_the_rest(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.00.
		$this->top_up( $user, 50 );

		do_action( 'woocommerce_scheduled_subscription_payment', $sub->get_id() );

		$this->assertCount( 1, $this->charges );
		$this->assertEqualsWithDelta( 1.5, (float) $this->charges[0][0], 0.001 );
		$this->assertSame( 0, Balance::get( $user ) );
	}

	public function test_the_early_bill_takes_credit_too(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 3000, 0.02 );
		$this->top_up( $user, 100000 );

		$this->assertTrue( Threshold::check( $sub ) );
		$this->assertSame( array(), $this->charges );
		$order = $sub->get_last_order( 'all', 'renewal' );
		$this->assertTrue( $order->is_paid(), $order->get_status() );
		$this->assertLessThan( 100000, Balance::get( $user ) );
	}

	public function test_credit_comes_back_once_when_the_invoice_is_cancelled_or_refunded(): void {
		foreach ( array( 'cancelled' => 601, 'refunded' => 602 ) as $status => $resource ) {
			$user = $this->new_user();
			$sub  = $this->usage_account( $user );
			$this->hours( $resource, $user, 100, 0.02 );
			$this->top_up( $user, 1000 );
			$order = wcs_create_renewal_order( $sub );
			$this->assertSame( 800, Balance::get( $user ) );

			$order->update_status( $status );
			$this->assertSame( 1000, Balance::get( $user ), $status );
			do_action( 'woocommerce_order_status_' . $status, $order->get_id() );
			wc_get_order( $order->get_id() )->update_status( 'cancelled' === $status ? 'pending' : 'completed' );
			wc_get_order( $order->get_id() )->update_status( $status );
			$this->assertSame( 1000, Balance::get( $user ), 'Once only: ' . $status );
		}
	}

	public function test_a_failed_invoice_gives_credit_back_and_is_payable_in_full(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100, 0.02 );
		$this->top_up( $user, 100 );
		$order = wcs_create_renewal_order( $sub );
		$this->assertEqualsWithDelta( 1.0, (float) $order->get_total(), 0.001 );

		$order->update_status( 'failed' );

		$this->assertSame( 100, Balance::get( $user ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertEqualsWithDelta( 2.0, (float) $order->get_total(), 0.001 );
		$this->assertCount( 1, $order->get_items( 'fee' ) );
	}

	public function test_the_credit_page_and_the_usage_box(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		wp_set_current_user( $user );
		$this->top_up( $user, 500 );
		$this->hours( 501, $user, 100 );
		// wcs_create_renewal_order() commits the test's transaction (WCS_SQL_Transaction), so it comes before anything
		// that must not outlive the test: a server and its activation would stay behind and break tests that expect none.
		$order = wcs_create_renewal_order( $sub );
		$this->top_up( $user, 1000 );

		$v = $this->usage_product();
		$r = $this->usage_vm( $v['u-None'], $user, 5, $sub->get_id() );

		$html = Page::html();
		$this->assertStringContainsString( 'Used on invoice #' . $order->get_order_number(), $html );
		$this->assertStringContainsString( 'Prepayment for Pay as you go usage. No VAT is charged on a top-up; VAT is charged on your usage invoices.', $html );
		$this->assertStringNotContainsString( 'VAT included', $html );
		$this->assertStringNotContainsString( 'incl. VAT', $html );
		$this->assertStringNotContainsString( 'excl. VAT', $html );

		// More usage on the server, so the box has something to show, and credit left.
		$this->hours( $r->id(), $user, 50, 0.01, 'backups' );
		\BeltoftServices\Usage\Estimate::reset();
		\BeltoftServices\Account\UsageView::reset();
		$box = \BeltoftServices\Account\UsageView::html();
		$this->assertStringContainsString( 'Account credit: ', $box );
		$this->assertStringContainsString( 'used first on your next usage invoice.', $box );
	}

	public function test_an_admin_recalculation_keeps_the_credit_and_the_vat(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.50 due.
		$this->top_up( $user, 100 );

		$order = wcs_create_renewal_order( $sub );
		$this->assertEqualsWithDelta( 1.5, (float) $order->get_total(), 0.001 );

		$order = wc_get_order( $order->get_id() );
		$order->calculate_totals( true );

		$this->assertEqualsWithDelta( 1.5, (float) $order->get_total(), 0.001, 'Credit kept after Recalculate.' );
		$this->assertEqualsWithDelta( 0.5, (float) $order->get_total_tax(), 0.001, 'VAT unchanged.' );
		$credit = array_values( array_filter( $order->get_items( 'fee' ), static fn( $f ) => 'Paid from account credit' === $f->get_name() ) );
		$this->assertEqualsWithDelta( -1.0, (float) $credit[0]->get_total(), 0.001 );

		// Credit that covered the whole invoice, VAT part included.
		$user2 = $this->new_user();
		$sub2  = $this->usage_account( $user2 );
		$this->hours( 502, $user2, 100 );
		$this->top_up( $user2, 1000 );
		$full = wcs_create_renewal_order( $sub2 );
		$full = wc_get_order( $full->get_id() );
		$full->calculate_totals( true );
		$this->assertEqualsWithDelta( 0.0, (float) $full->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.5, (float) $full->get_total_tax(), 0.001 );
	}
}
