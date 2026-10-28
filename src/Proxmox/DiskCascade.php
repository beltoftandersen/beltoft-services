<?php
/** A server's subscription ending ends the subscriptions of its extra disks. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Runs after beltoft-licenses has handled the same event (priority 10). A disk subscription is only
 * touched when every line and every license on it is a disk of the ending server; anything else gets a
 * note and one admin alert, and is never cancelled.
 */
final class DiskCascade {
	/** Subscription statuses that end a server's subscription for good or at the end of its term. */
	private const HOOKS = array( 'pending-cancel', 'cancelled', 'expired' );

	public static function register(): void {
		foreach ( self::HOOKS as $status ) {
			add_action( 'woocommerce_subscription_status_' . $status, array( __CLASS__, 'on_status' ), 20 );
		}
	}

	/** @param mixed $subscription Subscription. */
	public static function on_status( $subscription ): void {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$status = (string) $subscription->get_status();
		if ( ! in_array( $status, self::HOOKS, true ) ) {
			return;
		}
		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$server = Resource::for_license( (int) $license['id'] );
			if ( $server && SshKeyField::DRIVER === $server->get( 'driver' ) ) {
				self::end_disks( $subscription, $server, $status );
			}
		}
	}

	private static function end_disks( $server_subscription, Resource $server, string $status ): void {
		$by_subscription = array();
		foreach ( DiskMeta::for_server( $server ) as $disk ) {
			$id = (int) ( $disk->license()['subscription_id'] ?? 0 );
			if ( $id > 0 ) {
				$by_subscription[ $id ][] = $disk;
			}
		}
		foreach ( $by_subscription as $id => $disks ) {
			$disk_subscription = wcs_get_subscription( $id );
			if ( ! $disk_subscription instanceof \WC_Subscription || in_array( $disk_subscription->get_status(), array( 'cancelled', 'expired', 'trash' ), true ) || ( 'pending-cancel' === $status && 'pending-cancel' === $disk_subscription->get_status() ) ) {
				continue;
			}
			if ( ! self::only_disks_of( $disk_subscription, $server ) ) {
				$text = sprintf( 'Server subscription #%1$d ended, but disk subscription #%2$d also holds other lines or licenses. It was not changed; end the extra disks by hand.', (int) $server_subscription->get_id(), $id );
				$server_subscription->add_order_note( $text );
				$disk_subscription->add_order_note( $text );
				Alerts::admin( $disks[0], $text );
				continue;
			}
			/* translators: %d: server subscription number. */
			$note = sprintf( __( 'Ended with its server (subscription #%d).', 'beltoft-services' ), (int) $server_subscription->get_id() );
			if ( 'pending-cancel' === $status ) {
				$disk_subscription->update_status( 'pending-cancel', $note );
				self::align_end( $server_subscription, $disk_subscription );
			} else {
				$disk_subscription->update_status( 'cancelled', __( 'Cancelled with its server.', 'beltoft-services' ) );
			}
			/* translators: %d: disk subscription number. */
			$server_subscription->add_order_note( sprintf( __( 'Extra disk subscription #%d ends with this server.', 'beltoft-services' ), $id ) );
		}
	}

	/** Every line is a disk product and every license on the subscription belongs to a disk of this server. */
	private static function only_disks_of( \WC_Subscription $subscription, Resource $server ): bool {
		$items = $subscription->get_items();
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			$driver = Product::driver_for( (int) $item->get_product_id() );
			if ( ! $driver || DiskDriver::ID !== $driver->id() ) {
				return false;
			}
		}
		$server_license = (int) ( $server->license()['id'] ?? 0 );
		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$disk = Resource::for_license( (int) $license['id'] );
			if ( ! $disk || DiskDriver::ID !== $disk->get( 'driver' ) || (int) $disk->get( 'server_license' ) !== $server_license ) {
				return false;
			}
		}
		return true;
	}

	/** The disk ends when its server ends, when WooCommerce Subscriptions accepts that date. */
	private static function align_end( \WC_Subscription $server_subscription, \WC_Subscription $disk_subscription ): void {
		$end = (string) $server_subscription->get_date( 'end' );
		if ( '' === $end || $end === (string) $disk_subscription->get_date( 'end' ) ) {
			return;
		}
		try {
			$disk_subscription->update_dates( array( 'end' => $end ) );
		} catch ( \Exception $e ) {
			$disk_subscription->add_order_note( __( 'The end date could not be aligned with the server; the disk runs to its own end.', 'beltoft-services' ) );
		}
	}
}
