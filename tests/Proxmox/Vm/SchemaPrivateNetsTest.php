<?php
/**
 * Version 7 of the schema: several private networks per customer (a name column, no unique key on user_id).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Vm;

use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Tests\TestCase;

/**
 * The table here is a temporary copy of the version 6 table (the suite turns CREATE TABLE into CREATE TEMPORARY TABLE).
 */
final class SchemaPrivateNetsTest extends TestCase {

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Schema::tables()['private_nets'] );

		parent::tear_down();

		update_option( Schema::OPTION_DB_VERSION, Schema::DB_VERSION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keeps the restored version past the rollback.
		$wpdb->query( 'COMMIT' );
	}

	/** A version 6 copy of the table with two rows, as old code left it. */
	private function version_6_table(): void {
		global $wpdb;

		$table = Schema::tables()['private_nets'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table );
		$wpdb->query(
			'CREATE TABLE ' . $table . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NULL DEFAULT NULL,
				vlan smallint(5) unsigned NOT NULL,
				cidr varchar(18) NOT NULL,
				state varchar(12) NOT NULL DEFAULT 'free',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				UNIQUE KEY vlan (vlan),
				UNIQUE KEY cidr (cidr),
				KEY state (state,id)
			)"
		);
		$wpdb->query( 'INSERT INTO ' . $table . " (user_id, vlan, cidr, state, updated_at) VALUES (7, 1000, '10.64.0.0/28', 'held', '2026-11-01 10:00:00'), (NULL, 1001, '10.64.0.16/28', 'free', '2026-11-01 10:00:00')" );
		// phpcs:enable

		update_option( Schema::OPTION_DB_VERSION, '6' );
		delete_transient( Schema::RETRY_TRANSIENT );
	}

	/** Index names of the table, with whether each is unique. */
	private function indexes(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', Schema::tables()['private_nets'] ), ARRAY_A );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[ $row['Key_name'] ] = 0 === (int) $row['Non_unique'];
		}

		return $out;
	}

	public function test_a_fresh_install_has_the_name_column_and_a_plain_user_key(): void {
		$this->assertSame( '7', Schema::DB_VERSION );
		$this->assertSame( array(), Schema::missing_columns() );
		$this->assertFalse( Schema::private_user_key_unique() );
		$this->assertFalse( $this->indexes()['user_id'] );
	}

	public function test_upgrade_from_version_6_drops_the_unique_key_adds_the_name_and_keeps_the_rows(): void {
		global $wpdb;

		$this->version_6_table();
		$this->assertTrue( Schema::private_user_key_unique() );
		$this->assertSame( array( 'private_nets.name' ), Schema::missing_columns() );

		Schema::maybe_upgrade();

		$this->assertSame( array(), Schema::missing_columns() );
		$this->assertFalse( Schema::private_user_key_unique() );
		$this->assertArrayHasKey( 'user_id', $this->indexes(), 'A plain key of the same name is in place.' );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, vlan, state, name FROM %i ORDER BY id', Schema::tables()['private_nets'] ), ARRAY_A );
		// phpcs:enable
		$this->assertSame( array( array( 'user_id' => '7', 'vlan' => '1000', 'state' => 'held', 'name' => '' ), array( 'user_id' => null, 'vlan' => '1001', 'state' => 'free', 'name' => '' ) ), $rows );

		// A customer can now hold two rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test write.
		$this->assertSame( 1, $wpdb->update( Schema::tables()['private_nets'], array( 'user_id' => 7, 'state' => 'held' ), array( 'vlan' => 1001 ) ) );
	}

	public function test_the_version_is_stored_only_when_the_unique_key_is_gone(): void {
		$this->version_6_table();

		$break = static function ( $query ) {
			return false !== stripos( $query, 'DROP INDEX' ) ? 'SELECT * FROM zz_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		Schema::maybe_upgrade();
		remove_filter( 'query', $break );

		$this->assertTrue( Schema::private_user_key_unique(), 'The key is still unique.' );
		$this->assertSame( '6', get_option( Schema::OPTION_DB_VERSION ), 'Not marked done while the unique key is there.' );
		$this->assertNotFalse( get_transient( Schema::RETRY_TRANSIENT ), 'A retry is planned.' );

		delete_transient( Schema::RETRY_TRANSIENT );
		Schema::maybe_upgrade();

		$this->assertFalse( Schema::private_user_key_unique() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_running_the_upgrade_again_changes_nothing(): void {
		$this->version_6_table();
		Schema::maybe_upgrade();
		$indexes = $this->indexes();

		update_option( Schema::OPTION_DB_VERSION, '6' );
		delete_transient( Schema::RETRY_TRANSIENT );
		Schema::maybe_upgrade();

		$this->assertSame( $indexes, $this->indexes() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );
	}
}
