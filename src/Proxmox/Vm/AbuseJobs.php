<?php
/**
 * Hourly usage read of the running VMs (abuse check, customer usage alerts, traffic alerts), in batches.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Proxmox\Network\Monitor;
use BeltoftServices\Proxmox\Pve\JobScope;
use BeltoftServices\Proxmox\Network\Traffic;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly usage read of the running VMs (abuse check, customer usage alerts, traffic alerts), in batches.
 */
final class AbuseJobs {

	/**
	 * Hourly at :20: queues the running VMs found by the power job (at most MAX_VMS) and starts
	 * the first batch. A queue left over from an earlier run is replaced.
	 */
	public static function abuse(): void {
		if ( Settings::get( 'pause_all' ) || ! self::reads_usage() ) {
			return;
		}

		$lists = array();

		foreach ( array_keys( JobScope::clusters() ) as $id ) {
			$list = get_transient( ProxmoxJobs::RUNNING_PREFIX . $id );

			$lists[] = array_values( array_filter( is_array( $list ) ? $list : array(), 'is_array' ) );
		}

		// Round robin over the clusters, so one big cluster cannot use the whole cap.
		$queue = array();
		$round = 0;

		while ( count( $queue ) < ProxmoxJobs::MAX_VMS ) {
			$took = false;

			foreach ( $lists as $list ) {
				if ( isset( $list[ $round ] ) && count( $queue ) < ProxmoxJobs::MAX_VMS ) {
					$queue[] = $list[ $round ];
					$took    = true;
				}
			}

			if ( ! $took ) {
				break;
			}

			++$round;
		}

		as_unschedule_all_actions( ProxmoxJobs::ABUSE_BATCH, array(), Jobs::GROUP );

		if ( array() === $queue ) {
			delete_transient( ProxmoxJobs::QUEUE );
			return;
		}

		set_transient(
			ProxmoxJobs::QUEUE,
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
		$queue = get_transient( ProxmoxJobs::QUEUE );

		if ( Settings::get( 'pause_all' ) || ! self::reads_usage() || ! is_array( $queue ) || ! isset( $queue['items'], $queue['cursor'] ) ) {
			delete_transient( ProxmoxJobs::QUEUE );
			return;
		}

		$items  = (array) $queue['items'];
		$cursor = (int) $queue['cursor'];
		$end    = min( count( $items ), $cursor + ProxmoxJobs::BATCH_SIZE );

		// The cursor is stored first: a batch that dies halfway is not repeated.
		if ( $end >= count( $items ) ) {
			delete_transient( ProxmoxJobs::QUEUE );
		} else {
			$queue['cursor'] = $end;
			set_transient( ProxmoxJobs::QUEUE, $queue, 6 * HOUR_IN_SECONDS );

			if ( ! as_has_scheduled_action( ProxmoxJobs::ABUSE_BATCH, array(), Jobs::GROUP ) ) {
				as_schedule_single_action( time() + ProxmoxJobs::BATCH_DELAY, ProxmoxJobs::ABUSE_BATCH, array(), Jobs::GROUP );
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
				$all                 = JobScope::clusters();
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

		// One read feeds the abuse check (operator), the customer usage alerts and the traffic alerts.
		if ( 'off' !== (string) Settings::get( 'abuse_mode' ) ) {
			self::check_abuse( $resource, $samples );
		}

		if ( 'on' === Settings::get( 'monitor_alerts' ) ) {
			Monitor::usage( $resource, $samples );
		}

		Traffic::check( $resource, $samples );
	}

	/** Whether the hourly usage read is needed: the abuse check, the customer alerts or the traffic alerts use it. */
	private static function reads_usage(): bool {
		return 'off' !== (string) Settings::get( 'abuse_mode' ) || 'on' === Settings::get( 'monitor_alerts' ) || 'on' === Settings::get( 'traffic_alerts' );
	}

	/**
	 * The abuse analysis of one reading.
	 *
	 * @param Resource          $resource Resource.
	 * @param array<int, mixed> $samples  Rows of rrddata.
	 */
	private static function check_abuse( Resource $resource, array $samples ): void {
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
		return Usage::since( ProxmoxJobs::SINCE_PREFIX, $resource_id, $reading, max( 1, (int) Settings::get( 'abuse_hours' ) ) + 24 );
	}

	/**
	 * The samples have no usable `cpu` and `time`: said once a week in the license's history, never an alert.
	 *
	 * @param Resource $resource Resource.
	 */
	private static function note_impossible( Resource $resource ): void {
		$key = ProxmoxJobs::NOTE_PREFIX . 'none_' . $resource->id();

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
		$noted      = ProxmoxJobs::NOTE_PREFIX . $resource->id() . '_' . gmdate( 'Ymd' );

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
}
