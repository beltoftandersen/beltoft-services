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

	/** Warn once per disk-full episode; re-arm after usage falls five points below the threshold. */
	public static function disk( Resource $r, array $disk, ?int $now = null ): void {
		$now = $now ?? time();
		$used = (int) ( $disk['used'] ?? 0 );
		$total = (int) ( $disk['total'] ?? 0 );
		if ( $total <= 0 || $used < 0 ) { return; }
		$threshold = (int) Settings::get( 'monitor_disk_percent' );
		$threshold = $threshold >= 50 && $threshold <= 99 ? $threshold : 90;
		$percent = 100 * $used / $total;
		$r->locked( static function () use ( $r, $disk, $now, $threshold, $percent ): bool {
			if ( $percent < $threshold - 5 && $r->get( 'monitor_disk_notified' ) ) {
				return $r->set( array( 'monitor_disk_notified' => null ) );
			}
			if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $percent < $threshold || $r->get( 'monitor_disk_notified' ) || 'on' !== Settings::get( 'monitor_alerts' ) ) {
				return false;
			}
			if ( ! Emails::send( self::EMAIL_ID, $r, array( 'reason' => 'disk', 'disk_used' => $disk['used'], 'disk_total' => $disk['total'] ) ) ) {
				return false;
			}
			$stored = $r->set( array( 'monitor_disk_notified' => $now ) );
			if ( $stored ) { Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Monitoring email: disk nearly full' ); }
			return $stored;
		} );
	}
}
