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

	public function tear_down(): void {
		$this->vat( false );
		parent::tear_down();
	}

	/**
	 * Rows for hours hours of one item, as the meter writes them, ending an hour ago.
	 */
	private function hours( int $resource, int $user, int $hours, float $rate = 0.02260274, string $item = 'server', int $ago = 1 ): void {
		// Early in a month the rows would reach into the month before and split into two lines, so they end in the middle of last month then.
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$base  = $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS;
		$end   = $base - $ago * HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, $item, 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS );
		}
	}

	/** The first hour of the latest 31 day month that ended at least a month ago. */
	private function long_month(): int {
		$base = (int) strtotime( gmdate( 'Y-m-01 00:00:00', time() ) . ' UTC' );
		do {
			$base = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $base - DAY_IN_SECONDS ) . ' UTC' );
		} while ( 31 !== (int) gmdate( 't', $base ) || $base > time() - 62 * DAY_IN_SECONDS );
		return $base;
	}

	private function fees( \WC_Order $order ): array {
		return array_values( $order->get_items( 'fee' ) );
	}

	public function test_the_order_language_picks_an_installed_locale_and_the_site_language_comes_back(): void {
		$this->assertSame( 'en_US', Renewal::locale_for( 'en' ) );
		$this->assertSame( 'en_US', Renewal::locale_for( 'EN-us' ) );
		$this->assertSame( '', Renewal::locale_for( 'xx' ) );
		$this->assertSame( '', Renewal::locale_for( '' ) );

		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$sub->update_meta_data( '_order_lang', 'en' );
		$sub->save();
		$this->hours( 501, $user, 100 );
		$this->settings( array( 'usage_order_language_meta' => '_order_lang' ) );
		$before = determine_locale();

		$order = wcs_create_renewal_order( $sub );

		$this->assertCount( 1, $this->fees( $order ) );
		$this->assertSame( $before, determine_locale(), 'The site language is back after the lines.' );
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
		$this->assertCount( 1, $order->get_items( 'line_item' ), 'The 0 account line stays, so the order-pay cart is built from it.' );
		// Sorted by resource, then item.
		$this->assertStringStartsWith( 'Backups (', $fees[0]->get_name() );
		$this->assertStringContainsString( '100 hours', $fees[1]->get_name() );
		$this->assertStringContainsString( '0.0226', $fees[1]->get_name() );
		$this->assertStringNotContainsString( '0.02260274', $fees[1]->get_name() );
		$this->assertMatchesRegularExpression( '/^[^\n]+\n\w+ \d{4}: 100 hours at /', $fees[1]->get_name(), 'The title alone, then the month, hours and rate on a line of their own.' );
		$this->assertStringStartsWith( 'Server ', $fees[1]->get_name() );
		$this->assertDoesNotMatchRegularExpression( '/\d{4}-\d{2}/', $fees[1]->get_name() );
		$this->assertSame( '0.5', $fees[0]->get_total() );
		$this->assertSame( '2.26', $fees[1]->get_total() );
		// Hours x price per hour is what an accounting system prints as columns; it comes to the charge.
		$this->assertSame( '100', (string) $fees[1]->get_meta( '_beltoft_services_usage_hours' ) );
		$this->assertEqualsWithDelta( 2.26, 100 * (float) $fees[1]->get_meta( '_beltoft_services_usage_price' ), 0.005 );
		$this->assertSame( '0.23', $fees[2]->get_total() );
		$this->assertSame( '2.99', $order->get_total() );
		$this->assertSame( '2.99', $order->get_meta( Renewal::META_BILLED ) );
		$this->assertStringNotContainsString( "\u{2014}", $fees[0]->get_name() );
	}

	public function test_the_order_pay_cart_of_a_usage_invoice_holds_the_account_line_and_the_fees(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$sub = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );

		$order = wcs_create_renewal_order( $sub );
		$sub->update_status( 'on-hold' );
		$order->update_status( 'failed' );

		$renewal = \WCS_Cart_Renewal::class;
		$object  = \WC_Subscriptions_Core_Plugin::instance()->get_cart_handler( 'WCS_Cart_Renewal' );
		$method  = new \ReflectionMethod( $renewal, 'setup_cart' );
		$method->setAccessible( true );
		$method->invoke( $object, $order, array( 'subscription_id' => $sub->get_id(), 'renewal_order_id' => $order->get_id() ), 'all_items_required' );

		WC()->cart->calculate_totals();

		$this->assertNotEmpty( WC()->cart->get_cart(), 'Your checkout is empty' );
		$this->assertCount( 1, WC()->cart->get_fees() );
		$this->assertEquals( 2.26, (float) WC()->cart->get_total( 'edit' ) );
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
		$base = $this->long_month();
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
		$base = $this->long_month();

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

	public function test_line_wording_uses_plural_forms_and_translatable_units(): void {
		$call = static function ( string $method, ...$args ) {
			$m = new \ReflectionMethod( \BeltoftServices\Usage\Renewal::class, $method );
			$m->setAccessible( true );
			return $m->invoke( null, ...$args );
		};
		$this->assertSame( '1 hour', $call( 'hours_text', 1 ) );
		$this->assertSame( '600 hours', $call( 'hours_text', 600 ) );
		$this->assertSame( '', $call( 'quantity_note', 1.0, 'hour' ) );
		$this->assertSame( ' (2.5 GB)', $call( 'quantity_note', 2.5, 'GB' ) );
		$this->assertSame( ' (1 pack)', $call( 'quantity_note', 1.0, 'pack' ) );
		$this->assertSame( ' (3 packs)', $call( 'quantity_note', 3.0, 'pack' ) );
		$this->assertSame( 'GB', $call( 'unit_name', 'GB' ) );
		$this->assertSame( 'other', $call( 'unit_name', 'other' ) );
	}
}
