<?php
/**
 * The credit page shows this month's usage as reserved against the balance (display only).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Reserve;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * Left = balance less the usage invoice total incl. VAT, as Credit\Invoice would take it.
 */
class ReserveTest extends CreditTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'usage_minimum' => 0 ) );
		$this->vat( true );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
	}

	/** A usage customer with $hours at 0.02 (2.00 net, 2.50 incl. VAT for 100 hours); logged in. */
	private function customer( int $hours, string $dkk_first = '' ): int {
		$user = $this->new_user();
		if ( '' !== $dkk_first ) {
			$this->top_up( $user, 1000, 'DKK' );
		}
		$this->usage_account( $user );
		wp_set_current_user( $user );
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02, $end - $i * HOUR_IN_SECONDS );
		}
		Estimate::reset();

		return $user;
	}

	private function top_up( int $user, int $cents, string $currency = '' ): void {
		$this->assertTrue( Balance::credit( $user, $cents, Balance::TOPUP, 'order_item', 'seed-' . wp_generate_uuid4(), '', $currency ) );
	}

	public function test_balance_and_usage_show_left_after_usage_incl_vat(): void {
		$user = $this->customer( 100 );
		$this->top_up( $user, 1000 );
		$html = Page::html();

		$this->assertStringContainsString( 'Used so far this month (estimate)', $html );
		$this->assertStringContainsString( "\u{20AC}2.50", $html );
		$this->assertStringContainsString( 'Left after this month', $html );
		$this->assertStringContainsString( "\u{20AC}7.50", $html );
		$this->assertStringContainsString( 'nothing is taken from your balance until the invoice is made', $html );
		$this->assertStringNotContainsString( 'charged to your card', $html );
		$this->assertSame( 1000, Balance::get( $user ), 'The balance is untouched.' );
	}

	public function test_it_is_the_same_in_the_including_display(): void {
		$user = $this->customer( 100 );
		$this->top_up( $user, 1000 );
		update_option( 'woocommerce_tax_display_shop', 'incl' );

		$this->assertSame( 750, Reserve::for_user( $user )['left'] );
		$this->assertSame( 250, Reserve::for_user( $user )['usage'] );
	}

	public function test_usage_over_the_balance_leaves_zero_and_the_rest_goes_to_the_card(): void {
		$user = $this->customer( 100 );
		$this->top_up( $user, 100 );
		$html = Page::html();
		$r    = Reserve::for_user( $user );

		$this->assertSame( 0, $r['left'] );
		$this->assertSame( 150, $r['rest'] );
		$this->assertStringContainsString( "Your usage is more than your balance. The rest, \u{20AC}1.50, is charged to your card.", $html );
	}

	public function test_no_balance_or_no_usage_looks_as_before(): void {
		$user = $this->customer( 100 );
		$this->assertNull( Reserve::for_user( $user ) );
		$this->assertStringNotContainsString( 'Used so far this month', Page::html() );

		$other = $this->new_user();
		$this->usage_account( $other );
		wp_set_current_user( $other );
		$this->top_up( $other, 500 );
		Estimate::reset();
		$this->assertNull( Reserve::for_user( $other ) );
		$this->assertStringNotContainsString( 'Used so far this month', Page::html() );
		$this->assertStringContainsString( 'Balance', Page::html() );
	}

	public function test_credit_in_another_currency_is_not_reserved(): void {
		$user = $this->customer( 100, 'DKK' );

		$this->assertSame( 1000, Balance::get_in( $user, 'DKK' ) );
		$this->assertNull( Reserve::for_user( $user ) );
		$this->assertStringNotContainsString( 'Used so far this month', Page::html() );
	}

	public function test_a_month_under_the_minimum_is_carried_and_uses_no_credit(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user = $this->customer( 100 );
		$this->top_up( $user, 100 );
		$r = Reserve::for_user( $user );

		$this->assertTrue( $r['carried'] );
		$this->assertSame( 100, $r['left'], 'A part cover with a rest under the minimum is not used.' );
		$this->assertStringContainsString( 'carried to next month', Page::html() );
	}

	public function test_credit_that_covers_it_all_is_used_even_under_the_minimum(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user = $this->customer( 100 );
		$this->top_up( $user, 1000 );
		$r = Reserve::for_user( $user );

		$this->assertFalse( $r['carried'] );
		$this->assertSame( 750, $r['left'] );
	}
}
