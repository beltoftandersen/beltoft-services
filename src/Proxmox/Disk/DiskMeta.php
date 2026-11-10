<?php
/** Link an extra disk license to the server chosen at checkout. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Disk;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonLink;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Pve\DiskCapacity;
use BeltoftServices\Resource;
use BeltoftServices\Alerts;

defined( 'ABSPATH' ) || exit;

final class DiskMeta {
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 10, 3 );
	}

	public static function initial_meta( array $meta, array $license, Driver $driver ): array {
		return AddonLink::initial_meta( self::addon(), $meta, $license, $driver );
	}

	/** One unique server license in the same paid order, possibly issued after the disk. */
	public static function server_in_order( int $order_id, int $user_id ): ?int {
		$found = array();
		foreach ( Licenses::where( 'order_id', $order_id ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) === $user_id && self::is_server( $license ) ) {
				$found[] = (int) $license['id'];
			}
		}
		return 1 === count( $found ) ? $found[0] : null;
	}

	private static function is_server( array $license ): bool {
		return AddonLink::is_server( $license );
	}

	/** Live disk resources linked to one server license, including paused disks awaiting retention. */
	public static function for_server( Resource $server ): array {
		return AddonLink::for_server( self::addon(), $server );
	}

	private static function addon(): Addon {
		return Addon::for_driver( DiskDriver::ID );
	}

	/**
	 * Meta of a disk: its number on the server, "Data disk N". Given once and never reused.
	 */
	public const NUMBER = 'disk_no';

	/**
	 * Meta of the server: the highest number ever given to one of its extra disks.
	 */
	public const HIGHEST = 'disk_no_max';

	/**
	 * Gives every extra disk of the server that has no number the next free one, in today's order (the number of
	 * the slot, then the id), under the server's disk lock. A removed disk's number is not given again: the
	 * server keeps the highest number ever used. A disk that gets its slot is numbered by the same call.
	 *
	 * @param Resource $server Server resource.
	 * @return bool Whether every disk has a number now (false: the lock was busy).
	 */
	public static function number_disks( Resource $server ): bool {
		$slot  = static fn( Resource $d ): int => 1 === preg_match( '/(\d+)$/', (string) $d->get( 'slot' ), $m ) ? (int) $m[1] : PHP_INT_MAX;
		$count = 0;

		foreach ( self::for_server( $server ) as $disk ) {
			$count += $disk->get( self::NUMBER ) ? 0 : 1;
		}

		if ( 0 === $count ) {
			return true;
		}

		list( $locked ) = Locks::with(
			Locks::name( 'server_disks', $server->id() ),
			Locks::allocation_wait(),
			static function () use ( $server, $slot ): void {
				$fresh   = Resource::find( $server->id() );
				$disks   = self::for_server( $server );
				$highest = (int) ( $fresh ? $fresh->get( self::HIGHEST ) : 0 );
				$missing = array();

				foreach ( $disks as $disk ) {
					if ( $disk->get( self::NUMBER ) ) {
						$highest = max( $highest, (int) $disk->get( self::NUMBER ) );
					} else {
						$missing[] = $disk;
					}
				}

				usort( $missing, static fn( Resource $a, Resource $b ): int => array( $slot( $a ), $a->id() ) <=> array( $slot( $b ), $b->id() ) );

				foreach ( $missing as $disk ) {
					++$highest;
					$disk->set( array( self::NUMBER => $highest ) );
				}

				if ( $fresh && $missing ) {
					$fresh->set( array( self::HIGHEST => $highest ) );
				}
			}
		);

		return $locked;
	}

	/**
	 * What the customer calls the extra disks of a server: "Data disk N" from the disk's own number, in number
	 * order. The slot name itself is for the admin views. Disk id => label.
	 *
	 * @param Resource $server Server resource.
	 * @return array<int, string>
	 */
	public static function labels( Resource $server ): array {
		self::number_disks( $server );

		$disks = array();

		foreach ( self::for_server( $server ) as $disk ) {
			$disks[] = array( (int) $disk->get( self::NUMBER ), $disk->id() );
		}

		// A disk the busy lock left without a number comes after the numbered ones, not stored.
		usort( $disks, static fn( array $a, array $b ): int => array( 0 === $a[0] ? PHP_INT_MAX : $a[0], $a[1] ) <=> array( 0 === $b[0] ? PHP_INT_MAX : $b[0], $b[1] ) );

		$out  = array();
		$next = $disks ? max( array_column( $disks, 0 ) ) : 0;

		foreach ( $disks as $entry ) {
			$number = $entry[0] > 0 ? $entry[0] : ++$next;
			/* translators: %d: the number of the extra disk of a server, 1 for the first. */
			$out[ $entry[1] ] = sprintf( __( 'Data disk %d', 'beltoft-services' ), $number );
		}

		return $out;
	}

	/**
	 * The customer's name for one extra disk, or 'Data disk' when its server is not known.
	 *
	 * @param Resource $disk Disk resource.
	 */
	public static function label( Resource $disk ): string {
		$server = Resource::for_license( (int) $disk->get( 'server_license' ) );
		$labels = $server ? self::labels( $server ) : array();

		return $labels[ $disk->id() ] ?? __( 'Data disk', 'beltoft-services' );
	}

	/** Reclaim reservations after failure. */
	public static function state_changed( $resource, $old, $new ): void {
		if ( ! $resource instanceof Resource ) {
			return;
		}
		if ( DiskDriver::ID === $resource->get( 'driver' ) && in_array( $new, array( 'failed', 'deleted' ), true ) ) {
			DiskCapacity::release( $resource );
		}
	}

	/**
	 * A disk whose server was deleted has been ended by AddonCascade: close it, release its capacity and tell the admin.
	 *
	 * @param Resource $disk Disk.
	 */
	public static function after_parent_deleted( Resource $disk ): void {
		// Silent on purpose: the capacity is released here (see Resource::transition()).
		$disk->close();
		DiskCapacity::release( $disk );
		Alerts::admin( $disk, 'The parent server was deleted while the extra disk subscription may still bill. Check subscription #' . (int) ( $disk->license()['subscription_id'] ?? 0 ) . '.' );
	}
}
