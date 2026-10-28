<?php
/** New volumes are qcow2; existing raw volumes are still recognised. FakeClient only. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Cli\Commands;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Proxmox\Provision;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';
require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

class QcowTest extends BackupTestCase {
	private function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk    = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		return $disk;
	}

	private function images(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/storage/disk-store/content', array( 'content' => 'images', 'vmid' => 5000 ) );
	}

	public function test_the_format_is_qcow2_unless_the_filter_says_raw(): void {
		$this->assertSame( 'qcow2', Provision::disk_format() );
		add_filter( 'beltoft_services_proxmox_disk_format', static fn() => 'raw' );
		$this->assertSame( 'raw', Provision::disk_format() );
		remove_all_filters( 'beltoft_services_proxmox_disk_format' );
		add_filter( 'beltoft_services_proxmox_disk_format', static fn() => 'vmdk' );
		$this->assertSame( 'qcow2', Provision::disk_format(), 'An invalid value falls back to qcow2.' );
		remove_all_filters( 'beltoft_services_proxmox_disk_format' );
	}

	public function test_the_clone_sends_the_format_and_the_fake_keeps_it(): void {
		$server = $this->ready_vm();
		$clone  = $this->calls( 'POST', '/clone' );
		$this->assertCount( 1, $clone );
		$this->assertSame( 'qcow2', $clone[0]['params']['format'] );
		$this->assertSame( 'qcow2', $this->fake->vm( 5000 )['disk_format'] );
		$this->assertSame( 'ready', Resource::find( $server->id() )->get( 'state' ) );
	}

	public function test_the_reinstall_import_carries_the_format(): void {
		$server = $this->ready_vm();
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-disk-0.raw', 'size' => '30G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => 'disk-store:5000/vm-5000-disk-0.raw,size=30G' ) );
		$disk = $this->disk_for( $server ); // Only a server with an extra disk is reinstalled by importing a new boot volume.
		Jobs::run( $disk->id() );
		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $server->id() )->get( 'state' ); $i++ ) {
			Resource::find( $server->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $server->id() );
			$this->run_jobs();
		}
		$import = array_values( array_filter( $this->fake->calls(), static fn( $c ) => isset( $c['params']['boot'] ) ) );
		$this->assertCount( 1, $import );
		$this->assertStringEndsWith( ',format=qcow2', $import[0]['params']['scsi0'] );
		$this->assertStringContainsString( '.qcow2,', $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' )['scsi0'] );
	}

	public function test_an_extra_disk_is_created_as_qcow2(): void {
		$disk = $this->disk_for( $this->ready_vm() );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		$this->assertMatchesRegularExpression( '/^vm-5000-bs1-[a-f0-9]+\.qcow2$/', (string) $disk->get( 'volname' ) );
		$post = $this->calls( 'POST', '/storage/disk-store/content' );
		$this->assertSame( 'qcow2', $post[0]['params']['format'] );
		$this->assertSame( 'qcow2', $this->images()[0]['format'] );
	}

	public function test_the_filter_switches_an_extra_disk_to_raw(): void {
		add_filter( 'beltoft_services_proxmox_disk_format', static fn() => 'raw' );
		$disk = $this->disk_for( $this->ready_vm() );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		remove_all_filters( 'beltoft_services_proxmox_disk_format' );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		$this->assertStringEndsWith( '.raw', (string) $disk->get( 'volname' ) );
		$this->assertSame( 'raw', $this->calls( 'POST', '/storage/disk-store/content' )[0]['params']['format'] );
	}

	public function test_the_fake_refuses_a_mismatched_extension(): void {
		$this->ready_vm();
		$path = '/nodes/node-a/storage/disk-store/content';
		$this->assertWPError( $this->fake->request( 'POST', $path, array( 'vmid' => 5000, 'filename' => 'vm-5000-x.raw', 'size' => '1G', 'format' => 'qcow2' ) ) );
		$this->assertWPError( $this->fake->request( 'POST', $path, array( 'vmid' => 5000, 'filename' => 'vm-5000-x.qcow2', 'size' => '1G', 'format' => 'raw' ) ) );
		$this->assertWPError( $this->fake->request( 'POST', $path, array( 'vmid' => 5000, 'filename' => 'vm-5000-x.qcow2', 'size' => '1G' ) ), 'No format means raw.' );
		$this->assertSame( 'disk-store:5000/vm-5000-x.qcow2', $this->fake->request( 'POST', $path, array( 'vmid' => 5000, 'filename' => 'vm-5000-x.qcow2', 'size' => '1G', 'format' => 'qcow2' ) ) );
	}

	public function test_an_existing_raw_extra_disk_is_still_recognised_grown_and_deleted(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$name = 'vm-5000-bs1-' . str_replace( '-', '', (string) $disk->get( 'uuid' ) ) . '.raw';
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi1', 'volname' => $name ) ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		$this->assertSame( $name, $disk->get( 'volname' ) );

		$plan            = (array) $disk->get( 'plan' );
		$plan['disk_gb'] = 100;
		$this->assertTrue( $disk->set( array( 'plan' => $plan ) ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->update_plan( $disk )->is_done() );
		$this->assertSame( 100, (int) $disk->get( 'size_gb' ) );

		$this->assertTrue( Registry::get( 'proxmox-disk' )->delete( $disk )->is_done() );
		$this->assertSame( array(), $this->images() );
	}

	public function test_an_existing_raw_orphan_is_recognised_and_the_cli_adopts_both_formats(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$name = 'vm-5000-bs1-' . str_replace( '-', '', (string) $disk->get( 'uuid' ) ) . '.raw';
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi1', 'volname' => $name ) ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$result = Registry::get( 'proxmox-disk' )->delete( $disk );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 'disk-store:5000/' . $name, $disk->get( 'left_volume' ) );

		$server_license = $server->license();
		foreach ( array( 'scsi2' => 'raw', 'scsi3' => 'qcow2' ) as $slot => $format ) {
			$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
			$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
			$volid   = $this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-existing-' . $slot . '.' . $format, 'size' => '50G', 'vmid' => 5000, 'format' => $format ) );
			$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( $slot => $volid . ',backup=0' ) );
			\WP_CLI::reset();
			( new Commands() )->link_disk( array( (string) $license['id'], (string) $server_license['id'], $slot ), array() );
			$linked = Resource::for_license( (int) $license['id'] );
			$this->assertNotNull( $linked, $format );
			$this->assertSame( 'vm-5000-existing-' . $slot . '.' . $format, $linked->get( 'volname' ) );
		}
	}
}
