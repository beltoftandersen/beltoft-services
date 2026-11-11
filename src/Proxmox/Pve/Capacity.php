<?php
/**
 * Reads the cached node capacity of a cluster.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

use BeltoftServices\Alerts;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that reads the capacity transient written by CapacityJobs::capacity() (rows with
 * node, status, maxmem and `mem`, the committed memory plus the host reserve). It never calls
 * Proxmox: with no cache there is no answer (Placement waits). Capacity never stops a sale: the numbers
 * decide where a machine goes (Placement) and what the admin is told (overage_alerts()), nothing else.
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
			Alerts::quietly( static fn() => self::overage_alerts( $cluster_id ) ); // The committed memory moved: tell the admin when a node is over its budget; a failing alert never fails the placement.
		}

		return (bool) $ok;
	}

	/**
	 * Mails the admin when the memory committed on a node passes its budget (the node memory minus the host reserve, setting
	 * `host_reserve_gb`), and again at each further 10 percent, once per step, forgotten when the share falls back under the
	 * step, and a step is forgotten only when the share has fallen 5 points under it (Alerts::overage()). Reads the cache only.
	 *
	 * @param string $cluster_id Cluster id.
	 * @return string[] Nodes a mail was sent for.
	 */
	public static function overage_alerts( string $cluster_id ): array {
		$cluster = Clusters::all()[ $cluster_id ] ?? null;
		$rows    = self::rows( $cluster_id );
		$sent    = array();

		if ( null === $cluster || null === $rows ) {
			return $sent;
		}

		$reserve = max( 0, (int) Settings::get( 'host_reserve_gb' ) ) * 1024;
		$allowed = array_map( 'strval', (array) $cluster['nodes'] );

		// A node taken out of the cluster list no longer keeps a remembered step.
		Alerts::overage_prune( 'pve:' . $cluster_id . ':', array_map( static fn( $n ) => 'pve:' . $cluster_id . ':' . $n . ':memory', $allowed ) );

		foreach ( $rows as $row ) {
			$name = (string) ( $row['node'] ?? '' );
			$max  = (int) ( $row['maxmem'] ?? 0 ) / 1048576;
			$mem  = (int) ( $row['mem'] ?? 0 ) / 1048576;

			if ( ! in_array( $name, $allowed, true ) || 'online' !== (string) ( $row['status'] ?? '' ) || $max - $reserve <= 0 ) {
				continue;
			}

			$budget    = $max - $reserve;
			$committed = max( 0.0, $mem - $reserve );

			$ok = Alerts::overage(
				'pve:' . $cluster_id . ':' . $name . ':memory',
				$committed / $budget,
				/* translators: 1: node name, 2: cluster id. */
				sprintf( __( 'Node %1$s of cluster %2$s is over its committed memory budget', 'beltoft-services' ), $name, $cluster_id ),
				array(
					/* translators: 1: node name, 2: cluster id, 3: committed in GB, 4: budget in GB. */
					sprintf( __( 'Node %1$s of cluster %2$s has %3$.1f GB of memory committed to servers of this site, a budget of %4$.1f GB.', 'beltoft-services' ), $name, $cluster_id, $committed / 1024, $budget / 1024 ),
					/* translators: 1: node memory in GB, 2: reserve in GB. */
					sprintf( __( 'The budget is the memory of the node (%1$.1f GB) minus the setting "Memory kept free on each node, in GB" (%2$.1f GB).', 'beltoft-services' ), $max / 1024, $reserve / 1024 ),
					__( 'Nothing is stopped: new servers are placed on the node with the most room left. Add a node or raise the capacity.', 'beltoft-services' ),
				)
			);

			if ( $ok ) {
				$sent[] = $name;
			}
		}

		return $sent;
	}
}
