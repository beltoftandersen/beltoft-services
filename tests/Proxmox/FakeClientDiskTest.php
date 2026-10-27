<?php
/** Fake disk storage behavior. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Tests\TestCase;

class FakeClientDiskTest extends TestCase {
	public function test_named_image_can_be_allocated_attached_resized_and_removed(): void {
		$fake = new FakeClient();
		$fake->add_node( 'node-a' );
		$fake->add_vm( array( 'vmid' => 5001, 'node' => 'node-a', 'config' => array( 'scsi0' => 'local:5001/vm-5001-disk-0.raw,size=30G' ) ) );
		$name = 'vm-5001-bs1-abcdef123456.raw';
		$volid = 'local:5001/' . $name;
		$this->assertSame( $volid, $fake->request( 'POST', '/nodes/node-a/storage/local/content', array( 'vmid' => 5001, 'filename' => $name, 'size' => '50G', 'format' => 'raw' ) ) );
		$this->assertSame( $volid, $fake->request( 'GET', '/nodes/node-a/storage/local/content', array( 'content' => 'images', 'vmid' => 5001 ) )[0]['volid'] );
		$this->assertWPError( $fake->request( 'POST', '/nodes/node-a/storage/local/content', array( 'vmid' => 5001, 'filename' => $name, 'size' => '50G', 'format' => 'raw' ) ) );
		$config = $fake->request( 'GET', '/nodes/node-a/qemu/5001/config' );
		$upid = $fake->request( 'POST', '/nodes/node-a/qemu/5001/config', array( 'scsi1' => $volid . ',size=50G,backup=0', 'digest' => $config['digest'] ) );
		$this->assertTrue( $fake->wait_task( $upid, 5 ) );
		$this->assertSame( $volid . ',size=50G,backup=0', $fake->request( 'GET', '/nodes/node-a/qemu/5001/config' )['scsi1'] );
		$upid = $fake->request( 'PUT', '/nodes/node-a/qemu/5001/resize', array( 'disk' => 'scsi1', 'size' => '100G' ) );
		$this->assertTrue( $fake->wait_task( $upid, 5 ) );
		$this->assertStringContainsString( 'size=100G', $fake->request( 'GET', '/nodes/node-a/qemu/5001/config' )['scsi1'] );
		$fake->request( 'PUT', '/nodes/node-a/qemu/5001/config', array( 'delete' => 'scsi1' ) );
		$this->assertSame( $volid, $fake->request( 'GET', '/nodes/node-a/qemu/5001/config' )['unused0'] );
		$fake->request( 'PUT', '/nodes/node-a/qemu/5001/config', array( 'delete' => 'unused0' ) );
		$this->assertSame( array(), $fake->request( 'GET', '/nodes/node-a/storage/local/content', array( 'content' => 'images', 'vmid' => 5001 ) ) );
	}
}
