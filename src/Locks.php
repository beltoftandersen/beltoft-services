<?php
/**
 * Names of the MySQL named locks.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL lock names are global to the database server, not per database. Every name therefore
 * carries a short hash of this site's database name and table prefix, so two sites on one
 * server (staging and production, or parallel test runs) never block each other's
 * resources that happen to share an id.
 */
final class Locks {

	/**
	 * The lock name for one thing of this site: bs_<site hash>_<scope>_<id> (at most 64 chars).
	 *
	 * @param string $scope What is locked, e.g. 'resource' or 'license'.
	 * @param int    $id    Its id.
	 */
	public static function name( string $scope, int $id ): string {
		global $wpdb;

		return 'bs_' . self::site_hash( DB_NAME, (string) $wpdb->prefix ) . '_' . $scope . '_' . $id;
	}

	/**
	 * Short hash of a database name and table prefix.
	 *
	 * @param string $database Database name.
	 * @param string $prefix   Table prefix.
	 */
	public static function site_hash( string $database, string $prefix ): string {
		return substr( md5( $database . '|' . $prefix ), 0, 10 );
	}
}
