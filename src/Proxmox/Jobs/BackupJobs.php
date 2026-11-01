<?php
/**
 * Nightly backup run, one server per action.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Jobs;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\SshKeyField;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Nightly backup run, one server per action.
 */
final class BackupJobs {

	/**
	 * Nightly: starts the run. One server is handled per action and the next action is queued
	 * BACKUP_DELAY seconds later until no server is due, so only one vzdump runs at a time (the
	 * uplink to the backup server is the limit).
	 */
	public static function backup(): void {
		if ( Settings::get( 'pause_all' ) ) {
			return;
		}

		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		delete_transient( ProxmoxJobs::BACKUP_RUN );

		self::backup_next();
	}

	/**
	 * One server of the run: the first one with a backup due (or still running), or whose backups
	 * are to be removed. A server that is still wanted after its turn without a running backup
	 * (it could not be reached) is left out for the rest of the run, so a run always ends.
	 */
	public static function backup_next(): void {
		if ( Settings::get( 'pause_all' ) ) {
			delete_transient( ProxmoxJobs::BACKUP_RUN );
			return;
		}

		$skip   = get_transient( ProxmoxJobs::BACKUP_RUN );
		$skip   = is_array( $skip ) ? array_map( 'intval', $skip ) : array();
		$picked = null;

		// Servers first, then managed databases (their backups do not depend on a plan variation).
		foreach ( array( SshKeyField::DRIVER, DbDriver::ID ) as $driver_id ) {
			$driver = Registry::get( $driver_id );

			if ( ! $driver ) {
				continue;
			}

			foreach ( Resource::live_ids( $driver->kind() ) as $id ) {
				$resource = Resource::find( $id );

				if ( ! $resource || $driver_id !== $resource->get( 'driver' ) || in_array( $id, $skip, true ) ) {
					continue;
				}

				if ( Backups::purge_due( $resource ) || Backups::due( $resource, time() ) ) {
					$picked = $resource;
					break 2;
				}
			}
		}

		if ( null === $picked ) {
			delete_transient( ProxmoxJobs::BACKUP_RUN );
			return;
		}

		$backups = new Backups( $picked );

		$result = Backups::purge_due( $picked ) ? $backups->run_purge() : $backups->step();

		$after = Resource::find( $picked->id() );
		$task  = $after ? $after->get( 'backup_task' ) : null;

		// Still running is fine (it is polled again); any other retry (a poll error, an unreachable cluster) moves on.
		$stuck = $result->is_retry() && ! in_array( $result->error(), array( Backups::WAITING, Backups::PURGE_PENDING ), true );

		if ( ! $after || $stuck || ( ! ( is_array( $task ) && ! empty( $task['upid'] ) ) && ( Backups::purge_due( $after ) || Backups::due( $after, time() ) ) ) ) {
			$skip[] = $picked->id();
			set_transient( ProxmoxJobs::BACKUP_RUN, $skip, 6 * HOUR_IN_SECONDS );
		}

		if ( ! as_has_scheduled_action( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP ) ) {
			as_schedule_single_action( time() + ProxmoxJobs::BACKUP_DELAY, ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		}
	}
}
