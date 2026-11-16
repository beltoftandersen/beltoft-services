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
			self::alert_failed_upgrade();
		}
	}

	/**
	 * Tells the admin once per schema version that the upgrade did not finish, with what is missing. Without it a restricted
	 * database account makes a deploy look fine while features that need the new tables or columns stay off.
	 */
	private static function alert_failed_upgrade(): void {
		$alerted = get_option( 'beltoft_services_schema_alerted', array() );
		$alerted = is_array( $alerted ) ? $alerted : array();

		if ( ( $alerted[ self::class ] ?? '' ) === self::DB_VERSION ) {
			return;
		}

		$missing = array_merge(
			method_exists( self::class, 'missing_keys' ) ? (array) self::missing_keys() : array(),
			method_exists( self::class, 'missing_columns' ) ? (array) self::missing_columns() : array()
		);

		$lines = array(
			sprintf( 'The database upgrade of %s to version %s did not finish.', self::class, self::DB_VERSION ),
			'Missing: ' . ( $missing ? implode( ', ', array_map( 'strval', $missing ) ) : 'the version could not be stored (see the database error log)' ),
			'It is tried again every hour. Check that the database account may create tables and alter them.',
		);

		if ( Alerts::notify( 'The database upgrade did not finish', $lines, null, '', array( 'fallback' => true ) ) ) {
			$alerted[ self::class ] = self::DB_VERSION;
			update_option( 'beltoft_services_schema_alerted', $alerted, false );
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
