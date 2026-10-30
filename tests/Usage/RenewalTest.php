<?php
/**
 * The usage account's renewal order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Renewal;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Lines, VAT, cap, idempotence, zero months.
 */
class RenewalTest extends UsageTestCase {

	private function vat( bool $on ): void {
		update_option( 'woocommerce_calc_taxes', $on ? 'yes' : 'no' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_prices_include_tax', 'no' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		if ( $on ) {
			\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		}
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	public function tear_down(): void {
		$this->vat( false );
		parent::tear_down();
	}

	/**
	 * Rows for hours hours of one item, as the meter writes them, ending an hour ago.
	 */
	private function hours( int $resource, int $user, int $hours, float $rate = 0.02260274, string $item = 'server', int $ago = 1 ): void {
		$end = Ledger::hour( time() ) - $ago * HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, $item, 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS );
		}
	}

	private function fees( \WC_Order $order ): array {
		return array_values( $order->get_items( 'fee' ) );
	}

	public function test_the_renewal_gets_one_line_per_resource_and_item_with_hours_times_rate(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );
		$this->hours( 501, $user, 100, 0.00502283, 'backups' );
		$this->hours( 502, $user, 10 );

		$order = wcs_create_renewal_order( $sub );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$fees = $this->fees( $order );
		$this->assertCount( 3, $fees );
		$this->assertCount( 0, $order->get_items( 'line_item' ), 'The 0 line is replaced.' );
		// Sorted by resource, then item.
		$this->assertStringContainsString( 'backups', $fees[0]->get_name() );
		$this->assertStringContainsString( '100 hours', $fees[1]->get_name() );
		$this->assertStringContainsString( '0.02260274', $fees[1]->get_name() );
		$this->assertSame( '0.5', $fees[0]->get_total() );
		$this->assertSame( '2.26', $fees[1]->get_total() );
		$this->assertSame( '0.23', $fees[2]->get_total() );
		$this->assertSame( '2.99', $order->get_total() );
		$this->assertSame( '2.99', $order->get_meta( Renewal::META_BILLED ) );
		$this->assertStringNotContainsString( "\u{2014}", $fees[0]->get_name() );
	}

	public function test_vat_is_added_to_the_usage_lines(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );

		$order = wcs_create_renewal_order( $sub );

		$this->assertEqualsWithDelta( 2.26, (float) $order->get_total() - (float) $order->get_total_tax(), 0.001 );
		$this->assertEquals( 0.57, (float) $order->get_total_tax() );
		$this->assertEquals( 2.83, (float) $order->get_total() );
	}

	public function test_a_resource_is_capped_at_the_monthly_cap(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		// 800 hours at 15 x 1.1 / 730 would be 18.08: the cap is 16.50. The hours are all in the month before last, so they share one month.
		$base = (int) strtotime( gmdate( 'Y-m-01 00:00:00', strtotime( '-2 months' ) ) . ' UTC' );
		for ( $i = 0; $i < 730; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02260274, $base + $i * HOUR_IN_SECONDS );
		}
		// More hours than the month has (a long month): 745 hours fit in a 31 day month.
		for ( $i = 730; $i < 744; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02260274, $base + $i * HOUR_IN_SECONDS );
		}

		$order = wcs_create_renewal_order( $sub );
		$fees  = $this->fees( $order );

		$this->assertCount( 1, $fees );
		$this->assertSame( '16.5', $fees[0]->get_total() );
		$this->assertStringContainsString( 'capped at', $fees[0]->get_name() );
		$this->assertStringContainsString( '744 hours', $fees[0]->get_name() );
	}

	public function test_an_early_bill_and_the_renewal_in_the_same_month_share_one_cap(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$base = (int) strtotime( gmdate( 'Y-m-01 00:00:00', strtotime( '-2 months' ) ) . ' UTC' );

		for ( $i = 0; $i < 600; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02260274, $base + $i * HOUR_IN_SECONDS );
		}
		$first = wcs_create_renewal_order( $sub );
		$this->assertSame( '13.56', $this->fees( $first )[0]->get_total() );

		for ( $i = 600; $i < 744; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02260274, $base + $i * HOUR_IN_SECONDS );
		}
		$second = wcs_create_renewal_order( $sub );
		// 144 more hours would be 3.25, but only 16.50 - 13.56 is left of the month.
		$this->assertSame( '2.94', $this->fees( $second )[0]->get_total() );
	}

	public function test_a_renewal_made_again_for_the_same_period_bills_nothing_twice(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 50 );

		$first = wcs_create_renewal_order( $sub );
		$this->assertCount( 1, $this->fees( $first ) );

		$again = wcs_create_renewal_order( $sub );
		$this->assertCount( 0, $this->fees( $again ), 'Its rows belong to the first order.' );
		$this->assertCount( 1, $again->get_items( 'line_item' ), 'The 0 line stays.' );
		$this->assertSame( '0.00', (string) number_format( (float) $again->get_total(), 2, '.', '' ) );

		$this->assertSame( array(), Ledger::unbilled( $user, time() + DAY_IN_SECONDS ) );
	}

	public function test_a_zero_month_stays_a_zero_renewal(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );

		$order = wcs_create_renewal_order( $sub );

		$this->assertCount( 0, $this->fees( $order ) );
		$this->assertCount( 1, $order->get_items( 'line_item' ) );
		$this->assertEquals( 0, (float) $order->get_total() );
		$this->assertSame( '', (string) $order->get_meta( Renewal::META_BILLED ) );
	}

	public function test_another_subscription_is_left_alone_and_a_cancelled_order_frees_its_rows(): void {
		$user  = $this->new_user();
		$other = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->hours( 501, $user, 20 );

		$plain = wcs_create_renewal_order( $other );
		$this->assertCount( 0, $this->fees( $plain ), 'Not an account.' );

		$sub   = $this->usage_account( $user );
		$order = wcs_create_renewal_order( $sub );
		$this->assertCount( 1, $this->fees( $order ) );

		$order->update_status( 'cancelled' );
		$this->assertCount( 20, Ledger::unbilled( $user, time() + DAY_IN_SECONDS ), 'The rows are free again.' );

		$next = wcs_create_renewal_order( $sub );
		$this->assertCount( 1, $this->fees( $next ) );
	}

	public function test_a_paid_usage_renewal_marks_the_account_as_charged(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 50 );
		$order = wcs_create_renewal_order( $sub );

		$this->assertFalse( Account::charged( $sub ) );
		do_action( 'woocommerce_subscription_renewal_payment_complete', $sub, $order );
		$this->assertTrue( Account::charged( wcs_get_subscription( $sub->get_id() ) ) );
	}
}
