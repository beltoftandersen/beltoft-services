<?php
/** Extra disk policies: a lapsed disk is detached with its data kept, a server's end ends its disks. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class DiskPolicyTest extends BackupTestCase {
	private function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		return $disk;
	}

	private function ready_disk(): Resource {
		$disk = $this->disk_for( $this->ready_vm() );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		return $disk;
	}

	private function images(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/storage/disk-store/content', array( 'content' => 'images', 'vmid' => 5000 ) );
	}

	private function config(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}

	public function test_pause_detaches_and_keeps_the_volume_and_resume_reattaches_the_same_volume(): void {
		$disk = $this->ready_disk();
		$volname = (string) $disk->get( 'volname' );
		$driver = Registry::get( 'proxmox-disk' );
		$paused = $driver->pause( $disk );
		$this->assertTrue( $paused->is_done(), $paused->error() );
		$this->assertTrue( $disk->set( array( 'detached' => array( 'volid' => 'disk-store:5000/' . $volname, 'slot' => 'scsi1' ) ) ) );
		$config = $this->config();
		$this->assertArrayNotHasKey( 'scsi1', $config );
		$this->assertContains( 'disk-store:5000/' . $volname, array_values( array_filter( $config, static fn( $k ) => 0 === strpos( (string) $k, 'unused' ), ARRAY_FILTER_USE_KEY ) ) );
		$this->assertCount( 1, $this->images(), 'The volume and its data stay on the storage.' );
		$this->assertCount( 0, $this->calls( 'DELETE', '.raw' ) );
		$again = $driver->pause( Resource::find( $disk->id() ) );
		$this->assertTrue( $again->is_done(), 'A repeat is idempotent: ' . $again->error() );
		$this->assertCount( 1, $this->images() );

		$resumed = $driver->resume( Resource::find( $disk->id() ) );
		$this->assertTrue( $resumed->is_done(), $resumed->error() );
		$config = $this->config();
		$this->assertStringContainsString( $volname, (string) $config['scsi1'] );
		$this->assertStringContainsString( 'backup=0', (string) $config['scsi1'] );
		$this->assertSame( array(), array_filter( array_keys( $config ), static fn( $k ) => 0 === strpos( (string) $k, 'unused' ) ), 'The unusedN entry is gone.' );
		$this->assertCount( 1, $this->images() );
		$this->assertCount( 1, $this->calls( 'POST', '/storage/disk-store/content' ), 'No second allocation.' );
		$this->assertTrue( $driver->resume( Resource::find( $disk->id() ) )->is_done(), 'Resume is idempotent.' );
	}

	public function test_pause_with_a_pending_hot_detach_retries_and_does_not_mark_detached(): void {
		$disk = $this->ready_disk();
		$this->fake->hold_detach( 5000, true );
		$result = Registry::get( 'proxmox-disk' )->pause( $disk );
		$this->assertTrue( $result->is_retry(), $result->error() );
		$this->assertStringContainsString( 'restarted', $result->error() );
		$this->assertCount( 1, $this->images() );
		$this->assertCount( 0, $this->calls( 'DELETE', '.raw' ) );
	}

	public function test_pause_leaves_a_linked_disk_attached(): void {
		$disk = $this->ready_disk();
		$this->assertTrue( $disk->set( array( 'linked' => true ) ) );
		$before = count( $this->calls( 'PUT', '/qemu/5000/config' ) );
		$result = Registry::get( 'proxmox-disk' )->pause( Resource::find( $disk->id() ) );
		$this->assertTrue( $result->is_done() );
		$this->assertCount( $before, $this->calls( 'PUT', '/qemu/5000/config' ) );
		$this->assertArrayHasKey( 'scsi1', $this->config() );
	}

	public function test_pause_of_a_never_attached_disk_touches_nothing(): void {
		$disk = $this->disk_for( $this->ready_vm() );
		$before = count( $this->calls( 'PUT', '/qemu/5000/config' ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->pause( $disk )->is_done() );
		$this->assertCount( $before, $this->calls( 'PUT', '/qemu/5000/config' ) );
	}

	public function test_pause_refuses_when_the_server_belongs_to_another_customer(): void {
		$disk = $this->ready_disk();
		$other = $this->service_license( $this->service_product( 'proxmox', array( 'cluster' => 'lab' ), array( 'slug' => 'x-' . wp_generate_password( 5, false ) ) ), array( 'fire' => false ) );
		$this->assertTrue( $disk->set( array( 'server_license' => (int) $other['id'] ) ) );
		$before = count( $this->calls( 'PUT', '/qemu/5000/config' ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->pause( Resource::find( $disk->id() ) )->is_failed() );
		$this->assertCount( $before, $this->calls( 'PUT', '/qemu/5000/config' ) );
	}

	public function test_a_paused_disk_is_shown_as_detached_with_its_data_kept(): void {
		$disk = $this->ready_disk();
		$server = Resource::for_license( (int) $disk->get( 'server_license' ) );
		$items = \BeltoftServices\Proxmox\DiskPanels::server( $server )['items'];
		$this->assertFalse( $items[0]['paused'] );
		$this->assertTrue( $disk->transition( 'paused', 'test' ) );
		$items = \BeltoftServices\Proxmox\DiskPanels::server( $server )['items'];
		$this->assertTrue( $items[0]['paused'] );
		ob_start();
		\BeltoftServices\Proxmox\DiskPanels::account( Resource::find( $disk->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'This disk is detached from your server because its subscription is not active. Your data is kept until the disk is deleted.', $html );
		$this->assertStringNotContainsString( 'sudo mkfs', $html, 'A detached disk shows no format instructions.' );
	}
}
