<?php
/** Short-lived, one-use console tickets. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/** Stores only encrypted upstream tickets; the browser token is represented by its hash. */
final class ConsoleSessions {
	public const PREFIX = 'beltoft_services_console_';
	public const TTL = 30;
	public const PURGE = 'beltoft_services_console_purge';

	public static function register(): void {
		add_action( self::PURGE, array( __CLASS__, 'purge' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 10, 3 );
	}

	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::PURGE, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), '*/5 * * * *', self::PURGE, array(), Jobs::GROUP );
		}
	}

	/** Fingerprint the request's trusted peer address without storing it. */
	public static function ip_hash( string $ip ): string {
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	/**
	 * All still valid, unused sessions. Direct SQL bypasses an object cache's stale option value.
	 *
	 * @return array<string, array<string, mixed>> Option name to record.
	 */
	private static function pending(): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-use tickets must be read fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM %i WHERE option_name LIKE %s", $wpdb->options, $like ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) $row['option_value'], true );
			if ( is_array( $data ) && (int) ( $data['created'] ?? 0 ) + self::TTL > time() ) {
				$out[ (string) $row['option_name'] ] = $data;
			}
		}
		return $out;
	}

	/** Whether another pending ticket would exceed the per-resource or per-user limit. */
	public static function at_limit( int $user_id, int $resource_id ): bool {
		$user = 0;
		foreach ( self::pending() as $entry ) {
			if ( (int) ( $entry['resource'] ?? 0 ) === $resource_id ) {
				return true;
			}
			if ( (int) ( $entry['user'] ?? 0 ) === $user_id ) {
				++$user;
			}
		}
		return $user >= 2;
	}

	/**
	 * Create one pending ticket. The lock serializes the count and add_option across requests.
	 *
	 * @return string|\WP_Error 64-character browser token, or a plain refusal.
	 */
	public static function create( int $user_id, int $resource_id, string $ip, string $cluster, string $node, int $vmid, int $port, string $ticket ) {
		if ( $user_id < 1 || $resource_id < 1 || $vmid < 1 || $port < 1 || '' === $ticket || ! Crypto::available() ) {
			return new \WP_Error( 'console_unavailable', 'The console is not available.' );
		}
		list( $locked, $result ) = Locks::with(
			Locks::name( 'console', 0 ),
			3,
			static function () use ( $user_id, $resource_id, $ip, $cluster, $node, $vmid, $port, $ticket ) {
				if ( self::at_limit( $user_id, $resource_id ) ) {
					return new \WP_Error( 'console_busy', 'A console is already being opened. Please wait a moment.' );
				}
				$token = bin2hex( random_bytes( 32 ) );
				$name = self::PREFIX . hash( 'sha256', $token );
				$record = array(
					'user' => $user_id,
					'resource' => $resource_id,
					'ip_hash' => self::ip_hash( $ip ),
					'cluster' => $cluster,
					'node' => $node,
					'vmid' => $vmid,
					'port' => $port,
					'ticket' => Crypto::encrypt( $ticket ),
					'created' => time(),
				);
				return add_option( $name, wp_json_encode( $record ), '', false ) ? $token : new \WP_Error( 'console_unavailable', 'The console is not available.' );
			}
		);
		return $locked ? $result : new \WP_Error( 'console_busy', 'A console is already being opened. Please wait a moment.' );
	}

	/** Read a browser token. Only the server-side caller receives the encrypted record. */
	public static function peek( string $token, int $user_id, string $ip ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || $user_id < 1 ) {
			return null;
		}
		global $wpdb;
		$name = self::PREFIX . hash( 'sha256', $token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-use ticket must be read fresh.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || (int) ( $data['created'] ?? 0 ) + self::TTL <= time() || (int) ( $data['user'] ?? 0 ) !== $user_id || ! hash_equals( (string) ( $data['ip_hash'] ?? '' ), self::ip_hash( $ip ) ) ) {
			return null;
		}
		$data['_name'] = $name;
		$data['_raw'] = $raw;
		return $data;
	}

	/** Exactly one concurrent consumer can remove this value. */
	public static function consume( array $entry ): ?string {
		global $wpdb;
		$name = (string) ( $entry['_name'] ?? '' );
		$raw = (string) ( $entry['_raw'] ?? '' );
		if ( '' === $name || '' === $raw || (int) ( $entry['created'] ?? 0 ) + self::TTL <= time() ) {
			return null;
		}
		$ticket = Crypto::decrypt( (string) ( $entry['ticket'] ?? '' ) );
		if ( null === $ticket ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-delete makes the ticket single-use.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $raw ) );
		wp_cache_delete( $name, 'options' );
		return 1 === $deleted ? $ticket : null;
	}

	/** Remove expired rows; a bounded periodic sweep. */
	public static function purge(): int {
		global $wpdb;
		$removed = 0;
		foreach ( self::rows( 250 ) as $name => $raw ) {
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) || (int) ( $data['created'] ?? 0 ) + self::TTL <= time() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- only exact stale value is removed.
				$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $raw ) );
				wp_cache_delete( $name, 'options' );
			}
		}
		return $removed;
	}

	/** Remove pending tickets when a service becomes unavailable. */
	public static function state_changed( Resource $r, string $old, string $new ): void {
		if ( in_array( $new, array( 'paused', 'deleting', 'deleted', 'failed' ), true ) ) {
			self::purge_resource( $r->id() );
		}
	}

	public static function purge_resource( int $resource_id ): int {
		global $wpdb;
		$removed = 0;
		foreach ( self::rows( 1000 ) as $name => $raw ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) && (int) ( $data['resource'] ?? 0 ) === $resource_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- remove this resource's unused ticket.
				$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $raw ) );
				wp_cache_delete( $name, 'options' );
			}
		}
		return $removed;
	}

	/** @return array<string,string> */
	private static function rows( int $limit ): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- console option sweep.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d', $wpdb->options, $like, $limit ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['option_name'] ] = (string) $row['option_value'];
		}
		return $out;
	}
}
