<?php
/**
 * This plugin's own Proxmox allocation tables.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Currency;
use BeltoftServices\Locks;
use BeltoftServices\VersionedSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the allocation tables, the way beltoft-licenses' Install does: dbDelta, and the
 * schema version is stored only once the unique keys are verified in place, so a failed attempt
 * is tried again on the next request. (Not named Install: Retention and Resource import
 * BeltoftLicenses\Install.)
 */
final class Schema {

	use VersionedSchema;

	public const DB_VERSION        = '6';
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
		'ip_log' => array(
			'PRIMARY' => array( 'id' ),
		),
		'usage' => array(
			'resource_item_hour' => array( 'resource_id', 'item', 'hour_bucket' ),
		),
		'credit' => array(
			'kind_ref' => array( 'kind', 'ref_type', 'ref_id' ),
		),
	);

	/**
	 * Columns that the code writes and that a version may be stored only once they exist, per table.
	 * Version 6: the currency of a ledger row. An empty value reads as the shop's currency (Currency::of_row()).
	 */
	private const REQUIRED_COLUMNS = array(
		'usage'  => array( 'currency' ),
		'credit' => array( 'currency' ),
	);

	/** Rows per backfill statement, and the seconds one request may spend on the backfill. */
	private const BACKFILL_BATCH   = 5000;
	private const BACKFILL_SECONDS = 15;

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
			'usage' => $wpdb->prefix . 'bs_usage',
			'credit' => $wpdb->prefix . 'bs_credit',
			'ip_log' => $wpdb->prefix . 'beltoft_services_ip_log',
		);
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::create_tables();
	}

	/**
	 * Unique keys that are missing, as "table.key" names. Empty when all are in place.
	 *
	 * @return string[]
	 */
	public static function missing_keys(): array {
		return self::unique_keys_missing();
	}

	/**
	 * Required columns that are missing, as "table.column" names. Empty when all are in place.
	 *
	 * @return string[]
	 */
	public static function missing_columns(): array {
		global $wpdb;

		$tables  = self::tables();
		$missing = array();

		foreach ( self::REQUIRED_COLUMNS as $table => $columns ) {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check on our own table.
			$found = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $tables[ $table ] ) );
			$wpdb->suppress_errors( $suppress );

			foreach ( $columns as $column ) {
				if ( ! in_array( $column, is_array( $found ) ? $found : array(), true ) ) {
					$missing[] = $table . '.' . $column;
				}
			}
		}

		return $missing;
	}

	/**
	 * Whether the code may write the version 6 columns: the stored version says they exist and are filled.
	 * A site whose upgrade has not finished (or failed) keeps writing rows the old way, which read the same.
	 */
	public static function has_currency(): bool {
		return self::DB_VERSION === get_option( self::OPTION_DB_VERSION );
	}

	/**
	 * Sets the shop's currency on the ledger rows that have none, in keyset windows (each step reads forward from the last id it
	 * handled, so a table is walked once whatever its ids look like) and under a lock that is not waited for. Idempotent: only empty
	 * values are written, and an empty value reads as the shop's currency anyway, so stopping at any point loses nothing.
	 *
	 * A shop currency that is not a three letter code is treated like an empty one: nothing to set, the backfill counts as done.
	 *
	 * @return bool True when every row was visited (or there is nothing to set); false when it stopped early, failed or another request holds the lock.
	 */
	private static function backfill_currency(): bool {
		global $wpdb;

		$base = Currency::clean( Currency::base() );

		if ( '' === $base ) {
			return true;
		}

		$tables   = self::tables();
		$batch    = max( 1, (int) apply_filters( 'beltoft_services_schema_backfill_batch', self::BACKFILL_BATCH ) );
		$deadline = microtime( true ) + (float) apply_filters( 'beltoft_services_schema_backfill_seconds', self::BACKFILL_SECONDS );

		list( $locked, $done ) = Locks::with(
			Locks::name( 'schema_backfill', 0 ),
			0,
			static function () use ( $wpdb, $tables, $base, $batch, $deadline ): bool {
				foreach ( array( 'usage', 'credit' ) as $key ) {
					$last = 0;

					while ( true ) {
						if ( microtime( true ) >= $deadline ) {
							return false;
						}

						$suppress = $wpdb->suppress_errors( true );
						// The id that ends this window: the batch-th empty row after the last one handled. None left means the window is the rest of the table.
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
						$end = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id > %d AND currency = '' ORDER BY id LIMIT 1 OFFSET %d", $tables[ $key ], $last, $batch - 1 ) );

						if ( '' !== (string) $wpdb->last_error ) {
							$wpdb->suppress_errors( $suppress );
							return false;
						}

						if ( null === $end ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
							$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET currency = %s WHERE id > %d AND currency = ''", $tables[ $key ], $base, $last ) );
							$wpdb->suppress_errors( $suppress );

							if ( false === $result ) {
								return false;
							}

							break;
						}

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
						$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET currency = %s WHERE id > %d AND id <= %d AND currency = ''", $tables[ $key ], $base, $last, (int) $end ) );
						$wpdb->suppress_errors( $suppress );

						if ( false === $result ) {
							return false;
						}

						$last = (int) $end;
					}
				}

				return true;
			}
		);

		return $locked && true === $done;
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

		// Who held which address and when (public and private), for abuse reports. Rows are only added and closed.
		dbDelta(
			"CREATE TABLE {$t['ip_log']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(7) NOT NULL,
				address varchar(45) NOT NULL,
				resource_id bigint(20) unsigned NOT NULL,
				started_at datetime NOT NULL,
				ended_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY address (address,started_at),
				KEY resource_open (resource_id,kind,ended_at)
			) $collate;"
		);

		// The pay-as-you-go ledger: one row per resource, item and UTC hour (the unique key keeps the meter idempotent).
		dbDelta(
			"CREATE TABLE {$t['usage']} (
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
				currency char(3) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY resource_item_hour (resource_id,item,hour_bucket),
				KEY user_open (user_id,order_id,hour_bucket),
				KEY order_id (order_id)
			) $collate;"
		);

		// The customer's account credit balance: an append-only ledger per customer. The unique key makes every write idempotent.
		dbDelta(
			"CREATE TABLE {$t['credit']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				kind varchar(12) NOT NULL,
				amount_cents bigint(20) NOT NULL,
				balance_after_cents bigint(20) NOT NULL,
				ref_type varchar(20) NOT NULL DEFAULT '',
				ref_id varchar(64) NOT NULL DEFAULT '',
				note varchar(255) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				currency char(3) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY kind_ref (kind,ref_type,ref_id),
				KEY user_id (user_id,id)
			) $collate;"
		);

		$wpdb->suppress_errors( $suppress );

		// The version is stored only when the keys and the new columns are in place and the backfill finished: a failed ALTER or an unfinished backfill is tried again.
		if ( array() === self::missing_keys() && array() === self::missing_columns() && self::backfill_currency() ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
			delete_transient( self::RETRY_TRANSIENT );
		}
	}
}
