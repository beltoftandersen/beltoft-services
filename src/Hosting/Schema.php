<?php
/**
 * The hosting allocation tables.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the WordPress hosting tables with dbDelta, the way Proxmox\Vm\Schema does, with its own
 * version option so the two schemas never wait for each other. The version is stored only once the unique keys
 * are verified in place: they are the backstop under the allocation locks.
 */
final class Schema {

	public const DB_VERSION        = '2';
	public const OPTION_DB_VERSION = 'beltoft_services_hosting_db_version';
	public const RETRY_TRANSIENT   = 'beltoft_services_hosting_schema_retry';

	/**
	 * Unique keys that allocation relies on, per table: key name => columns in order.
	 */
	private const UNIQUE_KEYS = array(
		'hosting'       => array(
			'resource_id' => array( 'resource_id' ),
			'node_vmid'   => array( 'node', 'vmid' ),
			'node_ip'     => array( 'node', 'ip' ),
			'node_port'   => array( 'node', 'ssh_port' ),
		),
		'hosting_sites' => array(
			'resource_slug' => array( 'resource_id', 'slug' ),
			'slug'          => array( 'slug' ),
		),
		'hosting_domains' => array(
			'host' => array( 'host' ),
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
			'hosting'       => $wpdb->prefix . 'beltoft_services_hosting',
			'hosting_sites' => $wpdb->prefix . 'beltoft_services_hosting_sites',
			'hosting_domains' => $wpdb->prefix . 'beltoft_services_hosting_domains',
		);
	}

	/**
	 * Creates or updates the tables when the stored schema version is behind.
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
	 * Creates or updates the tables, and stores the schema version once the unique keys exist.
	 *
	 * `hosting` holds one row per hosting account: the node record it lives on, the container id, its private
	 * address and public SSH port. The address, port and container id are unique per node (several NULLs are
	 * fine: they are filled in step by step). `hosting_sites` holds the websites of an account.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();

		$suppress = $wpdb->suppress_errors( true );

		dbDelta(
			"CREATE TABLE {$t['hosting']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id bigint(20) unsigned NOT NULL,
				node varchar(40) NOT NULL,
				vmid int(11) unsigned NULL DEFAULT NULL,
				ip varchar(45) NULL DEFAULT NULL,
				ssh_port smallint(5) unsigned NULL DEFAULT NULL,
				db_host varchar(64) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				updated datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY resource_id (resource_id),
				UNIQUE KEY node_vmid (node,vmid),
				UNIQUE KEY node_ip (node,ip),
				UNIQUE KEY node_port (node,ssh_port)
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['hosting_sites']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id bigint(20) unsigned NOT NULL,
				slug varchar(32) NOT NULL,
				label varchar(100) NOT NULL DEFAULT '',
				php varchar(8) NOT NULL DEFAULT '',
				workers smallint(5) unsigned NOT NULL DEFAULT 0,
				flags text NULL,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY resource_slug (resource_id,slug),
				UNIQUE KEY slug (slug)
			) $collate;"
		);

		// The names of a site: its preview name, a domain on our name servers, an external domain.
		dbDelta(
			"CREATE TABLE {$t['hosting_domains']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource_id bigint(20) unsigned NOT NULL,
				site_id bigint(20) unsigned NOT NULL,
				host varchar(253) NOT NULL,
				kind varchar(10) NOT NULL,
				www_mode varchar(10) NOT NULL DEFAULT '',
				state varchar(10) NOT NULL DEFAULT 'pending',
				cert_state varchar(10) NOT NULL DEFAULT '',
				checked datetime NULL DEFAULT NULL,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY host (host),
				KEY resource_id (resource_id),
				KEY site_id (site_id)
			) $collate;"
		);

		$wpdb->suppress_errors( $suppress );

		if ( array() === self::missing_keys() ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
			delete_transient( self::RETRY_TRANSIENT );
		}
	}
}
