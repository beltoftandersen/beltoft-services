<?php
/** Node-local disk capacity and reservation tests. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox\Pve;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Pve\DiskCapacity;
use BeltoftServices\Proxmox\Vm\Stock;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Backup/BackupTestCase.php';

/**
 * Changed on purpose with the stock policy of 2026-11-11 (capacity never stops a sale): the promised-storage budget
 * (`disk_promise_percent`) is retired, so fits() only asks whether the storage really has the space free, and a disk
 * product is never closed for lack of storage.
 */
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
		$this->settings( array( 'disk_reserve_gb' => 100000 ) );
		$this->assertFalse( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 50 ), 'The kept-free reserve is more than the storage really has free.' );
		$this->settings( array( 'disk_reserve_gb' => 0 ) );
		$this->assertTrue( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 50 ), 'The promised total is not a limit any more.' );
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
		$this->settings( array( 'disk_reserve_gb' => 0 ) );
		$disk = $this->disk_resource( $server );
		$this->assertTrue( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 50 ) );
		$this->assertTrue( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 70 ), 'A changed target replaces the reservation.' );
		$this->assertCount( 1, get_option( DiskCapacity::RESERVATIONS ) );
		$this->assertSame( 70, DiskCapacity::reservation( $disk )['gb'] );
		$this->settings( array( 'disk_reserve_gb' => 100000 ) );
		$this->assertFalse( DiskCapacity::reserve( $disk, 'lab', 'node-a', 'disk-store', 90 ), 'The new size still has to be really free on the storage.' );
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
		\BeltoftServices\Proxmox\Pve\CapacityJobs::capacity();
		$left = array_keys( (array) get_option( DiskCapacity::RESERVATIONS ) );
		sort( $left );
		$expected = array( 888888, $live->id() );
		sort( $expected );
		$this->assertSame( $expected, $left, 'An old reservation of a missing disk goes; a live disk and a fresh one stay.' );
	}

	public function test_disk_product_stock_ignores_storage_capacity(): void {
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$this->assertTrue( wc_get_product( $product->get_id() )->is_in_stock(), 'No reading of the storage: on sale.' );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->settings( array( 'disk_reserve_gb' => 100000 ) );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertTrue( $fresh->is_in_stock() );
		$this->assertTrue( $fresh->is_purchasable() );
		Stock::sync();
		$this->assertSame( 'instock', wc_get_product( $product->get_id() )->get_stock_status( 'edit' ), 'No storage reading closes a disk product.' );
	}

	public function test_storage_is_only_read_when_a_disk_product_exists(): void {
		delete_transient( 'beltoft_services_disk_products' );
		$this->assertFalse( \BeltoftServices\Proxmox\Pve\DiskCapacity::wanted(), 'No disk product: no extra call to the cluster.' );

		$this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ) );
		delete_transient( 'beltoft_services_disk_products' );
		$this->assertTrue( \BeltoftServices\Proxmox\Pve\DiskCapacity::wanted() );

		add_filter( 'beltoft_services_disk_capacity_enabled', '__return_false' );
		$this->assertFalse( \BeltoftServices\Proxmox\Pve\DiskCapacity::wanted(), 'The filter can switch it off.' );
	}

	public function test_fits_counts_the_reservations_of_other_disks_but_not_its_own(): void {
		$server = $this->ready_vm();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) ); // The fake storage has 4 TB free.
		$this->settings( array( 'disk_reserve_gb' => 0 ) );
		$a = $this->disk_resource( $server );
		$b = $this->disk_resource( $server );

		$this->assertTrue( DiskCapacity::reserve( $a, 'lab', 'node-a', 'disk-store', 3000 ) );
		$this->assertFalse( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 2000 ), '3000 GB are reserved for another disk: 2000 more do not fit in 4 TB.' );
		$this->assertFalse( DiskCapacity::reserve( $b, 'lab', 'node-a', 'disk-store', 2000 ) );
		$this->assertTrue( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 3500, $a->id() ), 'A disk does not count against itself.' );
		$this->assertTrue( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 900 ), 'What is left (1096 GB) fits.' );
	}

	public function test_the_first_wait_of_a_disk_job_mails_the_admin_once_and_a_new_job_mails_again(): void {
		$server = $this->ready_vm();
		$mails  = array();
		add_filter(
			'pre_wp_mail',
			static function ( $pre, $args ) use ( &$mails ) {
				$mails[] = $args;

				return true;
			},
			10,
			2
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com', 'disk_reserve_gb' => 100000 ) ); // Nothing is free enough.
		$disk = $this->disk_resource( $server );
		$ctx  = array( 'lab', 'node-a', 'disk-store' );

		DiskCapacity::wait_alert( $disk, 'create', $ctx[0], $ctx[1], $ctx[2], 50 );
		DiskCapacity::wait_alert( $disk, 'create', $ctx[0], $ctx[1], $ctx[2], 50 );
		$this->assertCount( 1, $mails, 'Once for the job.' );
		$this->assertSame( 'christian.beltoft@gmail.com', $mails[0]['to'] );
		$this->assertStringContainsString( 'disk-store', $mails[0]['subject'] . $mails[0]['message'], 'The storage is named.' );
		$this->assertStringContainsString( '50 GB', $mails[0]['message'], 'The size asked.' );
		$this->assertStringContainsString( 'Free space', $mails[0]['message'] );

		DiskCapacity::wait_alert( $disk, 'grow', $ctx[0], $ctx[1], $ctx[2], 20 );
		$this->assertCount( 2, $mails, 'A grow is another job.' );

		DiskCapacity::release( $disk );
		DiskCapacity::wait_alert( $disk, 'create', $ctx[0], $ctx[1], $ctx[2], 50 );
		$this->assertCount( 3, $mails, 'The job finished: the next wait says it again.' );
	}

	public function test_a_failing_mailer_never_fails_a_wait(): void {
		$server = $this->ready_vm();
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		add_filter( 'pre_wp_mail', static function () {
			throw new \RuntimeException( 'mailer down' );
		} );
		$disk = $this->disk_resource( $server );
		DiskCapacity::wait_alert( $disk, 'create', 'lab', 'node-a', 'disk-store', 50 );
		$this->assertTrue( true );
	}
}
