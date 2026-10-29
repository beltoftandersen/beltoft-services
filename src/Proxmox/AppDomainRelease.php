<?php
/** Remove only app A records this plugin made, after a reinstall starts. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Domains\Records;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class AppDomainRelease {
	public const HOOK = 'beltoft_services_app_domain_release';
	private const DELAYS = array( 60, 300, 900, 3600 );

	public static function register(): void {
		add_action( 'beltoft_services_reinstall_started', array( __CLASS__, 'started' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( Jobs::SWEEP, array( __CLASS__, 'sweep' ) );
	}

	/** The action fires after reinstall metadata is stored. No DNS work runs inside the server job. */
	public static function started( Resource $r, array $args = array() ): void {
		if ( (array) $r->get( 'app_domain_old' ) ) {
			self::schedule( $r->id() );
		}
	}

	public static function schedule( int $id, int $delay = 0 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$waiting = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );
		if ( ! $waiting ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), Jobs::GROUP );
		}
	}

	/** Recover an old pending request if its scheduled action was lost. */
	public static function sweep(): void {
		global $wpdb;
		$table = \BeltoftLicenses\Install::tables()['activations'];
		$pending = '%' . $wpdb->esc_like( '"app_domain_old":[' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table sweep, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE kind = 'vm' AND deactivated_at IS NULL AND meta LIKE %s", $table, $pending ) );
		foreach ( (array) $ids as $id ) {
			$r = Resource::find( (int) $id );
			$old = $r ? (array) $r->get( 'app_domain_old' ) : array();
			if ( $r && $r->is_live() && $old && time() - (int) ( $old[0]['at'] ?? 0 ) >= MINUTE_IN_SECONDS ) {
				self::schedule( $r->id() );
			}
		}
	}

	/** One bounded attempt. Records::delete_set takes the zone lock; this job never takes the resource lock. */
	public static function run( int $id ): void {
		$r = Resource::find( $id );
		if ( ! $r || ! $r->is_live() ) {
			return;
		}
		$old = (array) $r->get( 'app_domain_old' );
		if ( ! $old ) {
			return;
		}
		$entry = array_shift( $old );
		if ( ! is_array( $entry ) ) {
			$r->set( array( 'app_domain_old' => $old ) );
			return;
		}
		$zone = (string) ( $entry['zone'] ?? '' );
		$name = (string) ( $entry['name'] ?? '' );
		$ip = (string) ( $entry['ip'] ?? '' );
		$user = (int) ( $r->license()['user_id'] ?? 0 );
		if ( '' === $zone || '' === $name || $name === (string) $r->get( 'app_domain' ) || $name === (string) $r->get( 'app_domain_want' ) || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || ! self::editable( $zone, $user ) ) {
			$r->set( array( 'app_domain_old' => $old ) );
			if ( $old ) { self::schedule( $id ); }
			return;
		}
		$existing = AppDomains::existing( $zone, $name );
		if ( is_array( $existing ) && array_diff( array_keys( $existing ), array( 'A' ) ) ) {
			$r->set( array( 'app_domain_old' => $old ) );
			if ( $old ) { self::schedule( $id ); }
			return;
		}
		$expect = array( array( 'ttl' => AppDomains::TTL, 'value' => $ip ) );
		$done = Records::delete_set( $zone, $name, 'A', $expect );
		if ( true === $done || in_array( $done->get_error_code(), array( 'stale', 'records_off', 'zone_missing' ), true ) ) {
			$r->set( array( 'app_domain_old' => $old ) );
			if ( $old ) { self::schedule( $id ); }
			return;
		}
		$tries = (int) ( $entry['tries'] ?? 0 );
		if ( $tries < count( self::DELAYS ) ) {
			$entry['tries'] = $tries + 1;
			$entry['at'] = time();
			array_unshift( $old, $entry );
			$r->set( array( 'app_domain_old' => $old ) );
			self::schedule( $id, self::DELAYS[ $tries ] );
			return;
		}
		$r->set( array( 'app_domain_old' => $old ) );
		AppAlerts::domain_release_failed( $r, $name, $done->get_error_message() );
		if ( $old ) { self::schedule( $id ); }
	}

	/** The current owner must still be able to edit this precise zone. */
	private static function editable( string $zone, int $user ): bool {
		if ( $user <= 0 || ! class_exists( \BeltoftLicenses\Licenses::class ) ) {
			return false;
		}
		foreach ( \BeltoftLicenses\Licenses::where( 'user_id', $user ) as $license ) {
			$domain = Resource::for_license( (int) $license['id'] );
			if ( $domain && strtolower( (string) $domain->get( 'domain' ) ) === $zone && AppDomains::editable( $domain, $user ) ) {
				return true;
			}
		}
		return false;
	}
}
