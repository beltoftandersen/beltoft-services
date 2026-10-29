<?php
/** Link a snapshot pack license to its server and count its slots. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class SnapshotMeta {
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
		add_filter( 'beltoft_services_snapshot_paid_slots', array( __CLASS__, 'paid_slots' ), 10, 2 );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 10, 3 );
	}

	/** The server chosen at checkout, from the order line (the line says which server; the nonce was checked at add to cart). */
	public static function initial_meta( array $meta, array $license, Driver $driver ): array {
		if ( SnapshotDriver::ID !== $driver->id() ) {
			return $meta;
		}
		$order_id = (int) ( $license['order_id'] ?? 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order || (int) ( $license['user_id'] ?? 0 ) < 1 ) {
			return $meta;
		}
		$uid             = '';
		$subscription_id = (int) ( $license['subscription_id'] ?? 0 );
		if ( $subscription_id > 0 && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				foreach ( $subscription->get_items() as $item ) {
					if ( (int) $item->get_product_id() === (int) $license['product_id'] ) {
						$uid = (string) $item->get_meta( '_beltoft_services_snapshot_uid', true );
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
			$item_uid = (string) $item->get_meta( '_beltoft_services_snapshot_uid', true );
			if ( '' !== $uid && $uid !== $item_uid ) {
				continue;
			}
			$matches[] = $item;
		}
		if ( 1 !== count( $matches ) ) {
			return $meta;
		}
		$link = $matches[0]->get_meta( '_beltoft_services_snapshot_server', true );
		if ( is_numeric( $link ) && (int) $link > 0 ) {
			$server = Licenses::find( (int) $link );
			if ( $server && (int) $server['user_id'] === (int) $license['user_id'] && self::is_server( $server ) ) {
				$resource = Resource::for_license( (int) $link );
				if ( $resource && true !== $resource->get( 'linked' ) && ! in_array( $resource->get( 'state' ), array( 'failed', 'deleted' ), true ) ) {
					$meta['server_license'] = (int) $link;
				}
			}
		}
		$meta['order_item_id'] = (int) $matches[0]->get_id();

		return $meta;
	}

	private static function is_server( array $license ): bool {
		$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );

		return $driver && SshKeyField::DRIVER === $driver->id();
	}

	/** The server resource a pack belongs to, or null. */
	public static function server_of( Resource $pack ): ?Resource {
		$id = (int) $pack->get( 'server_license' );
		if ( $id < 1 ) {
			return null;
		}
		$server = Resource::for_license( $id );

		return $server && SshKeyField::DRIVER === $server->get( 'driver' ) ? $server : null;
	}

	/** Live pack resources linked to one server, whatever their state (the cascade and the card use this). */
	public static function for_server( Resource $server ): array {
		global $wpdb;
		$license_id = (int) ( $server->license()['id'] ?? 0 );
		$out        = array();
		if ( $license_id < 1 ) {
			return $out;
		}
		// One query for the candidates (the license id is in the meta text), then the exact check on each: no loading of every pack of every customer.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE kind = 'snapshot' AND deactivated_at IS NULL AND meta LIKE %s ORDER BY id ASC", \BeltoftLicenses\Install::tables()['activations'], '%\"server_license\":%' . $wpdb->esc_like( (string) $license_id ) . '%' ) );
		foreach ( (array) $ids as $id ) {
			$pack = Resource::find( (int) $id );
			if ( $pack && SnapshotDriver::ID === $pack->get( 'driver' ) && (int) $pack->get( 'server_license' ) === $license_id ) {
				$out[] = $pack;
			}
		}

		return $out;
	}

	/** Packs that hold a place for a purchase check: every live pack that is not failed, deleted, revoked or suspended for good, in any state (requested, provisioning, paused, on hold). */
	public static function reserved_for( Resource $server ): array {
		return array_values(
			array_filter(
				self::for_server( $server ),
				static fn( Resource $pack ) => ! in_array( $pack->get( 'state' ), array( 'failed', 'deleted', 'deleting' ), true ) && Licenses::STATUS_REVOKED !== ( $pack->license()['status'] ?? '' )
			)
		);
	}

	/** Slots the packs of a server hold for a purchase check, counted or not at this moment. */
	public static function reserved_slots( Resource $server ): int {
		$sum = 0;
		foreach ( self::reserved_for( $server ) as $pack ) {
			$sum += self::slots_of( $pack );
		}

		return $sum;
	}

	/** Slots one pack gives (its plan, stored on the resource). */
	public static function slots_of( Resource $pack ): int {
		return max( 0, (int) ( ( (array) $pack->get( 'plan' ) )['snapshot_slots'] ?? 0 ) );
	}

	/** Days a snapshot is kept with this pack, or 0 for the setting. */
	public static function days_of( Resource $pack ): int {
		return max( 0, (int) ( ( (array) $pack->get( 'plan' ) )['snapshot_days'] ?? 0 ) );
	}

	/** Does the pack give its slots now: ready, meant to be ready, and its license active. */
	public static function counts( Resource $pack ): bool {
		return 'ready' === $pack->get( 'state' ) && 'ready' === $pack->get( 'desired' ) && Lifecycle::is_active( $pack->license() );
	}

	/** Packs that count now. */
	public static function active_for( Resource $server ): array {
		return array_values( array_filter( self::for_server( $server ), array( __CLASS__, 'counts' ) ) );
	}

	/** Slots bought on top of the free ones: the sum of the live, active packs (the caller caps it at the maximum). */
	public static function paid_for( Resource $server ): int {
		$sum = 0;
		foreach ( self::active_for( $server ) as $pack ) {
			$sum += self::slots_of( $pack );
		}

		return $sum;
	}

	/** Days a snapshot is kept on the paid slots: the longest period of the active packs, or 0 for the setting. */
	public static function days_for( Resource $server ): int {
		$days = 0;
		foreach ( self::active_for( $server ) as $pack ) {
			$days = max( $days, self::days_of( $pack ) );
		}

		return $days;
	}

	/**
	 * Filter beltoft_services_snapshot_paid_slots.
	 *
	 * @param mixed $slots  Slots so far.
	 * @param mixed $server Server resource.
	 * @return mixed
	 */
	public static function paid_slots( $slots, $server = null ) {
		if ( ! $server instanceof Resource || SshKeyField::DRIVER !== $server->get( 'driver' ) ) {
			return $slots;
		}

		return (int) $slots + self::paid_for( $server );
	}

	/** Check the server's snapshots against the slots left (D7). Never fails: a busy lock is left to the hourly job. */
	public static function clamp_server( Resource $pack ): void {
		$server = self::server_of( $pack );
		if ( $server && true !== $server->get( 'linked' ) && Snapshots::tracked( $server ) ) {
			SnapshotExpiry::clamp( $server );
		}
	}

	/** A deleted server ends its packs. */
	public static function state_changed( $resource, $old, $new ): void {
		unset( $old );
		if ( ! $resource instanceof Resource || 'deleted' !== $new || SshKeyField::DRIVER !== $resource->get( 'driver' ) ) {
			return;
		}
		foreach ( self::for_server( $resource ) as $pack ) {
			if ( 'deleted' !== $pack->get( 'state' ) && $pack->transition( 'deleted', 'Parent server deleted; snapshot slots no longer available' ) ) {
				$pack->close();
				\BeltoftServices\Alerts::admin( $pack, 'The parent server was deleted while the snapshot slots subscription may still bill. Check subscription #' . (int) ( $pack->license()['subscription_id'] ?? 0 ) . '.' );
			}
		}
	}
}
