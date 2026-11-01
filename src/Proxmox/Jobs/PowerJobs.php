<?php
/**
 * Power job: every 15 minutes the power, CPU and memory of our VMs from one /cluster/resources call per cluster.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Jobs;

use BeltoftServices\Proxmox\Client;
use BeltoftServices\Proxmox\DbActions;
use BeltoftServices\Proxmox\DbMonitor;
use BeltoftServices\Proxmox\Monitor;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Power job: every 15 minutes the power, CPU and memory of our VMs from one /cluster/resources call per cluster.
 */
final class PowerJobs {

	/**
	 * Every 15 minutes: one /cluster/resources call per cluster; each of our VMs' power, CPU
	 * (PVE's fraction, not a percent) and memory go to the status transient, and the running ones
	 * to a list for the abuse job. Monitoring alert state may be stored under the resource lock; a resource whose customer
	 * stopped the VM stays as it is. A VM with a lock (an operation is running) is left to that operation.
	 */
	public static function power(): void {
		foreach ( JobScope::clusters() as $id => $one ) {
			$rows = JobScope::vm_rows( $one['client'] );

			if ( null === $rows ) {
				continue;
			}

			$ours    = VmRecords::for_cluster( $id );
			$running = array();

			foreach ( $rows as $vm ) {
				$vmid = (int) ( $vm['vmid'] ?? 0 );

				if ( ! empty( $vm['template'] ) || ! isset( $ours[ $vmid ] ) || '' !== (string) ( $vm['lock'] ?? '' ) ) {
					continue;
				}

				$power = (string) ( $vm['status'] ?? '' );

				if ( '' === $power ) {
					continue;
				}

				$before = Operations::cached_status( (int) $ours[ $vmid ] );

				self::remember_status( (int) $ours[ $vmid ], $vm );
				$resource = Resource::find( (int) $ours[ $vmid ] );
				if ( $resource ) { Monitor::power( $resource, $power ); }
				if ( $resource ) { self::hostname_applied( $resource, $vm, $power, $before ); }
				if ( $resource ) { self::display_applied( $resource, $vm, $power, $before ); }
				if ( $resource && DbActions::is_db( $resource ) ) { DbMonitor::check( $resource, $one['client'], (string) ( $vm['node'] ?? '' ), $vmid, $power ); }

				if ( 'running' === $power ) {
					if ( $resource ) {
						self::refresh_agent( $one['client'], $resource, (string) ( $vm['node'] ?? '' ), $vmid );
					}
					$running[] = array(
						'resource' => $ours[ $vmid ],
						'cluster'  => $id,
						'vmid'     => $vmid,
						'node'     => (string) ( $vm['node'] ?? '' ),
					);
				}
			}

			set_transient( ProxmoxJobs::RUNNING_PREFIX . $id, $running, 2 * HOUR_IN_SECONDS );
		}
	}

	/**
	 * Keeps one /cluster/resources row (power, CPU fraction, memory bytes) as the resource's status
	 * transient. Shared by the power job and the live usage route. A row without a status is ignored.
	 *
	 * @param int                  $resource_id Resource id.
	 * @param array<string, mixed> $vm          Row of /cluster/resources?type=vm.
	 */
	public static function remember_status( int $resource_id, array $vm ): void {
		$power = (string) ( $vm['status'] ?? '' );

		if ( '' === $power ) {
			return;
		}

		set_transient(
			Operations::STATUS_PREFIX . $resource_id,
			array(
				'power' => $power,
				'cpu'   => (float) ( $vm['cpu'] ?? 0 ),
				'mem'   => (int) ( $vm['mem'] ?? 0 ),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * A server renamed while it ran shows the old hostname until its next boot. A boot after the rename ends the
	 * notice: the row's uptime says when it booted (now minus uptime, later than the rename); a row without an
	 * uptime is judged by the first stopped to running change seen after the rename.
	 *
	 * @param Resource                  $r      Server resource.
	 * @param array<string, mixed>      $vm     Cluster row.
	 * @param string                    $power  Power now.
	 * @param array<string, mixed>|null $before The cached row of the run before.
	 */
	private static function hostname_applied( Resource $r, array $vm, string $power, ?array $before ): void {
		$at = (int) $r->get( ServerName::PENDING_AT );

		if ( 'running' !== $power || null === $r->get( ServerName::PENDING ) ) {
			return;
		}

		$booted = isset( $vm['uptime'] ) && (int) $vm['uptime'] > 0
			? time() - (int) $vm['uptime'] > $at
			: ( null !== $before && 'stopped' === $before['power'] );

		if ( $booted ) {
			$r->set( array( ServerName::PENDING => null, ServerName::PENDING_AT => null ) );
		}
	}

	/**
	 * A server that got the standard display while it ran shows the serial one until its next boot. A boot after the
	 * change ends the note (the same test as hostname_applied()).
	 *
	 * @param Resource                  $r      Server resource.
	 * @param array<string, mixed>      $vm     Cluster row.
	 * @param string                    $power  Power now.
	 * @param array<string, mixed>|null $before The cached row of the run before.
	 */
	private static function display_applied( Resource $r, array $vm, string $power, ?array $before ): void {
		$at = (int) $r->get( 'display_pending_at' );

		if ( 'running' !== $power || $at <= 0 ) {
			return;
		}

		$booted = isset( $vm['uptime'] ) && (int) $vm['uptime'] > 0
			? time() - (int) $vm['uptime'] > $at
			: ( null !== $before && 'stopped' === $before['power'] );

		if ( $booted ) {
			$r->set( array( 'display_pending_at' => null ) );
		}
	}

	/**
	 * A server whose guest agent was not up yet when it was built: ask again, at most once an hour,
	 * until it answers. Reset password and consistent snapshots depend on it.
	 *
	 * @param Client   $client Cluster client.
	 * @param Resource $r      Server resource.
	 * @param string   $node   Node.
	 * @param int      $vmid   VMID.
	 */
	private static function refresh_agent( Client $client, Resource $r, string $node, int $vmid ): void {
		if ( 'unknown' !== $r->get( 'agent' ) || '' === $node || (int) $r->get( 'agent_checked_at' ) > time() - HOUR_IN_SECONDS ) {
			return;
		}

		$ping = $client->request( 'POST', '/nodes/' . rawurlencode( $node ) . '/qemu/' . $vmid . '/agent/ping' );

		$r->set( is_wp_error( $ping ) ? array( 'agent_checked_at' => time() ) : array( 'agent' => 'ok', 'agent_checked_at' => null ) );
	}
}
