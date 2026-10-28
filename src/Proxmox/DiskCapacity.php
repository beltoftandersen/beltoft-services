<?php
/** Capacity accounting for node-local extra disks. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DiskCapacity {
	public const RESERVATIONS = 'beltoft_services_disk_reservations';
	private const PREFIX = 'beltoft_services_disk_capacity_';

	private static function cache_key( string $cluster, string $node, string $storage ): string {
		return self::PREFIX . md5( $cluster . '|' . $node . '|' . $storage );
	}

	/**
	 * Is there a disk product? The storage reading is only taken for them, so a site that sells
	 * no extra disks makes no extra call to its cluster. The filter forces it on or off.
	 */
	public static function wanted(): bool {
		$found = get_transient( 'beltoft_services_disk_products' );
		if ( false === $found ) {
			$ids   = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private' ), 'meta_key' => \BeltoftServices\Product::META_DRIVER, 'meta_value' => 'proxmox-disk', 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one row, once every ten minutes.
			$found = $ids ? 'yes' : 'no';
			set_transient( 'beltoft_services_disk_products', $found, 10 * MINUTE_IN_SECONDS );
		}

		return (bool) apply_filters( 'beltoft_services_disk_capacity_enabled', 'yes' === $found );
	}

	/** Store only a real storage-status reply. */
	public static function refresh( string $cluster, string $node, string $storage, Client $client ): bool {
		$reply = $client->request( 'GET', '/nodes/' . rawurlencode( $node ) . '/storage/' . rawurlencode( $storage ) . '/status' );
		if ( ! is_array( $reply ) || empty( $reply['active'] ) || ! isset( $reply['total'], $reply['avail'] ) ) {
			return false;
		}
		set_transient( self::cache_key( $cluster, $node, $storage ), array( 'total' => (int) $reply['total'], 'avail' => (int) $reply['avail'], 'read_at' => time() ), 2 * HOUR_IN_SECONDS );
		return true;
	}

	/** True only with a fresh reading and enough free and promised capacity. */
	public static function fits( string $cluster, string $node, string $storage, int $gb, int $exclude = 0 ): bool {
		$row = get_transient( self::cache_key( $cluster, $node, $storage ) );
		if ( ! is_array( $row ) || time() - (int) ( $row['read_at'] ?? 0 ) >= 2 * HOUR_IN_SECONDS || $gb < 1 ) {
			return false;
		}
		$reservations = (array) get_option( self::RESERVATIONS, array() );
		$reserved = 0;
		foreach ( $reservations as $id => $entry ) {
			if ( (int) $id !== $exclude && is_array( $entry ) && $entry['cluster'] === $cluster && $entry['node'] === $node && $entry['storage'] === $storage ) {
				$reserved += (int) $entry['gb'];
			}
		}
		$reserve = max( 0, (int) Settings::get( 'disk_reserve_gb' ) );
		if ( (int) $row['avail'] < ( $gb + $reserved + $reserve ) * 1073741824 ) {
			return false;
		}
		$promised = 0;
		foreach ( VmRecords::rows_for_cluster( $cluster ) as $record ) {
			if ( $record['node'] !== $node ) {
				continue;
			}
			$server = Resource::find( (int) $record['resource_id'] );
			if ( $server && $server->is_live() ) {
				$plan = (array) $server->get( 'plan' );
				$promised += max( (int) ( $plan['disk_gb'] ?? 0 ), (int) $server->get( 'disk_gb' ) );
			}
		}
		foreach ( Resource::live_ids( 'disk' ) as $id ) {
			$disk = Resource::find( $id );
			if ( ! $disk || ! $disk->is_live() ) {
				continue;
			}
			$server = Resource::for_license( (int) $disk->get( 'server_license' ) );
			$record = $server ? VmRecords::for_resource( $server->id() ) : null;
			if ( $record && $record['cluster'] === $cluster && $record['node'] === $node ) {
				$promised += max( 0, (int) $disk->get( 'size_gb' ) );
			}
		}
		$percent = min( 100, max( 1, (int) Settings::get( 'disk_promise_percent' ) ) );
		return ( $promised + $reserved + $gb ) * 1073741824 <= (int) $row['total'] * $percent / 100;
	}

	/** Reserve one disk or grow delta atomically across all nodes and storages. */
	public static function reserve( Resource $disk, string $cluster, string $node, string $storage, int $gb ): bool {
		list( $locked, $ok ) = Locks::with( Locks::name( 'disk_capacity', 0 ), Locks::allocation_wait(), static function () use ( $disk, $cluster, $node, $storage, $gb ): bool {
			$entries = (array) get_option( self::RESERVATIONS, array() );
			$existing = $entries[ $disk->id() ] ?? null;
			if ( is_array( $existing ) && $existing['cluster'] === $cluster && $existing['node'] === $node && $existing['storage'] === $storage && (int) $existing['gb'] === $gb ) {
				return true;
			}
			// A different size or place (a grow retried after the target moved) replaces this resource's own reservation, which does not count against itself.
			if ( ! self::fits( $cluster, $node, $storage, $gb, $disk->id() ) ) {
				return false;
			}
			$entries[ $disk->id() ] = array( 'cluster' => $cluster, 'node' => $node, 'storage' => $storage, 'gb' => $gb, 'at' => time() );
			return update_option( self::RESERVATIONS, $entries, false );
		} );
		return $locked && $ok;
	}

	public static function release( Resource $disk ): void {
		Locks::with( Locks::name( 'disk_capacity', 0 ), Locks::allocation_wait(), static function () use ( $disk ): void {
			$entries = (array) get_option( self::RESERVATIONS, array() );
			unset( $entries[ $disk->id() ] );
			update_option( self::RESERVATIONS, $entries, false );
		} );
	}

	/** Current reservation for the admin panel; never a remote request. */
	public static function reservation( Resource $disk ): ?array {
		$entries = (array) get_option( self::RESERVATIONS, array() );
		$entry = $entries[ $disk->id() ] ?? null;
		return is_array( $entry ) ? $entry : null;
	}

	/** Remove reservations left by resources that no longer exist or are closed. */
	public static function sweep(): void {
		Locks::with( Locks::name( 'disk_capacity', 0 ), Locks::allocation_wait(), static function (): void {
			$entries = (array) get_option( self::RESERVATIONS, array() );
			$changed = false;
			foreach ( $entries as $id => $entry ) {
				$resource = Resource::find( (int) $id );
				if ( ! is_array( $entry ) || ( time() - (int) ( $entry['at'] ?? 0 ) > DAY_IN_SECONDS && ( ! $resource || ! $resource->is_live() ) ) ) {
					unset( $entries[ $id ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( self::RESERVATIONS, $entries, false );
			}
		} );
	}
}
