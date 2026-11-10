<?php
/** The names of one kind of server add-on: its driver, cart keys, order line keys and nonce. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Addon;

use BeltoftServices\Proxmox\AppLicenceCart;
use BeltoftServices\Proxmox\AppLicenceDriver;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Disk\DiskMeta;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\Snapshot\SnapshotMeta;
use BeltoftServices\Proxmox\Snapshot\SnapshotDriver;

defined( 'ABSPATH' ) || exit;

/**
 * One add-on kind a customer buys for a server: an extra disk, a snapshot pack or an app licence. The three differ
 * only in these names and two small rules, so the code that is the same for all of them reads them from here.
 */
final class Addon {
	/**
	 * @param string $driver_id           Driver id of the add-on's products.
	 * @param string $kind                Resource kind (activation kind) of its licenses.
	 * @param string $prefix              Prefix of its cart and order line keys.
	 * @param string $recurring_suffix    Suffix of its recurring cart key.
	 * @param string $nonce_action        Nonce action checked at add to cart.
	 * @param bool   $accepts_cart_server Whether it can be bought together with a server still in the cart.
	 * @param bool   $server_must_be_live Whether the server it links to must be a live, own (not adopted) resource.
	 * @param string $list_order          Order of its resources on a server, ASC or DESC.
	 * @param string $parent_deleted_event History text when its server is deleted.
	 * @param \Closure $after_parent_deleted What it does once ended for a deleted server, given the resource.
	 */
	public function __construct(
		public readonly string $driver_id,
		public readonly string $kind,
		public readonly string $prefix,
		public readonly string $recurring_suffix,
		public readonly string $nonce_action,
		public readonly bool $accepts_cart_server,
		public readonly bool $server_must_be_live,
		public readonly string $list_order,
		public readonly string $parent_deleted_event,
		public readonly \Closure $after_parent_deleted
	) {}

	/** Cart item key holding the server the add-on is for. */
	public function cart_for(): string {
		return 'beltoft_services_' . $this->prefix . '_for';
	}

	/** Cart item key holding the unique id of the line. */
	public function cart_uid(): string {
		return 'beltoft_services_' . $this->prefix . '_uid';
	}

	/** Order line meta key holding the unique id of the line. */
	public function item_uid(): string {
		return '_beltoft_services_' . $this->prefix . '_uid';
	}

	/** Order line meta key holding the server the add-on is for. */
	public function item_server(): string {
		return '_beltoft_services_' . $this->prefix . '_server';
	}

	/**
	 * The three kinds, in the order disk, snapshot, app licence.
	 *
	 * @return Addon[]
	 */
	public static function all(): array {
		return array(
			new self( DiskDriver::ID, 'disk', 'disk', '_disk_', 'beltoft-services-disk', true, false, 'ASC', 'Parent server deleted; extra disk no longer available', DiskMeta::after_parent_deleted( ... ) ),
			new self( SnapshotDriver::ID, 'snapshot', 'snapshot', '_snapshots_', 'beltoft-services-snapshots', false, true, 'ASC', 'Parent server deleted; snapshot slots no longer available', SnapshotMeta::after_parent_deleted( ... ) ),
			new self( AppLicenceDriver::ID, 'applic', 'applic', '_applic_', AppLicenceCart::NONCE, true, false, 'DESC', 'Parent server deleted; app licence no longer available', AppLicence::after_parent_deleted( ... ) ),
		);
	}

	/** The kind whose products use a driver, or null. */
	public static function for_driver( string $driver_id ): ?Addon {
		foreach ( self::all() as $addon ) {
			if ( $addon->driver_id === $driver_id ) {
				return $addon;
			}
		}

		return null;
	}

	/**
	 * Driver ids of the three kinds.
	 *
	 * @return string[]
	 */
	public static function driver_ids(): array {
		return array_map( static fn( Addon $addon ) => $addon->driver_id, self::all() );
	}
}
