<?php
/**
 * The usage estimate follows the visitor's incl./excl. VAT display; REST and MCP numbers stay net.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\UsageView;
use BeltoftServices\Proxmox\Db\DbCard;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Display only: for_user() stays net.
 */
class EstimateInclTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$this->display( 'excl' );
	}

	public function tear_down(): void {
		UsageView::reset();
		Estimate::reset();
		parent::tear_down();
	}

	private function display( string $mode ): void {
		update_option( 'woocommerce_tax_display_shop', $mode );
		update_option( 'woocommerce_tax_display_cart', $mode );
	}

	/** A customer with a usage server and $hours of usage at 0.02 (2.00 for 100 hours); logged in. */
	private function customer( int $hours = 100 ): array {
		$v     = $this->usage_product();
		$user  = $this->new_user();
		$sub   = $this->usage_account( $user );
		$r     = $this->usage_vm( $v['u-None'], $user, 5, $sub->get_id() );
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $r->id(), $user, 'server', 1.0, 'hour', 0.02, $end - $i * HOUR_IN_SECONDS );
		}
		Estimate::reset();
		UsageView::reset();
		wp_set_current_user( $user );

		return array( $user, $r );
	}

	public function test_excluding_display_is_unchanged(): void {
		list( $user, $r ) = $this->customer();
		$html             = UsageView::html();

		$this->assertStringContainsString( 'Total so far, excl. VAT (estimate)', $html );
		$this->assertStringContainsString( "\u{20AC}2.00", $html );
		$this->assertStringNotContainsString( 'incl. VAT', $html );
		$this->assertSame( "Usage so far: \u{20AC}2.00 (estimate)", Estimate::so_far( $user ) );
		$this->assertSame( "Cost so far this month: \u{20AC}2.00", Estimate::cost_line( $r->id() ) );
	}

	public function test_including_display_shows_the_invoice_figure_and_labels(): void {
		list( $user, $r ) = $this->customer();
		$this->display( 'incl' );
		$html = UsageView::html();

		$this->assertStringContainsString( 'Total so far, incl. VAT (estimate)', $html );
		$this->assertStringContainsString( "\u{20AC}2.50", $html );
		$this->assertStringContainsString( 'Amount incl. VAT', $html );
		$this->assertStringNotContainsString( "\u{20AC}2.00", $html );
		$this->assertSame( "Usage so far, incl. VAT: \u{20AC}2.50 (estimate)", Estimate::so_far( $user ) );
		$this->assertSame( "Cost so far this month, incl. VAT: \u{20AC}2.50", Estimate::cost_line( $r->id() ) );
		$this->assertSame( "Cost so far this month: \u{20AC}2.00", Estimate::cost_line_net( $r->id() ) );
		$this->assertSame( "Cost so far this month, incl. VAT: \u{20AC}2.50", DbCard::view( $r )['cost'] );
		$this->assertSame( "Cost so far this month: \u{20AC}2.00", DbCard::view( $r )['cost_net'] );
	}

	public function test_the_stored_estimate_stays_net(): void {
		list( $user ) = $this->customer();
		$this->display( 'incl' );
		$estimate = Estimate::for_user( $user );

		$this->assertSame( 2.0, $estimate['total'] );
		$this->assertSame( 250, Estimate::invoice_cents( $estimate ) );
	}

	public function test_a_vat_exempt_customer_is_shown_net(): void {
		list( $user, $r ) = $this->customer();
		$this->display( 'incl' );
		WC()->customer->set_is_vat_exempt( true );

		$this->assertSame( "Cost so far this month: \u{20AC}2.00", Estimate::cost_line( $r->id() ) );
		$this->assertSame( 200, Estimate::invoice_cents( Estimate::for_user( $user ) ) );
		WC()->customer->set_is_vat_exempt( false );
	}

	public function test_no_usage_account_shows_nothing(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->display( 'incl' );

		$this->assertSame( '', Estimate::so_far( $user ) );
		$this->assertSame( '', UsageView::html() );
	}
}
