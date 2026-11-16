<?php
/**
 * Boundaries of the pay as you go early bill, the capped flag and the ledger cutoff hour.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;
use BeltoftServices\Usage\Threshold;

/**
 * Equal values: the line the code draws is exactly here.
 */
class BoundaryTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'usage_minimum' => 0, 'usage_threshold_new' => 2, 'usage_threshold' => 2 ) );
		$this->vat( false );
		Estimate::reset();
	}

	public function tear_down(): void {
		$this->vat( false );
		Estimate::reset();
		parent::tear_down();
	}

	private function hours( int $resource, int $user, int $hours ): void {
		$end = Ledger::hour( time() ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, 'server', 1.0, 'hour', 0.02, $end - $i * HOUR_IN_SECONDS );
		}
	}

	public function test_an_accrued_amount_equal_to_the_threshold_is_billed_early(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // Exactly 2.00.

		$this->assertTrue( Threshold::check( $sub ) );
	}

	public function test_an_accrued_amount_just_under_the_threshold_is_not_billed(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 99 ); // 1.98.

		$this->assertFalse( Threshold::check( $sub ) );
	}

	public function test_a_threshold_of_zero_with_nothing_accrued_makes_no_order(): void {
		$this->settings( array( 'usage_threshold_new' => 0, 'usage_threshold' => 0 ) );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );

		$this->assertFalse( Threshold::check( $sub ) );
	}

	public function test_a_charge_equal_to_the_room_left_is_not_capped(): void {
		// A 31 day month holds the whole cap of hours.
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', strtotime( '-1 month' ) ) . ' UTC' );

		while ( 31 !== (int) gmdate( 't', $month ) ) {
			$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $month - DAY_IN_SECONDS ) . ' UTC' );
		}

		$rows  = array();
		$room  = (int) Rates::HOURS;

		// A whole cap of hours at 1.00: the raw charge is the cap exactly.
		for ( $i = 0; $i < $room; $i++ ) {
			$rows[] = array( 'id' => 0, 'resource_id' => 501, 'item' => 'server', 'unit' => 'hour', 'quantity' => 1.0, 'rate' => 1.0, 'hour_bucket' => gmdate( 'Y-m-d H:i:s', $month + $i * HOUR_IN_SECONDS ) );
		}

		$line = Renewal::statement( $rows )['lines'][0];

		$this->assertSame( (float) $room, $line['cap'] );
		$this->assertSame( (float) $room, $line['charge'] );
		$this->assertFalse( $line['capped'] );

		$rows[] = array( 'id' => 0, 'resource_id' => 501, 'item' => 'server', 'unit' => 'hour', 'quantity' => 1.0, 'rate' => 1.0, 'hour_bucket' => gmdate( 'Y-m-d H:i:s', $month + $room * HOUR_IN_SECONDS ) );
		$this->assertTrue( Renewal::statement( $rows )['lines'][0]['capped'], 'One hour more than the room is capped.' );
	}

	public function test_a_ledger_row_at_exactly_the_cutoff_hour_stays_unbilled(): void {
		$user = $this->new_user();
		$hour = Ledger::hour( time() ) - 5 * HOUR_IN_SECONDS;
		Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02, $hour );

		$this->assertCount( 0, Ledger::unbilled( $user, $hour ), 'The cutoff hour itself is not billed yet.' );
		$this->assertCount( 1, Ledger::unbilled( $user, $hour + HOUR_IN_SECONDS ) );
	}
}
