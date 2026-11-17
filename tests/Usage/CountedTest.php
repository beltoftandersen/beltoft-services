<?php
/**
 * Counted services: the pull of events, the free intro, price steps, billing and the customer's view.
 * No network: the HTTP recorder answers the service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Http\Connections;
use BeltoftServices\Http\OrderPull;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;
use BeltoftServices\Usage\Counted\Intro;
use BeltoftServices\Usage\Counted\Meter;
use BeltoftServices\Usage\Counted\Store;
use BeltoftServices\Usage\Counted\Views;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;

/**
 * Counted service tests.
 */
class CountedTest extends TestCase {

	private const SECRET = 'S3cret-Value-Never-Shown';

	/** @var array<int, array<string, mixed>> The service's events by sequence. */
	private $remote = array();

	/** @var int|null Total the service reports (null: the number of events). */
	private $total = null;

	/** @var \WC_Product_Variation */
	private $variation;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Connections::OPTION );
		$this->assertTrue(
			Connections::save(
				'cnt',
				array(
					'label'     => 'Counted',
					'base_url'  => 'https://api.example.com',
					'auth'      => 'hmac',
					'secret'    => self::SECRET,
					'endpoints' => array(
						'provision' => array( 'method' => 'POST', 'path' => '/tenants/' ),
						'events'    => array( 'method' => 'GET', 'path' => '/tenants/{resource_id}/orders/' ),
					),
				)
			)
		);

		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com', 'counted_billing' => 1, 'counted_hold_hours' => 24 ) );
		$this->remote = array();
		$this->total  = null;

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test reset.
		$wpdb->query( 'DELETE FROM ' . Store::table() );
		$wpdb->query( 'DELETE FROM ' . Intro::table() );
		$wpdb->query( 'DELETE FROM ' . Ledger::table() );
		// phpcs:enable
		add_filter( 'pre_wp_mail', '__return_true' );

		$this->respond_with(
			function ( $url ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
				$after = (int) ( $q['after'] ?? 0 );
				$limit = (int) ( $q['limit'] ?? 500 );
				$rows  = array_values( array_filter( $this->remote, static fn( $o ) => $o['seq'] > $after ) );
				$page  = array_slice( $rows, 0, $limit );
				$body  = array(
					'orders'      => $page,
					'next'        => count( $rows ) > $limit ? end( $page )['seq'] : null,
					'high'        => $this->remote ? max( array_keys( $this->remote ) ) : 0,
					'count_total' => $this->total ?? count( $this->remote ),
				);

				return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Connections::OPTION );
		parent::tear_down();
	}

	/**
	 * A counted service: 500 at 0.10, up to 2000 at 0.05, then 0.02.
	 */
	private function service( array $license = array() ): Resource {
		$product = $this->service_product( 'http', array( 'limit' => 1000 ), array( 'connection' => 'cnt', 'variations' => array( array() ), 'slug' => 'cnt-' . wp_generate_password( 5, false ) ) );
		$variation = wc_get_product( $product->get_children()[0] );
		$variation->set_regular_price( '0' );
		$variation->update_meta_data( Rates::META_BILLING, 'counted' );
		$variation->update_meta_data( Rates::META_UNIT_STEPS, '500:0.10,2000:0.05,0:0.02' );
		$variation->update_meta_data( Rates::META_UNIT_STEPS . '_DKK', '500:0.80,2000:0.40,0:0.16' );
		$variation->save();
		$this->variation = $variation;

		$row = $this->service_license(
			$product,
			array_merge(
				array(
					'fire'         => false,
					'source'       => 'usage',
					'variation_id' => $variation->get_id(),
					'email'        => 'christian.beltoft@gmail.com',
				),
				$license
			)
		);
		$r = Resource::create( $row, Registry::get( 'http' ), Product::plan( $product->get_id(), $variation->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'uuid' => wp_generate_uuid4() ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Adds events to the fake service.
	 */
	private function remote( int $count, int $from = 1, string $store = 'shop.example', string $odoo = 'odoo.example/db', string $source = 'webhook' ): void {
		for ( $i = $from; $i < $from + $count; $i++ ) {
			$this->remote[ $i ] = array(
				'seq' => $i, 'store_key' => $store, 'odoo_key' => $odoo, 'woo_order_id' => (string) $i, 'order_number' => (string) $i,
				'order_date' => gmdate( 'c', time() - DAY_IN_SECONDS ), 'status' => 'processing', 'total' => '10.00', 'currency' => 'EUR',
				'odoo_order_name' => 'S' . $i, 'source' => $source, 'created_at' => gmdate( 'c' ),
			);
		}
	}

	private function states( Resource $r ): array {
		return Store::states( $r->id() );
	}

	// ------------------------------------------------------------ pull

	public function test_the_pull_stores_events_once_and_moves_the_cursor(): void {
		$r = $this->service( array( 'user_id' => 0 ) );
		Rates::unit_steps( $this->variation );
		$this->remote( 3 );
		$now = time();

		$this->assertSame( 3, OrderPull::pull( $r, $now ) );
		$r = Resource::find( $r->id() );
		$this->assertSame( 3, (int) $r->get( OrderPull::CURSOR ) );
		$this->assertSame( 0, OrderPull::pull( $r, $now + 10 ), 'A second pull finds nothing new.' );
		$this->assertSame( 3, Store::count( $r->id() ) );
	}

	public function test_a_repeated_store_and_order_under_a_new_sequence_is_not_stored_again(): void {
		$r = $this->service();
		$this->remote( 1 );
		OrderPull::pull( $r, time() );
		$this->remote[2]                 = $this->remote[1];
		$this->remote[2]['seq']          = 2;
		$this->assertSame( 0, OrderPull::pull( Resource::find( $r->id() ), time() ) );
		$this->assertSame( 1, Store::count( $r->id() ) );
	}

	public function test_pages_are_followed(): void {
		$r = $this->service();
		update_option( 'beltoft_services_settings', array_merge( (array) get_option( 'beltoft_services_settings' ), array( 'counted_cap_min' => 5000, 'counted_intro_days' => 0 ) ) );
		$this->remote( 620 );
		$this->assertSame( 620, OrderPull::pull( $r, time() ) );
	}

	public function test_a_sequence_that_goes_back_stops_the_pull(): void {
		$r = $this->service();
		$this->remote( 3 );
		OrderPull::pull( $r, time() );
		$r = Resource::find( $r->id() );
		$r->set( array( OrderPull::CURSOR => 10 ) );
		// A service that answers with an old sequence whatever the cursor.
		$this->respond_with( static fn() => array( 'headers' => array(), 'body' => wp_json_encode( array( 'orders' => array( array( 'seq' => 4, 'store_key' => 's', 'woo_order_id' => '4', 'source' => 'webhook' ) ), 'next' => null, 'count_total' => 4 ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null ) );
		OrderPull::pull( Resource::find( $r->id() ), time() );
		$this->assertNotSame( '', (string) Resource::find( $r->id() )->get( OrderPull::STOPPED ) );
		$this->assertSame( 0, OrderPull::pull( Resource::find( $r->id() ), time() ), 'A stopped service is not pulled.' );
	}

	public function test_a_total_below_what_is_held_stops_the_pull(): void {
		$r = $this->service();
		$this->remote( 3 );
		$this->total = 2;
		OrderPull::pull( $r, time() );
		$this->assertNotSame( '', (string) Resource::find( $r->id() )->get( OrderPull::STOPPED ) );
	}

	public function test_a_total_above_what_is_held_reads_from_the_start_once(): void {
		$r = $this->service();
		$this->remote( 2 );
		$this->total = 5;
		OrderPull::pull( $r, time() );
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( OrderPull::CURSOR ), 'Read again from 0.' );
	}

	public function test_a_failed_read_keeps_the_cursor_and_counts_the_error(): void {
		$r = $this->service();
		$this->remote( 2 );
		OrderPull::pull( $r, time() );
		$this->respond_with( static fn() => array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 502, 'message' => '' ), 'cookies' => array(), 'filename' => null ) );
		$this->assertSame( 0, OrderPull::pull( Resource::find( $r->id() ), time() ) );
		$r = Resource::find( $r->id() );
		$this->assertSame( 2, (int) $r->get( OrderPull::CURSOR ) );
		$this->assertSame( 1, (int) $r->get( OrderPull::ERRORS ) );
	}

	public function test_the_read_is_signed_and_carries_the_cursor(): void {
		$r = $this->service();
		$this->remote( 1 );
		OrderPull::pull( $r, time() );
		$call = $this->http_calls()[0];
		$this->assertSame( 'GET', $call['args']['method'] );
		$this->assertStringContainsString( '/orders/?after=0&limit=500', $call['url'] );
		$this->assertArrayHasKey( 'X-Beltoft-Signature', $call['args']['headers'] );
	}

	public function test_an_invalid_event_is_refused(): void {
		$r = $this->service();
		$this->remote( 1 );
		$this->remote[1]['woo_order_id'] = 'abc';
		$this->remote( 1, 2 );
		$this->remote[2]['source'] = 'magic';
		$this->assertSame( 0, OrderPull::pull( $r, time() ) );
	}

	// ------------------------------------------------------------ intro

	public function test_the_intro_starts_at_the_first_event_and_makes_the_orders_free(): void {
		$r   = $this->service();
		$now = 1900000000;
		$this->remote( 3 );
		OrderPull::pull( $r, $now );
		$r = Resource::find( $r->id() );

		$this->assertSame( $now, (int) $r->get( Intro::STARTED ) );
		$this->assertSame( $now + 7 * DAY_IN_SECONDS, (int) $r->get( Intro::ENDS ) );
		$this->assertSame( array( Store::FREE => 3 ), $this->states( $r ) );
		$this->assertSame( 7, Intro::status( $r, $now + 1 )['days_left'] );
		$this->assertSame( 'ended', Intro::status( $r, $now + 8 * DAY_IN_SECONDS )['state'] );
	}

	public function test_orders_after_the_window_bill(): void {
		$r   = $this->service();
		$now = 1900000000;
		$this->remote( 1 );
		OrderPull::pull( $r, $now );
		$this->remote( 2, 2 );
		OrderPull::pull( Resource::find( $r->id() ), $now + 7 * DAY_IN_SECONDS + 60 );
		$this->assertEquals( array( Store::FREE => 1, Store::BILLABLE => 2 ), $this->states( $r ) );
	}

	public function test_an_intro_of_zero_days_bills_from_the_start(): void {
		$r = $this->service();
		update_option( 'beltoft_services_settings', array_merge( (array) get_option( 'beltoft_services_settings' ), array( 'counted_intro_days' => 0 ) ) );
		$this->remote( 1 );
		OrderPull::pull( $r, time() );
		$this->assertSame( array( Store::BILLABLE => 1 ), $this->states( $r ) );
	}

	public function test_a_second_service_for_the_same_store_gets_no_intro(): void {
		$a = $this->service();
		$this->remote( 1 );
		OrderPull::pull( $a, time() );

		$b = $this->service( array( 'email' => 'other@example.com' ) );
		OrderPull::pull( $b, time() );
		$this->assertSame( 'store', Resource::find( $b->id() )->get( Intro::DENIED ) );
		$this->assertSame( array( Store::BILLABLE => 1 ), $this->states( $b ) );
	}

	public function test_a_second_service_for_the_same_odoo_database_gets_no_intro(): void {
		$a = $this->service();
		$this->remote( 1 );
		OrderPull::pull( $a, time() );

		$b = $this->service( array( 'email' => 'other@example.com' ) );
		$this->remote = array();
		$this->remote( 1, 1, 'other-shop.example', 'odoo.example/db' );
		OrderPull::pull( $b, time() );
		$this->assertSame( 'odoo', Resource::find( $b->id() )->get( Intro::DENIED ) );
	}

	public function test_a_second_service_of_the_same_customer_gets_no_intro(): void {
		$a = $this->service();
		$this->remote( 1 );
		OrderPull::pull( $a, time() );

		$b = $this->service( array( 'user_id' => (int) $a->license()['user_id'], 'email' => 'x@example.com', 'product_id' => (int) $a->license()['product_id'] ) );
		$this->remote = array();
		$this->remote( 1, 1, 'another.example', 'another.example/db' );
		OrderPull::pull( $b, time() );
		$this->assertNotSame( '', (string) Resource::find( $b->id() )->get( Intro::DENIED ) );
	}

	public function test_the_claims_survive_a_deleted_service(): void {
		$a = $this->service();
		$this->remote( 1 );
		OrderPull::pull( $a, time() );
		// The service goes away (its licence row is removed); the claims live in their own table.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test.
		$wpdb->delete( \BeltoftLicenses\Install::tables()['licenses'], array( 'id' => (int) $a->license()['id'] ) );

		$this->assertSame( $a->id(), Intro::claimed_by( 'store', 'shop.example' ) );
		$this->assertSame( $a->id(), Intro::claimed_by( 'odoo', 'odoo.example/db' ) );
		$this->assertSame( 0, Intro::claimed_by( 'store', 'never.example' ) );
	}

	public function test_a_customer_who_held_the_product_before_gets_no_intro(): void {
		$a = $this->service();
		// Another licence of the same product for the same email: this service is the second holding.
		$this->service_license( wc_get_product( (int) $a->license()['product_id'] ), array( 'fire' => false, 'email' => 'christian.beltoft@gmail.com', 'user_id' => (int) $a->license()['user_id'] ) );
		$this->remote( 1 );
		OrderPull::pull( $a, time() );
		$this->assertSame( 'customer', Resource::find( $a->id() )->get( Intro::DENIED ) );
	}

	public function test_a_free_rate_service_is_free_for_good_and_takes_no_intro(): void {
		$r = $this->service();
		$r->set( array( 'counted_free_rate' => 'yes' ) );
		$this->remote( 2 );
		OrderPull::pull( Resource::find( $r->id() ), time() );
		$r = Resource::find( $r->id() );
		$this->assertSame( array( Store::FREE => 2 ), $this->states( $r ) );
		$this->assertNull( $r->get( Intro::STARTED ) );
		$this->assertSame( 0, Intro::claimed_by( 'store', 'shop.example' ) );
		$this->assertSame( 'rate', Store::recent( $r->id(), 1 )[0]['free_reason'] );
	}

	// ------------------------------------------------------------ cap

	public function test_orders_over_the_daily_cap_are_held_not_billed(): void {
		update_option( 'beltoft_services_settings', array_merge( (array) get_option( 'beltoft_services_settings' ), array( 'counted_intro_days' => 0, 'counted_cap_min' => 3, 'counted_cap_factor' => 1 ) ) );
		$r = $this->service();
		$this->remote( 5 );
		OrderPull::pull( $r, time() );
		$this->assertEquals( array( Store::BILLABLE => 3, Store::HELD => 2 ), $this->states( $r ) );

		$this->assertSame( 2, Store::release_held( $r->id(), time() + 3600 ) );
		$this->assertSame( array( Store::BILLABLE => 5 ), $this->states( $r ) );
	}

	// ------------------------------------------------------------ steps and billing

	public function test_unit_steps_are_read_from_meta_per_currency(): void {
		$this->service();
		$this->assertSame( array( array( 'upto' => 500, 'rate' => 0.10 ), array( 'upto' => 2000, 'rate' => 0.05 ), array( 'upto' => null, 'rate' => 0.02 ) ), Rates::unit_steps( $this->variation, '' ) );
		$this->assertSame( 0.16, Rates::unit_steps( $this->variation, 'DKK' )[2]['rate'] );
		$this->assertSame( array(), Rates::unit_steps( $this->variation, 'SEK' ), 'No price in a currency: no steps.' );
	}

	public function test_a_single_unit_price_is_one_step(): void {
		$this->service();
		$this->variation->delete_meta_data( Rates::META_UNIT_STEPS );
		$this->variation->update_meta_data( Rates::META_UNIT_PRICE, '0.10' );
		$this->variation->save();
		$this->assertSame( array( array( 'upto' => null, 'rate' => 0.10 ) ), Rates::unit_steps( wc_get_product( $this->variation->get_id() ), '' ) );
	}

	public function test_allocation_fills_the_steps_like_brackets(): void {
		$steps = Rates::unit_steps( ( function () {
			$this->service();
			return $this->variation;
		} )() );
		$this->assertSame( array( array( 'step' => 1, 'quantity' => 10, 'rate' => 0.10 ) ), Meter::allocate( 0, 10, $steps ) );
		$this->assertSame( array( array( 'step' => 1, 'quantity' => 100, 'rate' => 0.10 ), array( 'step' => 2, 'quantity' => 50, 'rate' => 0.05 ) ), Meter::allocate( 400, 150, $steps ) );
		$total = 0.0;
		foreach ( Meter::allocate( 0, 10000, $steps ) as $part ) {
			$total += $part['quantity'] * $part['rate'];
		}
		$this->assertEquals( 285.0, round( $total, 2 ), '10,000 orders = 50 + 75 + 160.' );
		$this->assertSame( array( array( 'step' => 3, 'quantity' => 5, 'rate' => 0.02 ) ), Meter::allocate( 2500, 5, $steps ) );
	}

	/**
	 * Billable events in given hours, by hand, then billed.
	 *
	 * @param array<string, int> $hours Hour (Y-m-d H:00:00) => number of events.
	 */
	private function seed_billable( Resource $r, array $hours, int $first_id = 1 ): void {
		$id = $first_id;
		foreach ( $hours as $hour => $n ) {
			$at = (int) strtotime( $hour . ' UTC' );
			for ( $i = 0; $i < $n; $i++ ) {
				Store::add(
					array(
						'resource_id' => $r->id(), 'user_id' => (int) $r->license()['user_id'], 'remote_seq' => 100000 + $id, 'store_key' => 's', 'woo_order_id' => (string) $id,
						'received_at' => $at + 5, 'state' => Store::BILLABLE,
					)
				);
				++$id;
			}
		}
	}

	private function ledger( Resource $r ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT item, quantity, rate, hour_bucket FROM ' . Ledger::table() . ' WHERE resource_id = %d ORDER BY hour_bucket, item', $r->id() ), ARRAY_A );
	}

	public function test_an_hour_is_billed_in_steps_and_marked_billed(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-11-20 12:30:00 UTC' );
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 480, '2026-11-18 11:00:00' => 30 ) );

		$this->assertSame( 3, Meter::bill( $r, $now ) );
		$rows = $this->ledger( $r );
		$this->assertSame( array( 'order', '480.0000', '0.10000000' ), array( $rows[0]['item'], $rows[0]['quantity'], $rows[0]['rate'] ) );
		$this->assertSame( array( 'order', '20.0000' ), array( $rows[1]['item'], $rows[1]['quantity'] ) );
		$this->assertSame( array( 'order_2', '10.0000', '0.05000000' ), array( $rows[2]['item'], $rows[2]['quantity'], $rows[2]['rate'] ) );
		$this->assertSame( array( Store::BILLED => 510 ), $this->states( $r ) );
	}

	public function test_a_rerun_writes_nothing_and_changes_nothing(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-11-20 12:30:00 UTC' );
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 600 ) );
		Meter::bill( $r, $now );
		$before = $this->ledger( $r );

		$this->assertSame( 0, Meter::bill( $r, $now + 3600 ) );
		$this->assertSame( $before, $this->ledger( $r ) );
	}

	public function test_a_run_that_stopped_after_one_row_completes_with_the_same_rows(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-11-20 12:30:00 UTC' );
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 600 ) );
		// The first row was written, the event states were not updated.
		Ledger::record( $r->id(), (int) $r->license()['user_id'], 'order', 500.0, 'order', 0.10, (int) strtotime( '2026-11-18 10:00:00 UTC' ), '' );

		$this->assertSame( 1, Meter::bill( $r, $now ) );
		$this->assertCount( 2, $this->ledger( $r ) );
		$this->assertSame( array( Store::BILLED => 600 ), $this->states( $r ) );
	}

	public function test_a_late_event_is_billed_behind_everything_already_billed(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-11-20 12:30:00 UTC' );
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 500 ) );
		Meter::bill( $r, $now );
		$billed = $this->ledger( $r );

		// A late event (even one about an old order) is received now and gets a later hour: step 2, the earlier hours do not move.
		$this->seed_billable( $r, array( '2026-11-20 08:00:00' => 1 ), 9000 );
		Meter::bill( $r, $now + 2 * DAY_IN_SECONDS );
		$rows = $this->ledger( $r );
		$this->assertSame( $billed[0], $rows[0], 'An invoiced hour is never rewritten.' );
		$this->assertSame( array( 'order_2', '1.0000' ), array( $rows[1]['item'], $rows[1]['quantity'] ) );
	}

	public function test_the_steps_start_again_each_month(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-12-05 12:30:00 UTC' );
		$this->seed_billable( $r, array( '2026-11-30 22:00:00' => 500, '2026-12-01 01:00:00' => 5 ) );
		Meter::bill( $r, $now );
		$rows = $this->ledger( $r );
		$this->assertSame( 'order', $rows[1]['item'], 'The first order of December is step 1 again.' );
		$this->assertSame( '5.0000', $rows[1]['quantity'] );
	}

	public function test_orders_in_the_hold_window_and_the_current_hour_are_not_billed(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-11-20 12:30:00 UTC' );
		$this->seed_billable( $r, array( '2026-11-20 06:00:00' => 4, '2026-11-20 12:00:00' => 4 ) );
		$this->assertSame( 0, Meter::bill( $r, $now ) );
		$this->assertSame( array( Store::BILLABLE => 8 ), $this->states( $r ) );
	}

	public function test_free_orders_do_not_count_towards_the_steps(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-11-20 12:30:00 UTC' );
		for ( $i = 1; $i <= 300; $i++ ) {
			Store::add( array( 'resource_id' => $r->id(), 'remote_seq' => $i, 'store_key' => 's', 'woo_order_id' => (string) $i, 'received_at' => (int) strtotime( '2026-11-17 10:00:00 UTC' ), 'state' => Store::FREE, 'free_reason' => 'intro' ) );
		}
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 450 ), 1000 );
		Meter::bill( $r, $now );
		$rows = $this->ledger( $r );
		$this->assertCount( 1, $rows, 'All 450 are in step 1.' );
		$this->assertSame( '450.0000', $rows[0]['quantity'] );
	}

	public function test_no_price_bills_nothing_and_keeps_the_events(): void {
		$r = $this->service();
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 3 ) );
		add_filter( 'beltoft_services_unit_steps', '__return_empty_array' );
		$this->assertSame( 0, Meter::bill( Resource::find( $r->id() ), (int) strtotime( '2026-11-20 12:30:00 UTC' ) ) );
		$this->assertSame( array( Store::BILLABLE => 3 ), $this->states( $r ) );
		$this->assertCount( 0, $this->ledger( $r ) );
	}

	public function test_billing_is_off_until_the_setting_is_on(): void {
		$r = $this->service();
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 3 ) );
		$this->settings( array( 'counted_billing' => 0 ) );
		$this->assertSame( 0, Meter::run( (int) strtotime( '2026-11-20 12:30:00 UTC' ) ) );
		$this->assertCount( 0, $this->ledger( $r ) );
	}

	// ------------------------------------------------------------ statement

	public function test_the_statement_shows_the_orders_of_each_step(): void {
		$r   = $this->service();
		$now = (int) strtotime( '2026-11-20 12:30:00 UTC' );
		$this->seed_billable( $r, array( '2026-11-18 10:00:00' => 1000, '2026-11-18 11:00:00' => 9000 ) );
		Meter::bill( $r, $now );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Ledger::table() . ' WHERE resource_id = %d', $r->id() ), ARRAY_A );
		$st   = Renewal::statement( $rows );

		$this->assertCount( 3, $st['lines'] );
		$units = array_column( $st['lines'], 'units', 'item' );
		$this->assertEquals( array( 'order' => 500, 'order_2' => 1500, 'order_3' => 8000 ), $units );
		$this->assertEquals( 285.0, $st['total'], 'No cap beyond the steps.' );
		$this->assertSame( 'Orders processed, step 2', Renewal::item_label( 'order_2' ) );
	}

	// ------------------------------------------------------------ view

	public function test_the_card_shows_the_intro_and_the_orders(): void {
		$r = $this->service();
		$this->remote( 2 );
		OrderPull::pull( $r, time() );
		ob_start();
		Views::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Free intro: 7 days left', $html );
		$this->assertStringContainsString( 'Orders so far', $html );
		$this->assertStringContainsString( 'Free (intro)', $html );
		$this->assertStringContainsString( 'S1', $html );
	}

	public function test_the_card_says_free_of_charge_for_a_free_rate_service(): void {
		$r = $this->service();
		$r->set( array( 'counted_free_rate' => 'yes' ) );
		$this->remote( 1 );
		OrderPull::pull( Resource::find( $r->id() ), time() );
		ob_start();
		Views::render( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'Free of charge', (string) ob_get_clean() );
	}

	public function test_nothing_is_shown_for_a_service_that_is_not_counted(): void {
		$r = $this->service();
		$this->variation->delete_meta_data( Rates::META_BILLING );
		$this->variation->save();
		ob_start();
		Views::render( Resource::find( $r->id() ) );
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
