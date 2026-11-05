<?php
/**
 * Which hosting account sits on which node, container, address and port.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Locks;

defined( 'ABSPATH' ) || exit;

/**
 * The allocation index of the hosting accounts. A named lock serializes every choice (container id, address,
 * SSH port) and the unique keys of the table refuse a duplicate even if the lock fails. No transactions (a
 * START TRANSACTION would commit the test suite's own).
 */
final class Records {

	/**
	 * Most insert or update attempts (failed ones included) in one allocation.
	 */
	private const MAX_ATTEMPTS = 50;

	/**
	 * The record of a resource, or null.
	 *
	 * @param int $resource_id Activation id.
	 * @return array{id: int, resource_id: int, node: string, vmid: ?int, ip: ?string, ssh_port: ?int, db_host: string}|null
	 */
	public static function for_resource( int $resource_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- allocation state must be read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d', Schema::tables()['hosting'], $resource_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'          => (int) $row['id'],
			'resource_id' => (int) $row['resource_id'],
			'node'        => (string) $row['node'],
			'vmid'        => null === $row['vmid'] ? null : (int) $row['vmid'],
			'ip'          => null === $row['ip'] ? null : (string) $row['ip'],
			'ssh_port'    => null === $row['ssh_port'] ? null : (int) $row['ssh_port'],
			'db_host'     => (string) $row['db_host'],
		);
	}

	/**
	 * Records the node a resource was placed on. The first placement stays: a later call returns the stored
	 * record whatever node it is given.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $node        Node record id.
	 * @return array<string, mixed>|null Null when the row could not be stored.
	 */
	public static function open( int $resource_id, string $node ): ?array {
		global $wpdb;

		$existing = self::for_resource( $resource_id );

		if ( $existing ) {
			return $existing;
		}

		$now      = gmdate( 'Y-m-d H:i:s' );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the unique key is the backstop.
		$wpdb->insert(
			Schema::tables()['hosting'],
			array(
				'resource_id' => $resource_id,
				'node'        => $node,
				'created'     => $now,
				'updated'     => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		return self::for_resource( $resource_id );
	}

	/**
	 * How many accounts a node holds.
	 *
	 * @param string $node Node record id.
	 */
	public static function count_on_node( string $node ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a rare read of our own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE node = %s', Schema::tables()['hosting'], $node ) );
	}

	/**
	 * The resource ids on a node.
	 *
	 * @param string $node Node record id.
	 * @return int[]
	 */
	public static function resource_ids( string $node ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a rare read of our own table.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT resource_id FROM %i WHERE node = %s ORDER BY id', Schema::tables()['hosting'], $node ) ) );
	}

	/**
	 * Gives a resource a container id: the one it has, or the lowest free id of the node's range. The id is
	 * recorded before the assertion runs and before anything is created, so no other provision can choose it.
	 *
	 * @param int      $resource_id Activation id.
	 * @param int      $min         Lowest id of the range.
	 * @param int      $max         Highest id of the range.
	 * @param int[]    $taken       Ids the node lists.
	 * @param callable $is_free     fn( int $vmid ): bool, asked once per candidate. It may throw: the record stays and a retry asks again.
	 * @return ?int The id, or null when the range is used up.
	 * @throws \RuntimeException When the allocation lock cannot be taken in time.
	 */
	public static function allocate_vmid( int $resource_id, int $min, int $max, array $taken, callable $is_free ): ?int {
		global $wpdb;

		if ( $min < 1 || $max < $min ) {
			return null;
		}

		list( $locked, $vmid ) = Locks::with(
			Locks::name( 'hosting-vmid', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $resource_id, $min, $max, $taken, $is_free ) {
				$record = self::for_resource( $resource_id );

				if ( ! $record ) {
					return null;
				}

				if ( null !== $record['vmid'] ) {
					return $record['vmid'];
				}

				$table = Schema::tables()['hosting'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- allocation state must be read fresh.
				$recorded = $wpdb->get_col( $wpdb->prepare( 'SELECT vmid FROM %i WHERE node = %s AND vmid BETWEEN %d AND %d', $table, $record['node'], $min, $max ) );
				$skip     = array_flip( array_map( 'intval', array_merge( $taken, $recorded ) ) );
				$asked    = 0;

				for ( $id = $min; $id <= $max && $asked < self::MAX_ATTEMPTS; $id++ ) {
					if ( isset( $skip[ $id ] ) ) {
						continue;
					}

					$suppress = $wpdb->suppress_errors( true );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the unique key is the backstop.
					$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET vmid = %d, updated = %s WHERE resource_id = %d AND vmid IS NULL', $table, $id, gmdate( 'Y-m-d H:i:s' ), $resource_id ) );
					$wpdb->suppress_errors( $suppress );

					++$asked;

					if ( ! $updated ) {
						continue;
					}

					if ( $is_free( $id ) ) {
						return $id;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- undoing our own record.
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET vmid = NULL WHERE resource_id = %d', $table, $resource_id ) );
				}

				return null;
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The container id allocation is busy.' );
		}

		return null === $vmid ? null : (int) $vmid;
	}

	/**
	 * Stores one allocated value (address or port) of a resource, once: an existing value stays.
	 *
	 * @param int        $resource_id Activation id.
	 * @param string     $column      ip or ssh_port.
	 * @param string|int $value       Value.
	 * @return bool True when the row now holds that value.
	 */
	public static function claim( int $resource_id, string $column, $value ): bool {
		global $wpdb;

		if ( ! in_array( $column, array( 'ip', 'ssh_port' ), true ) ) {
			return false;
		}

		$table    = Schema::tables()['hosting'];
		$format   = 'ip' === $column ? '%s' : '%d';
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column is one of two literals; the unique key is the backstop.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET {$column} = {$format}, updated = %s WHERE resource_id = %d AND {$column} IS NULL", $table, $value, gmdate( 'Y-m-d H:i:s' ), $resource_id ) );
		$wpdb->suppress_errors( $suppress );

		$record = self::for_resource( $resource_id );

		return $record && (string) $value === (string) $record[ $column ];
	}

	/**
	 * Values already used on a node.
	 *
	 * @param string $node   Node record id.
	 * @param string $column ip or ssh_port.
	 * @return array<int, string>
	 */
	public static function used( string $node, string $column ): array {
		global $wpdb;

		if ( ! in_array( $column, array( 'ip', 'ssh_port' ), true ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column is one of two literals.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM %i WHERE node = %s AND {$column} IS NOT NULL", Schema::tables()['hosting'], $node ) ) );
	}

	/**
	 * Remembers the database host of an account.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $host        Host.
	 */
	public static function set_db_host( int $resource_id, string $host ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->update( Schema::tables()['hosting'], array( 'db_host' => $host, 'updated' => gmdate( 'Y-m-d H:i:s' ) ), array( 'resource_id' => $resource_id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/**
	 * Removes the record of a resource: its address, port and container id are free again.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function remove( int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		return false !== $wpdb->delete( Schema::tables()['hosting'], array( 'resource_id' => $resource_id ), array( '%d' ) );
	}
}
