<?php
/**
 * Capacity cache job: committed memory per node (see the formula in ProxmoxJobs), hourly and after a cluster is saved.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Vm\ProxmoxJobs;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Capacity cache job: committed memory per node (see the formula in ProxmoxJobs), hourly and after a cluster is saved.
 */
final class CapacityJobs {

	/**
	 * Hourly: caches each cluster's nodes (see the formula in the class comment) for Placement and
	 * the stock check. A cluster that cannot be read keeps its last cache.
	 */
	public static function capacity(): void {
		foreach ( JobScope::clusters() as $id => $one ) {
			self::cache_capacity( $id, $one['client'] );
		}
		// Stale extra-disk reservations of closed or missing disks are freed (local, no remote call).
		DiskCapacity::sweep();
	}

	/**
	 * Activation hook: queues a capacity run for every cluster, so the stock is not closed until
	 * the first hourly run (a plugin that is activated again finds its clusters already saved).
	 * Action Scheduler may not be ready yet; the run is then queued when it is.
	 */
	public static function activate(): void {
		if ( class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			self::queue_all_capacity();
			return;
		}

		add_action( 'action_scheduler_init', array( __CLASS__, 'queue_all_capacity' ) );
	}

	/**
	 * Queues the capacity run of each saved cluster (a run already waiting is enough).
	 */
	public static function queue_all_capacity(): void {
		foreach ( array_keys( Clusters::all() ) as $id ) {
			self::queue_capacity( (string) $id );
		}
	}

	/**
	 * Queues a capacity run for one cluster (after it was saved), so its stock is not closed
	 * until the next hourly run. A run already waiting is enough.
	 *
	 * @param string $id Cluster id.
	 */
	public static function queue_capacity( string $id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( ProxmoxJobs::CAPACITY_NOW, array( $id ), Jobs::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( ProxmoxJobs::CAPACITY_NOW, array( $id ), Jobs::GROUP );
	}

	/**
	 * The queued run for one cluster.
	 *
	 * @param string $id Cluster id.
	 */
	public static function capacity_now( $id ): void {
		$all = JobScope::clusters();

		if ( isset( $all[ (string) $id ] ) ) {
			self::cache_capacity( (string) $id, $all[ (string) $id ]['client'] );
		}
	}

	/**
	 * Reads one cluster and writes its cache. Committed memory also counts the VMs recorded for
	 * this site that the listing does not show yet (a clone just placed), at the memory of their
	 * plan, on the node they were placed on. The write takes the lock Capacity::reserve() uses.
	 *
	 * @param string $id     Cluster id.
	 * @param Client $client Client.
	 */
	private static function cache_capacity( string $id, Client $client ): void {
		$reserve = max( 0, (int) Settings::get( 'host_reserve_gb' ) ) * 1073741824;
		$nodes   = $client->request( 'GET', '/nodes' );
		$vms     = JobScope::vm_rows( $client );

		if ( ! is_array( $nodes ) || null === $vms ) {
			return; // Never cache a guess: the old cache and the stored stock status stay as they are until it can be read.
		}

		list( $locked ) = Locks::with(
			Locks::name( 'capacity', 0 ),
			Locks::allocation_wait(),
			static function () use ( $id, $nodes, $vms, $reserve ): bool {
				$records   = VmRecords::rows_for_cluster( $id );
				$ours      = array_column( $records, 'resource_id', 'vmid' );
				$listed    = array();
				$committed = array();

				foreach ( $vms as $vm ) {
					$listed[ (int) ( $vm['vmid'] ?? 0 ) ] = true;

					if ( ! empty( $vm['template'] ) || ! isset( $ours[ (int) ( $vm['vmid'] ?? 0 ) ] ) ) {
						continue;
					}

					$node               = (string) ( $vm['node'] ?? '' );
					$committed[ $node ] = ( $committed[ $node ] ?? 0 ) + (int) ( $vm['maxmem'] ?? 0 );
				}

				foreach ( $records as $record ) {
					if ( isset( $listed[ $record['vmid'] ] ) || '' === $record['node'] ) {
						continue; // Listed (counted above), or not placed yet.
					}

					$resource = Resource::find( $record['resource_id'] );

					if ( ! $resource || ! $resource->is_live() ) {
						continue; // Deleted (or closed): nothing is coming.
					}

					$plan = $resource->get( 'plan' );
					$mb       = is_array( $plan ) ? (int) ( $plan['memory_mb'] ?? 0 ) : 0;

					$committed[ $record['node'] ] = ( $committed[ $record['node'] ] ?? 0 ) + $mb * 1048576;
				}

				$rows = array();

				foreach ( $nodes as $node ) {
					if ( ! is_array( $node ) || '' === (string) ( $node['node'] ?? '' ) ) {
						continue;
					}

					$name   = (string) $node['node'];
					$rows[] = array(
						'node'     => $name,
						'status'   => (string) ( $node['status'] ?? '' ),
						'maxmem'   => (int) ( $node['maxmem'] ?? 0 ),
						'mem'      => ( $committed[ $name ] ?? 0 ) + $reserve,
						'mem_used' => (int) ( $node['mem'] ?? 0 ),
						'maxcpu'   => (int) ( $node['maxcpu'] ?? 0 ),
						'cpu'      => (float) ( $node['cpu'] ?? 0 ),
					);
				}

				set_transient( Placement::CAPACITY_PREFIX . $id, $rows, 2 * HOUR_IN_SECONDS );

				return true;
			}
		);

		$cluster = Clusters::get( $id );
		if ( $cluster && ! empty( $cluster['storage'] ) && DiskCapacity::wanted() ) {
			foreach ( $nodes as $node ) {
				if ( is_array( $node ) && 'online' === ( $node['status'] ?? '' ) && ! empty( $node['node'] ) ) {
					DiskCapacity::refresh( $id, (string) $node['node'], (string) $cluster['storage'], $client );
				}
			}
		}
		// Committed memory moved: tell the admin where a node is over its budget (Capacity::overage_alerts()).
		if ( $locked ) {
			Capacity::overage_alerts( $id );
		}
	}
}
