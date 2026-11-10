<?php
/** Link a snapshot pack license to its server and count its slots. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Lifecycle;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonLink;
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
		return AddonLink::initial_meta( self::addon(), $meta, $license, $driver );
	}

	private static function is_server( array $license ): bool {
		return AddonLink::is_server( $license );
	}

	private static function addon(): Addon {
		return Addon::for_driver( SnapshotDriver::ID );
	}

	/** The server resource a pack belongs to, or null. */
	public static function server_of( Resource $pack ): ?Resource {
		$id = (int) $pack->get( 'server_license' );
		if ( $id < 1 ) {
			return null;
		}
		$server = Resource::for_license( $id );

		return $server && ProxmoxDriver::ID === $server->get( 'driver' ) ? $server : null;
	}

	/** Live pack resources linked to one server, whatever their state (the cascade and the card use this). */
	public static function for_server( Resource $server ): array {
		return AddonLink::for_server( self::addon(), $server );
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
		if ( ! $server instanceof Resource || ProxmoxDriver::ID !== $server->get( 'driver' ) ) {
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
		if ( ! $resource instanceof Resource || 'deleted' !== $new || ProxmoxDriver::ID !== $resource->get( 'driver' ) ) {
			return;
		}
		foreach ( self::for_server( $resource ) as $pack ) {
			// Silent on purpose: the parent's own email covers the customer (see Resource::transition()).
			if ( 'deleted' !== $pack->get( 'state' ) && $pack->transition( 'deleted', 'Parent server deleted; snapshot slots no longer available' ) ) {
				$pack->close();
				\BeltoftServices\Alerts::admin( $pack, 'The parent server was deleted while the snapshot slots subscription may still bill. Check subscription #' . (int) ( $pack->license()['subscription_id'] ?? 0 ) . '.' );
			}
		}
	}
}
