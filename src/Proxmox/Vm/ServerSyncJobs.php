<?php
/**
 * Sweep jobs that bring servers to the standard display and to their plan's NIC speed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Proxmox\Db\DbActions;
use BeltoftServices\Proxmox\Db\DbIp;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\JobScope;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Sweep jobs that bring servers to the standard display and to their plan's NIC speed.
 */
final class ServerSyncJobs {

	/**
	 * From the sweep: gives every server the standard display (see display()). One cluster listing per cluster.
	 */
	public static function displays(): void {
		foreach ( JobScope::clusters() as $id => $one ) {
			$rows = JobScope::vm_rows( $one['client'] );

			if ( null === $rows ) {
				continue;
			}

			$ours = VmRecords::for_cluster( $id );

			foreach ( $rows as $vm ) {
				$vmid = (int) ( $vm['vmid'] ?? 0 );

				if ( ! empty( $vm['template'] ) || ! isset( $ours[ $vmid ] ) || '' !== (string) ( $vm['lock'] ?? '' ) ) {
					continue;
				}

				$resource = Resource::find( (int) $ours[ $vmid ] );

				if ( $resource ) {
					self::display( $resource, $vm );
				}
			}
		}
	}

	/**
	 * Writes `vga=std` to one server that has the serial display (servers built before the standard display), once:
	 * the `display_std` mark is set when it is written, or when the server already had another display. On a running
	 * server Proxmox keeps it pending until the next restart: that is done for us, and `display_pending_at` lets the
	 * console tab say so. NEVER restarts a server. Left alone: a server that is not ready, adopted (linked), on hold,
	 * or locked by a running operation.
	 *
	 * @param Resource             $r  Server resource.
	 * @param array<string, mixed> $vm Cluster row.
	 */
	private static function display( Resource $r, array $vm ): void {
		if ( null !== $r->get( 'display_std' ) || 'ready' !== $r->get( 'state' ) || true === $r->get( 'linked' ) || $r->on_hold() || '' === (string) ( $vm['node'] ?? '' ) ) {
			return;
		}

		$running = 'running' === (string) ( $vm['status'] ?? '' );

		$r->locked(
			static function () use ( $r, $running ): bool {
				// Fresh under the lock: an operation may have started since the cluster row was read.
				if ( null !== $r->get( 'display_std' ) || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || true === $r->get( 'linked' ) ) {
					return false;
				}

				$written = ( new Operations( $r ) )->reconcile_display();

				if ( null === $written ) {
					return false;
				}

				return $r->set( array( 'display_std' => time(), 'display_pending_at' => $written && $running ? time() : null ) );
			}
		);
	}

	/**
	 * From the sweep: brings every server's public NIC to its plan's speed limit (see net_rate()). One cluster listing
	 * per cluster; a VM is read only when it is due.
	 */
	public static function net_rates(): void {
		foreach ( JobScope::clusters() as $id => $one ) {
			$rows = JobScope::vm_rows( $one['client'] );

			if ( null === $rows ) {
				continue;
			}

			$ours = VmRecords::for_cluster( $id );

			foreach ( $rows as $vm ) {
				$vmid = (int) ( $vm['vmid'] ?? 0 );

				if ( ! empty( $vm['template'] ) || ! isset( $ours[ $vmid ] ) || '' !== (string) ( $vm['lock'] ?? '' ) ) {
					continue;
				}

				$resource = Resource::find( (int) $ours[ $vmid ] );

				if ( $resource ) {
					self::net_rate( $resource, $vm );
				}
			}
		}
	}

	/**
	 * Brings one server's public NIC to its plan's speed limit (servers built before the limit existed, or a limit
	 * changed on the NIC by hand): the same rewrite as a plan change, with no restart. Left alone: a server that is
	 * not ready, adopted (linked), on hold, locked by a running operation, or with no known speed (stored plan or product). A checked
	 * server is looked at again after a day, or at once when the plan's speed changed.
	 *
	 * @param Resource             $r  Server resource.
	 * @param array<string, mixed> $vm Cluster row.
	 */
	private static function net_rate( Resource $r, array $vm ): void {
		if ( 'ready' !== $r->get( 'state' ) || true === $r->get( 'linked' ) || $r->on_hold() || '' === (string) ( $vm['node'] ?? '' ) ) {
			return;
		}

		// The stored plan's speed, or the product's current one for a server built before the field.
		$mbit = Operations::effective_mbit( $r );

		if ( null === $mbit ) {
			return;
		}

		if ( null !== $r->get( 'net_rate_mbit' ) && (float) $r->get( 'net_rate_mbit' ) === $mbit && (int) $r->get( 'net_rate_at' ) > time() - DAY_IN_SECONDS ) {
			return;
		}

		$r->locked(
			static function () use ( $r, $mbit ): bool {
				// Fresh under the lock: an operation may have started since the cluster row was read.
				if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || true === $r->get( 'linked' ) ) {
					return false;
				}

				if ( ! ( new Operations( $r ) )->reconcile_rate() ) {
					return false;
				}

				return $r->set( array( 'net_rate_mbit' => $mbit, 'net_rate_at' => time() ) );
			}
		);
	}

	/** Servers looked at per sweep run for IPv6: a hundred servers do not make a hundred writes in one request. */
	public const IPV6_PER_RUN = 20;

	/**
	 * From the sweep: gives every server and every database with a public address the IPv6 address of its cluster (see
	 * ipv6()). Only clusters that have IPv6 on. One cluster listing per cluster; at most IPV6_PER_RUN servers are read
	 * per run (the rest wait for the next sweep).
	 */
	public static function ipv6s(): void {
		$budget = self::IPV6_PER_RUN;

		foreach ( JobScope::clusters() as $id => $one ) {
			if ( null === Ipv6::prefix_bytes( $one['cluster'] ) ) {
				continue;
			}

			$rows = JobScope::vm_rows( $one['client'] );

			if ( null === $rows ) {
				continue;
			}

			$ours = VmRecords::for_cluster( $id );

			foreach ( $rows as $vm ) {
				$vmid = (int) ( $vm['vmid'] ?? 0 );

				if ( $budget < 1 ) {
					return;
				}

				if ( ! empty( $vm['template'] ) || ! isset( $ours[ $vmid ] ) || '' !== (string) ( $vm['lock'] ?? '' ) ) {
					continue;
				}

				$resource = Resource::find( (int) $ours[ $vmid ] );

				if ( $resource && self::ipv6( $resource, $vm, $one['cluster'] ) ) {
					--$budget;
				}
			}
		}
	}

	/**
	 * Writes the IPv6 address into one server's ipconfig0 as a pending change and NEVER restarts it: the address is live
	 * after the customer's next stop and start. `ipv6_at` marks a server that was looked at (again after a day, or at
	 * once when its address is not the one the cluster derives now); on a running server written to, `ipv6_pending_at`
	 * lets the server page say so until its next boot (PowerJobs). Left alone: a server that is not ready, adopted
	 * (linked), on hold, locked by a running operation, or a database in the middle of an address change.
	 *
	 * @param Resource             $r       Server resource.
	 * @param array<string, mixed> $vm      Cluster row.
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @return bool Whether the server was read (counts against the budget).
	 */
	private static function ipv6( Resource $r, array $vm, array $cluster ): bool {
		if ( 'ready' !== $r->get( 'state' ) || true === $r->get( 'linked' ) || $r->on_hold() || '' === (string) ( $vm['node'] ?? '' ) ) {
			return false;
		}

		$record  = VmRecords::for_resource( $r->id() );
		$address = null === $record ? null : Ipv6::address( $cluster, (int) $record['vmid'] );

		if ( null === $address ) {
			return false;
		}

		if ( $r->get( 'ip6' ) === $address && (int) $r->get( 'ipv6_at' ) > time() - DAY_IN_SECONDS ) {
			return false;
		}

		if ( DbActions::is_db( $r ) && ( ! DbIp::idle( $r ) || '' === (string) $r->get( 'ip' ) ) ) {
			return false;
		}

		$running = 'running' === (string) ( $vm['status'] ?? '' );
		$read    = false;

		$r->locked(
			static function () use ( $r, $running, &$read ): bool {
				// Fresh under the lock: an operation may have started since the cluster row was read.
				if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || true === $r->get( 'linked' ) ) {
					return false;
				}

				$read   = true;
				$result = ( new Operations( $r ) )->reconcile_ipv6();

				if ( 'failed' === $result ) {
					return false;
				}

				return $r->set( array( 'ipv6_at' => time(), 'ipv6_pending_at' => 'written' === $result && $running ? time() : $r->get( 'ipv6_pending_at' ) ) );
			}
		);

		return $read;
	}
}
