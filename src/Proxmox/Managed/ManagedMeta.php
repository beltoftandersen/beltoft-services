<?php
/** Link a managed server licence to its server and tell whether a server is managed. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Managed;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Lifecycle;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonLink;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class ManagedMeta {

	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
	}

	/** The server chosen at add to cart, from the order line. */
	public static function initial_meta( array $meta, array $license, Driver $driver ): array {
		return AddonLink::initial_meta( self::addon(), $meta, $license, $driver );
	}

	private static function addon(): Addon {
		return Addon::for_driver( ManagedDriver::ID );
	}

	/** The server resource a managed service belongs to, or null. */
	public static function server_of( Resource $managed ): ?Resource {
		$id = (int) $managed->get( 'server_license' );

		if ( $id < 1 ) {
			return null;
		}

		$server = Resource::for_license( $id );

		return $server && ProxmoxDriver::ID === $server->get( 'driver' ) ? $server : null;
	}

	/** Managed services linked to one server, whatever their state. */
	public static function for_server( Resource $server ): array {
		return AddonLink::for_server( self::addon(), $server );
	}

	/** Every one that holds the place for a purchase check: live, not failed, deleted or revoked. */
	public static function reserved_for( Resource $server ): array {
		return array_values( array_filter( self::for_server( $server ), static fn( Resource $m ) => ! in_array( $m->get( 'state' ), array( 'failed', 'deleted', 'deleting' ), true ) && 'revoked' !== ( $m->license()['status'] ?? '' ) ) );
	}

	/** Does it count now: ready, meant to be ready, and its licence active. */
	public static function counts( Resource $managed ): bool {
		return 'ready' === $managed->get( 'state' ) && 'ready' === $managed->get( 'desired' ) && Lifecycle::is_active( $managed->license() );
	}

	/** Is the server managed now? */
	public static function is_managed( Resource $server ): bool {
		return array() !== array_filter( self::for_server( $server ), array( __CLASS__, 'counts' ) );
	}

	/** A managed service whose server was deleted has been ended by AddonCascade: close it and tell the admin. */
	public static function after_parent_deleted( Resource $managed ): void {
		$managed->close();
		\BeltoftServices\Alerts::admin( $managed, 'The parent server was deleted while the managed server subscription may still bill. Check subscription #' . (int) ( $managed->license()['subscription_id'] ?? 0 ) . '.' );
	}
}
