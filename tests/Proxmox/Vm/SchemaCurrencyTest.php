<?php
/**
 * Version 6 of the schema: the currency column of the usage and credit ledgers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Vm;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Currency;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Tests\TestCase;
use BeltoftServices\Usage\Ledger;

/**
 * The tables here are temporary copies of the version 5 tables (the suite turns CREATE TABLE into CREATE TEMPORARY TABLE).
 */
final class SchemaCurrencyTest extends TestCase {

	public function tear_down(): void {
		global $wpdb;

		foreach ( array( 'usage', 'credit' ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Schema::tables()[ $key ] );
		}

		parent::tear_down();

		update_option( Schema::OPTION_DB_VERSION, Schema::DB_VERSION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keeps the restored version past the rollback.
		$wpdb->query( 'COMMIT' );
	}

	/** Columns of a table. */
	private function columns( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
		return $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	}

	/** Version 5 copies of both tables, with rows, as old code left them. */
	private function version_5_tables(): void {
		global $wpdb;

		$t = Schema::tables();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $t['usage'] );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $t['credit'] );
		$wpdb->query(
			'CREATE TABLE ' . $t['usage'] . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				item varchar(20) NOT NULL,
				quantity decimal(12,4) NOT NULL DEFAULT 1,
				unit varchar(10) NOT NULL DEFAULT 'hour',
				rate decimal(14,8) NOT NULL DEFAULT 0,
				hour_bucket datetime NOT NULL,
				order_id bigint(20) unsigned NULL DEFAULT NULL,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY resource_item_hour (resource_id,item,hour_bucket),
				KEY user_open (user_id,order_id,hour_bucket),
				KEY order_id (order_id)
			)"
		);
		$wpdb->query(
			'CREATE TABLE ' . $t['credit'] . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				kind varchar(12) NOT NULL,
				amount_cents bigint(20) NOT NULL,
				balance_after_cents bigint(20) NOT NULL,
				ref_type varchar(20) NOT NULL DEFAULT '',
				ref_id varchar(64) NOT NULL DEFAULT '',
				note varchar(255) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY kind_ref (kind,ref_type,ref_id),
				KEY user_id (user_id,id)
			)"
		);
		$wpdb->query( 'INSERT INTO ' . $t['usage'] . " (resource_id, user_id, item, rate, hour_bucket, created) VALUES (1, 7, 'server', 0.05, '2026-11-01 10:00:00', '2026-11-01 11:00:00'), (1, 7, 'server', 0.05, '2026-11-01 11:00:00', '2026-11-01 12:00:00')" );
		$wpdb->query( 'INSERT INTO ' . $t['credit'] . " (user_id, kind, amount_cents, balance_after_cents, ref_id, created) VALUES (7, 'topup', 500, 500, 'a', '2026-11-01 10:00:00')" );
		// phpcs:enable

		update_option( Schema::OPTION_DB_VERSION, '5' );
		delete_transient( Schema::RETRY_TRANSIENT );
	}

	/** Currency values of a table, one per row in id order. */
	private function values( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return $wpdb->get_col( 'SELECT currency FROM ' . $table . ' ORDER BY id' );
	}

	public function test_a_fresh_install_has_the_columns(): void {
		$t = Schema::tables();

		$this->assertSame( '6', Schema::DB_VERSION );
		$this->assertContains( 'currency', $this->columns( $t['usage'] ) );
		$this->assertContains( 'currency', $this->columns( $t['credit'] ) );
		$this->assertSame( array(), Schema::missing_columns() );
	}

	public function test_upgrade_from_version_5_adds_the_columns_and_backfills_the_shop_currency(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->version_5_tables();
		$t = Schema::tables();

		$this->assertSame( array( 'usage.currency', 'credit.currency' ), Schema::missing_columns() );

		Schema::maybe_upgrade();

		$this->assertSame( array(), Schema::missing_columns() );
		$this->assertSame( '6', get_option( Schema::OPTION_DB_VERSION ) );
		$this->assertSame( array( 'EUR', 'EUR' ), $this->values( $t['usage'] ) );
		$this->assertSame( array( 'EUR' ), $this->values( $t['credit'] ) );
	}

	public function test_the_upgrade_changes_nothing_but_the_new_column(): void {
		global $wpdb;

		$this->version_5_tables();
		$t = Schema::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$before = $wpdb->get_results( 'SELECT id, resource_id, user_id, item, quantity, unit, rate, hour_bucket, order_id, created FROM ' . $t['usage'], ARRAY_A );

		Schema::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$after = $wpdb->get_results( 'SELECT id, resource_id, user_id, item, quantity, unit, rate, hour_bucket, order_id, created FROM ' . $t['usage'], ARRAY_A );

		$this->assertSame( $before, $after );
	}

	public function test_running_the_upgrade_twice_changes_nothing(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->version_5_tables();
		$t = Schema::tables();

		Schema::create_tables();
		$columns = $this->columns( $t['usage'] );
		$values  = $this->values( $t['usage'] );
		Schema::create_tables();

		$this->assertSame( $columns, $this->columns( $t['usage'] ) );
		$this->assertSame( $values, $this->values( $t['usage'] ) );
		$this->assertSame( '6', get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_the_backfill_never_touches_a_value_that_is_set(): void {
		global $wpdb;

		update_option( 'woocommerce_currency', 'EUR' );
		$this->version_5_tables();
		Schema::create_tables();
		$t = Schema::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test write.
		$wpdb->query( 'UPDATE ' . $t['usage'] . " SET currency = 'DKK' WHERE id = 1" );

		Schema::create_tables();

		$this->assertSame( array( 'DKK', 'EUR' ), $this->values( $t['usage'] ) );
	}

	public function test_a_failed_alter_does_not_store_the_version(): void {
		$this->version_5_tables();

		$break = static function ( $query ) {
			return false !== stripos( $query, 'ADD COLUMN currency' ) ? 'SELECT * FROM zz_no_such_table' : $query;
		};
		add_filter( 'query', $break );

		ob_start();
		Schema::maybe_upgrade();
		ob_end_clean();

		remove_filter( 'query', $break );

		$this->assertSame( '5', get_option( Schema::OPTION_DB_VERSION ), 'not marked done while a column is missing' );
		$this->assertFalse( Schema::has_currency() );
		$this->assertNotFalse( get_transient( Schema::RETRY_TRANSIENT ) );

		delete_transient( Schema::RETRY_TRANSIENT );
		Schema::maybe_upgrade();

		$this->assertSame( '6', get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_a_failed_backfill_keeps_the_version_and_resumes(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->version_5_tables();
		$t = Schema::tables();

		$break = static function ( $query ) {
			return 0 === stripos( ltrim( $query ), 'UPDATE' ) && false !== stripos( $query, "currency = 'EUR'" ) ? 'SELECT * FROM zz_no_such_table' : $query;
		};
		add_filter( 'query', $break );

		ob_start();
		Schema::maybe_upgrade();
		ob_end_clean();

		remove_filter( 'query', $break );

		$this->assertSame( '5', get_option( Schema::OPTION_DB_VERSION ) );

		delete_transient( Schema::RETRY_TRANSIENT );
		Schema::maybe_upgrade();

		$this->assertSame( '6', get_option( Schema::OPTION_DB_VERSION ) );
		$this->assertSame( array( 'EUR', 'EUR' ), $this->values( $t['usage'] ) );
	}

	public function test_a_request_that_finds_the_backfill_locked_does_not_store_the_version(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->version_5_tables();

		// A second connection holds the lock, as a concurrent request would.
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( \BeltoftServices\Locks::name( 'schema_backfill', 0 ) ) . "', 0)" );

		Schema::create_tables();

		$this->assertSame( '5', get_option( Schema::OPTION_DB_VERSION ) );

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( \BeltoftServices\Locks::name( 'schema_backfill', 0 ) ) . "')" );
		$other->close();

		Schema::create_tables();

		$this->assertSame( '6', get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_a_row_written_by_old_code_reads_as_the_shop_currency(): void {
		global $wpdb;

		update_option( 'woocommerce_currency', 'EUR' );
		$t = Schema::tables();
		// Old code lists its columns and never the new one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test write.
		$wpdb->query( 'INSERT INTO ' . $t['credit'] . " (user_id, kind, amount_cents, balance_after_cents, ref_type, ref_id, note, created) VALUES (9, 'topup', 100, 100, 'x', 'old', '', '2026-11-01 10:00:00')" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test write.
		$wpdb->query( 'INSERT INTO ' . $t['usage'] . " (resource_id, user_id, item, rate, hour_bucket, created) VALUES (99, 9, 'server', 0.05, '2026-11-01 10:00:00', '2026-11-01 11:00:00')" );

		$credit = Balance::history( 9, 1 )[0];
		$usage  = Ledger::unbilled( 9, strtotime( '2026-11-02 00:00:00 UTC' ) )[0];

		$this->assertSame( '', $credit['currency'] );
		$this->assertSame( 'EUR', Currency::of_row( $credit ) );
		$this->assertSame( 'EUR', Currency::of_row( $usage ) );
		$this->assertSame( 'EUR', Currency::of_row( array() ) );
		$this->assertSame( 'DKK', Currency::of_row( array( 'currency' => 'DKK' ) ) );
	}

	public function test_new_rows_carry_the_shop_currency_and_balances_are_unchanged(): void {
		update_option( 'woocommerce_currency', 'EUR' );

		$this->assertTrue( Ledger::record( 55, 11, 'server', 1.0, 'hour', 0.05, strtotime( '2026-11-01 10:00:00 UTC' ) ) );
		$this->assertTrue( Balance::credit( 11, 500, Balance::TOPUP, 'order_item', 'new' ) );

		$this->assertSame( 'EUR', Currency::of_row( Ledger::unbilled( 11, strtotime( '2026-11-02 00:00:00 UTC' ) )[0] ) );
		$this->assertSame( 'EUR', Balance::history( 11, 1 )[0]['currency'] );
		$this->assertSame( 500, Balance::get( 11 ) );
	}

	public function test_while_the_upgrade_is_unfinished_writers_use_the_old_columns(): void {
		update_option( Schema::OPTION_DB_VERSION, '5' );
		$this->assertFalse( Schema::has_currency() );

		$this->assertTrue( Ledger::record( 56, 12, 'server', 1.0, 'hour', 0.05, strtotime( '2026-11-01 10:00:00 UTC' ) ) );
		$this->assertSame( '', Ledger::unbilled( 12, strtotime( '2026-11-02 00:00:00 UTC' ) )[0]['currency'] );
	}
}
