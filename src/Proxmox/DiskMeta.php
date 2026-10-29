<?php
/** Link an extra disk license to the server chosen at checkout. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
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
	 * What the customer calls the extra disks of a server: "Data disk 1", "Data disk 2", in slot
	 * order (the number of the slot, scsi2 before scsi10; a disk with no slot yet comes last, by id).
	 * The slot name itself is for the admin views. Disk id => label.
	 *
	 * @param Resource $server Server resource.
	 * @return array<int, string>
	 */
	public static function labels( Resource $server ): array {
		$disks = self::for_server( $server );
		$slot  = static fn( Resource $d ): int => 1 === preg_match( '/(\d+)$/', (string) $d->get( 'slot' ), $m ) ? (int) $m[1] : PHP_INT_MAX;

		usort( $disks, static fn( Resource $a, Resource $b ): int => array( $slot( $a ), $a->id() ) <=> array( $slot( $b ), $b->id() ) );

		$out = array();

		foreach ( array_values( $disks ) as $i => $disk ) {
			/* translators: %d: the number of the extra disk of a server, 1 for the first. */
			$out[ $disk->id() ] = sprintf( __( 'Data disk %d', 'beltoft-services' ), $i + 1 );
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
