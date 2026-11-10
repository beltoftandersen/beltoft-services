<?php
/** Ties a bought add-on to its server, once for all kinds. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Addon;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class AddonLink {
	/**
	 * Meta of a new add-on license from its order line: which server it is for and which line it came from. The line says
	 * which server (the nonce was checked at add to cart); the server must be the buyer's own.
	 *
	 * @param Addon  $a       Add-on kind.
	 * @param array  $meta    Meta so far.
	 * @param array  $license License row.
	 * @param Driver $driver  Driver of the license.
	 * @return array
	 */
	public static function initial_meta( Addon $a, array $meta, array $license, Driver $driver ): array {
		if ( $a->driver_id !== $driver->id() ) {
			return $meta;
		}

		$order_id = (int) ( $license['order_id'] ?? 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		// Only a snapshot pack refuses a licence without a user (as before); a disk and an app licence go on.
		if ( ! $order || ( $a->server_must_be_live && (int) ( $license['user_id'] ?? 0 ) < 1 ) ) {
			return $meta;
		}

		$uid             = '';
		$subscription_id = (int) ( $license['subscription_id'] ?? 0 );

		if ( $subscription_id > 0 && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $subscription_id );

			foreach ( $subscription ? $subscription->get_items() : array() as $item ) {
				if ( (int) $item->get_product_id() === (int) $license['product_id'] ) {
					$uid = (string) $item->get_meta( $a->item_uid(), true );
					break;
				}
			}
		}

		$matches = array();

		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() !== (int) $license['product_id'] ) {
				continue;
			}

			if ( '' !== $uid && $uid !== (string) $item->get_meta( $a->item_uid(), true ) ) {
				continue;
			}

			$matches[] = $item;
		}

		if ( 1 !== count( $matches ) ) {
			return $meta;
		}

		$link = $matches[0]->get_meta( $a->item_server(), true );

		if ( is_numeric( $link ) && (int) $link > 0 ) {
			$server = Licenses::find( (int) $link );

			if ( $server && (int) $server['user_id'] === (int) $license['user_id'] && self::is_server( $server ) && ( ! $a->server_must_be_live || self::is_live_server( (int) $link ) ) ) {
				$meta['server_license'] = (int) $link;
			}
		} elseif ( 'cart_server' === $link && $a->accepts_cart_server ) {
			$meta['server_order_id'] = $order_id;
		}

		$meta['order_item_id'] = (int) $matches[0]->get_id();

		return $meta;
	}

	/**
	 * Add-on resources of one kind linked to a server, whatever their state, in the kind's order.
	 *
	 * @param Addon    $a      Add-on kind.
	 * @param Resource $server Server resource.
	 * @return Resource[]
	 */
	public static function for_server( Addon $a, Resource $server ): array {
		$license_id = (int) ( $server->license()['id'] ?? 0 );
		$out        = array();

		if ( $license_id < 1 ) {
			return $out;
		}

		// One query for the candidates (the license id is in the meta text, as a number or a string), then the exact check on each.
		foreach ( Resource::find_by_meta( $a->kind, 'server_license', array( $license_id, (string) $license_id ), $a->list_order ) as $id ) {
			$resource = Resource::find( (int) $id );

			if ( $resource && $a->driver_id === $resource->get( 'driver' ) && (int) $resource->get( 'server_license' ) === $license_id ) {
				$out[] = $resource;
			}
		}

		return $out;
	}

	/** Whether a license is a Proxmox server (its product's driver). */
	public static function is_server( array $license ): bool {
		$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );

		return $driver && ProxmoxDriver::ID === $driver->id();
	}

	/** Whether the server license has a resource that is its own (not adopted) and not failed or deleted. */
	private static function is_live_server( int $license_id ): bool {
		$resource = Resource::for_license( $license_id );

		return $resource && true !== $resource->get( 'linked' ) && ! in_array( $resource->get( 'state' ), array( 'failed', 'deleted' ), true );
	}
}
