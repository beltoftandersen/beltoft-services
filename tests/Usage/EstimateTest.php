<?php
/**
 * The usage estimate in My Account: Billing, the Overview box and the server and database pages.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\Overview;
use BeltoftServices\Account\UsageView;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\Panels;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Estimate from the ledger, and where it is shown.
 */
class EstimateTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
	}

	public function tear_down(): void {
		$this->on( '' );
		UsageView::reset();
		Overview::reset();
		parent::tear_down();
	}

	/** Pretends the customer is on this My Account endpoint. */
	private function on( string $endpoint ): void {
		global $wp;
		foreach ( array_keys( WC()->query->get_query_vars() ) as $key ) {
			unset( $wp->query_vars[ $key ] );
		}
		if ( '' !== $endpoint ) {
			$wp->query_vars[ $endpoint ] = '';
		}
	}

	private function hours( int $resource, int $user, int $hours, float $rate = 0.02260274, string $item = 'server' ): void {
		$end = Ledger::hour( time() ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, $item, 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS );
		}
	}

	/** A customer with a usage account, one usage server and $hours of usage; logged in. */
	private function customer( int $hours = 100 ): array {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 12 * DAY_IN_SECONDS ) ) );
		$r = $this->usage_vm( $v['u-None'], $user, 5, $sub->get_id() );
		$this->hours( $r->id(), $user, $hours );
		Overview::reset();
		UsageView::reset();
		wp_set_current_user( $user );

		return array( $user, $r, $sub );
	}

	public function test_the_estimate_is_hours_times_rate_excl_vat(): void {
		list( $user, $r ) = $this->customer( 100 );
		$estimate         = Estimate::for_user( $user );

		$this->assertSame( 2.26, $estimate['total'] );
		$this->assertSame( 2.26, $estimate['resources'][ $r->id() ]['total'] );
		$this->assertSame( 100, $estimate['resources'][ $r->id() ]['lines'][0]['hours'] );
		$this->assertFalse( $estimate['resources'][ $r->id() ]['lines'][0]['capped'] );
	}

	public function test_the_estimate_is_capped_like_the_invoice(): void {
		list( $user ) = $this->customer( 1 );
		// A full 31 day month: 744 hours at 0.02 is 14.88, over the cap of 0.02 x 730.
		$start = (int) strtotime( '2026-01-01 00:00:00 UTC' );
		for ( $i = 0; $i < 744; $i++ ) {
			Ledger::record( 9001, $user, 'backups', 1.0, 'hour', 0.02, $start + $i * HOUR_IN_SECONDS );
		}
		Estimate::reset();
		$estimate = Estimate::for_user( $user );
		$line     = $estimate['resources'][9001]['lines'];

				$this->assertSame( 14.6, $line[0]['charge'] );
		$this->assertTrue( $line[0]['capped'] );
	}

	public function test_billed_rows_are_not_in_the_estimate(): void {
		list( $user, $r ) = $this->customer( 10 );
		Ledger::claim( array_column( Ledger::unbilled( $user, time() ), 'id' ), 777 );
		Estimate::reset();

		$this->assertSame( 0.0, Estimate::for_user( $user )['total'] );
		$this->assertSame( '', UsageView::html() );
		$this->assertSame( "Cost so far this month: \u{20AC}0.00", Estimate::cost_line( $r->id() ) );
	}

	public function test_it_is_built_once_per_request(): void {
		list( $user ) = $this->customer( 5 );
		Estimate::for_user( $user );
		$this->hours( 4242, $user, 3 );
		$this->assertArrayNotHasKey( 4242, Estimate::for_user( $user )['resources'], 'The second call reads the memo, not the ledger.' );
	}

	public function test_a_customer_without_a_usage_account_has_no_estimate_anywhere(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$r = $this->ready_vm();
		$this->hours( $r->id(), $user, 10 );

		$this->assertNull( Estimate::for_user( $user ) );
		$this->assertSame( '', Estimate::so_far( $user ) );
		$this->assertSame( '', Estimate::cost_line( $r->id() ) );
		$this->assertSame( '', UsageView::html() );
	}

	public function test_billing_shows_usage_this_month_with_the_next_invoice_date(): void {
		list( , $r ) = $this->customer( 100 );
		$html        = UsageView::html();

		$this->assertStringContainsString( 'Usage this month', $html );
		$this->assertStringContainsString( 'excl. VAT', $html );
		$this->assertStringContainsString( "\u{20AC}2.26", $html );
		$this->assertStringContainsString( 'server, 100 hours', $html );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), time() + 12 * DAY_IN_SECONDS ), $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertNotSame( '', Estimate::cost_line( $r->id() ) );
	}

	public function test_billing_prints_the_section_on_the_subscriptions_page_only_and_once(): void {
		$this->customer( 20 );

		$this->on( 'orders' );
		ob_start();
		UsageView::in_content();
		$this->assertSame( '', ob_get_clean() );

		$this->on( 'subscriptions' );
		ob_start();
		UsageView::in_content();
		UsageView::in_content();
		$out = ob_get_clean();
		$this->assertSame( 1, substr_count( $out, 'Usage this month' ) );
	}

	public function test_the_overview_billing_box_shows_usage_so_far(): void {
		$this->customer( 100 );
		$html = Overview::section( 'next_payment' );

		$this->assertStringContainsString( "Usage so far: \u{20AC}2.26 (estimate)", $html );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), time() + 12 * DAY_IN_SECONDS ), $html );
	}

	public function test_the_overview_billing_box_is_unchanged_for_a_monthly_customer(): void {
		$user = $this->new_user();
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$sub->set_total( '15.00' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
		$sub->save();
		Overview::reset();
		wp_set_current_user( $user );
		$html = Overview::section( 'next_payment' );

		$this->assertStringContainsString( 'Next payment', $html );
		$this->assertStringNotContainsString( 'Usage so far', $html );
	}

	public function test_a_server_page_shows_the_cost_for_pay_as_you_go_only(): void {
		list( , $r ) = $this->customer( 100 );

		ob_start();
		Panels::account( $r );
		$this->assertStringContainsString( "Cost so far this month: \u{20AC}2.26", ob_get_clean() );

		$monthly = $this->ready_vm();
		ob_start();
		Panels::account( $monthly );
		$this->assertStringNotContainsString( 'Cost so far', ob_get_clean() );
	}

	public function test_a_database_page_shows_the_cost_for_pay_as_you_go_only(): void {
		list( , $r ) = $this->customer( 100 );
		$this->assertSame( "Cost so far this month: \u{20AC}2.26", DbCard::view( $r )['cost'] );
		$this->assertSame( '', DbCard::view( $this->ready_vm() )['cost'] );

		ob_start();
		DbCard::render( $r );
		$this->assertStringContainsString( "Cost so far this month: \u{20AC}2.26", ob_get_clean() );
	}
}
