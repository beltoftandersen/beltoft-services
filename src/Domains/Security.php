<?php
/** Security notices and the automatic re-lock of domains. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A domain that is unlocked for transfer is exposed, so the customer is told when it is unlocked, when its transfer code is
 * shown and when its name servers change (never with the code itself), and a domain left unlocked is locked again after a
 * number of days (setting domain_relock_days, default 0 = off) unless the registrar shows an outgoing transfer under way.
 */
final class Security {
	public const HOOK = 'beltoft_services_domains_relock';
	/** Registrar calls one run may make. */
	public const BATCH = 50;

	public static function register(): void {
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'relock' ) );
	}

	public static function schedule(): void {
		if ( function_exists( 'as_next_scheduled_action' ) && ! as_next_scheduled_action( self::HOOK, array(), 'beltoft-services' ) ) {
			as_schedule_cron_action( time() + HOUR_IN_SECONDS, '30 5 * * *', self::HOOK, array(), 'beltoft-services' );
		}
	}

	/** Tell the customer of one security event. Never throws; the transfer code is never part of it. */
	public static function notify( Resource $r, string $event ): bool {
		if ( 'domain' !== $r->get( 'driver' ) ) { return false; }
		return Emails::send( 'beltoft_services_domain_security', $r, array( 'domain_event' => $event ) );
	}

	public static function days(): int {
		return max( 0, min( 60, (int) Settings::get( 'domain_relock_days' ) ) );
	}

	/** Daily: lock again each ready domain that has been unlocked for the set number of days. */
	public static function relock(): void {
		$days = self::days();
		if ( $days <= 0 || Settings::get( 'pause_all' ) ) { return; }
		$calls = 0;
		foreach ( Resource::live_ids( 'domain' ) as $id ) {
			if ( $calls >= self::BATCH ) { return; }
			$r = Resource::find( $id );
			if ( ! $r || ! $r->is_live() || 'domain' !== $r->get( 'driver' ) || 'ready' !== $r->get( 'state' ) || ! ctype_digit( (string) $r->get( 'remote_id' ) ) ) { continue; }
			if ( $r->get( 'is_locked' ) ) {
				if ( $r->get( 'unlocked_at' ) ) { $r->set( array( 'unlocked_at' => null ) ); }
				continue;
			}
			$since = (int) $r->get( 'unlocked_at' );
			if ( $since <= 0 ) {
				// Unlocked in another way than the account action (a transfer arrives unlocked): the days count from here.
				$r->set( array( 'unlocked_at' => time() ) );
				continue;
			}
			if ( time() - $since < $days * DAY_IN_SECONDS ) { continue; }
			++$calls;
			self::relock_one( $r );
		}
	}

	private static function relock_one( Resource $r ): void {
		$path   = '/domains/' . $r->get( 'remote_id' );
		$detail = Clients::registrar()->request( 'GET', $path );
		if ( is_wp_error( $detail ) ) { return; }
		// Anything but an active domain may be an outgoing transfer under way: it is left alone.
		if ( 'ACT' !== ( $detail['status'] ?? '' ) || apply_filters( 'beltoft_services_domain_outgoing_transfer_pending', false, $r, $detail ) ) { return; }
		if ( empty( $detail['is_locked'] ) ) {
			$reply = Clients::registrar()->request( 'PUT', $path, array( 'is_locked' => true ) );
			if ( is_wp_error( $reply ) ) { return; }
			$r->set( array( 'is_locked' => true, 'unlocked_at' => null, 'relocked_at' => time() ) );
			self::notify( $r, 'relocked' );
			return;
		}
		// Locked at the registrar already: only the record was behind.
		$r->set( array( 'is_locked' => true, 'unlocked_at' => null ) );
	}
}
