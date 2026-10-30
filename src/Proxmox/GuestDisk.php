<?php
/**
 * Root filesystem usage reported by the VM's guest agent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class GuestDisk {
	private const SUCCESS_TTL = 6 * HOUR_IN_SECONDS;
	private const FAILURE_TTL = HOUR_IN_SECONDS;
	/** A normal server is read when its reading is this old (a little under six hours, so the six hourly run finds it due). */
	private const READ_TTL = 6 * HOUR_IN_SECONDS - 600;
	/** A server at or above this percent is read every hour (a little under, so the hourly run finds it due). */
	public const WATCH_PERCENT = 85;
	private const WATCH_TTL = HOUR_IN_SECONDS - 300;

	/**
	 * Whether the disk job should read this server now: no reading, an old one, or a high one that is an hour old.
	 * A failed read counts as a reading for this (a server with no agent is tried about every six hours).
	 *
	 * @param Resource $r Resource.
	 */
	public static function due( Resource $r ): bool {
		$total = (int) $r->get( 'disk_total' );
		$last  = max( (int) $r->get( 'disk_read_at' ), (int) $r->get( 'disk_fail_at' ) );
		if ( $last <= 0 ) {
			return true;
		}
		$high = $total > 0 && 100 * (int) $r->get( 'disk_used' ) / $total >= self::WATCH_PERCENT;

		return time() - $last >= ( $high ? self::WATCH_TTL : self::READ_TTL );
	}

	/** The last successful reading, while its six-hour cache is valid. */
	public static function cached( Resource $r ): ?array {
		$used = $r->get( 'disk_used' );
		$total = $r->get( 'disk_total' );
		$read_at = (int) $r->get( 'disk_read_at' );
		if ( $read_at <= 0 || time() - $read_at >= self::SUCCESS_TTL || ! is_numeric( $used ) || ! is_numeric( $total ) || (int) $used < 0 || (int) $total <= 0 || (int) $used > (int) $total ) {
			return null;
		}
		return array( 'used' => (int) $used, 'total' => (int) $total );
	}

	/**
	 * Read a running VM at most once in six hours. A failed agent read waits one hour.
	 * The customer route only reads the stored meta and never calls this method.
	 *
	 * @return array{used:int,total:int}|null
	 */
	public static function read( Resource $r ): ?array {
		$disk = null;
		$r->locked( static function () use ( $r, &$disk ): bool {
			if ( 'proxmox' !== $r->get( 'driver' ) || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $r->get( 'task' ) ) {
				return false;
			}

			$now = time();
			$status = Operations::cached_status( $r->id() );
			if ( null !== $status && 'running' !== $status['power'] ) {
				return false;
			}
			$disk = self::cached( $r );
			if ( null !== $disk && ! self::due( $r ) ) {
				return false;
			}
			$disk = null;
			if ( $now - (int) $r->get( 'disk_fail_at' ) < self::FAILURE_TTL ) {
				return false;
			}

			$record = VmRecords::for_resource( $r->id() );
			if ( null === $record ) {
				return false;
			}
			$cluster = Clusters::get( (string) $record['cluster'] );
			if ( null === $cluster || null !== Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() ) ) {
				return false;
			}
			try {
				$client = Clients::for( $cluster );
			} catch ( \Throwable $e ) {
				return false;
			}
			$verified = Ownership::verify( $client, $record, $r );
			if ( is_wp_error( $verified ) || 'running' !== $verified['status'] || '' !== $verified['lock'] ) {
				return false;
			}

			$path = '/nodes/' . rawurlencode( $verified['node'] ) . '/qemu/' . (int) $record['vmid'] . '/agent/get-fsinfo';
			$reply = $client instanceof LiveClient
				? $client->request_with_timeout( 'GET', $path, array(), 5 )
				: $client->request( 'GET', $path );
			$disk = is_wp_error( $reply ) ? null : self::root( $reply );
			if ( null === $disk ) {
				return $r->set( array( 'disk_fail_at' => $now ) );
			}
			$stored = $r->set( array(
				'disk_used' => $disk['used'],
				'disk_total' => $disk['total'],
				'disk_read_at' => $now,
				'disk_fail_at' => null,
			) );
			if ( ! $stored ) {
				$disk = null;
			}
			return $stored;
		} );
		return $disk;
	}

	/** Parse the observed Proxmox reply shape, using only the root mount. */
	public static function root( $reply ): ?array {
		if ( ! is_array( $reply ) || ! isset( $reply['result'] ) || ! is_array( $reply['result'] ) ) {
			return null;
		}
		foreach ( $reply['result'] as $row ) {
			if ( ! is_array( $row ) || '/' !== ( $row['mountpoint'] ?? null ) ) {
				continue;
			}
			$used = $row['used-bytes'] ?? null;
			$total = $row['total-bytes'] ?? null;
			if ( ! is_numeric( $used ) || ! is_numeric( $total ) || (float) $used < 0 || (float) $total <= 0 || (float) $used > (float) $total ) {
				return null;
			}
			return array( 'used' => (int) $used, 'total' => (int) $total );
		}
		return null;
	}
}
