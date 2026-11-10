<?php
/**
 * Guest disk readings: queues the running VMs and reads them in batches.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Jobs;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\DbGrow;
use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\Monitor;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\Pve\JobScope;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Guest disk readings: queues the running VMs and reads them in batches.
 */
final class DiskJobs {

	/** Queue the running VMs from the power sweep for a six-hourly filesystem reading. */
	public static function disk(): void {
		if ( Settings::get( 'pause_all' ) ) {
			return;
		}
		$queue = array();
		foreach ( array_keys( JobScope::clusters() ) as $id ) {
			$list = get_transient( ProxmoxJobs::RUNNING_PREFIX . $id );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $item ) {
				if ( ! is_array( $item ) || count( $queue ) >= ProxmoxJobs::MAX_VMS ) {
					continue;
				}
				// The job runs hourly: a server is read when its reading is old, or hourly while its disk is high.
				$r = Resource::find( (int) ( $item['resource'] ?? 0 ) );
				if ( ! $r || GuestDisk::due( $r ) || DbGrow::manual( $r ) ) {
					$queue[] = $item;
				}
			}
		}
		as_unschedule_all_actions( ProxmoxJobs::DISK_BATCH, array(), Jobs::GROUP );
		if ( array() === $queue ) {
			delete_transient( ProxmoxJobs::DISK_QUEUE );
			return;
		}
		set_transient( ProxmoxJobs::DISK_QUEUE, array( 'items' => $queue, 'cursor' => 0 ), 8 * HOUR_IN_SECONDS );
		self::disk_batch();
	}

	/** One batch of 25. Store progress before reads so a timeout cannot repeat a whole batch. */
	public static function disk_batch(): void {
		$queue = get_transient( ProxmoxJobs::DISK_QUEUE );
		if ( Settings::get( 'pause_all' ) || ! is_array( $queue ) || ! isset( $queue['items'], $queue['cursor'] ) ) {
			delete_transient( ProxmoxJobs::DISK_QUEUE );
			return;
		}
		$items = (array) $queue['items'];
		$cursor = (int) $queue['cursor'];
		$end = min( count( $items ), $cursor + ProxmoxJobs::BATCH_SIZE );
		if ( $end >= count( $items ) ) {
			delete_transient( ProxmoxJobs::DISK_QUEUE );
		} else {
			$queue['cursor'] = $end;
			set_transient( ProxmoxJobs::DISK_QUEUE, $queue, 8 * HOUR_IN_SECONDS );
			if ( ! as_has_scheduled_action( ProxmoxJobs::DISK_BATCH, array(), Jobs::GROUP ) ) {
				as_schedule_single_action( time() + ProxmoxJobs::BATCH_DELAY, ProxmoxJobs::DISK_BATCH, array(), Jobs::GROUP );
			}
		}
		for ( $i = $cursor; $i < $end; ++$i ) {
			$item = $items[ $i ];
			if ( ! is_array( $item ) ) {
				continue;
			}
			$r = Resource::find( (int) ( $item['resource'] ?? 0 ) );
			$record = $r ? VmRecords::for_resource( $r->id() ) : null;
			if ( ! $r || ! $r->is_live() || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || null === $record ) {
				continue;
			}
			if ( (string) $record['cluster'] !== (string) ( $item['cluster'] ?? '' ) || (int) $record['vmid'] !== (int) ( $item['vmid'] ?? 0 ) ) {
				continue;
			}
			$disk = GuestDisk::read( $r );
			if ( null !== $disk ) {
				Monitor::disk( $r, $disk );
			}
			// A database waiting for its file system to be grown by hand: one more read, then the flag may go.
			DbGrow::check_manual( $r );
		}
	}
}
