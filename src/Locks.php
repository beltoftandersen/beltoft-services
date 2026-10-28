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
	 * The lock name for one domain name, case-insensitive (at most 64 chars).
	 *
	 * @param string $domain Domain name.
	 */
	public static function domain( string $domain ): string {
		global $wpdb;

		return 'bs_' . self::site_hash( DB_NAME, (string) $wpdb->prefix ) . '_dnsd_' . md5( strtolower( $domain ) );
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

	/**
	 * Seconds that IP pool and VMID allocation wait for their lock (default 10).
	 */
	public static function allocation_wait(): int {
		/**
		 * Filters how long IP pool and VMID allocation wait for their lock.
		 *
		 * @param int $seconds Seconds (default 10).
		 */
		return max( 0, (int) apply_filters( 'beltoft_services_alloc_lock_wait', 10 ) );
	}

	/**
	 * Runs a callback while holding a named lock. Used by code that must read and write one
	 * thing atomically without a transaction (a START TRANSACTION would also commit the test
	 * suite's own). The lock is released even when the callback throws.
	 *
	 * @param string   $name     Lock name from name().
	 * @param int      $timeout  Seconds to wait for the lock.
	 * @param callable $callback Work to do.
	 * @return array{0: bool, 1: mixed} Whether the lock was taken, and the callback's result.
	 */
	public static function with( string $name, int $timeout, callable $callback ): array {
		if ( ! self::acquire( $name, $timeout ) ) {
			return array( false, null );
		}

		try {
			return array( true, $callback() );
		} finally {
			self::release( $name );
		}
	}

	/**
	 * Takes a named database lock.
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait.
	 */
	private static function acquire( string $name, int $timeout ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, $timeout ) );
	}

	/**
	 * Releases a named database lock.
	 *
	 * @param string $name Lock name.
	 */
	private static function release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
	}
}
