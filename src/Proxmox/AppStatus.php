<?php
/** App status and one-time credentials from bounded guest-agent reads. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class AppStatus {
	public const HOOK = 'beltoft_services_app_check';
	private const LIMIT = 21600;

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( \BeltoftServices\Jobs::SWEEP, array( __CLASS__, 'sweep' ) );
	}

	public static function schedule( int $id, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$waiting = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array( $id ), 'group' => \BeltoftServices\Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );
			if ( $waiting ) {
				return;
			}
			as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), \BeltoftServices\Jobs::GROUP );
		}
	}

	/** Recover a lost poll after an interrupted worker. */
	public static function sweep(): void {
		foreach ( self::candidates() as $id ) {
			$r = Resource::find( $id );
			if ( $r && in_array( $r->get( 'app_state' ), array( 'installing', 'unknown' ), true ) && time() - (int) $r->get( 'app_started_at' ) < self::LIMIT ) {
				self::schedule( $id, 15 );
			}
		}
	}

	/** Narrow the sweep in SQL before loading resources. Verify each match after reading it. */
	public static function candidates(): array {
		global $wpdb;
		$table = \BeltoftLicenses\Install::tables()['activations'];
		$installing = '%' . $wpdb->esc_like( '"app_state":"installing"' ) . '%';
		$unknown = '%' . $wpdb->esc_like( '"app_state":"unknown"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded custom-table sweep, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE kind = 'vm' AND deactivated_at IS NULL AND (meta LIKE %s OR meta LIKE %s)", $table, $installing, $unknown ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function run( int $id ): void {
		$r = Resource::find( $id );
		if ( ! $r || ! $r->is_live() || ! in_array( $r->get( 'app_state' ), array( 'installing', 'unknown' ), true ) ) {
			return;
		}
		$r->locked( static function () use ( $r ) {
			$record = VmRecords::for_resource( $r->id() );
			$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
			if ( ! $record || ! $cluster ) {
				return false;
			}
			if ( null !== Clients::gate( $record['cluster'], $cluster, Clients::live_allowed() ) ) {
				return false;
			}
			try {
				$client = Clients::for( $cluster );
			} catch ( \Throwable $e ) {
				return false;
			}
			self::check( $r, $client, $record['node'], (int) $record['vmid'] );
			return true;
		} );
	}

	/** Parse an untrusted guest status, matching only this request and fixed fields. */
	public static function check( Resource $r, Client $client, string $node, int $vmid ): void {
		if ( ! in_array( $r->get( 'app_state' ), array( 'installing', 'unknown' ), true ) ) {
			return;
		}
		$raw = Apps::read_file( $client, $node, $vmid, Apps::STATUS );
		$status = null === $raw ? null : json_decode( $raw, true );
		$request = (string) $r->get( 'app_request' );
		$app = Apps::version( (string) $r->get( 'app_id' ), (string) $r->get( 'app_version' ), true );
		$valid = is_array( $status ) && '' !== $request && hash_equals( $request, (string) ( $status['request'] ?? '' ) ) && in_array( $status['state'] ?? '', array( 'installing', 'ready', 'failed' ), true ) && in_array( $status['step'] ?? '', (array) ( $app['steps'] ?? array() ), true ) && is_string( $status['updated'] ?? null ) && false !== strtotime( $status['updated'] );
		if ( $valid ) {
			$state = $status['state'];
			$meta = array( 'app_state' => $state, 'app_step' => $status['step'], 'app_checked_at' => time() );
			if ( 'ready' === $state ) {
				$credentials = self::credentials( $r, $client, $node, $vmid );
				if ( array() !== (array) ( $app['credentials'] ?? array() ) && null === $credentials ) {
					$meta['app_state'] = 'installing';
					$meta['app_step'] = 'credentials';
				} else {
					$meta['app_installed_at'] = time();
				}
				if ( null !== $credentials && array() !== $credentials ) {
					$meta['app_reveal'] = Crypto::encrypt( wp_json_encode( $credentials ) );
					$meta['app_reveal_until'] = time() + Access::REVEAL_DAYS * DAY_IN_SECONDS;
				}
			}
			$r->set( $meta );
			if ( 'failed' === $state ) {
				AppAlerts::failure( $r, 'Installation failed at ' . $status['step'] );
			}
		} elseif ( time() - (int) $r->get( 'app_started_at' ) > 1800 ) {
			$r->set( array( 'app_state' => 'unknown', 'app_checked_at' => time() ) );
			AppAlerts::failure( $r, 'Installation has not reported a valid status within 30 minutes' );
		}
		if ( in_array( $r->get( 'app_state' ), array( 'installing', 'unknown' ), true ) && time() - (int) $r->get( 'app_started_at' ) < self::LIMIT ) {
			self::schedule( $r->id(), time() - (int) $r->get( 'app_started_at' ) < 120 ? 15 : 30 );
		}
	}

	private static function credentials( Resource $r, Client $client, string $node, int $vmid ): ?array {
		$app = Apps::version( (string) $r->get( 'app_id' ), (string) $r->get( 'app_version' ), true );
		$keys = (array) ( $app['credentials'] ?? array() );
		if ( array() === $keys ) {
			return null;
		}
		$raw = Apps::read_file( $client, $node, $vmid, Apps::CREDENTIALS );
		$data = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$out = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]+$/', $key ) || ! is_string( $data[ $key ] ?? null ) || strlen( $data[ $key ] ) > 256 || preg_match( '/[\x00-\x1f\x7f]/', $data[ $key ] ) ) {
				return null;
			}
			$out[ $key ] = $data[ $key ];
		}
		return $out;
	}
}
