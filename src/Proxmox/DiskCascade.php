<?php
/** A server's subscription ending ends the subscriptions of its add-ons (extra disks, snapshot slots). @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Runs after beltoft-licenses has handled the same event (priority 10). An add-on subscription is only
 * touched when every line and every license on it is an add-on of the ending server; anything else gets a
 * note and one admin alert, and is never cancelled.
 */
final class DiskCascade {
	/** Subscription statuses that end a server's subscription for good or at the end of its term. */
	private const HOOKS = array( 'pending-cancel', 'cancelled', 'expired' );

	/** Subscription meta: the server subscription a disk subscription was put in pending-cancel with. */
	public const MARKER = '_beltoft_services_ended_with_server';

	/** Subscription meta: the end date the disk subscription had before the cascade (empty: none). */
	public const ORIGINAL_END = '_beltoft_services_original_end';

	/** Add-on resources linked to a server: its extra disks and its snapshot packs, live, whatever their state. */
	public static function addons( Resource $server ): array {
		return array_merge( DiskMeta::for_server( $server ), SnapshotMeta::for_server( $server ) );
	}

	public static function register(): void {
		add_action( 'woocommerce_subscription_status_active', array( __CLASS__, 'on_active' ), 20 );
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

	/**
	 * The server subscription is active again: the disk subscriptions the cascade put in pending-cancel
	 * (marked with this server subscription's id) are reactivated. One the customer ended themselves has
	 * no marker and stays; a cancelled one stays cancelled.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function on_active( $subscription ): void {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$server_sub_id = (int) $subscription->get_id();
		foreach ( Licenses::where( 'subscription_id', $server_sub_id ) as $license ) {
			$server = Resource::for_license( (int) $license['id'] );
			if ( ! $server || SshKeyField::DRIVER !== $server->get( 'driver' ) ) {
				continue;
			}
			$seen = array();
			foreach ( self::addons( $server ) as $disk ) {
				$id = (int) ( $disk->license()['subscription_id'] ?? 0 );
				if ( $id < 1 || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$disk_subscription = wcs_get_subscription( $id );
				if ( ! $disk_subscription instanceof \WC_Subscription || 'pending-cancel' !== $disk_subscription->get_status() || (int) $disk_subscription->get_meta( self::MARKER ) !== $server_sub_id ) {
					continue;
				}
				$original = (string) $disk_subscription->get_meta( self::ORIGINAL_END );
				$disk_subscription->delete_meta_data( self::MARKER );
				$disk_subscription->delete_meta_data( self::ORIGINAL_END );
				$disk_subscription->save();
				try {
					// WooCommerce Subscriptions restores the end date it kept before the pending cancellation.
					$disk_subscription->update_status( 'active', __( 'Active again with its server.', 'beltoft-services' ) );
					$wanted = '' === $original ? 0 : $original;
					if ( ( 0 === $wanted ? '' : (string) $wanted ) !== (string) $disk_subscription->get_date( 'end' ) ) {
						$disk_subscription->update_dates( array( 'end' => $wanted ) );
					}
				} catch ( \Exception $e ) {
					// WooCommerce Subscriptions refuses some reactivations (for example with automatic renewal): the disk is left as it is.
					$text = sprintf( 'Server subscription #%1$d is active again, but disk subscription #%2$d could not be reactivated: %3$s', $server_sub_id, $id, $e->getMessage() );
					$disk_subscription->add_order_note( $text );
					$subscription->add_order_note( $text );
					Alerts::admin( $disk, $text );
					continue;
				}
				$subscription->add_order_note(
					SnapshotDriver::ID === $disk->get( 'driver' )
						/* translators: %d: add-on subscription number. */
						? sprintf( __( 'Snapshot slots subscription #%d is active again with this server.', 'beltoft-services' ), $id )
						/* translators: %d: disk subscription number. */
						: sprintf( __( 'Extra disk subscription #%d is active again with this server.', 'beltoft-services' ), $id )
				);
			}
		}
	}

	private static function end_disks( $server_subscription, Resource $server, string $status ): void {
		$by_subscription = array();
		foreach ( self::addons( $server ) as $disk ) {
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
			if ( ! self::only_addons_of( $disk_subscription, $server ) ) {
				$text = sprintf( 'Server subscription #%1$d ended, but disk subscription #%2$d also holds other lines or licenses. It was not changed; end the extra disks by hand.', (int) $server_subscription->get_id(), $id );
				$server_subscription->add_order_note( $text );
				$disk_subscription->add_order_note( $text );
				Alerts::admin( $disks[0], $text );
				continue;
			}
			/* translators: %d: server subscription number. */
			$note = sprintf( __( 'Ended with its server (subscription #%d).', 'beltoft-services' ), (int) $server_subscription->get_id() );
			if ( 'pending-cancel' === $status ) {
				$disk_subscription->update_meta_data( self::MARKER, (int) $server_subscription->get_id() );
				$disk_subscription->update_meta_data( self::ORIGINAL_END, (string) $disk_subscription->get_date( 'end' ) );
				$disk_subscription->save();
				$disk_subscription->update_status( 'pending-cancel', $note );
				self::align_end( $server_subscription, $disk_subscription );
			} else {
				$disk_subscription->update_status( 'cancelled', __( 'Cancelled with its server.', 'beltoft-services' ) );
			}
			$server_subscription->add_order_note(
				SnapshotDriver::ID === $disks[0]->get( 'driver' )
					/* translators: %d: add-on subscription number. */
					? sprintf( __( 'Snapshot slots subscription #%d ends with this server.', 'beltoft-services' ), $id )
					/* translators: %d: disk subscription number. */
					: sprintf( __( 'Extra disk subscription #%d ends with this server.', 'beltoft-services' ), $id )
			);
		}
	}

	/** Every line is an add-on product and every license on the subscription belongs to an add-on of this server. */
	private static function only_addons_of( \WC_Subscription $subscription, Resource $server ): bool {
		$items = $subscription->get_items();
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			$driver = Product::driver_for( (int) $item->get_product_id() );
			if ( ! $driver || ! in_array( $driver->id(), Stock::addon_drivers(), true ) ) {
				return false;
			}
		}
		$server_license = (int) ( $server->license()['id'] ?? 0 );
		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$disk = Resource::for_license( (int) $license['id'] );
			if ( ! $disk || ! in_array( $disk->get( 'driver' ), Stock::addon_drivers(), true ) || (int) $disk->get( 'server_license' ) !== $server_license ) {
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
