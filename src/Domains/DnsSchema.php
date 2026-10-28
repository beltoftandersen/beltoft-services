<?php
/**
 * Tables for verified DNS hosting: domain ownership claims and the one-holder assignments.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the two tables the way Proxmox\Schema does: dbDelta, then the keys that the lock
 * relies on are verified in place, and the schema version is stored only when they exist. The
 * feature stays off (see DnsClaimsFeature) until ready() is true.
 */
final class DnsSchema {

	public const DB_VERSION        = '1';
	public const OPTION_DB_VERSION = 'beltoft_services_dns_db_version';
	public const RETRY_TRANSIENT   = 'beltoft_services_dns_schema_retry';

	/**
	 * Keys that must exist, per table: key name => columns in order.
	 */
	private const UNIQUE_KEYS = array(
		'assignments' => array(
			'PRIMARY' => array( 'domain' ),
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
			'claims'      => $wpdb->prefix . 'beltoft_services_dns_claims',
			'assignments' => $wpdb->prefix . 'beltoft_services_dns_assignments',
		);
	}

	/**
	 * Creates or updates the tables when the stored version is behind.
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
	 * Is the schema in place: the stored version is current and the keys were verified.
	 */
	public static function ready(): bool {
		return self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) && array() === self::missing_keys();
	}

	/**
	 * Keys that are missing, as "table.key" names. Empty when all are in place.
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

		/**
		 * Filters the keys reported missing (lets a test simulate a failed verification).
		 *
		 * @param string[] $missing "table.key" names.
		 */
		return (array) apply_filters( 'beltoft_services_dns_missing_keys', $missing );
	}

	/**
	 * Creates or updates the tables with dbDelta and stores the version once the keys exist.
	 *
	 * The primary key on the assignment's domain is the real guard that one customer at a time
	 * holds a domain; the named lock only orders the checks.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();

		// Errors here would be printed during activation; a failure shows as a missing key below.
		$suppress = $wpdb->suppress_errors( true );

		dbDelta(
			"CREATE TABLE {$t['claims']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				domain varchar(253) NOT NULL,
				token varchar(64) NOT NULL,
				status varchar(20) NOT NULL,
				parent_ns longtext NULL,
				ds_present tinyint(1) NULL DEFAULT NULL,
				last_result longtext NULL,
				draft_records longtext NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				verified_at datetime NULL DEFAULT NULL,
				checked_at datetime NULL DEFAULT NULL,
				check_failures int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY user_domain (user_id,domain(191)),
				KEY status_expires (status,expires_at)
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['assignments']} (
				domain varchar(253) CHARACTER SET ascii NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				claim_id bigint(20) unsigned NULL DEFAULT NULL,
				status varchar(20) NOT NULL,
				reserved_until datetime NULL DEFAULT NULL,
				order_id bigint(20) unsigned NULL DEFAULT NULL,
				order_item_id bigint(20) unsigned NULL DEFAULT NULL,
				license_id bigint(20) unsigned NULL DEFAULT NULL,
				resource_id bigint(20) unsigned NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (domain),
				KEY user_status (user_id,status)
			) $collate;"
		);

		$wpdb->suppress_errors( $suppress );

		if ( array() === self::missing_keys() ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
			delete_transient( self::RETRY_TRANSIENT );
		}
	}
}
