<?php
/** The three Proxmox add-on kinds keep the names their carts, order lines and stock use. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox\Addon;

use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppLicenceCart;
use BeltoftServices\Proxmox\AppLicenceDriver;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Snapshot\SnapshotDriver;
use BeltoftServices\Proxmox\Vm\Stock;

class AddonTest extends \WP_UnitTestCase {
	public function test_the_three_add_ons_keep_their_keys(): void {
		$golden = array(
			'disk'     => array( DiskDriver::ID, 'beltoft_services_disk_for', 'beltoft_services_disk_uid', '_beltoft_services_disk_uid', '_beltoft_services_disk_server', '_disk_', 'beltoft-services-disk', true, false, 'ASC' ),
			'snapshot' => array( SnapshotDriver::ID, 'beltoft_services_snapshot_for', 'beltoft_services_snapshot_uid', '_beltoft_services_snapshot_uid', '_beltoft_services_snapshot_server', '_snapshots_', 'beltoft-services-snapshots', false, true, 'ASC' ),
			'applic'   => array( AppLicenceDriver::ID, AppLicence::CART_FOR, AppLicence::CART_UID, AppLicence::ITEM_UID, AppLicence::ITEM_SERVER, '_applic_', AppLicenceCart::NONCE, true, false, 'DESC' ),
		);
		$seen   = array();
		foreach ( Addon::all() as $a ) {
			$seen[ $a->kind ] = array( $a->driver_id, $a->cart_for(), $a->cart_uid(), $a->item_uid(), $a->item_server(), $a->recurring_suffix, $a->nonce_action, $a->accepts_cart_server, $a->server_must_be_live, $a->list_order );
		}
		$this->assertSame( $golden, $seen );
	}

	public function test_the_order_is_disk_snapshot_applic(): void {
		$this->assertSame( array( 'disk', 'snapshot', 'applic' ), array_map( static fn( Addon $a ) => $a->kind, Addon::all() ) );
	}

	public function test_driver_ids_match_the_stock_list(): void {
		$this->assertSame( array( DiskDriver::ID, SnapshotDriver::ID, AppLicenceDriver::ID ), Addon::driver_ids() );
		$this->assertSame( Addon::driver_ids(), Stock::addon_drivers() );
		$this->assertSame( 'snapshot', Addon::for_driver( SnapshotDriver::ID )->kind );
		$this->assertNull( Addon::for_driver( 'proxmox' ) );
	}
}
