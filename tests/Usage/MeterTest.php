<?php
/**
 * Rates, the ledger and the hourly meter.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Resource;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Meter and rate maths.
 */
class MeterTest extends UsageTestCase {

	private function rows( int $resource_id, string $item = '' ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Ledger::table() . ' WHERE resource_id = %d' . ( '' === $item ? '' : ' AND item = %s' ) . ' ORDER BY hour_bucket', ...( '' === $item ? array( $resource_id ) : array( $resource_id, $item ) ) ), ARRAY_A );
	}

	public function test_rate_and_cap_at_ten_percent(): void {
		$this->assertSame( 0.02260274, Rates::hourly( 15.0, 10.0 ) );
		$this->assertSame( 16.5, Rates::cap( 15.0, 10.0 ) );
		$this->assertSame( 0.02465753, Rates::hourly( 15.0, 20.0 ) );
		$this->assertSame( 18.0, Rates::cap( 15.0, 20.0 ) );
	}

	public function test_the_premium_is_a_product_setting_with_a_default_of_ten(): void {
		$v = $this->usage_product();
		$this->assertSame( 10.0, Rates::premium( $v['u-None']->get_parent_id() ) );
		$this->assertSame( 10.0, Rates::premium( $v['u-None']->get_id() ) );

		$w = $this->usage_product( '25' );
		$this->assertSame( 25.0, Rates::premium( $w['u-None']->get_id() ) );
	}

	public function test_a_usage_variation_is_priced_from_its_monthly_sibling(): void {
		$v = $this->usage_product();

		$this->assertTrue( Rates::is_usage( $v['u-None'] ) );
		$this->assertFalse( Rates::is_usage( $v['m-None'] ) );
		$this->assertSame( $v['m-None']->get_id(), Rates::sibling( $v['u-None'], false )->get_id() );
		$this->assertSame( $v['u-Daily']->get_id(), Rates::sibling( $v['m-Daily'], true )->get_id() );
		$this->assertSame( 15.0, Rates::monthly( $v['u-None'] ) );
		$this->assertSame( 18.0, Rates::monthly( $v['u-Daily'] ) );
	}

	public function test_the_meter_writes_one_row_per_full_hour_and_runs_again_without_duplicates(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );
		$now  = time();

		$first = Meter::run( $now );
		$rows  = $this->rows( $r->id(), 'server' );

		// Activated 5 hours ago: the hour it began in and the full hours since, up to the last full hour.
		$this->assertSame( $first, count( $rows ) );
		$this->assertGreaterThanOrEqual( 5, $first );
		$this->assertLessThanOrEqual( 6, $first );
		$this->assertSame( 'hour', $rows[0]['unit'] );
		$this->assertEquals( 0.02260274, (float) $rows[0]['rate'] );
		$this->assertSame( (string) $user, $rows[0]['user_id'] );
		$this->assertSame( gmdate( 'Y-m-d H:00:00', $now - HOUR_IN_SECONDS ), end( $rows )['hour_bucket'] );

		$this->assertSame( 0, Meter::run( $now ), 'A second run writes nothing.' );
		$this->assertCount( $first, $this->rows( $r->id(), 'server' ) );
	}

	public function test_a_missed_hour_is_filled_and_nothing_older_than_48_hours_is_written(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 200 );
		$now  = time();

		Meter::run( $now );
		$this->assertCount( 48, $this->rows( $r->id(), 'server' ), 'The first run looks back 48 hours.' );

		global $wpdb;
		$gap = gmdate( 'Y-m-d H:00:00', $now - 3 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->delete( Ledger::table(), array( 'resource_id' => $r->id(), 'hour_bucket' => $gap ) );
		$this->assertCount( 47, $this->rows( $r->id(), 'server' ) );

		$this->assertSame( 1, Meter::run( $now ), 'The next run fills the missing hour.' );
		$this->assertCount( 48, $this->rows( $r->id(), 'server' ) );
	}

	public function test_a_deleted_resource_stops_and_a_stopped_server_still_counts(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 10 );
		$this->assertTrue( $r->set( array( 'state' => 'paused' ) ) );

		$this->assertGreaterThan( 0, Meter::run() );
		$count = count( $this->rows( $r->id() ) );

		$this->assertTrue( $r->set( array( 'state' => 'deleted' ) ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->delete( Ledger::table(), array( 'resource_id' => $r->id() ) );
		$this->assertSame( 0, Meter::run(), 'A server that was deleted is not metered.' );
		$this->assertGreaterThan( 0, $count );
	}

	public function test_backups_are_a_separate_item_when_enabled_and_the_server_item_is_the_base_price(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-Daily'], $user, 3 );

		$items = Rates::items( $r );
		$this->assertSame( array( 'server', 'backups' ), array_column( $items, 'item' ) );
		$this->assertSame( Rates::hourly( 15.0, 10.0 ), $items[0]['rate'] );
		$this->assertSame( Rates::hourly( 3.0, 10.0 ), $items[1]['rate'] );

		Meter::run();
		$this->assertNotEmpty( $this->rows( $r->id(), 'backups' ) );
		$this->assertSame( count( $this->rows( $r->id(), 'server' ) ), count( $this->rows( $r->id(), 'backups' ) ) );
	}

	public function test_a_server_without_backups_has_no_backups_item_and_a_monthly_license_is_not_metered(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 3 );
		$this->assertSame( array( 'server' ), array_column( Rates::items( $r ), 'item' ) );

		$parent  = wc_get_product( $v['m-None']->get_parent_id() );
		$license = $this->service_license( $parent, array( 'variation_id' => $v['m-None']->get_id(), 'user_id' => $user, 'email' => self::MAIL, 'fire' => false ) );
		$monthly = \BeltoftServices\Resource::create( $license, \BeltoftServices\Drivers\Registry::get( 'proxmox' ), \BeltoftServices\Product::plan( $parent->get_id(), $v['m-None']->get_id() ), array() );
		$this->activated_ago( $monthly->id(), 5 );

		Meter::run();
		$this->assertSame( array(), $this->rows( $monthly->id() ) );
		$this->assertNotEmpty( $this->rows( $r->id() ) );
	}

	public function test_the_meter_is_scheduled_hourly_in_the_services_group(): void {
		as_unschedule_all_actions( Meter::HOOK, array(), 'beltoft-services' );
		Meter::schedule();
		$this->assertTrue( as_has_scheduled_action( Meter::HOOK, array(), 'beltoft-services' ) );
		Meter::unschedule();
		$this->assertFalse( as_has_scheduled_action( Meter::HOOK, array(), 'beltoft-services' ) );
	}

	public function test_billing_starts_in_the_hour_the_server_first_was_ready(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 10 );
		$this->assertNotNull( $r->get( Meter::READY_AT ), 'Reaching ready records the time.' );

		// Created 10 hours ago, but ready only 3 hours ago.
		$r->set( array( Meter::READY_AT => time() - 3 * HOUR_IN_SECONDS ) );
		Meter::run();
		$rows = $this->rows( $r->id(), 'server' );
		$this->assertSame( gmdate( 'Y-m-d H:00:00', time() - 3 * HOUR_IN_SECONDS ), $rows[0]['hour_bucket'] );
		$this->assertLessThanOrEqual( 3, count( $rows ) );
	}

	public function test_a_server_that_never_got_ready_bills_nothing(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 10 );
		// Back to a server that never was ready: no ready time, and it failed.
		$r->set( array( Meter::READY_AT => null, 'state' => 'failed' ) );
		$this->assertNull( Resource::find( $r->id() )->get( Meter::READY_AT ) );

		$this->assertSame( 0, Meter::run() );
		$this->assertSame( array(), $this->rows( $r->id() ) );

		$r->set( array( 'state' => 'provisioning' ) );
		$this->assertSame( 0, Meter::run(), 'Still being set up: nothing yet.' );
	}

	public function test_a_server_that_failed_after_it_was_ready_keeps_billing(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 10 );
		$r->set( array( 'state' => 'failed' ) );

		$this->assertGreaterThan( 0, Meter::run() );
		$this->assertNotEmpty( $this->rows( $r->id(), 'server' ) );
	}
}
