<?php
/**
 * Recurring Proxmox jobs: capacity cache, power state, abuse report, password purge.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler cron jobs of group beltoft-services. All of them return at once while the
 * kill switch is on, and only look at clusters that are live or hold persisted fake state.
 *
 * Calls (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html):
 * - capacity, per cluster and hour (and once after a cluster is saved): GET /nodes and GET /cluster/resources?type=vm (two calls);
 * - power, per cluster and 15 minutes: GET /cluster/resources?type=vm (one call for every VM);
 * - abuse, per running VM and hour: GET /nodes/{node}/qemu/{vmid}/rrddata.
 *
 * Nothing here writes resource meta except under the resource lock (Resource::locked() or
 * Resource::desire()); power state and "high since" live in transients.
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
	public const ABUSE        = 'beltoft_services_proxmox_abuse';
	public const ABUSE_BATCH  = 'beltoft_services_proxmox_abuse_batch';

	public const RUNNING_PREFIX = 'beltoft_services_proxmox_running_';
	public const QUEUE          = 'beltoft_services_proxmox_abuse_queue';
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
	 * Cron expressions.
	 *
	 * @var array<string, string>
	 */
	private const CRONS = array(
		self::CAPACITY => '0 * * * *',
		self::POWER    => '*/15 * * * *',
		self::ABUSE    => '20 * * * *',
	);

	/**
	 * A gap this long between two readings means the "high since" value is not continuous.
	 */
	private const CONTINUITY_SLACK = 900;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::CAPACITY, array( __CLASS__, 'capacity' ) );
		add_action( self::CAPACITY_NOW, array( __CLASS__, 'capacity_now' ) );
		add_action( self::POWER, array( __CLASS__, 'power' ) );
		add_action( self::ABUSE, array( __CLASS__, 'abuse' ) );
		add_action( self::ABUSE_BATCH, array( __CLASS__, 'abuse_batch' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		// After the sweep itself (priority 10): one password purge a day.
		add_action( Jobs::SWEEP, array( __CLASS__, 'purge_passwords' ), 20 );
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
		as_unschedule_all_actions( self::CAPACITY_NOW, array(), Jobs::GROUP );
	}

	/**
	 * The clusters the jobs may look at, with a client each. Nothing while the kill switch is on.
	 * A fake cluster counts only when it keeps its state (staging): a plain fake has nothing
	 * to read.
	 *
	 * @return array<string, array{cluster: array<string, mixed>, client: Client}>
	 */
	private static function clusters(): array {
		if ( Settings::get( 'pause_all' ) ) {
			return array();
		}

		$out = array();

		foreach ( array_keys( Clusters::all() ) as $id ) {
			$cluster = Clusters::get( (string) $id );

			if ( null === $cluster ) {
				continue;
			}

			if ( 'live' !== $cluster['client'] && ( empty( $cluster['persist'] ) || ! is_array( get_option( FakeClient::OPTION, null ) ) ) ) {
				continue;
			}

			// A live cluster without a site tag is left alone (and the admin told).
			if ( null !== Clients::gate( (string) $id, $cluster, Clients::live_allowed() ) ) {
				continue;
			}

			try {
				$out[ (string) $id ] = array(
					'cluster' => $cluster,
					'client'  => Clients::for( $cluster ),
				);
			} catch ( \Throwable $e ) {
				unset( $e ); // This cluster is skipped; the next run tries again.
			}
		}

		return $out;
	}

	/**
	 * GET /cluster/resources?type=vm, or null when it fails.
	 *
	 * @param Client $client Client.
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function vm_rows( Client $client ): ?array {
		$rows = $client->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) );

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : null;
	}

	// -- Capacity --------------------------------------------------------------------------------

	/**
	 * Hourly: caches each cluster's nodes (see the formula in the class comment) for Placement and
	 * the stock check. A cluster that cannot be read keeps its last cache.
	 */
	public static function capacity(): void {
		foreach ( self::clusters() as $id => $one ) {
			self::cache_capacity( $id, $one['client'] );
		}
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
		if ( ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( self::CAPACITY_NOW, array( $id ), Jobs::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::CAPACITY_NOW, array( $id ), Jobs::GROUP );
	}

	/**
	 * The queued run for one cluster.
	 *
	 * @param string $id Cluster id.
	 */
	public static function capacity_now( $id ): void {
		$all = self::clusters();

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
		$vms     = self::vm_rows( $client );

		if ( ! is_array( $nodes ) || null === $vms ) {
			return; // Never cache a guess: stock stays closed (or on the old cache) until it can be read.
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

		unset( $locked ); // Busy: the cache stays as it is and the next run writes it.
	}

	// -- Power -----------------------------------------------------------------------------------

	/**
	 * Every 15 minutes: one /cluster/resources call per cluster; each of our VMs' power, CPU
	 * (PVE's fraction, not a percent) and memory go to the status transient, and the running ones
	 * to a list for the abuse job. Resource meta is not touched, and a resource whose customer
	 * stopped the VM stays as it is. A VM with a lock (an operation is running) is left to that operation.
	 */
	public static function power(): void {
		foreach ( self::clusters() as $id => $one ) {
			$rows = self::vm_rows( $one['client'] );

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

				set_transient(
					Operations::STATUS_PREFIX . $ours[ $vmid ],
					array(
						'power' => $power,
						'cpu'   => (float) ( $vm['cpu'] ?? 0 ),
						'mem'   => (int) ( $vm['mem'] ?? 0 ),
					),
					HOUR_IN_SECONDS
				);

				if ( 'running' === $power ) {
					$running[] = array(
						'resource' => $ours[ $vmid ],
						'cluster'  => $id,
						'vmid'     => $vmid,
						'node'     => (string) ( $vm['node'] ?? '' ),
					);
				}
			}

			set_transient( self::RUNNING_PREFIX . $id, $running, 2 * HOUR_IN_SECONDS );
		}
	}

	// -- Abuse -----------------------------------------------------------------------------------

	/**
	 * Hourly at :20: queues the running VMs found by the power job (at most MAX_VMS) and starts
	 * the first batch. A queue left over from an earlier run is replaced.
	 */
	public static function abuse(): void {
		if ( Settings::get( 'pause_all' ) || 'off' === (string) Settings::get( 'abuse_mode' ) ) {
			return;
		}

		$lists = array();

		foreach ( array_keys( self::clusters() ) as $id ) {
			$list = get_transient( self::RUNNING_PREFIX . $id );

			$lists[] = array_values( array_filter( is_array( $list ) ? $list : array(), 'is_array' ) );
		}

		// Round robin over the clusters, so one big cluster cannot use the whole cap.
		$queue = array();
		$round = 0;

		while ( count( $queue ) < self::MAX_VMS ) {
			$took = false;

			foreach ( $lists as $list ) {
				if ( isset( $list[ $round ] ) && count( $queue ) < self::MAX_VMS ) {
					$queue[] = $list[ $round ];
					$took    = true;
				}
			}

			if ( ! $took ) {
				break;
			}

			++$round;
		}

		as_unschedule_all_actions( self::ABUSE_BATCH, array(), Jobs::GROUP );

		if ( array() === $queue ) {
			delete_transient( self::QUEUE );
			return;
		}

		set_transient(
			self::QUEUE,
			array(
				'items'  => $queue,
				'cursor' => 0,
			),
			6 * HOUR_IN_SECONDS
		);

		self::abuse_batch();
	}

	/**
	 * One batch of the abuse run. The cursor in the queue transient only moves forward and the
	 * next batch is queued only while items are left, so a run always ends.
	 */
	public static function abuse_batch(): void {
		$queue = get_transient( self::QUEUE );

		if ( Settings::get( 'pause_all' ) || 'off' === (string) Settings::get( 'abuse_mode' ) || ! is_array( $queue ) || ! isset( $queue['items'], $queue['cursor'] ) ) {
			delete_transient( self::QUEUE );
			return;
		}

		$items  = (array) $queue['items'];
		$cursor = (int) $queue['cursor'];
		$end    = min( count( $items ), $cursor + self::BATCH_SIZE );

		// The cursor is stored first: a batch that dies halfway is not repeated.
		if ( $end >= count( $items ) ) {
			delete_transient( self::QUEUE );
		} else {
			$queue['cursor'] = $end;
			set_transient( self::QUEUE, $queue, 6 * HOUR_IN_SECONDS );

			if ( ! as_has_scheduled_action( self::ABUSE_BATCH, array(), Jobs::GROUP ) ) {
				as_schedule_single_action( time() + self::BATCH_DELAY, self::ABUSE_BATCH, array(), Jobs::GROUP );
			}
		}

		$clients = array();

		for ( $i = $cursor; $i < $end; $i++ ) {
			$item = $items[ $i ];

			if ( ! is_array( $item ) ) {
				continue;
			}

			$cluster = (string) ( $item['cluster'] ?? '' );

			if ( ! isset( $clients[ $cluster ] ) ) {
				$all                 = self::clusters();
				$clients[ $cluster ] = $all[ $cluster ]['client'] ?? null;
			}

			if ( null !== $clients[ $cluster ] ) {
				self::check_vm( $clients[ $cluster ], $item );
			}
		}
	}

	/**
	 * Checks one running VM.
	 *
	 * @param Client               $client Client.
	 * @param array<string, mixed> $item   resource, cluster, vmid, node.
	 */
	private static function check_vm( Client $client, array $item ): void {
		$resource = Resource::find( (int) ( $item['resource'] ?? 0 ) );
		$record   = $resource ? VmRecords::for_resource( $resource->id() ) : null;

		if ( ! $resource || ! $resource->is_live() || 'ready' !== $resource->get( 'state' ) || 'ready' !== $resource->get( 'desired' ) ) {
			return;
		}

		if ( null === $record || (string) $item['cluster'] !== $record['cluster'] || (int) $item['vmid'] !== $record['vmid'] ) {
			return;
		}

		$cached = Operations::cached_status( $resource->id() );

		if ( null !== $cached && 'running' !== $cached['power'] ) {
			return; // Stopped since the power job looked: nothing is running to measure.
		}

		$samples = $client->request(
			'GET',
			'/nodes/' . rawurlencode( (string) $item['node'] ) . '/qemu/' . (int) $item['vmid'] . '/rrddata',
			array(
				'timeframe' => 'hour',
				'cf'        => 'AVERAGE',
			)
		);

		if ( ! is_array( $samples ) ) {
			return;
		}

		$threshold = (int) Settings::get( 'abuse_cpu' );
		$reading   = self::analyse( $samples, (float) $threshold );

		if ( null === $reading ) {
			self::note_impossible( $resource );
			return;
		}

		$since    = self::since( $resource->id(), $reading );
		$duration = max( $reading['longest'], null === $since ? 0 : $reading['last'] - $since );

		if ( $duration < max( 1, (int) Settings::get( 'abuse_hours' ) ) * HOUR_IN_SECONDS ) {
			return;
		}

		self::report( $resource, $threshold, $duration, $since ?? $reading['last'] - $duration );
	}

	/**
	 * Reads the samples of one hour. The CPU of a sample is PVE's fraction, so 0.9 is 90 percent
	 * (not documented in the API viewer: the keys `cpu` and `time` are read defensively). A
	 * sample without a numeric `cpu` and `time` is a gap: it ends a run, and an unfinished
	 * newest sample does not count. The interval between samples is not assumed.
	 *
	 * @param array<int, mixed> $samples   Rows of rrddata.
	 * @param float             $threshold CPU percent.
	 * @return array{first: int, last: int, longest: int, trailing: ?int}|null Seconds. `trailing` is where the run that reaches the newest sample began. Null: no usable sample.
	 */
	public static function analyse( array $samples, float $threshold ): ?array {
		$usable = array();

		foreach ( array_values( $samples ) as $sample ) {
			$ok = is_array( $sample ) && isset( $sample['cpu'], $sample['time'] ) && is_numeric( $sample['cpu'] ) && is_numeric( $sample['time'] );

			$usable[] = $ok ? array( (int) $sample['time'], (float) $sample['cpu'] * 100 ) : null;
		}

		while ( array() !== $usable && null === end( $usable ) ) {
			array_pop( $usable );
		}

		if ( array() === array_filter( $usable ) ) {
			return null;
		}

		$run     = null;
		$longest = 0;
		$first   = null;
		$last    = 0;

		foreach ( $usable as $point ) {
			if ( null === $point ) {
				$run = null;
				continue;
			}

			$first ??= $point[0];
			$last    = $point[0];

			if ( $point[1] >= $threshold ) {
				$run     ??= $point[0];
				$longest   = max( $longest, $point[0] - $run );
			} else {
				$run = null;
			}
		}

		return array(
			'first'    => (int) $first,
			'last'     => $last,
			'longest'  => $longest,
			'trailing' => $run,
		);
	}

	/**
	 * Since when the VM has been above the threshold, over several hourly readings. One reading
	 * covers an hour only, so `abuse_hours` of 2 or more is judged on this value (a design of this
	 * plugin, not something the docs describe). It is reset by a reading that ends below the threshold, and
	 * when the readings do not join up.
	 *
	 * @param int                                                                    $resource_id Resource id.
	 * @param array{first: int, last: int, longest: int, trailing: ?int} $reading     Reading.
	 * @return int|null Timestamp, or null when the newest sample is below the threshold.
	 */
	private static function since( int $resource_id, array $reading ): ?int {
		$key = self::SINCE_PREFIX . $resource_id;

		if ( null === $reading['trailing'] ) {
			delete_transient( $key );
			return null;
		}

		$since = $reading['trailing'];
		$prev  = get_transient( $key );

		// The run reaches back to the first sample, and the earlier reading ended where this one begins.
		if ( is_array( $prev ) && isset( $prev['since'], $prev['last'] ) && $reading['trailing'] === $reading['first'] && (int) $prev['last'] >= $reading['first'] - self::CONTINUITY_SLACK ) {
			$since = min( $since, (int) $prev['since'] );
		}

		set_transient(
			$key,
			array(
				'since' => $since,
				'last'  => $reading['last'],
			),
			( max( 1, (int) Settings::get( 'abuse_hours' ) ) + 24 ) * HOUR_IN_SECONDS
		);

		return $since;
	}

	/**
	 * The samples have no usable `cpu` and `time`: said once a week in the license's history, never an alert.
	 *
	 * @param Resource $resource Resource.
	 */
	private static function note_impossible( Resource $resource ): void {
		$key = self::NOTE_PREFIX . 'none_' . $resource->id();

		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, WEEK_IN_SECONDS );
		Events::add( (int) ( $resource->license()['id'] ?? 0 ), 'service', 'Abuse check not possible: the usage samples of the VM have no cpu or time values' );
	}

	/**
	 * Alerts the admin (the text carries the date, so the alert repeats at most daily), writes a
	 * history event, and in `pause` mode asks for the paused state.
	 *
	 * @param Resource $resource  Resource.
	 * @param int      $threshold CPU percent.
	 * @param int      $duration  Seconds above the threshold.
	 * @param int      $since     Timestamp the high load began (or was first seen).
	 */
	private static function report( Resource $resource, int $threshold, int $duration, int $since ): void {
		$mode = (string) Settings::get( 'abuse_mode' );

		// A protected or adopted machine is reported, never paused.
		if ( 'pause' === $mode && ( $resource->get( 'protected' ) || true === $resource->get( 'linked' ) ) ) {
			$mode = 'report';
		}

		$text = sprintf(
			'CPU at or above %d%% for %s (since %s UTC) %s',
			$threshold,
			human_time_diff( 0, $duration ),
			gmdate( 'Y-m-d H:i', $since ),
			gmdate( 'Y-m-d' )
		);

		$license_id = (int) ( $resource->license()['id'] ?? 0 );
		$noted      = self::NOTE_PREFIX . $resource->id() . '_' . gmdate( 'Ymd' );

		// Alerts::abuse() stores its dedupe hash in the resource meta: only under the lock.
		$resource->locked(
			static function () use ( $resource, $text, $mode ): bool {
				Alerts::abuse( $resource, $text, $mode );

				return true;
			}
		);

		if ( false === get_transient( $noted ) ) {
			set_transient( $noted, 1, DAY_IN_SECONDS );
			Events::add( $license_id, 'service', 'Abuse suspected: ' . $text );
		}

		if ( 'pause' === $mode ) {
			$fresh = Resource::find( $resource->id() );

			if ( $fresh && 'ready' === $fresh->get( 'desired' ) && $fresh->desire( 'paused', 'Abuse suspected: ' . $text ) ) {
				Jobs::enqueue( $fresh->id() );
			}
		}
	}

	// -- Passwords -------------------------------------------------------------------------------

	/**
	 * Wipes the stored VM passwords whose reveal window (Access::REVEAL_DAYS) has passed, read or
	 * not. Runs once a day, from the sweep. A password of a reset that is not on the VM yet is left alone.
	 */
	public static function purge_passwords(): void {
		if ( false !== get_transient( self::PURGE_RAN ) || Settings::get( 'pause_all' ) ) {
			return;
		}

		set_transient( self::PURGE_RAN, 1, DAY_IN_SECONDS );

		$driver = Registry::get( SshKeyField::DRIVER );

		if ( ! $driver ) {
			return;
		}

		foreach ( Resource::live_ids( $driver->kind() ) as $id ) {
			$resource = Resource::find( $id );

			if ( ! $resource || SshKeyField::DRIVER !== $resource->get( 'driver' ) ) {
				continue;
			}

			$stored = $resource->get( Access::META_REVEAL );

			if ( ! is_string( $stored ) || '' === $stored || (int) $resource->get( Access::META_UNTIL ) >= time() ) {
				continue;
			}

			$resource->locked(
				static function () use ( $resource ): bool {
					$stored = $resource->get( Access::META_REVEAL );

					// Read again under the lock: a reveal or a reset may have changed it.
					if ( ! is_string( $stored ) || '' === $stored || (int) $resource->get( Access::META_UNTIL ) >= time() || $resource->get( Access::META_RESET ) ) {
						return false;
					}

					return Access::purge( $resource );
				}
			);
		}
	}
}
