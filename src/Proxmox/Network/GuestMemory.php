<?php
/**
 * The memory a server's guest says it uses: latest reading, history table, background job and retention.
 *
 * Only the guest's own /proc/meminfo is used (MemTotal and MemAvailable, read through the guest agent); a Proxmox
 * memory figure is never stored or shown, because Proxmox counts the guest's file cache as used.
 *
 * Stored per server: used and total MB, nothing else. Rows of 5 minutes are kept 2 days, then rolled up to hourly rows
 * (average and peak) kept 30 days, then deleted by the daily job. Rows of a server whose VM record is removed go with it.
 * The latest reading (what the first page render shows) is a transient of 15 minutes, with the table as fallback.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Proxmox\Db\DbActions;
use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Proxmox\Pve\JobScope;
use BeltoftServices\Proxmox\Pve\LiveClient;
use BeltoftServices\Proxmox\Pve\Ownership;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class GuestMemory {
	/** Seconds of one stored point, and of one rolled up point. */
	public const SPAN_RAW  = 300;
	public const SPAN_HOUR = 3600;
	/** Days the 5-minute points and the hourly points are kept. */
	public const RAW_DAYS    = 2;
	public const HOURLY_DAYS = 30;
	/** A reading older than this is treated as none. */
	public const MAX_AGE = 15 * MINUTE_IN_SECONDS;
	/** Transients: the latest reading and the time of the last failed read of a server. */
	public const LATEST_PREFIX = 'beltoft_services_guest_mem_latest_';
	public const FAILED_PREFIX = 'beltoft_services_guest_mem_failed_';
	/** Seconds one background round may spend, and failures that make it skip the rest of a node. */
	public const BUDGET_SECONDS = 45;
	public const NODE_FAILURES  = 3;

	/**
	 * Used and total MB from the text of /proc/meminfo, or null when it lacks MemTotal or MemAvailable.
	 * Used is MemTotal minus MemAvailable: the file cache is not counted.
	 *
	 * @param string $text File content.
	 * @return array{used_mb: int, total_mb: int}|null
	 */
	public static function parse( string $text ): ?array {
		if ( ! preg_match( '/^MemTotal:\s+(\d+)\s*kB/mi', $text, $total ) || ! preg_match( '/^MemAvailable:\s+(\d+)\s*kB/mi', $text, $available ) ) {
			return null;
		}

		$total     = (int) $total[1];
		$available = (int) $available[1];

		if ( $total <= 0 || $available > $total ) {
			return null;
		}

		return array(
			'used_mb'  => (int) round( ( $total - $available ) / 1024 ),
			'total_mb' => max( 1, (int) round( $total / 1024 ) ),
		);
	}

	/**
	 * Percent of a reading, 0 to 100.
	 *
	 * @param int $used  Used MB.
	 * @param int $total Total MB.
	 */
	public static function percent( int $used, int $total ): int {
		return $total > 0 ? max( 0, min( 100, (int) round( $used / $total * 100 ) ) ) : 0;
	}

	/**
	 * Reads /proc/meminfo of a running VM through the guest agent: one fixed path, and only on a VM that carries this
	 * resource's tag (the same proof as every other VM access). Never a customer-supplied path. Null when it cannot be read.
	 *
	 * @param Resource $resource Resource.
	 * @param Client   $client   Client.
	 * @param string   $node     Node.
	 * @param int      $vmid     VMID.
	 * @param bool     $node_down Set to true when the node itself did not answer (not the guest agent).
	 * @return array{used_mb: int, total_mb: int}|null
	 */
	public static function fetch( Resource $resource, Client $client, string $node, int $vmid, ?bool &$node_down = null ): ?array {
		$node_down = false;

		if ( '' === $node || $vmid <= 0 ) {
			return null;
		}

		$config = Ownership::config( $client, $node, $vmid );

		if ( is_wp_error( $config ) ) {
			$node_down = true;

			return null;
		}

		if ( ! Ownership::has_tag( (string) ( $config['description'] ?? '' ), Ownership::tag( $resource ) ) ) {
			return null;
		}

		$path   = '/nodes/' . rawurlencode( $node ) . '/qemu/' . $vmid . '/agent/file-read';
		$params = array( 'file' => '/proc/meminfo' );
		$reply  = $client instanceof LiveClient ? $client->request_with_timeout( 'GET', $path, $params, 5 ) : $client->request( 'GET', $path, $params );

		if ( is_wp_error( $reply ) || ! is_array( $reply ) || ! is_string( $reply['content'] ?? null ) ) {
			return null;
		}

		return self::parse( $reply['content'] );
	}

	/**
	 * Keeps a reading: it becomes the latest one, and a row of the 5-minute slot is written when the slot has none.
	 * Live reads and the background job both end here, so a slot never gets more than one row.
	 *
	 * @param int                                       $resource_id Resource id.
	 * @param array{used_mb: int, total_mb: int}        $reading     Reading.
	 * @param int|null                                  $now         Timestamp.
	 */
	public static function store( int $resource_id, array $reading, ?int $now = null ): void {
		global $wpdb;

		$now = $now ?? time();

		set_transient(
			self::LATEST_PREFIX . $resource_id,
			array( 'used_mb' => $reading['used_mb'], 'total_mb' => $reading['total_mb'], 'at' => $now ),
			self::MAX_AGE
		);
		delete_transient( self::FAILED_PREFIX . $resource_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; the primary key keeps one row per slot.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (resource_id, span, bucket, used_mb, total_mb, used_max_mb) VALUES (%d, %d, %d, %d, %d, %d)',
				Schema::tables()['guest_memory'],
				$resource_id,
				self::SPAN_RAW,
				intdiv( $now, self::SPAN_RAW ) * self::SPAN_RAW,
				$reading['used_mb'],
				$reading['total_mb'],
				$reading['used_mb']
			)
		);
	}

	/**
	 * Notes that the guest could not be read (no agent, no answer, not our tag).
	 *
	 * @param int      $resource_id Resource id.
	 * @param int|null $now         Timestamp.
	 */
	public static function failed( int $resource_id, ?int $now = null ): void {
		set_transient( self::FAILED_PREFIX . $resource_id, $now ?? time(), self::MAX_AGE );
	}

	/**
	 * The newest reading no older than 15 minutes: from the transient, else from the newest 5-minute row. No Proxmox call.
	 *
	 * @param int      $resource_id Resource id.
	 * @param int|null $now         Timestamp.
	 * @return array{used_mb: int, total_mb: int, percent: int, at: int}|null
	 */
	public static function latest( int $resource_id, ?int $now = null ): ?array {
		global $wpdb;

		$now  = $now ?? time();
		$kept = get_transient( self::LATEST_PREFIX . $resource_id );

		if ( is_array( $kept ) && isset( $kept['used_mb'], $kept['total_mb'], $kept['at'] ) && $now - (int) $kept['at'] <= self::MAX_AGE ) {
			return array(
				'used_mb'  => (int) $kept['used_mb'],
				'total_mb' => (int) $kept['total_mb'],
				'percent'  => self::percent( (int) $kept['used_mb'], (int) $kept['total_mb'] ),
				'at'       => (int) $kept['at'],
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT bucket, used_mb, total_mb FROM %i WHERE resource_id = %d AND span = %d AND bucket >= %d ORDER BY bucket DESC LIMIT 1', Schema::tables()['guest_memory'], $resource_id, self::SPAN_RAW, $now - self::MAX_AGE ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'used_mb'  => (int) $row['used_mb'],
			'total_mb' => (int) $row['total_mb'],
			'percent'  => self::percent( (int) $row['used_mb'], (int) $row['total_mb'] ),
			'at'       => (int) $row['bucket'],
		);
	}

	/**
	 * What the bar of a running server shows: ok (a reading), waiting (none yet, "…"), no_agent (the last read failed and
	 * nothing newer came, "–" and a hint).
	 *
	 * @param int      $resource_id Resource id.
	 * @param int|null $now         Timestamp.
	 * @return array{state: string, percent: int|null, used_mb: int|null, total_mb: int|null, age: int|null}
	 */
	public static function view( int $resource_id, ?int $now = null ): array {
		$now    = $now ?? time();
		$latest = self::latest( $resource_id, $now );
		$failed = get_transient( self::FAILED_PREFIX . $resource_id );

		if ( null !== $latest && ( ! is_numeric( $failed ) || (int) $failed <= $latest['at'] ) ) {
			return array(
				'state'    => 'ok',
				'percent'  => $latest['percent'],
				'used_mb'  => $latest['used_mb'],
				'total_mb' => $latest['total_mb'],
				'age'      => max( 0, $now - $latest['at'] ),
			);
		}

		return array(
			'state'    => is_numeric( $failed ) ? 'no_agent' : 'waiting',
			'percent'  => null,
			'used_mb'  => null,
			'total_mb' => null,
			'age'      => null,
		);
	}

	/**
	 * The text under a bar: "0.4 of 1.9 GB, updated 8 s ago". Empty when there is no reading.
	 *
	 * @param array<string, mixed> $view View from view().
	 */
	public static function line( array $view ): string {
		if ( 'ok' !== ( $view['state'] ?? '' ) ) {
			return '';
		}

		/* translators: 1: memory in use in GB, 2: total memory in GB, 3: how long ago the reading was taken, such as "8 s ago". */
		return sprintf( __( '%1$s of %2$s GB, updated %3$s', 'beltoft-services' ), number_format_i18n( (int) $view['used_mb'] / 1024, 1 ), number_format_i18n( (int) $view['total_mb'] / 1024, 1 ), self::ago( (int) $view['age'] ) );
	}

	/**
	 * "8 s ago" or "3 min ago".
	 *
	 * @param int $seconds Seconds.
	 */
	public static function ago( int $seconds ): string {
		if ( $seconds < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( __( '%d s ago', 'beltoft-services' ), $seconds );
		}

		/* translators: %d: minutes. */
		return sprintf( __( '%d min ago', 'beltoft-services' ), (int) floor( $seconds / 60 ) );
	}

	/**
	 * The text shown when the guest agent does not answer.
	 */
	public static function agent_hint(): string {
		return __( 'Memory needs the guest agent (qemu-guest-agent) running in your server.', 'beltoft-services' );
	}

	/**
	 * The history of a server for a chart range, on a regular time grid (a slot without a reading is null, so the chart shows a gap).
	 * hour: 5-minute points, day: 5-minute points, month: hourly points (average and peak; the newest two days are rolled up on the fly).
	 *
	 * @param int      $resource_id Resource id.
	 * @param string   $range       hour, day or month.
	 * @param int|null $now         Timestamp.
	 * @return array{step: int, points: array<int, array<string, mixed>>}
	 */
	public static function series( int $resource_id, string $range, ?int $now = null ): array {
		global $wpdb;

		$now   = $now ?? time();
		$table = Schema::tables()['guest_memory'];
		$hour  = 'month' === $range;
		$step  = $hour ? self::SPAN_HOUR : self::SPAN_RAW;
		$count = array(
			'hour'  => 12,
			'day'   => 288,
			'month' => 720,
		)[ $range ] ?? 12;
		$last  = intdiv( $now, $step ) * $step;
		$first = $last - ( $count - 1 ) * $step;

		if ( $hour ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT FLOOR(bucket / %d) * %d AS slot, ROUND(AVG(used_mb)) AS used_mb, ROUND(AVG(total_mb)) AS total_mb, MAX(used_max_mb) AS peak_mb FROM %i WHERE resource_id = %d AND bucket >= %d GROUP BY slot',
					self::SPAN_HOUR,
					self::SPAN_HOUR,
					$table,
					$resource_id,
					$first
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT bucket AS slot, used_mb, total_mb, used_max_mb AS peak_mb FROM %i WHERE resource_id = %d AND span = %d AND bucket >= %d', $table, $resource_id, self::SPAN_RAW, $first ),
				ARRAY_A
			);
		}

		$by_slot = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_slot[ (int) $row['slot'] ] = $row;
		}

		$points = array();

		for ( $t = $first; $t <= $last; $t += $step ) {
			$row = $by_slot[ $t ] ?? null;

			if ( null === $row ) {
				$points[] = array( 't' => $t, 'used_mb' => null, 'total_mb' => null, 'percent' => null, 'peak_percent' => null );
				continue;
			}

			$total    = max( 1, (int) $row['total_mb'] );
			$points[] = array(
				't'            => $t,
				'used_mb'      => (int) $row['used_mb'],
				'total_mb'     => $total,
				'percent'      => self::percent( (int) $row['used_mb'], $total ),
				'peak_percent' => self::percent( (int) $row['peak_mb'], $total ),
			);
		}

		return array( 'step' => $step, 'points' => $points );
	}

	/**
	 * The newest 5-minute readings of a server, newest first, as percent with their slot time.
	 *
	 * @param int $resource_id Resource id.
	 * @param int $limit       How many.
	 * @param int $since       Oldest slot time wanted.
	 * @return array<int, array{bucket: int, percent: int, used_mb: int, total_mb: int}>
	 */
	public static function recent( int $resource_id, int $limit, int $since = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT bucket, used_mb, total_mb FROM %i WHERE resource_id = %d AND span = %d AND bucket >= %d ORDER BY bucket DESC LIMIT %d', Schema::tables()['guest_memory'], $resource_id, self::SPAN_RAW, $since, $limit ), ARRAY_A );
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'bucket'   => (int) $row['bucket'],
				'percent'  => self::percent( (int) $row['used_mb'], (int) $row['total_mb'] ),
				'used_mb'  => (int) $row['used_mb'],
				'total_mb' => (int) $row['total_mb'],
			);
		}

		return $out;
	}

	/**
	 * Deletes every row of a server (its VM record was removed). Called by the deletion cascade.
	 *
	 * @param int $resource_id Resource id.
	 */
	public static function purge( int $resource_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->delete( Schema::tables()['guest_memory'], array( 'resource_id' => $resource_id ), array( '%d' ) );
		delete_transient( self::LATEST_PREFIX . $resource_id );
		delete_transient( self::FAILED_PREFIX . $resource_id );
	}

	/**
	 * The daily job: rolls 5-minute rows older than two days (whole hours only) up to hourly rows, deletes hourly rows
	 * older than 30 days, and deletes the rows of servers that have no VM record any more.
	 *
	 * @param int|null $now Timestamp.
	 */
	public static function cleanup( ?int $now = null ): void {
		global $wpdb;

		$now    = $now ?? time();
		$table  = Schema::tables()['guest_memory'];
		$vms    = Schema::tables()['vms'];
		$cutoff = intdiv( $now - self::RAW_DAYS * DAY_IN_SECONDS, self::SPAN_HOUR ) * self::SPAN_HOUR;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (resource_id, span, bucket, used_mb, total_mb, used_max_mb) SELECT resource_id, %d, FLOOR(bucket / %d) * %d, ROUND(AVG(used_mb)), ROUND(AVG(total_mb)), MAX(used_max_mb) FROM %i WHERE span = %d AND bucket < %d GROUP BY resource_id, FLOOR(bucket / %d)',
				$table,
				self::SPAN_HOUR,
				self::SPAN_HOUR,
				self::SPAN_HOUR,
				$table,
				self::SPAN_RAW,
				$cutoff,
				self::SPAN_HOUR
			)
		);
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE span = %d AND bucket < %d', $table, self::SPAN_RAW, $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE span = %d AND bucket < %d', $table, self::SPAN_HOUR, $now - self::HOURLY_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE m FROM %i m LEFT JOIN %i v ON v.resource_id = m.resource_id WHERE v.resource_id IS NULL', $table, $vms ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The recurring job (every 5 minutes): reads the memory of every running server of ours whose VM carries its tag, in
	 * a time budget, the servers read longest ago first. A node whose reads keep failing is skipped for the round, a server whose
	 * agent fails is marked and asked again next round. A server whose current 5-minute slot already has a reading (a customer's live read) is skipped, never one by age alone.
	 */
	public static function run( ?int $now = null ): void {
		$deadline = microtime( true ) + self::BUDGET_SECONDS;
		$now      = $now ?? time();
		$slot     = intdiv( $now, self::SPAN_RAW );

		foreach ( JobScope::clusters() as $id => $one ) {
			$rows = JobScope::vm_rows( $one['client'] );

			if ( null === $rows ) {
				continue;
			}

			$ours  = VmRecords::for_cluster( (string) $id );
			$queue = array();

			foreach ( $rows as $vm ) {
				$vmid = (int) ( $vm['vmid'] ?? 0 );

				if ( ! isset( $ours[ $vmid ] ) || ! empty( $vm['template'] ) || 'running' !== (string) ( $vm['status'] ?? '' ) || '' !== (string) ( $vm['lock'] ?? '' ) ) {
					continue;
				}

				$resource = Resource::find( (int) $ours[ $vmid ] );

				if ( ! $resource || 'ready' !== $resource->get( 'state' ) || 'ready' !== $resource->get( 'desired' ) || $resource->get( 'task' ) || $resource->on_hold() ) {
					continue;
				}

				if ( ProxmoxDriver::ID !== $resource->get( 'driver' ) && ! DbActions::is_db( $resource ) ) {
					continue;
				}

				$latest = self::latest( $resource->id(), $now );

				// Skipped only when the current 5-minute slot already has its row (every store writes the slot's row).
				if ( null !== $latest && intdiv( $latest['at'], self::SPAN_RAW ) === $slot ) {
					continue;
				}

				// Oldest reading first; a server whose agent did not answer lately goes after the others, so servers without
				// the agent never crowd out the ones that have it.
				$failed  = get_transient( self::FAILED_PREFIX . $resource->id() );
				$queue[] = array( $resource, $vm, max( null === $latest ? 0 : $latest['at'], is_numeric( $failed ) ? (int) $failed : 0 ) );
			}

			usort( $queue, static fn( $a, $b ) => $a[2] <=> $b[2] );

			$failures = array();

			foreach ( $queue as $item ) {
				if ( microtime( true ) >= $deadline ) {
					return;
				}

				list( $resource, $vm ) = $item;

				$node = (string) ( $vm['node'] ?? '' );

				if ( ( $failures[ $node ] ?? 0 ) >= self::NODE_FAILURES ) {
					continue;
				}

				$down    = false;
				$reading = self::fetch( $resource, $one['client'], $node, (int) $vm['vmid'], $down );

				if ( null === $reading ) {
					// Only a node that does not answer counts towards skipping it; a missing guest agent is the server's own.
					if ( $down ) {
						$failures[ $node ] = ( $failures[ $node ] ?? 0 ) + 1;
					}

					self::failed( $resource->id(), $now );
					continue;
				}

				$failures[ $node ] = 0;
				self::store( $resource->id(), $reading, $now );
				Monitor::memory( $resource, isset( $vm['uptime'] ) ? (int) $vm['uptime'] : null, $now );
			}
		}
	}
}
