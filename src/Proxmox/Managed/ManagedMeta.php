<?php
/** Link a managed server licence to its server and tell whether a server is managed. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Managed;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Lifecycle;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonLink;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class ManagedMeta {

	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
		add_filter( 'beltoft_services_simple_line_label', array( __CLASS__, 'line_label' ), 10, 3 );
	}

	/** The server chosen at add to cart, from the order line. */
	public static function initial_meta( array $meta, array $license, Driver $driver ): array {
		return AddonLink::initial_meta( self::addon(), $meta, $license, $driver );
	}

	/**
	 * The name of a Managed line says what it covers: "Managed: web1" (a server by its name, a database by its engine).
	 * Without a known server or database (one bought with the new server of the cart) the line keeps the product name.
	 *
	 * @param mixed $label      The name so far.
	 * @param mixed $product    The product.
	 * @param mixed $covered_id The licence id of the server or database, when known.
	 * @return mixed
	 */
	public static function line_label( $label, $product = null, $covered_id = 0 ) {
		$driver = $product instanceof \WC_Product ? \BeltoftServices\Product::driver_for( $product->get_id() ) : null;

		if ( ! $driver || ManagedDriver::ID !== $driver->id() || ! is_numeric( $covered_id ) || (int) $covered_id < 1 ) {
			return $label;
		}

		$parent  = Resource::for_license( (int) $covered_id );
		$license = $parent ? $parent->license() : null;

		if ( ! $parent || ! is_array( $license ) || ! in_array( (string) $parent->get( 'driver' ), self::addon()->parent_drivers(), true ) ) {
			return $label;
		}

		$name = \BeltoftServices\Proxmox\Vm\ServerName::email_label( $parent, $license );

		/* translators: 1: the name of the add-on (Managed), 2: the server or database it covers. */
		return '' === $name ? $label : sprintf( __( '%1$s: %2$s', 'beltoft-services' ), $product->get_name(), $name );
	}

	private static function addon(): Addon {
		return Addon::for_driver( ManagedDriver::ID );
	}

	/** The server or database resource a managed service belongs to, or null. */
	public static function server_of( Resource $managed ): ?Resource {
		$id = (int) $managed->get( 'server_license' );

		// Bought with its server at checkout: the one server of the same order. Kept, so the server page finds the service.
		if ( $id < 1 && (int) $managed->get( 'server_order_id' ) > 0 ) {
			$id = (int) self::parent_in_order( (int) $managed->get( 'server_order_id' ), (int) ( $managed->license()['user_id'] ?? 0 ) );

			if ( $id > 0 ) {
				$managed->set( array( 'server_license' => $id ) );
			}
		}

		if ( $id < 1 ) {
			return null;
		}

		$server = Resource::for_license( $id );

		return $server && in_array( (string) $server->get( 'driver' ), self::addon()->parent_drivers(), true ) ? $server : null;
	}

	/**
	 * The one server or database licence of a paid order for one customer, or null.
	 *
	 * @param int $order_id Order id.
	 * @param int $user_id  Customer.
	 */
	public static function parent_in_order( int $order_id, int $user_id ): ?int {
		$found = array();

		foreach ( Licenses::where( 'order_id', $order_id ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) === $user_id && AddonLink::is_parent( self::addon(), $license ) ) {
				$found[] = (int) $license['id'];
			}
		}

		return 1 === count( $found ) ? $found[0] : null;
	}

	/** Is the parent a database (not a server)? */
	public static function is_database( Resource $parent ): bool {
		return DbDriver::ID === (string) $parent->get( 'driver' );
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
		\BeltoftServices\Alerts::admin( $managed, 'The parent server or database was deleted while the managed subscription may still bill. Check subscription #' . (int) ( $managed->license()['subscription_id'] ?? 0 ) . '.' );
	}
}
