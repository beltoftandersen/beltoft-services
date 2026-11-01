<?php
/**
 * The prepaid balance ledger.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Tests\TestCase;

/**
 * Idempotency, rounding, the cache, the lock and the schema.
 */
class BalanceTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Balance::table() );
	}

	private function user(): int {
		return (int) self::factory()->user->create( array( 'user_email' => 'christian.beltoft+credit' . wp_generate_password( 6, false ) . '@gmail.com' ) );
	}

	public function test_schema_has_table_unique_key_and_usage_column(): void {
		global $wpdb;

		$this->assertSame( array(), Schema::missing_keys() );
		$this->assertSame( '5', Schema::DB_VERSION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
		$cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::tables()['usage'] ) );
		$this->assertContains( 'credit_id', $cols );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
		$cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Balance::table() ) );
		$this->assertSame( array( 'id', 'user_id', 'kind', 'amount_cents', 'balance_after_cents', 'ref_type', 'ref_id', 'note', 'created' ), $cols );
	}

	public function test_upgrade_runs_on_an_install_with_an_older_schema_version(): void {
		update_option( Schema::OPTION_DB_VERSION, '4' );
		delete_transient( Schema::RETRY_TRANSIENT );

		Schema::maybe_upgrade();

		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_credit_and_debit_keep_a_running_balance(): void {
		$u = $this->user();

		$this->assertSame( 0, Balance::get( $u ) );
		$this->assertTrue( Balance::credit( $u, 2500, Balance::TOPUP, 'order_item', '1-1', 'Top-up' ) );
		$this->assertTrue( Balance::debit( $u, 123, Balance::INVOICE, 'order', $u . ':20261101' ) );

		$this->assertSame( 2377, Balance::get( $u ) );
		$this->assertSame( '2377', get_user_meta( $u, Balance::META, true ) );

		$rows = Balance::history( $u );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'invoice', $rows[0]['kind'] );
		$this->assertSame( '-123', $rows[0]['amount_cents'] );
		$this->assertSame( '2377', $rows[0]['balance_after_cents'] );
		$this->assertSame( '2500', $rows[1]['balance_after_cents'] );
	}

	public function test_the_same_reference_counts_once(): void {
		$u = $this->user();

		$this->assertTrue( Balance::credit( $u, 1000, Balance::TOPUP, 'order_item', '7-3' ) );
		$this->assertFalse( Balance::credit( $u, 1000, Balance::TOPUP, 'order_item', '7-3' ), 'A webhook that arrives again.' );
		$this->assertFalse( Balance::credit( $u, 5000, Balance::TOPUP, 'order_item', '7-3' ), 'Even with another amount.' );
		$this->assertSame( 1000, Balance::get( $u ) );
		$this->assertCount( 1, Balance::history( $u ) );
		$this->assertTrue( Balance::exists( Balance::TOPUP, 'order_item', '7-3' ) );
		$this->assertFalse( Balance::exists( Balance::TOPUP, 'order_item', '7-4' ) );
		// Another kind with the same reference is its own row.
		$this->assertTrue( Balance::debit( $u, 100, Balance::REFUND, 'order_item', '7-3' ) );
	}

	public function test_amounts_must_be_positive_cents_and_users_real(): void {
		$u = $this->user();

		$this->assertFalse( Balance::credit( $u, 0, Balance::TOPUP, 'x', '1' ) );
		$this->assertFalse( Balance::credit( $u, -5, Balance::TOPUP, 'x', '2' ) );
		$this->assertFalse( Balance::debit( $u, 0, Balance::INVOICE, 'x', '3' ) );
		$this->assertFalse( Balance::credit( 0, 100, Balance::TOPUP, 'x', '4' ) );
		$this->assertFalse( Balance::credit( $u, 100, Balance::TOPUP, 'x', '' ) );
		$this->assertSame( array(), Balance::history( $u ) );
	}

	public function test_the_balance_may_go_negative(): void {
		$u = $this->user();

		Balance::credit( $u, 500, Balance::TOPUP, 'x', '1' );
		Balance::debit( $u, 800, Balance::CHARGEBACK, 'x', '1' );

		$this->assertSame( -300, Balance::get( $u ) );
	}

	public function test_balances_are_per_customer(): void {
		$a = $this->user();
		$b = $this->user();

		Balance::credit( $a, 1000, Balance::TOPUP, 'x', 'a' );
		Balance::credit( $b, 400, Balance::TOPUP, 'x', 'b' );

		$this->assertSame( 1000, Balance::get( $a ) );
		$this->assertSame( 400, Balance::get( $b ) );
	}

	/**
	 * @dataProvider cents_provider
	 */
	public function test_cents_round_half_up_without_float_drift( $amount, int $expected ): void {
		$this->assertSame( $expected, Balance::cents( $amount ) );
	}

	/** @return array<string, array{0: mixed, 1: int}> */
	public function cents_provider(): array {
		return array(
			'whole'          => array( 25, 2500 ),
			'string'         => array( '12.34', 1234 ),
			'half cent up'   => array( 0.285, 29 ),
			'under half'     => array( 0.2849, 28 ),
			'drift 1.005'    => array( 1.005, 101 ),
			'drift 8.325'    => array( '8.325', 833 ),
			'sub cent'       => array( '0.004', 0 ),
			'big'            => array( '1234567.89', 123456789 ),
			'zero'           => array( 0, 0 ),
		);
	}

	public function test_the_cache_is_rebuilt_from_the_ledger_when_missing_or_wrong(): void {
		$u = $this->user();

		Balance::credit( $u, 900, Balance::TOPUP, 'x', '1' );
		delete_user_meta( $u, Balance::META );
		$this->assertSame( 900, Balance::get( $u ), 'Missing cache.' );

		update_user_meta( $u, Balance::META, 5 );
		$this->assertSame( 900, Balance::recount( $u ) );
		$this->assertSame( '900', get_user_meta( $u, Balance::META, true ) );
	}

	public function test_a_write_waits_for_the_customers_lock_and_gives_up_without_it(): void {
		global $wpdb;

		$u     = $this->user();
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection plays the other request.
		$name  = $other->real_escape_string( Locks::balance( $u ) );
		$other->query( "SELECT GET_LOCK( '$name', 0 )" );
		add_filter( 'beltoft_services_balance_lock_wait', '__return_zero' );

		$this->assertFalse( Balance::credit( $u, 1000, Balance::TOPUP, 'x', '1' ), 'Another request is writing this balance.' );
		$this->assertFalse( Balance::exists( Balance::TOPUP, 'x', '1' ) );
		$this->assertSame( 0, Balance::get( $u ) );

		// Another customer is not blocked.
		$this->assertTrue( Balance::credit( $this->user(), 100, Balance::TOPUP, 'x', '2' ) );

		$other->query( "SELECT RELEASE_LOCK( '$name' )" );
		$this->assertTrue( Balance::credit( $u, 1000, Balance::TOPUP, 'x', '1' ), 'The retry works once the lock is free.' );
		$this->assertSame( 1000, Balance::get( $u ) );
		$other->close();

		$free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', Locks::balance( $u ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lock check.
		$this->assertSame( '1', $free, 'The write released its lock.' );
	}

	public function test_two_racing_writers_of_one_reference_write_one_row(): void {
		global $wpdb;

		$u = $this->user();

		// The race the lock exists for: a writer that read the balance before the other one wrote. The unique key
		// still lets one row in, and the cached balance follows the row that was written.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- simulates the second writer, which got in first.
		$wpdb->insert( Balance::table(), array( 'user_id' => $u, 'kind' => 'topup', 'amount_cents' => 700, 'balance_after_cents' => 700, 'ref_type' => 'order_item', 'ref_id' => '9-9', 'note' => '', 'created' => gmdate( 'Y-m-d H:i:s' ) ) );

		$this->assertFalse( Balance::credit( $u, 700, Balance::TOPUP, 'order_item', '9-9' ) );
		$this->assertSame( 700, Balance::get( $u ) );
		$this->assertCount( 1, Balance::history( $u ) );
	}

	public function test_sequential_writes_never_lose_an_update(): void {
		$u = $this->user();

		for ( $i = 1; $i <= 25; $i++ ) {
			Balance::credit( $u, 100, Balance::TOPUP, 'x', (string) $i );
			Balance::debit( $u, 30, Balance::INVOICE, 'x', (string) $i );
		}

		$this->assertSame( 25 * 70, Balance::get( $u ) );
		$rows = array_reverse( Balance::history( $u, 100 ) );
		$run  = 0;
		foreach ( $rows as $row ) {
			$run += (int) $row['amount_cents'];
			$this->assertSame( (string) $run, $row['balance_after_cents'] );
		}
	}

	public function test_locked_is_reentrant(): void {
		$u = $this->user();

		list( $locked, $result ) = Balance::locked(
			$u,
			static function () use ( $u ) {
				return Balance::credit( $u, 100, Balance::TOPUP, 'x', '1' ) && Balance::debit( $u, 40, Balance::INVOICE, 'x', '1' );
			}
		);

		$this->assertTrue( $locked );
		$this->assertTrue( $result );
		$this->assertSame( 60, Balance::get( $u ) );
	}

	public function test_history_is_newest_first_and_limited(): void {
		$u = $this->user();

		for ( $i = 1; $i <= 30; $i++ ) {
			Balance::credit( $u, $i, Balance::TOPUP, 'x', (string) $i );
		}

		$h = Balance::history( $u, 20 );
		$this->assertCount( 20, $h );
		$this->assertSame( '30', $h[0]['amount_cents'] );
		$this->assertCount( 30, Balance::history( $u, 100 ) );
	}
}
