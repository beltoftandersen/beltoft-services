<?php
/** Extra disk provisioning against the fake cluster. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Cli\Commands;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\DiskMeta;
use BeltoftServices\Proxmox\DiskPanels;
use BeltoftServices\Product;
use BeltoftServices\Resource;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

class DiskProvisionTest extends BackupTestCase {
	private function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		return $disk;
	}

	public function test_named_disk_attaches_once_and_retries_do_not_allocate_again(): void {
		$server = $this->ready_vm();
		$disk = $this->disk_for( $server );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		$this->assertSame( 'scsi1', $disk->get( 'slot' ) );
		$uuid = str_replace( '-', '', (string) $disk->get( 'uuid' ) );
		$this->assertStringContainsString( $uuid, (string) $disk->get( 'volname' ) );
		$config = $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
		$this->assertStringContainsString( (string) $disk->get( 'volname' ), (string) $config['scsi1'] );
		$this->assertStringContainsString( 'backup=0', (string) $config['scsi1'] );
		$this->assertCount( 1, $this->calls( 'POST', '/storage/disk-store/content' ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->provision( $disk )->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '/storage/disk-store/content' ) );
	}

	public function test_allocate_crash_recovers_own_named_volume_without_second_allocation(): void {
		$server = $this->ready_vm();
		$disk = $this->disk_for( $server );
		$uuid = str_replace( '-', '', (string) $disk->get( 'uuid' ) );
		$name = 'vm-5000-bs1-' . $uuid . '.raw';
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi1', 'volname' => $name ) ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		Jobs::run( $disk->id() );
		$this->assertSame( 'ready', Resource::find( $disk->id() )->get( 'state' ) );
		$this->assertCount( 1, $this->calls( 'POST', '/storage/disk-store/content' ) );
	}

	public function test_two_disks_claim_different_slots_and_foreign_disk_is_not_adopted(): void {
		$server = $this->ready_vm();
		$one = $this->disk_for( $server );
		$two = $this->disk_for( $server );
		Jobs::run( $one->id() );
		Jobs::run( $two->id() );
		$this->assertSame( 'scsi1', Resource::find( $one->id() )->get( 'slot' ) );
		$this->assertSame( 'scsi2', Resource::find( $two->id() )->get( 'slot' ) );
		$this->assertCount( 2, $this->calls( 'POST', '/storage/disk-store/content' ) );
		$three = $this->disk_for( $server );
		$this->assertTrue( $three->set( array( 'slot' => 'scsi3', 'volname' => 'vm-5000-bs3-' . str_replace( '-', '', (string) $three->get( 'uuid' ) ) . '.raw' ) ) );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi3' => 'disk-store:5000/vm-5000-foreign.raw,size=50G' ) );
		Jobs::run( $three->id() );
		$this->assertSame( 'failed', Resource::find( $three->id() )->get( 'state' ) );
		$this->assertStringContainsString( 'foreign.raw', $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' )['scsi3'] );
	}

	public function test_grow_and_delete_touch_only_the_named_volume(): void {
		$server = $this->ready_vm();
		$disk = $this->disk_for( $server );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$plan = (array) $disk->get( 'plan' );
		$plan['disk_gb'] = 100;
		$this->assertTrue( $disk->set( array( 'plan' => $plan ) ) );
		$grow = Registry::get( 'proxmox-disk' )->update_plan( $disk );
		$this->assertTrue( $grow->is_done(), $grow->error() );
		$this->assertSame( 100, (int) $disk->get( 'size_gb' ) );
		$this->settings( array( 'deletion_mode' => 'off' ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->delete( $disk )->is_failed() );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$remove = Registry::get( 'proxmox-disk' )->delete( $disk );
		$this->assertTrue( $remove->is_done(), $remove->error() );
		$this->assertSame( array(), $this->fake->request( 'GET', '/nodes/node-a/storage/disk-store/content', array( 'content' => 'images', 'vmid' => 5000 ) ) );
	}

	private function removable_disk(): array {
		$server = $this->ready_vm();
		$disk = $this->disk_for( $server );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		return array( $server, $disk );
	}

	private function images(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/storage/disk-store/content', array( 'content' => 'images', 'vmid' => 5000 ) );
	}

	public function test_hot_detach_pending_keeps_the_volume_until_the_detach_applies(): void {
		list( , $disk ) = $this->removable_disk();
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ) );
		$volname = (string) $disk->get( 'volname' );
		foreach ( array( true, false ) as $hide ) {
			$this->fake->hold_detach( 5000, $hide );
			$result = Registry::get( 'proxmox-disk' )->delete( $disk );
			$this->assertTrue( $result->is_retry(), 'Hide ' . var_export( $hide, true ) . ': ' . $result->error() );
			$this->assertStringContainsString( 'restarted', $result->error() );
			$this->assertCount( 0, $this->calls( 'DELETE', rawurlencode( 'disk-store:5000/' . $volname ) ), 'The volume is never deleted while the running server holds it.' );
			$this->assertCount( 1, $this->images() );
		}
	}

	public function test_orphan_volume_never_attached_is_removed_by_its_exact_name_only(): void {
		list( $server, $disk ) = $this->removable_disk();
		$uuid = str_replace( '-', '', (string) $disk->get( 'uuid' ) );
		$name = 'vm-5000-bs1-' . $uuid . '.raw';
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi1', 'volname' => $name ) ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-bs1-' . $uuid . 'x.raw', 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-bs2-other.raw', 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$result = Registry::get( 'proxmox-disk' )->delete( $disk );
		$this->assertTrue( $result->is_done(), $result->error() );
		$left = array_column( $this->images(), 'volid' );
		sort( $left );
		$this->assertSame( array( 'disk-store:5000/vm-5000-bs1-' . $uuid . 'x.raw', 'disk-store:5000/vm-5000-bs2-other.raw' ), $left, 'Similar names are untouched.' );
		$this->assertCount( 1, $this->calls( 'DELETE', rawurlencode( 'disk-store:5000/' . $name ) ) );
	}

	public function test_orphan_removal_keeps_every_guard(): void {
		list( , $disk ) = $this->removable_disk();
		$name = 'vm-5000-bs1-' . str_replace( '-', '', (string) $disk->get( 'uuid' ) ) . '.raw';
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi1', 'volname' => $name ) ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->settings( array( 'deletion_mode' => 'report' ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->delete( $disk )->is_failed() );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-bs1-someoneelse.raw', 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->assertTrue( $disk->set( array( 'volname' => 'vm-5000-bs1-someoneelse.raw' ) ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->delete( $disk )->is_failed(), 'A name not built from this uuid is refused.' );
		$this->assertCount( 0, $this->calls( 'DELETE', '.raw' ) );
		$this->assertTrue( $disk->set( array( 'volname' => $name, 'linked' => true ) ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->delete( $disk )->is_failed() );
		$this->assertCount( 0, $this->calls( 'DELETE', '.raw' ) );
		$this->assertCount( 2, $this->images() );
	}

	public function test_resume_between_claim_and_attach_provisions_instead_of_claiming_ready(): void {
		list( , $disk ) = $this->removable_disk();
		$name = 'vm-5000-bs1-' . str_replace( '-', '', (string) $disk->get( 'uuid' ) ) . '.raw';
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi1', 'volname' => $name ) ) );
		$result = Registry::get( 'proxmox-disk' )->resume( $disk );
		$this->assertTrue( $result->is_done(), $result->error() );
		$config = $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
		$this->assertStringContainsString( $name, (string) $config['scsi1'], 'The volume was allocated and attached by the resume.' );
		$this->assertCount( 1, $this->images() );
		$this->assertCount( 1, $this->calls( 'POST', '/storage/disk-store/content' ) );
		$this->assertTrue( $disk->set( array( 'steps' => array( 'server', 'slot', 'allocate', 'attach', 'verify' ) ) ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->resume( $disk )->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '/storage/disk-store/content' ), 'A verified disk is not provisioned again.' );
	}

	public function test_extra_disk_blocks_reinstall_and_restore_before_a_remote_call(): void {
		$server = $this->ready_vm();
		$disk = $this->disk_for( $server );
		Jobs::run( $disk->id() );
		$this->assertNotContains( 'reinstall', array_keys( Actions::offered( $server ) ) );
		$this->assertNotContains( 'restore', array_keys( Actions::offered( $server ) ) );
		$this->assertStringContainsString( 'Remove the extra disks', Actions::check( $server, 'reinstall', array( 'confirm' => 'REINSTALL' ) ) );
		$this->assertStringContainsString( 'Remove the extra disks', Actions::check( $server, 'restore', array( 'confirm' => 'RESTORE' ) ) );
	}

	public function test_account_panel_reports_disk_and_deletion_setting(): void {
		$server = $this->ready_vm();
		$disk = $this->disk_for( $server );
		Jobs::run( $disk->id() );
		$this->settings( array( 'deletion_mode' => 'off' ) );
		ob_start();
		\BeltoftServices\Proxmox\Panels::account( $server );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Disks', $html );
		$this->assertStringContainsString( 'scsi1: 50 GB', $html );
		$this->assertStringContainsString( 'The disk stays attached until we remove it.', $html );
		$this->assertStringNotContainsString( 'data-action="reinstall"', $html );
		$this->assertStringNotContainsString( 'data-action="restore"', $html );
	}

	public function test_disk_uses_server_retention_default(): void {
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$this->assertSame( \BeltoftServices\Proxmox\ProxmoxDriver::DEFAULT_RETENTION_DAYS, Product::retention_days( $product->get_id() ) );
	}

	public function test_link_disk_dry_run_and_adoption_use_only_an_existing_named_volume(): void {
		$server = $this->ready_vm();
		$server_license = $server->license();
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$name = 'vm-5000-existing-disk.raw';
		$volid = $this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi2' => $volid . ',backup=0' ) );
		\WP_CLI::reset();
		( new Commands() )->link_disk( array( (string) $license['id'], (string) $server_license['id'], 'scsi2' ), array( 'dry-run' => true ) );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertStringContainsString( 'No remote write', \WP_CLI::text() );
		( new Commands() )->link_disk( array( (string) $license['id'], (string) $server_license['id'], 'scsi2' ), array() );
		$disk = Resource::for_license( (int) $license['id'] );
		$this->assertTrue( (bool) $disk->get( 'linked' ) );
		$this->assertTrue( (bool) $disk->get( 'protected' ) );
		$this->assertSame( $name, $disk->get( 'volname' ) );
		$this->assertSame( 'scsi2', $disk->get( 'slot' ) );
	}

	public function test_parent_deletion_closes_disk_and_alerts_about_billing(): void {
		$server = $this->ready_vm();
		$disk = $this->disk_for( $server );
		Jobs::run( $disk->id() );
		DiskMeta::state_changed( $server, 'ready', 'deleted' );
		$this->assertSame( 'deleted', Resource::find( $disk->id() )->get( 'state' ) );
		$this->assertFalse( Resource::find( $disk->id() )->is_live() );
	}
}
