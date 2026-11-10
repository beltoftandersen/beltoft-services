<?php
/**
 * Hourly snapshot expiry and daily snapshot sync picking.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Snapshot;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Vm\ProxmoxJobs;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly snapshot expiry and daily snapshot sync picking.
 */
final class SnapshotJobs {

	/**
	 * Hourly: reads the servers' meta only. A server whose snapshots are past their time gets the internal
	 * `snapshot_expire`, and once a day one with snapshots gets `snapshot_sync`, both through the act queue
	 * (which runs them under the server's lock, also on a paused server), BATCH_DELAY apart. The clamp for
	 * paid slots that ended runs here too, before the picking. Nothing runs while the kill switch is on. It also
	 * runs with the customer switch off: what is there still expires.
	 */
	public static function snapshots(): void {
		if ( Settings::get( 'pause_all' ) ) {
			return;
		}

		$driver = Registry::get( ProxmoxDriver::ID );

		if ( ! $driver ) {
			return;
		}

		$daily = false === get_transient( ProxmoxJobs::SNAP_SYNCED );
		$slot  = 0;
		$now   = time();

		if ( $daily ) {
			set_transient( ProxmoxJobs::SNAP_SYNCED, 1, DAY_IN_SECONDS );
		}

		foreach ( Resource::live_ids( $driver->kind() ) as $id ) {
			$resource = Resource::find( $id );

			if ( ! $resource || ProxmoxDriver::ID !== $resource->get( 'driver' ) || true === $resource->get( 'linked' ) || ! Snapshots::tracked( $resource ) ) {
				continue;
			}

			if ( SnapshotExpiry::needs_clamp( $resource ) ) {
				SnapshotExpiry::clamp( $resource, $now );

				$resource = Resource::find( $id ) ?? $resource;
			}
			$action   = SnapshotExpiry::due( $resource, $now ) ? 'snapshot_expire' : ( $daily ? 'snapshot_sync' : '' );

			if ( '' === $action ) {
				continue;
			}

			Jobs::queue_act( $id, $action, array(), $slot * ProxmoxJobs::BATCH_DELAY );
			++$slot;
		}
	}
}
