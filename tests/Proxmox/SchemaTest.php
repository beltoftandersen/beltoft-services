<?php
/**
 * The plugin's tables.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Tests\TestCase;

/**
 * Schema creation, upgrade and uninstall.
 */
class SchemaTest extends TestCase {

	/**
	 * The test suite turns CREATE TABLE into CREATE TEMPORARY TABLE, so tables built here are
	 * temporary copies that hide the real ones. Drop them, and put the version back.
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( Schema::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
			$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table );
		}

		parent::tear_down();

		update_option( Schema::OPTION_DB_VERSION, Schema::DB_VERSION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keeps the restored version past the rollback.
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Keys of a table, unique ones only: name => columns.
	 *
	 * @param string $table Table.
	 * @return array<string, string[]>
	 */
	private function unique_keys( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$rows = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Non_unique = 0", ARRAY_A );
		$keys = array();
		foreach ( $rows as $row ) {
			$keys[ $row['Key_name'] ][] = $row['Column_name'];
		}

		return $keys;
	}

	public function test_the_bootstrap_created_both_tables_with_their_unique_keys(): void {
		$t = Schema::tables();

		$this->assertSame( array(), Schema::missing_keys() );
		$this->assertSame(
			array(
				'PRIMARY'     => array( 'id' ),
				'address'     => array( 'address' ),
				'resource_id' => array( 'resource_id' ),
			),
			$this->unique_keys( $t['ips'] )
		);
		$this->assertSame(
			array(
				'PRIMARY'     => array( 'id' ),
				'resource_id' => array( 'resource_id' ),
				'cluster_vm'  => array( 'cluster', 'vmid' ),
			),
			$this->unique_keys( $t['vms'] )
		);
	}

	public function test_schema_verifies_indexes_before_storing_version(): void {
		global $wpdb;

		// An older copy of the pool table without its unique keys (temporary, hiding the real one).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
		$wpdb->query(
			'CREATE TABLE ' . Schema::tables()['ips'] . ' (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				address varchar(45) NOT NULL,
				prefix tinyint(3) unsigned NOT NULL DEFAULT 32,
				gateway varchar(45) NULL DEFAULT NULL,
				state varchar(10) NOT NULL DEFAULT \'free\',
				resource_id bigint(20) unsigned NULL DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			)'
		);
		delete_option( Schema::OPTION_DB_VERSION );
		$this->assertSame( array( 'ips.address', 'ips.resource_id' ), Schema::missing_keys() );

		$break = static function ( $query ) {
			return false !== stripos( $query, 'ADD UNIQUE KEY' ) ? 'SELECT * FROM zz_no_such_table' : $query;
		};
		add_filter( 'query', $break );

		ob_start();
		Schema::activate();
		$output = (string) ob_get_clean();

		remove_filter( 'query', $break );

		$this->assertSame( '', $output, 'a failed key prints nothing' );
		$this->assertFalse( get_option( Schema::OPTION_DB_VERSION ), 'not marked as done while a key is missing' );

		Schema::maybe_upgrade();

		$this->assertSame( array(), Schema::missing_keys() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_boot_upgrades_when_the_version_is_behind(): void {
		delete_option( Schema::OPTION_DB_VERSION );

		\BeltoftServices\Plugin::boot();

		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_the_activation_hook_is_registered(): void {
		$this->assertNotFalse( has_action( 'activate_' . plugin_basename( BELTOFT_SERVICES_FILE ), array( Schema::class, 'activate' ) ) );
	}

	public function test_a_failing_upgrade_is_not_retried_on_every_request(): void {
		delete_option( Schema::OPTION_DB_VERSION );
		$runs  = 0;
		$count = static function ( $query ) use ( &$runs ) {
			if ( false !== stripos( $query, 'SHOW INDEX' ) ) {
				++$runs;
			}
			return $query;
		};
		// Simulate a persistent failure: the keys are never verified.
		$break = static function ( $query ) {
			return false !== stripos( $query, 'SHOW INDEX' ) ? 'SELECT * FROM zz_no_such_table' : $query;
		};
		add_filter( 'query', $count, 1 );
		add_filter( 'query', $break, 2 );

		ob_start();
		Schema::maybe_upgrade();
		$first = $runs;
		Schema::maybe_upgrade();
		Schema::maybe_upgrade();
		ob_end_clean();

		remove_filter( 'query', $count, 1 );
		remove_filter( 'query', $break, 2 );

		$this->assertFalse( get_option( Schema::OPTION_DB_VERSION ) );
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $runs, 'one attempt, then it waits' );
		$this->assertNotFalse( get_transient( Schema::RETRY_TRANSIENT ) );

		delete_transient( Schema::RETRY_TRANSIENT );
		Schema::maybe_upgrade();
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );
		$this->assertFalse( get_transient( Schema::RETRY_TRANSIENT ), 'success clears the throttle' );
	}

	/**
	 * Every transient family the plugin sets in src/ (one example each; checked against the code in review).
	 */
	private const TRANSIENTS = array(
		'beltoft_services_site_tag_alert_0123abcd',
		'beltoft_services_not_stored_5',
		'beltoft_services_usage_queued_5',
		'beltoft_services_http_usage_5',
		'beltoft_services_rl_0123abcd',
		'beltoft_services_conn_notice_1',
		'beltoft_services_ip_notice_1',
		'beltoft_services_cluster_notice_1',
		'beltoft_services_proxmox_abuse_queue',
		'beltoft_services_proxmox_abuse_since_5',
		'beltoft_services_proxmox_abuse_note_5_20261022',
		'beltoft_services_proxmox_status_5',
		'beltoft_services_proxmox_running_lab',
		'beltoft_services_proxmox_capacity_lab',
		'beltoft_services_proxmox_purge_ran',
		'beltoft_services_proxmox_schema_retry',
	);

	/**
	 * Runs uninstall.php and returns the queries it issued, as written (before the suite
	 * rewrites DROP TABLE into DROP TEMPORARY TABLE).
	 *
	 * @param int $delete_data The setting.
	 * @return string[]
	 */
	private function uninstall( int $delete_data ): array {
		$queries = array();
		$spy     = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		update_option( 'beltoft_services_settings', array( 'delete_data' => $delete_data ) );
		set_transient( 'beltoft_services_proxmox_ip_free', 4, 60 );
		update_option( 'beltoft_services_db_version', '1' );
		update_option( 'beltoft_services_proxmox_clusters', array( 'lab' => array() ) );
		update_option( 'beltoft_services_fake_proxmox', array( 'x' => 1 ) );
		set_transient( 'beltoft_services_proxmox_power_5', 'running', 60 );
		update_option( 'beltoft_services_breach_7', array( 'at' => 1, 'reason' => 'x', 'alerted' => 0 ), false );

		foreach ( self::TRANSIENTS as $name ) {
			set_transient( $name, 1, 600 );
		}

		set_transient( 'someone_elses_transient', 1, 600 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'beltoft-services/beltoft-services.php' );
		}

		add_filter( 'query', $spy, 1 );
		include BELTOFT_SERVICES_PATH . 'uninstall.php';
		remove_filter( 'query', $spy, 1 );

		return $queries;
	}

	public function test_uninstall_drops_tables_only_with_delete_data(): void {
		$t = Schema::tables();

		$queries = $this->uninstall( 0 );
		$this->assertSame( array(), preg_grep( '/DROP TABLE/i', $queries ), 'nothing dropped without the setting' );
		$this->assertSame( '1', get_option( 'beltoft_services_db_version' ) );
		$this->assertNotFalse( get_option( 'beltoft_services_proxmox_clusters' ) );
		$this->assertSame( 4, get_transient( 'beltoft_services_proxmox_ip_free' ) );
		$this->assertNotFalse( get_option( 'beltoft_services_breach_7' ), 'The breach marks stay without the setting.' );

		foreach ( self::TRANSIENTS as $name ) {
			$this->assertNotFalse( get_transient( $name ), $name . ' stays without the setting.' );
		}

		$queries = $this->uninstall( 1 );
		$drops   = array_values( preg_grep( '/DROP TABLE/i', $queries ) );
		$this->assertCount( 2, $drops );
		$this->assertStringContainsString( $t['ips'], implode( ' ', $drops ) );
		$this->assertStringContainsString( $t['vms'], implode( ' ', $drops ) );
		wp_cache_flush(); // The uninstall script deletes straight from the database.
		$this->assertFalse( get_option( 'beltoft_services_db_version' ) );
		$this->assertFalse( get_option( 'beltoft_services_proxmox_clusters' ) );
		$this->assertFalse( get_option( 'beltoft_services_fake_proxmox' ) );
		$this->assertFalse( get_transient( 'beltoft_services_proxmox_ip_free' ) );
		$this->assertFalse( get_transient( 'beltoft_services_proxmox_power_5' ) );
		$this->assertFalse( get_option( 'beltoft_services_breach_7' ), 'The breach marks go with the rest.' );

		foreach ( self::TRANSIENTS as $name ) {
			$this->assertFalse( get_transient( $name ), $name . ' is removed.' );
		}

		$this->assertEquals( 1, get_transient( 'someone_elses_transient' ), 'Other plugins\' transients are left alone.' );
	}
}
