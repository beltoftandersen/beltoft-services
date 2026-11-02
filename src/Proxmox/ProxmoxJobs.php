<?php
/**
 * Recurring Proxmox jobs (registrar, hook names, schedule; the work is in Proxmox\Jobs\*): capacity cache, power state, abuse report, disk readings, password purge.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Jobs\AbuseJobs;
use BeltoftServices\Proxmox\Jobs\BackupJobs;
use BeltoftServices\Proxmox\Jobs\CapacityJobs;
use BeltoftServices\Proxmox\Jobs\DiskJobs;
use BeltoftServices\Proxmox\Jobs\PasswordJobs;
use BeltoftServices\Proxmox\Jobs\PowerJobs;
use BeltoftServices\Proxmox\Jobs\ServerSyncJobs;
use BeltoftServices\Proxmox\Jobs\SnapshotJobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler cron jobs of group beltoft-services. All of them return at once while the
 * kill switch is on, and only look at clusters that are live or hold persisted fake state.
 *
 * Calls (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html):
 * - capacity, per cluster and hour (and once after a cluster is saved): GET /nodes and GET /cluster/resources?type=vm (two calls);
 * - power, per cluster and 15 minutes: GET /cluster/resources?type=vm (one call for every VM);
 * - abuse, per running VM and hour: GET /nodes/{node}/qemu/{vmid}/rrddata.
 * - disk, per running VM and six hours (hourly while the disk is at 85 percent or more): GET /nodes/{node}/qemu/{vmid}/agent/get-fsinfo.
 *
 * Resource meta writes use the resource lock (Resource::locked() or Resource::desire());
 * power state and abuse "high since" live in transients, while monitoring alert state is meta.
 *
 * CAPACITY FORMULA (user decision D8): a node's cached `mem` is the COMMITTED memory, not the
 * memory in use:
 *   mem = (sum of the configured memory, `maxmem` of GET /cluster/resources, of this site's VMs
 *          on the node, running or not) + host reserve (setting `host_reserve_gb`)
 * and `maxmem` is the node's memory. Placement and the stock check read free = maxmem - mem. The
 * node's real figure is kept as `mem_used`. "This site's VMs" are those recorded in the
 * beltoft_services_vms table for the cluster, plus recorded VMs not listed yet (just placed) at
 * their plan's memory; templates are never counted. A provision commits its VM's memory in the
 * cache when it picks the node (Capacity::reserve()), so a burst does not overcommit.
 */
final class ProxmoxJobs {

	public const CAPACITY     = 'beltoft_services_proxmox_capacity';
	public const CAPACITY_NOW = 'beltoft_services_proxmox_capacity_now';
	public const POWER        = 'beltoft_services_proxmox_power';
	public const POWER_AFTER  = 'beltoft_services_proxmox_power_after';
	public const ABUSE        = 'beltoft_services_proxmox_abuse';
	public const ABUSE_BATCH  = 'beltoft_services_proxmox_abuse_batch';
	public const DISK         = 'beltoft_services_proxmox_disk';
	public const DISK_BATCH   = 'beltoft_services_proxmox_disk_batch';
	public const BACKUP       = 'beltoft_services_proxmox_backup';
	public const BACKUP_NEXT  = 'beltoft_services_proxmox_backup_next';
	public const BACKUP_RUN   = 'beltoft_services_proxmox_backup_run';
	public const SNAPSHOTS    = 'beltoft_services_proxmox_snapshots';
	public const SNAP_SYNCED  = 'beltoft_services_proxmox_snapshots_synced';

	public const RUNNING_PREFIX = 'beltoft_services_proxmox_running_';
	public const QUEUE          = 'beltoft_services_proxmox_abuse_queue';
	public const DISK_QUEUE     = 'beltoft_services_proxmox_disk_queue';
	public const SINCE_PREFIX   = 'beltoft_services_proxmox_abuse_since_';
	public const NOTE_PREFIX    = 'beltoft_services_proxmox_abuse_note_';
	public const PURGE_RAN      = 'beltoft_services_proxmox_purge_ran';

	/**
	 * VMs per abuse batch, and most VMs per run.
	 */
	public const BATCH_SIZE = 25;
	public const MAX_VMS    = 500;

	/**
	 * Seconds between abuse batches.
	 */
	public const BATCH_DELAY = 60;

	/**
	 * Seconds between two servers of the nightly backup run.
	 */
	public const BACKUP_DELAY = 60;

	/**
	 * Cron expressions.
	 *
	 * @var array<string, string>
	 */
	private const CRONS = array(
		self::CAPACITY => '0 * * * *',
		self::POWER    => '*/15 * * * *',
		self::ABUSE    => '20 * * * *',
		self::DISK     => '10 * * * *',
		self::BACKUP   => '30 3 * * *',
		self::SNAPSHOTS => '40 * * * *',
	);

	/**
	 * A gap this long between two readings means the "high since" value is not continuous.
	 */
	public const CONTINUITY_SLACK = 900;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::CAPACITY, array( CapacityJobs::class, 'capacity' ) );
		add_action( self::CAPACITY_NOW, array( CapacityJobs::class, 'capacity_now' ) );
		add_action( self::POWER, array( PowerJobs::class, 'power' ) );
		add_action( self::POWER_AFTER, array( PowerJobs::class, 'after' ) );
		add_action( self::ABUSE, array( AbuseJobs::class, 'abuse' ) );
		add_action( self::ABUSE_BATCH, array( AbuseJobs::class, 'abuse_batch' ) );
		add_action( self::DISK, array( DiskJobs::class, 'disk' ) );
		add_action( self::DISK_BATCH, array( DiskJobs::class, 'disk_batch' ) );
		add_action( self::BACKUP, array( BackupJobs::class, 'backup' ) );
		add_action( self::BACKUP_NEXT, array( BackupJobs::class, 'backup_next' ) );
		add_action( self::SNAPSHOTS, array( SnapshotJobs::class, 'snapshots' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		// After the sweep itself (priority 10): one password purge a day.
		add_action( Jobs::SWEEP, array( PasswordJobs::class, 'purge_passwords' ), 20 );
		add_action( Jobs::SWEEP, array( PrivateNets::class, 'reclaim' ), 21 );
		add_action( Jobs::SWEEP, array( ServerSyncJobs::class, 'net_rates' ), 22 );
		add_action( Jobs::SWEEP, array( PrivateNets::class, 'check_pool' ), 24 );
		add_action( Jobs::SWEEP, array( ServerSyncJobs::class, 'displays' ), 23 );
	}

	/**
	 * Schedules the recurring jobs, once each.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		foreach ( self::CRONS as $hook => $cron ) {
			if ( ! as_has_scheduled_action( $hook, array(), Jobs::GROUP ) ) {
				as_schedule_cron_action( time(), $cron, $hook, array(), Jobs::GROUP );
			}
		}
	}

	/**
	 * Removes the jobs (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( array_keys( self::CRONS ) as $hook ) {
			as_unschedule_all_actions( $hook, array(), Jobs::GROUP );
		}

		as_unschedule_all_actions( self::ABUSE_BATCH, array(), Jobs::GROUP );
		as_unschedule_all_actions( self::DISK_BATCH, array(), Jobs::GROUP );
		as_unschedule_all_actions( self::BACKUP_NEXT, array(), Jobs::GROUP );
		as_unschedule_all_actions( self::CAPACITY_NOW, array(), Jobs::GROUP );
		as_unschedule_all_actions( self::POWER_AFTER, array(), Jobs::GROUP );
	}
}
