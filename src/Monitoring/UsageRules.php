<?php
/**
 * The customer usage emails of any guest (a server, a hosting account): CPU, memory and disk against the settings.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Monitoring;

use BeltoftLicenses\Events;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One set of rules for every guest: the same settings (monitor_alerts, monitor_cpu_*, monitor_mem_*, monitor_disk_*,
 * monitor_cooldown_days), the same per-service switch (meta usage_alerts) and the same email. The callers pass what they read.
 */
final class UsageRules {

	public const EMAIL_ID = 'beltoft_services_monitor_alert';

	/** The customer's per-service switch for the usage emails (disk, CPU and memory). The down email ignores it. */
	public static function wanted( Resource $r ): bool {
		return 'off' !== $r->get( 'usage_alerts' );
	}

	/**
	 * The disk levels every service uses (servers here, databases in Proxmox\Db\DbMonitor): the warning level (setting
	 * monitor_disk_percent, default 90) and the urgent one (monitor_disk_urgent_percent, default 97, always above the
	 * warning). Each re-arms five points below itself (REARM).
	 *
	 * @return array{0: int, 1: int} Warning level, urgent level, in percent.
	 */
	public static function disk_levels(): array {
		$threshold = (int) Settings::get( 'monitor_disk_percent' );
		$threshold = $threshold >= 50 && $threshold <= 99 ? $threshold : 90;
		$urgent    = (int) Settings::get( 'monitor_disk_urgent_percent' );
		$urgent    = $urgent >= 60 && $urgent <= 100 ? max( $urgent, $threshold + 1 ) : max( 97, $threshold + 1 );

		return array( $threshold, $urgent );
	}

	/** Points under a disk level at which it re-arms. */
	public const REARM = 5;

	/**
	 * Warn once per disk-full episode; a second, stronger email at the urgent level. Each level
	 * re-arms five points below its threshold.
	 */
	public static function disk( Resource $r, array $disk, ?int $now = null ): void {
		$now = $now ?? time();
		$used = (int) ( $disk['used'] ?? 0 );
		$total = (int) ( $disk['total'] ?? 0 );
		if ( $total <= 0 || $used < 0 ) { return; }
		list( $threshold, $urgent ) = self::disk_levels();
		$percent = 100 * $used / $total;
		$r->locked( static function () use ( $r, $disk, $now, $threshold, $urgent, $percent ): bool {
			if ( $percent < $threshold - 5 && ( $r->get( 'monitor_disk_notified' ) || $r->get( 'monitor_disk_urgent_notified' ) ) ) {
				return $r->set( array( 'monitor_disk_notified' => null, 'monitor_disk_urgent_notified' => null ) );
			}
			if ( $percent < $urgent - 5 && $r->get( 'monitor_disk_urgent_notified' ) ) {
				return $r->set( array( 'monitor_disk_urgent_notified' => null ) );
			}
			if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $percent < $threshold || 'on' !== Settings::get( 'monitor_alerts' ) || ! self::wanted( $r ) ) {
				return false;
			}
			$is_urgent = $percent >= $urgent;
			if ( $is_urgent ? $r->get( 'monitor_disk_urgent_notified' ) : $r->get( 'monitor_disk_notified' ) ) {
				return false;
			}
			$data = array( 'reason' => $is_urgent ? 'disk_urgent' : 'disk', 'disk_used' => $disk['used'], 'disk_total' => $disk['total'] );
			if ( ! Emails::send( self::EMAIL_ID, $r, $data ) ) {
				return false;
			}
			$stored = $r->set( $is_urgent
				? array( 'monitor_disk_urgent_notified' => $now, 'monitor_disk_notified' => $r->get( 'monitor_disk_notified' ) ?: $now )
				: array( 'monitor_disk_notified' => $now ) );
			if ( $stored ) { Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', $is_urgent ? 'Monitoring email: disk almost completely full' : 'Monitoring email: disk nearly full' ); }
			return $stored;
		} );
	}

	/** Seconds after a boot or reinstall in which memory is still settling: no memory email. */
	public const MEM_SETTLE = 900;

	/**
	 * Sustained high memory in use (without the file cache), judged on the stored 5-minute readings of the guest: the
	 * newest readings in a row, at least monitor_mem_minutes long, all at or above monitor_mem_percent. One email per episode
	 * (it re-arms when a reading is 10 points under the threshold), the same cool-down as CPU between two emails for one server.
	 * Never for database servers (they reserve memory on purpose), adopted machines, a stopped server, or in the first 15
	 * minutes after the server started (a boot or a reinstall; readings from before the boot do not count).
	 *
	 * @param Resource $r      Resource.
	 * @param int|null $uptime Seconds the VM has run, when Proxmox says; null when unknown.
	 * @param int|null $now    Timestamp.
	 */
	public static function memory( Resource $r, ?int $uptime = null, ?int $now = null ): void {
		$now = $now ?? time();
		if ( 'on' !== Settings::get( 'monitor_alerts' ) || true === $r->get( 'linked' ) || null === Guests::for( $r ) ) { return; }
		$threshold = (int) Settings::get( 'monitor_mem_percent' );
		$threshold = $threshold >= 50 && $threshold <= 99 ? $threshold : 90;
		$minutes = (int) Settings::get( 'monitor_mem_minutes' );
		$minutes = $minutes >= 5 && $minutes <= 120 ? $minutes : 15;
		$days = (int) Settings::get( 'monitor_cooldown_days' );
		$days = $days >= 1 && $days <= 90 ? $days : 7;
		$count = max( 1, intdiv( $minutes, 5 ) );
		$since = null !== $uptime ? $now - max( 0, $uptime ) : 0;
		$recent = GuestMemory::recent( $r->id(), $count, max( $since, $now - ( $count + 3 ) * GuestMemory::SPAN_RAW ) );
		if ( ! $recent ) { return; }
		$cleared = $recent[0]['percent'] <= $threshold - 10;
		// The newest readings must be a run: each next one at most two slots (10 minutes) before the one after it.
		$high = count( $recent ) >= $count && $now - $recent[0]['bucket'] <= 2 * GuestMemory::SPAN_RAW + GuestMemory::SPAN_RAW;
		$oldest = $recent[0]['bucket'];
		foreach ( $recent as $i => $reading ) {
			if ( $reading['percent'] < $threshold || ( $i > 0 && $recent[ $i - 1 ]['bucket'] - $reading['bucket'] > 2 * GuestMemory::SPAN_RAW ) ) { $high = false; }
			$oldest = $reading['bucket'];
		}
		if ( null !== $uptime && $uptime < self::MEM_SETTLE ) { $high = false; }
		$r->locked( static function () use ( $r, $now, $days, $threshold, $cleared, $high, $oldest, $recent ): bool {
			if ( $cleared && $r->get( 'monitor_mem_notified' ) ) {
				return $r->set( array( 'monitor_mem_notified' => null, 'monitor_mem_since' => null ) );
			}
			if ( ! $high || $r->get( 'monitor_mem_notified' ) || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $r->get( 'task' ) || ! self::wanted( $r ) ) {
				return false;
			}
			$last = (int) $r->get( 'monitor_mem_sent_at' );
			if ( $last > 0 && $now - $last < $days * DAY_IN_SECONDS ) {
				return false;
			}
			$data = array(
				'reason'       => 'memory',
				'mem_percent'  => $threshold,
				'mem_minutes'  => count( $recent ) * 5,
				'mem_used_mb'  => $recent[0]['used_mb'],
				'mem_total_mb' => $recent[0]['total_mb'],
				'mem_since'    => $oldest,
			);
			if ( ! Emails::send( self::EMAIL_ID, $r, $data ) ) {
				return false;
			}
			$stored = $r->set( array( 'monitor_mem_notified' => $now, 'monitor_mem_sent_at' => $now, 'monitor_mem_since' => $oldest ) );
			if ( $stored ) { Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Monitoring email: memory high' ); }
			return $stored;
		} );
	}

	/**
	 * Sustained high CPU, judged on the hourly usage window of a running VM. One email per episode,
	 * a cool-down between two emails for one server, and a re-arm when the newest sample is 20 points
	 * under the threshold. Adopted machines are never mailed. A stopped VM leaves gaps in the window,
	 * which end a run, so a recent boot is not read as sustained load.
	 *
	 * @param Resource          $r       Resource.
	 * @param array<int, mixed> $samples Rows of rrddata.
	 * @param int|null          $now     Timestamp.
	 */
	public static function usage( Resource $r, array $samples, ?int $now = null ): void {
		$now = $now ?? time();
		if ( 'on' !== Settings::get( 'monitor_alerts' ) || true === $r->get( 'linked' ) ) { return; }
		$threshold = (int) Settings::get( 'monitor_cpu_percent' );
		$threshold = $threshold >= 50 && $threshold <= 100 ? $threshold : 90;
		$hours = (int) Settings::get( 'monitor_cpu_hours' );
		$hours = $hours >= 1 && $hours <= 24 ? $hours : 3;
		$days = (int) Settings::get( 'monitor_cooldown_days' );
		$days = $days >= 1 && $days <= 90 ? $days : 7;
		$high = Samples::analyse( $samples, (float) $threshold );
		if ( null === $high ) { return; }
		$low = Samples::analyse( $samples, (float) max( 0, $threshold - 20 ) );
		$cleared = null !== $low && null === $low['trailing'];
		$since = Samples::since( Samples::CPU_PREFIX, $r->id(), $high, $hours + 24 );
		$duration = max( $high['longest'], null === $since ? 0 : $high['last'] - $since );
		$r->locked( static function () use ( $r, $now, $threshold, $hours, $days, $cleared, $since, $duration, $high ): bool {
			if ( $cleared && $r->get( 'monitor_cpu_notified' ) ) {
				return $r->set( array( 'monitor_cpu_notified' => null, 'monitor_cpu_since' => null ) );
			}
			if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $r->get( 'task' ) || ! self::wanted( $r ) ) {
				return false;
			}
			if ( $duration < $hours * HOUR_IN_SECONDS || $r->get( 'monitor_cpu_notified' ) ) {
				return false;
			}
			$last = (int) $r->get( 'monitor_cpu_sent_at' );
			if ( $last > 0 && $now - $last < $days * DAY_IN_SECONDS ) {
				return false;
			}
			$began = $since ?? $high['last'] - $duration;
			if ( ! Emails::send( self::EMAIL_ID, $r, array( 'reason' => 'cpu', 'cpu_percent' => $threshold, 'cpu_hours' => $hours, 'cpu_since' => $began ) ) ) {
				return false;
			}
			$stored = $r->set( array( 'monitor_cpu_notified' => $now, 'monitor_cpu_sent_at' => $now, 'monitor_cpu_since' => $began ) );
			if ( $stored ) { Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Monitoring email: CPU high' ); }
			return $stored;
		} );
	}

	/**
	 * Plain lines for what the usage emails told the customer about CPU and memory and is still the case. Cleared when the alert re-arms.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	public static function states( Resource $r ): array {
		$lines = array();
		$stamp = (string) get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		if ( $r->get( 'monitor_cpu_notified' ) && $r->get( 'monitor_cpu_since' ) ) {
			/* translators: %s: date and time. */
			$lines[] = sprintf( __( 'CPU high since %s', 'beltoft-services' ), wp_date( $stamp, (int) $r->get( 'monitor_cpu_since' ) ) );
		}

		if ( $r->get( 'monitor_mem_notified' ) && $r->get( 'monitor_mem_since' ) ) {
			/* translators: %s: date and time. */
			$lines[] = sprintf( __( 'Memory high since %s', 'beltoft-services' ), wp_date( $stamp, (int) $r->get( 'monitor_mem_since' ) ) );
		}

		return $lines;
	}
}
