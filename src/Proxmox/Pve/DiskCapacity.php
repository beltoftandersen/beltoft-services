<?php
/** Capacity accounting for node-local extra disks. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Pve;

use BeltoftServices\Alerts;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Db\DbDisk;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Vm\VmRecords;
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
			$found = $ids || DbDisk::sold() ? 'yes' : 'no';
			set_transient( 'beltoft_services_disk_products', $found, 10 * MINUTE_IN_SECONDS );
		}

		// Databases sold with extra storage and snapshots read the storage too.
		return (bool) apply_filters( 'beltoft_services_disk_capacity_enabled', 'yes' === $found || (bool) Settings::get( 'snapshots_enabled' ) );
	}

	/** Free bytes of the last fresh storage reading, or null when there is none. */
	public static function available( string $cluster, string $node, string $storage ): ?int {
		$row = get_transient( self::cache_key( $cluster, $node, $storage ) );

		return is_array( $row ) && time() - (int) ( $row['read_at'] ?? 0 ) < 2 * HOUR_IN_SECONDS ? (int) $row['avail'] : null;
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

	/** The last fresh storage reading (total and free bytes), or null when there is none. No remote call. */
	public static function reading( string $cluster, string $node, string $storage ): ?array {
		$row = get_transient( self::cache_key( $cluster, $node, $storage ) );

		return is_array( $row ) && time() - (int) ( $row['read_at'] ?? 0 ) < 2 * HOUR_IN_SECONDS ? array( 'total' => (int) $row['total'], 'avail' => (int) $row['avail'] ) : null;
	}

	/** GB of the other disk reservations on a node's storage. */
	private static function reserved_gb( string $cluster, string $node, string $storage, int $exclude ): int {
		$reserved = 0;
		foreach ( (array) get_option( self::RESERVATIONS, array() ) as $id => $entry ) {
			if ( (int) $id !== $exclude && is_array( $entry ) && $entry['cluster'] === $cluster && $entry['node'] === $node && $entry['storage'] === $storage ) {
				$reserved += (int) $entry['gb'];
			}
		}
		return $reserved;
	}

	/** GB the live servers and extra disks of a node count as theirs (what is promised, not what is used). */
	public static function committed_gb( string $cluster, string $node ): int {
		$promised = 0;
		foreach ( VmRecords::rows_for_cluster( $cluster ) as $record ) {
			if ( $record['node'] !== $node ) {
				continue;
			}
			$server = Resource::find( (int) $record['resource_id'] );
			if ( $server && $server->is_live() ) {
				$plan = (array) $server->get( 'plan' );
				$promised += DbDriver::ID === $server->get( 'driver' ) ? DbDisk::promised_gb( $server ) : max( (int) ( $plan['disk_gb'] ?? 0 ), (int) $server->get( 'disk_gb' ) );
				// Volumes left on the storage by deleted disks stay until the server goes.
				$promised += (int) array_sum( array_map( 'intval', array_filter( (array) $server->get( 'left_volumes' ), 'is_numeric' ) ) );
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
		return $promised;
	}

	/**
	 * True only with a fresh reading and the space really free on the storage (after the other reservations and the
	 * kept-free reserve). The promised total is not a limit: capacity is oversold on purpose (owner decision 2026-11-11),
	 * so this is asked only when a disk is made, never to stop a sale.
	 */
	public static function fits( string $cluster, string $node, string $storage, int $gb, int $exclude = 0 ): bool {
		$row = self::reading( $cluster, $node, $storage );
		if ( null === $row || $gb < 1 ) {
			return false;
		}
		$reserve = max( 0, (int) Settings::get( 'disk_reserve_gb' ) );
		return $row['avail'] >= ( $gb + self::reserved_gb( $cluster, $node, $storage, $exclude ) + $reserve ) * 1073741824;
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

	/**
	 * Tells the admin, once per job, that a disk create, disk grow or database grow waits because the storage does not have
	 * the space free: the storage, the size asked and the free space. The first wait only: a job that waits again says
	 * nothing more (the final failure alert of the reconciler stays). The mail never fails the step. Clearing happens in
	 * release(), so the next job of the same resource says it again.
	 *
	 * @param Resource $r       Disk or database resource.
	 * @param string   $op      What waits: create, grow or db-grow.
	 * @param string   $cluster Cluster id.
	 * @param string   $node    Node.
	 * @param string   $storage Storage.
	 * @param int      $gb      GB asked.
	 */
	public static function wait_alert( Resource $r, string $op, string $cluster, string $node, string $storage, int $gb ): void {
		try {
			$row  = self::reading( $cluster, $node, $storage );
			$free  = null === $row ? __( 'unknown (no fresh reading)', 'beltoft-services' ) : sprintf( '%.1f GB', ( (int) $row['avail'] ) / 1073741824 );
			$names = array(
				'create'  => __( 'disk create', 'beltoft-services' ),
				'grow'    => __( 'disk grow', 'beltoft-services' ),
				'db-grow' => __( 'database disk grow', 'beltoft-services' ),
			);

			Alerts::notify(
				/* translators: %s: storage name. */
				sprintf( __( 'Storage %s has no room for a disk job: waiting', 'beltoft-services' ), $storage ),
				array(
					/* translators: 1: job (disk create, disk grow, database disk grow), 2: resource id, 3: storage, 4: node, 5: cluster. */
					sprintf( __( 'A %1$s job for resource %2$d is waiting because storage "%3$s" on node "%4$s" (cluster "%5$s") does not have the space free.', 'beltoft-services' ), $names[ $op ] ?? $op, $r->id(), $storage, $node, $cluster ),
					/* translators: 1: GB asked, 2: free space, for example "12.0 GB". */
					sprintf( __( 'Size asked: %1$d GB. Free space on the storage: %2$s (after the kept-free reserve and the other waiting disks it is less).', 'beltoft-services' ), $gb, $free ),
					__( 'The job tries again every hour and the customer is not told. Free space on the storage or add storage; the job then goes on by itself.', 'beltoft-services' ),
				),
				null,
				self::wait_key( $r, $op )
			);
		} catch ( \Throwable $e ) {
			error_log( 'Beltoft Services: the storage wait alert failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- an alert never fails the step.
		}
	}

	/**
	 * The transient that holds back a second wait alert of the same job.
	 *
	 * @param Resource $r  Resource.
	 * @param string   $op Operation.
	 */
	private static function wait_key( Resource $r, string $op ): string {
		return 'beltoft_services_wait_' . $r->id() . '_' . $op;
	}

	public static function release( Resource $disk ): void {
		foreach ( array( 'create', 'grow', 'db-grow' ) as $op ) {
			delete_transient( self::wait_key( $disk, $op ) );
		}

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
