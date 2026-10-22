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
 * Proxmox: with no cache there is no answer (Placement waits; Stock keeps the stored status and queues a run).
 *
 * Self-healing: the job recounts committed memory from scratch every hour (listed VMs of this
 * site, plus recorded VMs that are placed but not listed yet and whose resource is still live).
 * A reservation made by place() for a clone that then failed (its record removed) or for a resource
 * that was deleted is therefore gone at the next run, and the cache never drifts for long.
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
		$cluster = Clusters::all()[ $cluster_id ] ?? null; // Only the node list is needed: no secret is decrypted.
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

	/**
	 * Records a placement and commits its memory in the cache, in one go under the lock the
	 * capacity job writes with: the memory is added only when `$record` succeeded, so a failed
	 * record is not counted and its retry counts once. With no cache nothing is added (the job's
	 * next run counts the recorded VM itself).
	 *
	 * @param string   $cluster_id Cluster id.
	 * @param string   $node       Node.
	 * @param int      $bytes      Memory.
	 * @param callable $record     fn(): bool, stores the node in the VM record.
	 * @return bool Whether the placement was recorded.
	 * @throws \RuntimeException When the lock stays busy.
	 */
	public static function place( string $cluster_id, string $node, int $bytes, callable $record ): bool {
		list( $locked, $ok ) = \BeltoftServices\Locks::with(
			\BeltoftServices\Locks::name( 'capacity', 0 ),
			\BeltoftServices\Locks::allocation_wait(),
			static function () use ( $cluster_id, $node, $bytes, $record ): bool {
				if ( ! $record() ) {
					return false;
				}

				$rows = self::rows( $cluster_id );

				if ( null === $rows ) {
					return true;
				}

				foreach ( $rows as $i => $row ) {
					if ( (string) ( $row['node'] ?? '' ) === $node ) {
						$rows[ $i ]['mem'] = (int) ( $row['mem'] ?? 0 ) + max( 0, $bytes );
					}
				}

				set_transient( Placement::CAPACITY_PREFIX . $cluster_id, $rows, 2 * HOUR_IN_SECONDS );

				return true;
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The capacity cache is busy.' );
		}

		if ( $ok ) {
			Stock::sync( $cluster_id ); // The committed memory moved: the plan that no longer fits closes.
		}

		return (bool) $ok;
	}
}
