<?php
/** Customer VPS monitoring alerts. @package BeltoftServices */
namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Monitor {
	public const EMAIL_ID = 'beltoft_services_monitor_alert';

	/** A down alert requires two readings separated by at least 30 minutes. */
	public static function power( Resource $r, string $power, ?int $now = null ): void {
		$now = $now ?? time();
		$r->locked( static function () use ( $r, $power, $now ): bool {
			if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $r->get( 'task' ) ) {
				return false;
			}
			if ( 'running' === $power ) {
				if ( ! $r->get( 'monitor_down_since' ) && ! $r->get( 'monitor_down_notified' ) && ! $r->get( 'stopped_by_customer' ) ) { return false; }
				return $r->set( array( 'monitor_down_since' => null, 'monitor_down_notified' => null, 'stopped_by_customer' => null ) );
			}
			if ( 'stopped' !== $power || $r->get( 'stopped_by_customer' ) ) {
				return false;
			}
			$since = (int) $r->get( 'monitor_down_since' );
			if ( $since <= 0 ) {
				return $r->set( array( 'monitor_down_since' => $now ) );
			}
			if ( $now - $since < 1800 || $r->get( 'monitor_down_notified' ) || 'on' !== Settings::get( 'monitor_alerts' ) ) {
				return false;
			}
			if ( ! Emails::send( self::EMAIL_ID, $r, array( 'reason' => 'down' ) ) ) {
				return false;
			}
			$stored = $r->set( array( 'monitor_down_notified' => $now ) );
			if ( $stored ) { Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Monitoring email: server down' ); }
			return $stored;
		} );
	}

	/** The customer's per-server switch for the usage emails (disk and CPU). The down email ignores it. */
	public static function usage_wanted( Resource $r ): bool {
		return 'off' !== $r->get( 'usage_alerts' );
	}

	/**
	 * Warn once per disk-full episode; a second, stronger email at the urgent level. Each level
	 * re-arms five points below its threshold.
	 */
	public static function disk( Resource $r, array $disk, ?int $now = null ): void {
		$now = $now ?? time();
		$used = (int) ( $disk['used'] ?? 0 );
		$total = (int) ( $disk['total'] ?? 0 );
		if ( $total <= 0 || $used < 0 ) { return; }
		$threshold = (int) Settings::get( 'monitor_disk_percent' );
		$threshold = $threshold >= 50 && $threshold <= 99 ? $threshold : 90;
		$urgent = (int) Settings::get( 'monitor_disk_urgent_percent' );
		$urgent = $urgent >= 60 && $urgent <= 100 ? max( $urgent, $threshold + 1 ) : max( 97, $threshold + 1 );
		$percent = 100 * $used / $total;
		$r->locked( static function () use ( $r, $disk, $now, $threshold, $urgent, $percent ): bool {
			if ( $percent < $threshold - 5 && ( $r->get( 'monitor_disk_notified' ) || $r->get( 'monitor_disk_urgent_notified' ) ) ) {
				return $r->set( array( 'monitor_disk_notified' => null, 'monitor_disk_urgent_notified' => null ) );
			}
			if ( $percent < $urgent - 5 && $r->get( 'monitor_disk_urgent_notified' ) ) {
				return $r->set( array( 'monitor_disk_urgent_notified' => null ) );
			}
			if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $percent < $threshold || 'on' !== Settings::get( 'monitor_alerts' ) || ! self::usage_wanted( $r ) ) {
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
		$high = ProxmoxJobs::analyse( $samples, (float) $threshold );
		if ( null === $high ) { return; }
		$low = ProxmoxJobs::analyse( $samples, (float) max( 0, $threshold - 20 ) );
		$cleared = null !== $low && null === $low['trailing'];
		$since = Usage::since( Usage::CPU_PREFIX, $r->id(), $high, $hours + 24 );
		$duration = max( $high['longest'], null === $since ? 0 : $high['last'] - $since );
		$r->locked( static function () use ( $r, $now, $threshold, $hours, $days, $cleared, $since, $duration, $high ): bool {
			if ( $cleared && $r->get( 'monitor_cpu_notified' ) ) {
				return $r->set( array( 'monitor_cpu_notified' => null, 'monitor_cpu_since' => null ) );
			}
			if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $r->get( 'task' ) || ! self::usage_wanted( $r ) ) {
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
}
