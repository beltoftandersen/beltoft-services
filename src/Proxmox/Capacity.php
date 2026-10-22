<?php
/**
 * Reads the cached node capacity of a cluster.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that reads the capacity transient written by ProxmoxJobs::capacity() (rows with
 * node, status, maxmem and `mem`, the committed memory plus the host reserve). It never calls
 * Proxmox: with no cache there is no answer, and callers treat that as "no room".
 */
final class Capacity {

	/**
	 * The cached node rows of a cluster, or null when there is no cache.
	 *
	 * @param string $cluster_id Cluster id.
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function rows( string $cluster_id ): ?array {
		$rows = get_transient( Placement::CAPACITY_PREFIX . $cluster_id );

		return is_array( $rows ) && array() !== $rows ? array_values( array_filter( $rows, 'is_array' ) ) : null;
	}

	/**
	 * Free memory in bytes (maxmem minus committed) of each online, allowed node.
	 *
	 * @param string $cluster_id Cluster id.
	 * @return array<string, int>|null Node => free bytes; null when there is no cache or the cluster is unknown.
	 */
	public static function free_by_node( string $cluster_id ): ?array {
		$cluster = Clusters::get( $cluster_id );
		$rows    = self::rows( $cluster_id );

		if ( null === $cluster || null === $rows ) {
			return null;
		}

		$allowed = array_map( 'strval', (array) $cluster['nodes'] );
		$free    = array();

		foreach ( $rows as $row ) {
			$name = (string) ( $row['node'] ?? '' );

			if ( in_array( $name, $allowed, true ) && 'online' === (string) ( $row['status'] ?? '' ) ) {
				$free[ $name ] = (int) ( $row['maxmem'] ?? 0 ) - (int) ( $row['mem'] ?? 0 );
			}
		}

		return $free;
	}

	/**
	 * Does one allowed node have this much free memory? False with no cache.
	 *
	 * @param string $cluster_id Cluster id.
	 * @param int    $bytes      Memory needed.
	 */
	public static function fits( string $cluster_id, int $bytes ): bool {
		foreach ( (array) self::free_by_node( $cluster_id ) as $free ) {
			if ( $free >= $bytes ) {
				return true;
			}
		}

		return false;
	}
}
