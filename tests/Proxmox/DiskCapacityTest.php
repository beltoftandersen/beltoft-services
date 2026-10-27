<?php
/** Node-local disk capacity and reservation tests. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Proxmox\Stock;
use BeltoftServices\Product;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class DiskCapacityTest extends BackupTestCase {
	public function tear_down(): void {
		delete_option( DiskCapacity::RESERVATIONS );
		parent::tear_down();
	}

	public function test_missing_cache_blocks_and_reservation_is_atomic_and_released(): void {
		$server = $this->ready_vm();
		$this->assertFalse( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 50 ) );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->assertTrue( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 50 ) );
		$this->settings( array( 'disk_promise_percent' => 1, 'disk_reserve_gb' => 0 ) );
		$this->assertFalse( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 50 ), 'The 30 GB boot disk plus 50 GB exceeds 1% of 4 TB.' );
		$this->settings( array( 'disk_promise_percent' => 100, 'disk_reserve_gb' => 0 ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server->license()['user_id'] ) );
		$disk = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		$this->assertTrue( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 50 ) );
		$this->assertTrue( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 50 ), 'A retry preserves one reservation.' );
		$this->assertCount( 1, get_option( DiskCapacity::RESERVATIONS ) );
		$this->assertSame( 50, DiskCapacity::reservation( $disk )['gb'] );
		DiskCapacity::release( $disk );
		$this->assertSame( array(), get_option( DiskCapacity::RESERVATIONS ) );
		$this->assertNull( DiskCapacity::reservation( $disk ) );
	}

	public function test_disk_product_stock_follows_verified_storage_capacity(): void {
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$this->assertFalse( Stock::available( $product ) );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->assertTrue( Stock::available( $product ) );
		$this->settings( array( 'disk_promise_percent' => 1, 'disk_reserve_gb' => 0 ) );
		$this->assertFalse( Stock::available( $product ) );
	}
}
