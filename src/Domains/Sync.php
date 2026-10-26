<?php
/** Daily registrar status and DNS delegation refresh. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Sync {
	public const HOOK = 'beltoft_services_domains_sync';
	public const DELEGATION_HOOK = 'beltoft_services_dns_delegation';
	public const HEALTH_HOOK = 'beltoft_services_dns_health';
	public const CURSOR = 'beltoft_services_domains_sync_cursor';

	public static function register(): void {
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::DELEGATION_HOOK, array( __CLASS__, 'delegation' ) );
		add_action( self::HEALTH_HOOK, array( Health::class, 'dns_check' ) );
	}
	public static function schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) { return; }
		if ( ! as_next_scheduled_action( self::HOOK, array(), 'beltoft-services' ) ) { as_schedule_cron_action( time() + HOUR_IN_SECONDS, '45 4 * * *', self::HOOK, array(), 'beltoft-services' ); }
		if ( ! as_next_scheduled_action( self::DELEGATION_HOOK, array(), 'beltoft-services' ) ) { as_schedule_cron_action( time() + HOUR_IN_SECONDS, '15 5 * * *', self::DELEGATION_HOOK, array(), 'beltoft-services' ); }
		if ( ! as_next_scheduled_action( self::HEALTH_HOOK, array(), 'beltoft-services' ) ) { as_schedule_cron_action( time() + 15 * MINUTE_IN_SECONDS, '*/15 * * * *', self::HEALTH_HOOK, array(), 'beltoft-services' ); }
	}
	public static function run(): void {
		$ids = Resource::live_ids( 'domain' );
		$cursor = (int) get_option( self::CURSOR, 0 );
		$batch = array_slice( array_values( array_filter( $ids, static fn( $id ) => $id > $cursor ) ), 0, 50 );
		if ( ! $batch ) { $batch = array_slice( $ids, 0, 50 ); }
		foreach ( $batch as $id ) {
			$r = Resource::find( $id );
			if ( ! $r || ! $r->is_live() || ! ctype_digit( (string) $r->get( 'remote_id' ) ) ) { continue; }
			$reply = Clients::registrar()->request( 'GET', '/domains/' . $r->get( 'remote_id' ) );
			if ( is_wp_error( $reply ) ) { Alerts::admin( $r, 'Domain sync could not read the registrar status.' ); continue; }
			$status = (string) ( $reply['status'] ?? '' );
			$expiry = (string) ( $reply['expiration_date'] ?? '' );
			if ( 'ACT' !== $status ) { Alerts::admin( $r, 'Domain status changed at the registrar.' ); }
			if ( '' !== $expiry && $r->get( 'expires_at' ) && $expiry !== $r->get( 'expires_at' ) ) { Alerts::admin( $r, 'Domain expiry changed at the registrar.' ); }
			$r->set( array( 'registrar_status' => $status, 'expires_at' => '' !== $expiry ? $expiry : $r->get( 'expires_at' ), 'is_locked' => (bool) ( $reply['is_locked'] ?? $r->get( 'is_locked' ) ), 'last_sync' => time() ) );
		}
		update_option( self::CURSOR, count( $batch ) < 50 ? 0 : (int) end( $batch ), false );
		Health::balance();
	}
	public static function delegation(): void {
		foreach ( Resource::live_ids( 'dns' ) as $id ) {
			$r = Resource::find( $id );
			if ( $r && $r->is_live() && 'ours' !== $r->get( 'delegation' ) ) { Delegation::refresh( $r ); }
		}
	}
}
