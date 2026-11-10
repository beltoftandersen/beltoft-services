<?php
/**
 * The upgrade and key check that the plugin's own table sets share.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * For a schema class with DB_VERSION, OPTION_DB_VERSION, RETRY_TRANSIENT and UNIQUE_KEYS constants and tables() and
 * create_tables() methods: runs the upgrade at most once an hour while it fails, and lists the unique keys that are
 * not in place.
 */
trait VersionedSchema {

	/**
	 * Creates or upgrades the tables when the stored version is not the current one.
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
	private static function unique_keys_missing(): array {
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
}
