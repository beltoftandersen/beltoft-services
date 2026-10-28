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

	private function disk_resource( Resource $server ): Resource {
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server->license()['user_id'] ) );
		$disk = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		return $disk;
	}

	public function test_reserve_replaces_an_existing_reservation_of_a_different_size(): void {
		$server = $this->ready_vm();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->settings( array( 'disk_promise_percent' => 100, 'disk_reserve_gb' => 0 ) );
		$disk = $this->disk_resource( $server );
		$this->assertTrue( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 50 ) );
		$this->assertTrue( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 70 ), 'A changed target replaces the reservation.' );
		$this->assertCount( 1, get_option( DiskCapacity::RESERVATIONS ) );
		$this->assertSame( 70, DiskCapacity::reservation( $disk )['gb'] );
		$this->settings( array( 'disk_promise_percent' => 1 ) );
		$this->assertFalse( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 90 ), 'The new size still has to fit.' );
		$this->assertSame( 70, DiskCapacity::reservation( $disk )['gb'], 'A refused replacement keeps the old reservation.' );
	}

	public function test_hourly_capacity_job_sweeps_stale_reservations(): void {
		$server = $this->ready_vm();
		$live = $this->disk_resource( $server );
		$old = time() - 2 * DAY_IN_SECONDS;
		update_option( DiskCapacity::RESERVATIONS, array(
			$live->id() => array( 'cluster' => 'lab', 'node' => 'node-a', 'storage' => 'disk-store', 'gb' => 50, 'at' => $old ),
			999999 => array( 'cluster' => 'lab', 'node' => 'node-a', 'storage' => 'disk-store', 'gb' => 50, 'at' => $old ),
			888888 => array( 'cluster' => 'lab', 'node' => 'node-a', 'storage' => 'disk-store', 'gb' => 50, 'at' => time() ),
		), false );
		$this->assertTrue( $live->is_live() );
		\BeltoftServices\Proxmox\ProxmoxJobs::capacity();
		$left = array_keys( (array) get_option( DiskCapacity::RESERVATIONS ) );
		sort( $left );
		$expected = array( 888888, $live->id() );
		sort( $expected );
		$this->assertSame( $expected, $left, 'An old reservation of a missing disk goes; a live disk and a fresh one stay.' );
	}

	public function test_disk_product_stock_follows_verified_storage_capacity(): void {
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$this->assertFalse( Stock::available( $product ) );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->assertTrue( Stock::available( $product ) );
		$this->settings( array( 'disk_promise_percent' => 1, 'disk_reserve_gb' => 0 ) );
		$this->assertFalse( Stock::available( $product ) );
	}

	public function test_storage_is_only_read_when_a_disk_product_exists(): void {
		delete_transient( 'beltoft_services_disk_products' );
		$this->assertFalse( \BeltoftServices\Proxmox\DiskCapacity::wanted(), 'No disk product: no extra call to the cluster.' );

		$this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ) );
		delete_transient( 'beltoft_services_disk_products' );
		$this->assertTrue( \BeltoftServices\Proxmox\DiskCapacity::wanted() );

		add_filter( 'beltoft_services_disk_capacity_enabled', '__return_false' );
		$this->assertFalse( \BeltoftServices\Proxmox\DiskCapacity::wanted(), 'The filter can switch it off.' );
	}
}
