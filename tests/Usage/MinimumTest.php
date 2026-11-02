<?php
/**
 * The minimum a card is charged for pay as you go usage.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Tests\Credit\CreditTestCase;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Minimum;
use BeltoftServices\Usage\Renewal;
use BeltoftServices\Usage\Threshold;

require_once dirname( __DIR__ ) . '/Credit/CreditTestCase.php';

/**
 * Carried, billed, credit, cap, early bill, setting 0, UI and MCP.
 */
class MinimumTest extends CreditTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'usage_minimum' => 5 ) );
		$this->vat( false );
	}

	private function hours( int $resource, int $user, int $hours, float $rate = 0.02, int $offset = 0 ): void {
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS - $offset * HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, 'server', 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS );
		}
	}

	private function email_on( string $id, \WC_Order $order ): bool {
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email->id === $id ) {
				$email->object = $order;
				return $email->is_enabled();
			}
		}
		$this->fail( 'No email ' . $id );
	}

	private function notes( \WC_Order $order ): array {
		return array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
	}

	public function test_under_the_minimum_is_carried_and_the_next_month_adds_it_up(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.00 net, 2.50 with VAT.

		$order = wcs_create_renewal_order( $sub );

		$this->assertCount( 0, $order->get_items( 'fee' ) );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( '', (string) $order->get_meta( Renewal::META_LINES ) );
		$this->assertCount( 100, Ledger::unbilled( $user, time() + DAY_IN_SECONDS ), 'The rows wait.' );
		$this->assertContains( 'Usage of €2.50 carried to next month (under the €5.00 minimum).', array_map( static fn( $n ) => html_entity_decode( $n ), $this->notes( $order ) ) );

		foreach ( array( 'customer_processing_renewal_order', 'customer_completed_renewal_order', 'customer_renewal_invoice', 'new_renewal_order' ) as $id ) {
			$this->assertFalse( $this->email_on( $id, $order ), $id );
		}

		$order->payment_complete();
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ) );

		// A month later: 100 more hours: 4.00 net with the carried rows, 5.00 with VAT, at the minimum: billed.
		$this->hours( 501, $user, 100, 0.02, 200 );
		$next = wcs_create_renewal_order( $sub );
		$this->assertEqualsWithDelta( 4.0, array_sum( array_map( static fn( $f ) => (float) $f->get_total(), $next->get_items( 'fee' ) ) ), 0.001 );
		$this->assertSame( 'yes', $next->get_meta( Renewal::META_LINES ) );
		$this->assertEqualsWithDelta( 5.0, (float) $next->get_total(), 0.001 );
		$this->assertSame( '', (string) $sub->get_meta( Minimum::META_AMOUNT ) );
	}

	public function test_at_or_above_the_minimum_is_billed(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 250 ); // exactly 5.00.

		$order = wcs_create_renewal_order( $sub );

		$this->assertEqualsWithDelta( 5.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 'yes', $order->get_meta( Renewal::META_LINES ) );
		$this->assertTrue( $this->email_on( 'customer_processing_renewal_order', $order ) );
	}

	public function test_credit_that_covers_it_pays_it(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );
		Balance::credit( $user, 1000, Balance::TOPUP, 'order_item', 'seed-a' );

		$order = wcs_create_renewal_order( $sub );

		$this->assertSame( 'yes', $order->get_meta( Renewal::META_LINES ) );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 800, Balance::get( $user ) );
	}

	public function test_partial_credit_with_a_rest_under_the_minimum_carries_the_whole_month(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.00.
		Balance::credit( $user, 100, Balance::TOPUP, 'order_item', 'seed-b' );

		$order = wcs_create_renewal_order( $sub );

		$this->assertSame( '', (string) $order->get_meta( Renewal::META_LINES ) );
		$this->assertSame( 100, Balance::get( $user ), 'The credit is not touched.' );
		$this->assertCount( 100, Ledger::unbilled( $user, time() + DAY_IN_SECONDS ) );
	}

	public function test_partial_credit_with_a_rest_at_the_minimum_is_billed(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 350 ); // 7.00.
		Balance::credit( $user, 200, Balance::TOPUP, 'order_item', 'seed-c' );

		$order = wcs_create_renewal_order( $sub );

		$this->assertEqualsWithDelta( 5.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 0, Balance::get( $user ) );
	}

	public function test_the_monthly_cap_holds_across_carried_months(): void {
		$this->settings( array( 'usage_minimum' => 2 ) );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		// Rate 0.001: the cap is 0.73 a month; 1000 hours would be 1.00 without it.
		$this->hours( 501, $user, 700, 0.001 );
		$this->hours( 501, $user, 20, 0.001, 1500 ); // an earlier month (about 62 days before).
		$this->assertFalse( Ledger::unbilled( $user, time() ) === array() );

		$statement = Renewal::statement( Ledger::unbilled( $user, time() ) );
		foreach ( $statement['lines'] as $line ) {
			$this->assertLessThanOrEqual( 0.73, $line['charge'] );
		}

		$order = wcs_create_renewal_order( $sub );
		$this->assertSame( '', (string) $order->get_meta( Renewal::META_LINES ), 'Under 2.00 in total: carried.' );
		$next = wcs_create_renewal_order( $sub );
		$this->assertSame( '', (string) $next->get_meta( Renewal::META_LINES ), 'And again.' );
		$this->assertSame( $statement['total'], Renewal::statement( Ledger::unbilled( $user, time() ) )['total'], 'Carrying changes no amount.' );
	}

	public function test_the_early_bill_respects_the_minimum(): void {
		$this->settings( array( 'usage_threshold_new' => 1, 'usage_threshold' => 1, 'usage_minimum' => 5 ) );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.00: over the threshold 1, under the minimum.

		$this->assertFalse( Threshold::check( $sub ) );
		$this->assertCount( 100, Ledger::unbilled( $user, time() + DAY_IN_SECONDS ) );
		$this->assertSame( '', (string) $sub->get_meta( Minimum::META_AMOUNT ), 'An early try carries nothing.' );
		$this->assertFalse( Threshold::check( $sub ), 'Not tried again at once.' );

		$this->settings( array( 'usage_minimum' => 0 ) );
		$sub->delete_meta_data( Threshold::META_HOLD );
		$sub->save();
		$this->assertTrue( Threshold::check( $sub ) );
	}

	public function test_setting_zero_is_the_old_behaviour(): void {
		$this->settings( array( 'usage_minimum' => 0 ) );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 10 );

		$order = wcs_create_renewal_order( $sub );

		$this->assertSame( 'yes', $order->get_meta( Renewal::META_LINES ) );
		$this->assertEqualsWithDelta( 0.2, (float) $order->get_total(), 0.001 );
	}

	public function test_the_usage_view_and_mcp_say_what_is_carried(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 );
		wcs_create_renewal_order( $sub );
		Estimate::reset();

		$estimate = Estimate::for_user( $user );
		$this->assertEqualsWithDelta( 2.0, $estimate['carried'], 0.001 );
		$this->assertEqualsWithDelta( 5.0, $estimate['minimum'], 0.001 );

		ob_start();
		wc_get_template( 'usage.php', array( 'estimate' => $estimate, 'credit' => 0 ), 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );
		$html = preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( (string) ob_get_clean() ) ) ) );
		$this->assertStringContainsString( 'Carried to next month: €2.00 (under the €5.00 minimum)', (string) $html );

		$account = \BeltoftServices\Mcp\Pricing::account( $user );
		$this->assertSame( 'Including VAT', substr( $account['usage_minimum']['note'], 0, 13 ) );
		$this->assertNotNull( $account['usage_minimum']['carried'] );
		$this->assertNotNull( $account['usage_minimum']['minimum'] );
	}
}
