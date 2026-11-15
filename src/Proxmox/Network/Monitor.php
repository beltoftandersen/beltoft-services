<?php
/** Customer VPS monitoring alerts: the power rule; the usage rules are the shared Monitoring\UsageRules. @package BeltoftServices */
namespace BeltoftServices\Proxmox\Network;

use BeltoftLicenses\Events;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Monitoring\UsageRules;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Monitor {
	public const EMAIL_ID = UsageRules::EMAIL_ID;

	/** Seconds after a boot or reinstall in which memory is still settling: no memory email. */
	public const MEM_SETTLE = UsageRules::MEM_SETTLE;

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

	/** The customer's per-server switch for the usage emails (disk, CPU, memory). The down email ignores it. */
	public static function usage_wanted( Resource $r ): bool {
		return UsageRules::wanted( $r );
	}

	/** Disk-full emails: the shared rule. */
	public static function disk( Resource $r, array $disk, ?int $now = null ): void {
		UsageRules::disk( $r, $disk, $now );
	}

	/** Sustained high memory: the shared rule. */
	public static function memory( Resource $r, ?int $uptime = null, ?int $now = null ): void {
		UsageRules::memory( $r, $uptime, $now );
	}

	/** Sustained high CPU: the shared rule. */
	public static function usage( Resource $r, array $samples, ?int $now = null ): void {
		UsageRules::usage( $r, $samples, $now );
	}
}
