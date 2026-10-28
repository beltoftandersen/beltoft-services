<?php
/**
 * This plugin's own Proxmox allocation tables.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the allocation tables, the way beltoft-licenses' Install does: dbDelta, and the
 * schema version is stored only once the unique keys are verified in place, so a failed attempt
 * is tried again on the next request. (Not named Install: Retention and Resource import
 * BeltoftLicenses\Install.)
 */
final class Schema {

	public const DB_VERSION        = '2';
	public const OPTION_DB_VERSION = 'beltoft_services_db_version';
	public const RETRY_TRANSIENT   = 'beltoft_services_proxmox_schema_retry';

	/**
	 * Unique keys that allocation relies on, per table: key name => columns in order.
	 */
	private const UNIQUE_KEYS = array(
		'ips' => array(
			'address'     => array( 'address' ),
			'resource_id' => array( 'resource_id' ),
		),
		'vms' => array(
			'resource_id' => array( 'resource_id' ),
			'cluster_vm'  => array( 'cluster', 'vmid' ),
		),
		'private_nets' => array(
			'user_id' => array( 'user_id' ),
			'vlan'    => array( 'vlan' ),
			'cidr'    => array( 'cidr' ),
		),
		'private_ips' => array(
			'resource_id' => array( 'resource_id' ),
			'net_address' => array( 'net_id', 'address' ),
		),
	);

	/**
	 * Table names.
	 *
	 * @return array<string, string>
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'ips' => $wpdb->prefix . 'beltoft_services_ips',
			'vms' => $wpdb->prefix . 'beltoft_services_vms',
			'private_nets' => $wpdb->prefix . 'beltoft_services_private_nets',
			'private_ips' => $wpdb->prefix . 'beltoft_services_private_ips',
		);
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::create_tables();
	}

	/**
	 * Creates or updates the tables when the stored schema version is behind. An update never
	 * runs the activation hook, so Plugin::boot() calls this.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) || false !== get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}

		self::create_tables();

		// A persistent failure is tried again in an hour, not on every request.
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			set_transient( self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Unique keys that are missing, as "table.key" names. Empty when all are in place.
	 *
	 * @return string[]
	 */
	public static function missing_keys(): array {
		global $wpdb;

		$tables  = self::tables();
		$missing = array();

		foreach ( self::UNIQUE_KEYS as $table => $keys ) {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check on our own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Non_unique = 0', $tables[ $table ] ), ARRAY_A );
			$wpdb->suppress_errors( $suppress );

			$found = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$found[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
			}

			foreach ( $keys as $name => $columns ) {
				if ( ! isset( $found[ $name ] ) || array_values( $found[ $name ] ) !== $columns ) {
					$missing[] = $table . '.' . $name;
				}
			}
		}

		return $missing;
	}

	/**
	 * Creates or updates the tables with dbDelta, and stores the schema version once the unique
	 * keys exist.
	 *
	 * An address is held by one resource at most (`resource_id` is unique; many NULLs are fine),
	 * and a VMID is recorded once per cluster. Those keys are the backstop under the named locks.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();

		// Errors here would be printed during activation; a failure shows as a missing key below.
		$suppress = $wpdb->suppress_errors( true );

		dbDelta(
			"CREATE TABLE {$t['ips']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				address varchar(45) NOT NULL,
				prefix tinyint(3) unsigned NOT NULL DEFAULT 32,
				gateway varchar(45) NULL DEFAULT NULL,
				state varchar(10) NOT NULL DEFAULT 'free',
				resource_id bigint(20) unsigned NULL DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY address (address),
				UNIQUE KEY resource_id (resource_id),
				KEY state (state,id)
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['vms']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id bigint(20) unsigned NOT NULL,
				cluster varchar(40) NOT NULL,
				node varchar(64) NOT NULL DEFAULT '',
				vmid int(11) unsigned NOT NULL,
				ip_id bigint(20) unsigned NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY resource_id (resource_id),
				UNIQUE KEY cluster_vm (cluster,vmid)
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['private_nets']} (
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
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['private_ips']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				net_id bigint(20) unsigned NOT NULL,
				resource_id bigint(20) unsigned NOT NULL,
				address varchar(45) NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY resource_id (resource_id),
				UNIQUE KEY net_address (net_id,address)
			) $collate;"
		);

		$wpdb->suppress_errors( $suppress );

		if ( array() === self::missing_keys() ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
			delete_transient( self::RETRY_TRANSIENT );
		}
	}
}
