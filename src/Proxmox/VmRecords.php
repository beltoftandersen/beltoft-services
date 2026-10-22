<?php
/**
 * Which resource owns which VMID on which cluster.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Locks;

defined( 'ABSPATH' ) || exit;

/**
 * The allocation index for VMIDs. Two provisions at the same moment get different ids: a named
 * lock serializes the choice, and the unique key on (cluster, vmid) refuses a duplicate even if
 * the lock fails. No transactions (a START TRANSACTION would commit the test suite's own).
 */
final class VmRecords {

	/**
	 * Seconds allocate() waits for the lock.
	 */
	private const LOCK_WAIT = 10;

	/**
	 * Most ids offered to the "is it free" assertion in one call.
	 */
	private const MAX_ASSERTIONS = 50;

	/**
	 * Gives a resource a VMID: the one it already has, or the lowest free id in the range.
	 *
	 * The id is recorded before the assertion runs and before anything is created, so no other
	 * provision can choose it. The assertion is the driver's `GET /cluster/nextid?vmid=<id>`:
	 * when it says the id is taken, the record is removed and the next id is tried.
	 *
	 * @param int      $resource_id      Activation id of the resource.
	 * @param string   $cluster          Cluster id.
	 * @param int      $min              Lowest VMID of the customer range.
	 * @param int      $max              Highest VMID of the customer range.
	 * @param int[]    $taken_on_cluster VMIDs the cluster lists (from /cluster/resources).
	 * @param callable $is_free          fn( int $vmid ): bool, asked once per candidate. It may throw: the record stays and a retry asks again.
	 * @return ?int The VMID, or null when the range is used up.
	 * @throws \RuntimeException When the allocation lock cannot be taken in time (not "no id": retry later).
	 */
	public static function allocate( int $resource_id, string $cluster, int $min, int $max, array $taken_on_cluster, callable $is_free ): ?int {
		global $wpdb;

		if ( $min < 1 || $max < $min ) {
			return null;
		}

		list( $locked, $vmid ) = Locks::with(
			Locks::name( 'vmid', 0 ),
			self::LOCK_WAIT,
			static function () use ( $wpdb, $resource_id, $cluster, $min, $max, $taken_on_cluster, $is_free ) {
				$existing = self::for_resource( $resource_id );

				if ( $existing ) {
					return $existing['vmid'];
				}

				$table = Schema::tables()['vms'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- allocation state must be read fresh.
				$recorded = $wpdb->get_col( $wpdb->prepare( 'SELECT vmid FROM %i WHERE cluster = %s AND vmid BETWEEN %d AND %d', $table, $cluster, $min, $max ) );
				$skip     = array_flip( array_map( 'intval', array_merge( $taken_on_cluster, $recorded ) ) );
				$asked    = 0;

				for ( $id = $min; $id <= $max && $asked < self::MAX_ASSERTIONS; $id++ ) {
					if ( isset( $skip[ $id ] ) ) {
						continue;
					}

					$suppress = $wpdb->suppress_errors( true );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the unique key is the backstop.
					$inserted = $wpdb->insert(
						$table,
						array(
							'resource_id' => $resource_id,
							'cluster'     => $cluster,
							'vmid'        => $id,
							'created_at'  => gmdate( 'Y-m-d H:i:s' ),
						),
						array( '%d', '%s', '%d', '%s' )
					);
					$wpdb->suppress_errors( $suppress );

					if ( ! $inserted ) {
						continue; // Somebody holds it (or the resource got a record meanwhile): not an assertion.
					}

					++$asked;

					if ( $is_free( $id ) ) {
						return $id;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- undoing our own record.
					$wpdb->delete( $table, array( 'resource_id' => $resource_id ), array( '%d' ) );
				}

				return null;
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The VMID allocation is busy.' );
		}

		return null === $vmid ? null : (int) $vmid;
	}

	/**
	 * The record of a resource.
	 *
	 * @param int $resource_id Activation id.
	 * @return ?array{id: int, resource_id: int, cluster: string, node: string, vmid: int, ip_id: ?int, created_at: string}
	 */
	public static function for_resource( int $resource_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- allocation state must be read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d', Schema::tables()['vms'], $resource_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'          => (int) $row['id'],
			'resource_id' => (int) $row['resource_id'],
			'cluster'     => (string) $row['cluster'],
			'node'        => (string) $row['node'],
			'vmid'        => (int) $row['vmid'],
			'ip_id'       => null === $row['ip_id'] ? null : (int) $row['ip_id'],
			'created_at'  => (string) $row['created_at'],
		);
	}

	/**
	 * Whether this VMID on this cluster is recorded for this resource.
	 *
	 * @param string $cluster     Cluster id.
	 * @param int    $vmid        VMID.
	 * @param int    $resource_id Activation id.
	 */
	public static function owned( string $cluster, int $vmid, int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership must be read fresh.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE cluster = %s AND vmid = %d AND resource_id = %d', Schema::tables()['vms'], $cluster, $vmid, $resource_id ) );
	}

	/**
	 * Records the node a machine now runs on.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $node        Node name.
	 * @return bool Whether a record exists for the resource.
	 */
	public static function move( int $resource_id, string $node ): bool {
		return self::update( $resource_id, array( 'node' => $node ), array( '%s' ) );
	}

	/**
	 * Records the pool address of a machine.
	 *
	 * @param int $resource_id Activation id.
	 * @param int $ip_id       Row id in the IP pool.
	 * @return bool Whether a record exists for the resource.
	 */
	public static function set_ip( int $resource_id, int $ip_id ): bool {
		return self::update( $resource_id, array( 'ip_id' => $ip_id ), array( '%d' ) );
	}

	/**
	 * Updates fields of a resource's record.
	 *
	 * @param int                  $resource_id Activation id.
	 * @param array<string, mixed> $data        Columns.
	 * @param string[]             $formats     Formats.
	 */
	private static function update( int $resource_id, array $data, array $formats ): bool {
		global $wpdb;

		if ( null === self::for_resource( $resource_id ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		return false !== $wpdb->update( Schema::tables()['vms'], $data, array( 'resource_id' => $resource_id ), $formats, array( '%d' ) );
	}

	/**
	 * Deletes a resource's record, which frees its VMID.
	 *
	 * @param int $resource_id Activation id.
	 * @return bool Whether a record was removed.
	 */
	public static function remove( int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		return (int) $wpdb->delete( Schema::tables()['vms'], array( 'resource_id' => $resource_id ), array( '%d' ) ) > 0;
	}
}
