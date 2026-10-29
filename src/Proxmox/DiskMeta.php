<?php
/** Link an extra disk license to the server chosen at checkout. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Alerts;

defined( 'ABSPATH' ) || exit;

final class DiskMeta {
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 10, 3 );
	}

	public static function initial_meta( array $meta, array $license, Driver $driver ): array {
		if ( DiskDriver::ID !== $driver->id() ) {
			return $meta;
		}
		$order_id = (int) ( $license['order_id'] ?? 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return $meta;
		}
		$uid = '';
		$subscription_id = (int) ( $license['subscription_id'] ?? 0 );
		if ( $subscription_id > 0 && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				foreach ( $subscription->get_items() as $item ) {
					if ( (int) $item->get_product_id() === (int) $license['product_id'] ) {
						$uid = (string) $item->get_meta( '_beltoft_services_disk_uid', true );
						break;
					}
				}
			}
		}
		$matches = array();
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() !== (int) $license['product_id'] ) {
				continue;
			}
			$item_uid = (string) $item->get_meta( '_beltoft_services_disk_uid', true );
			if ( '' !== $uid && $uid !== $item_uid ) {
				continue;
			}
			$matches[] = $item;
		}
		if ( 1 !== count( $matches ) ) {
			return $meta;
		}
		foreach ( $matches as $item ) {
			$link = $item->get_meta( '_beltoft_services_disk_server', true );
			if ( is_numeric( $link ) && (int) $link > 0 ) {
				$server = Licenses::find( (int) $link );
				if ( $server && (int) $server['user_id'] === (int) $license['user_id'] && self::is_server( $server ) ) {
					$meta['server_license'] = (int) $link;
				}
			} elseif ( 'cart_server' === $link ) {
				$meta['server_order_id'] = $order_id;
			}
			$meta['order_item_id'] = (int) $item->get_id();
			break;
		}
		return $meta;
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
		$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );
		return $driver && SshKeyField::DRIVER === $driver->id();
	}

	/** Live disk resources linked to one server license, including paused disks awaiting retention. */
	public static function for_server( Resource $server ): array {
		$license = $server->license();
		$out = array();
		foreach ( Resource::live_ids( 'disk' ) as $id ) {
			$disk = Resource::find( $id );
			if ( $disk && DiskDriver::ID === $disk->get( 'driver' ) && (int) $disk->get( 'server_license' ) === (int) ( $license['id'] ?? 0 ) ) {
				$out[] = $disk;
			}
		}
		return $out;
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

	/** Reclaim reservations after failure; account for disks lost with a deleted parent VM. */
	public static function state_changed( $resource, $old, $new ): void {
		if ( ! $resource instanceof Resource ) {
			return;
		}
		if ( DiskDriver::ID === $resource->get( 'driver' ) && in_array( $new, array( 'failed', 'deleted' ), true ) ) {
			DiskCapacity::release( $resource );
			return;
		}
		if ( 'deleted' !== $new || SshKeyField::DRIVER !== $resource->get( 'driver' ) ) {
			return;
		}
		foreach ( self::for_server( $resource ) as $disk ) {
			if ( $disk->transition( 'deleted', 'Parent server deleted; extra disk no longer available' ) ) {
				$disk->close();
				DiskCapacity::release( $disk );
				Alerts::admin( $disk, 'The parent server was deleted while the extra disk subscription may still bill. Check subscription #' . (int) ( $disk->license()['subscription_id'] ?? 0 ) . '.' );
			}
		}
	}
}
