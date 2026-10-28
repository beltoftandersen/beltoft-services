<?php
/** Restoring a server that has extra disks. FakeClient only. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class DiskRestoreTest extends BackupTestCase {
	private function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk    = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		return $disk;
	}

	private function config(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}

	private function backup_of( Resource $r ): string {
		$this->assertIsString( $this->fake->task( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => (int) $r->get( 'vmid' ), 'storage' => 'backup-store' ) ) );
		$list = ( new Backups( Resource::find( $r->id() ) ) )->list();
		return $list[0]['volid'];
	}

	private function restore( Resource $server, string $volid ): void {
		Jobs::act( $server->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
	}

	/** @return array<int,string> */
	private function lines(): array {
		return array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], $this->fake->calls() );
	}

	public function test_restore_and_reinstall_are_both_allowed_with_a_healthy_disk(): void {
		$server = $this->ready_vm();
		$this->disk_for( $server );
		$volid = $this->backup_of( $server );
		$this->assertContains( 'restore', array_keys( Actions::offered( $server ) ) );
		$this->assertNull( Actions::check( $server, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) ) );
		$this->assertContains( 'reinstall', array_keys( Actions::offered( $server ) ) );
		$this->assertNull( Actions::check( $server, 'reinstall', array( 'confirm' => 'REINSTALL' ) ) );
	}

	public function test_the_fake_restore_turns_backup_zero_disks_into_unused_entries_and_keeps_the_volumes(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$volid  = $this->backup_of( $server );
		$orphan = $this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-bs9-orphan.raw', 'size' => '1G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->task( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 5000, 'archive' => $volid, 'force' => 1, 'storage' => 'disk-store' ) );
		$config = $this->config();
		$this->assertArrayNotHasKey( 'scsi1', $config );
		$this->assertSame( $orphan, $config['unused0'] );
		$this->assertSame( 'disk-store:5000/' . $disk->get( 'volname' ), $config['unused1'] );
		$this->assertCount( 2, $this->fake->request( 'GET', '/nodes/node-a/storage/disk-store/content', array( 'content' => 'images', 'vmid' => 5000 ) ) );
	}

	public function test_one_disk_goes_back_to_its_slot_before_the_server_starts(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$volid  = $this->backup_of( $server );
		$seen = count( $this->fake->calls() );
		$this->restore( $server, $volid );

		$config = $this->config();
		$vol    = 'disk-store:5000/' . $disk->get( 'volname' );
		$this->assertStringStartsWith( $vol . ',backup=0', $config['scsi1'] );
		$this->assertNotContains( $vol, array_filter( $config, static fn( $v, $k ) => 0 === strpos( (string) $k, 'unused' ), ARRAY_FILTER_USE_BOTH ) );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'], 'Reattached while the server is still off.' );
		$this->assertCount( 1, $this->calls( 'POST', '/status/start' ), 'Only the first start of the server: the restore itself does not start it.' );
		$this->assertNull( Resource::find( $server->id() )->get( 'restore' ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$lines = array_slice( $this->lines(), $seen );
		$post  = array_keys( array_filter( $lines, static fn( $l ) => 'POST /nodes/node-a/qemu/5000/config' === $l ) );
		$start = array_keys( array_filter( $lines, static fn( $l ) => 'POST /nodes/node-a/qemu/5000/status/start' === $l ) );
		$this->assertNotEmpty( $post );
		$this->assertLessThan( min( $start ), max( $post ), 'The reattach comes before the start.' );
		$this->assertSame( 'ready', Resource::find( $disk->id() )->get( 'state' ) );
	}

	public function test_two_disks_each_go_back_to_their_own_slot(): void {
		$server = $this->ready_vm();
		$one    = $this->disk_for( $server );
		$two    = $this->disk_for( $server );
		$this->assertSame( 'scsi1', $one->get( 'slot' ) );
		$this->assertSame( 'scsi2', $two->get( 'slot' ) );
		$this->restore( $server, $this->backup_of( $server ) );
		$config = $this->config();
		$this->assertStringStartsWith( 'disk-store:5000/' . $one->get( 'volname' ) . ',backup=0', $config['scsi1'] );
		$this->assertStringStartsWith( 'disk-store:5000/' . $two->get( 'volname' ) . ',backup=0', $config['scsi2'] );
		$this->assertCount( 0, array_filter( array_keys( $config ), static fn( $k ) => 0 === strpos( (string) $k, 'unused' ) ), 'No unused entry is left.' );
		$this->assertCount( 0, array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ) );
	}

	public function test_a_retry_after_a_crash_finds_the_disk_attached_and_goes_on(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$volid  = $this->backup_of( $server );
		$this->restore( $server, $volid );
		$posts = count( $this->calls( 'POST', '/nodes/node-a/qemu/5000/config' ) );
		$steps = new \BeltoftServices\Proxmox\DiskSteps( Resource::find( $disk->id() ) );
		$result = $steps->reattach_after_restore( Resource::find( $server->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertCount( $posts, $this->calls( 'POST', '/nodes/node-a/qemu/5000/config' ), 'Already attached: no second write.' );
	}

	public function test_a_crash_between_the_reattach_and_the_queued_plan_resumes_the_restore(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$volid  = $this->backup_of( $server );
		// The task ran and the attach happened, but the plan was not queued: the saved phase is "restored".
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->task( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 5000, 'archive' => $volid, 'force' => 1, 'storage' => 'disk-store' ) );
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/config', array( 'scsi1' => 'disk-store:5000/' . $disk->get( 'volname' ) . ',backup=0' ) );
		$this->assertTrue( $server->set( array( 'restore' => array( 'volid' => $volid, 'ctime' => 1, 'phase' => 'restored' ) ) ) );
		Jobs::act( $server->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
		$this->assertStringStartsWith( 'disk-store:5000/' . $disk->get( 'volname' ), $this->config()['scsi1'] );
		$this->assertNull( Resource::find( $server->id() )->get( 'restore' ) );
		$this->assertTrue( Resource::find( $server->id() )->get( 'plan_dirty' ) );
	}

	private function pause_disk( Resource $disk ): Resource {
		$paused = Registry::get( 'proxmox-disk' )->pause( $disk );
		$this->assertTrue( $paused->is_done(), $paused->error() );
		$this->assertTrue( $disk->set( $paused->meta() ) );
		$disk = Resource::find( $disk->id() );
		$this->assertTrue( $disk->transition( 'paused', 'test' ) );
		return Resource::find( $disk->id() );
	}

	public function test_restore_is_refused_while_a_disk_is_detached_and_works_again_after_resume(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$volid  = $this->backup_of( $server );
		$args   = array( 'confirm' => 'RESTORE', 'volid' => $volid );
		$this->assertNull( Actions::check( $server, 'restore', $args ) );
		$disk = $this->pause_disk( $disk );
		$text = 'This server has an extra disk that is detached because its subscription is not active. Restore is possible again once the disk is active or removed.';
		$this->assertSame( $text, Actions::check( $server, 'restore', $args ) );
		$seen = count( $this->fake->calls() );
		$this->restore( $server, $volid );
		$this->assertSame( array(), array_values( array_filter( array_slice( $this->fake->calls(), $seen ), static fn( $c ) => 'GET' !== $c['method'] ) ), 'The job writes nothing.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertContains( 'disk-store:5000/' . $disk->get( 'volname' ), $this->config() );
		$resumed = Registry::get( 'proxmox-disk' )->resume( $disk );
		$this->assertTrue( $resumed->is_done(), $resumed->error() );
		$this->assertTrue( $disk->set( $resumed->meta() ) );
		$this->assertTrue( $disk->transition( 'ready', 'test' ) );
		$this->assertSame( array(), array_filter( array( Actions::check( Resource::find( $server->id() ), 'restore', $args ) ) ) );
	}

	public function test_a_disk_paused_after_the_request_stops_the_restore_before_the_vm_is_touched(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$volid  = $this->backup_of( $server );
		$this->assertNull( Actions::check( $server, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) ) );
		$this->pause_disk( $disk );
		$seen = count( $this->fake->calls() );
		$this->restore( $server, $volid );
		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => false !== strpos( $l, '/status/' ) || 'POST /nodes/node-a/qemu' === $l ) );
		$this->assertNull( Resource::find( $server->id() )->get( 'restore' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_a_missing_volume_alerts_once_fails_that_disk_and_the_restore_completes(): void {
		$server = $this->ready_vm();
		$ok     = $this->disk_for( $server );
		$lost   = $this->disk_for( $server );
		$volid  = $this->backup_of( $server );
		// The second volume vanishes from the storage after the backup (detached and destroyed outside the plugin).
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi2' ) );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'unused0' ) );
		$this->assertArrayNotHasKey( 'unused0', $this->config() );
		$this->mails = array();
		$this->restore( $server, $volid );

		$fresh = Resource::find( $server->id() );
		$this->assertNull( $fresh->get( 'restore' ), 'The restore completed.' );
		$this->assertTrue( $fresh->get( 'plan_dirty' ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'The server starts.' );
		$this->assertStringStartsWith( 'disk-store:5000/' . $ok->get( 'volname' ), $this->config()['scsi1'] );
		$this->assertSame( 'failed', Resource::find( $lost->id() )->get( 'state' ) );
		$this->assertSame( 'ready', Resource::find( $ok->id() )->get( 'state' ) );
		$alerts = array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] );
		$this->assertCount( 1, $alerts, 'One admin alert.' );
		$this->assertCount( 0, array_filter( $this->fake->calls(), static fn( $c ) => 'DELETE' === $c['method'] && false !== strpos( $c['path'], '/content/' ) ), 'No volume is touched.' );
	}

	public function test_a_taken_slot_is_not_overwritten(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		// The backup holds another drive in the slot the disk record names.
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi3' => 'disk-store:vm-5000-other,size=5G' ) );
		$volid = $this->backup_of( $server );
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi3' ) ) );
		$this->mails = array();
		$this->restore( $server, $volid );
		$this->assertStringStartsWith( 'disk-store:vm-5000-other', $this->config()['scsi3'] );
		$this->assertContains( 'disk-store:5000/' . $disk->get( 'volname' ), $this->config(), 'The volume stays an unused entry.' );
		$this->assertSame( 'failed', Resource::find( $disk->id() )->get( 'state' ) );
		$this->assertNull( Resource::find( $server->id() )->get( 'restore' ) );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ) );
	}

	public function test_a_restore_under_way_finishes_when_a_disk_fails_or_is_paused_between_rounds(): void {
		foreach ( array( 'failed', 'paused' ) as $state ) {
			$server = $this->ready_vm();
			$disk   = $this->disk_for( $server );
			$volid  = $this->backup_of( $server );
			$vmid   = (int) $server->get( 'vmid' );
			$this->fake->task( 'POST', '/nodes/node-a/qemu/' . $vmid . '/status/stop' );
			$this->fake->task( 'POST', '/nodes/node-a/qemu', array( 'vmid' => $vmid, 'archive' => $volid, 'force' => 1, 'storage' => 'disk-store' ) );
			$this->assertTrue( $server->set( array( 'restore' => array( 'volid' => $volid, 'ctime' => 1, 'phase' => 'restored' ) ) ) );
			$this->assertTrue( $disk->transition( $state, 'test' ) );
			$args = array( 'confirm' => 'RESTORE', 'volid' => $volid );
			$this->assertNull( Actions::check( Resource::find( $server->id() ), 'restore', $args ), $state );
			Jobs::act( $server->id(), 'restore', $args );
			$fresh = Resource::find( $server->id() );
			$this->assertNull( $fresh->get( 'restore' ), $state . ': the restore finished.' );
			$this->assertTrue( $fresh->get( 'plan_dirty' ), $state );
			Jobs::run( $server->id() );
			$this->assertSame( 'running', $this->fake->vm( $vmid )['status'], $state . ': the server starts.' );
		}
	}

	public function test_a_failed_or_deleting_disk_needs_support_and_a_paused_one_waits_for_payment(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$args   = array( 'confirm' => 'RESTORE', 'volid' => $this->backup_of( $server ) );
		$policy = 'This server has an extra disk that is detached because its subscription is not active. Restore is possible again once the disk is active or removed.';
		$help   = 'An extra disk on this server needs attention from support before a restore. Contact support.';
		foreach ( array( 'failed' => $help, 'deleting' => $help, 'paused' => $policy, 'ready' => null ) as $state => $text ) {
			$this->assertTrue( Resource::find( $disk->id() )->transition( $state, 'test' ) );
			$this->assertSame( $text, Actions::check( Resource::find( $server->id() ), 'restore', $args ), $state );
		}
	}

	public function test_a_left_volume_is_counted_in_the_capacity_check_until_the_server_goes(): void {
		$server = $this->ready_vm();
		$disk   = $this->disk_for( $server );
		$this->settings( array( 'deletion_mode' => 'on', 'disk_promise_percent' => 1, 'disk_reserve_gb' => 0 ) );
		$name = 'vm-5000-bs4-' . str_replace( '-', '', (string) $disk->get( 'uuid' ) ) . '.raw';
		$this->assertTrue( $disk->set( array( 'slot' => 'scsi4', 'volname' => $name, 'steps' => array(), 'size_gb' => null ) ) );
		$this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '50G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->assertTrue( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 10 ) );
		$result = Registry::get( 'proxmox-disk' )->delete( Resource::find( $disk->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$left = (array) Resource::find( $server->id() )->get( 'left_volumes' );
		$this->assertSame( array( 'disk-store:5000/' . $name => 50 ), $left );
		$this->assertFalse( DiskCapacity::fits( 'lab', 'node-a', 'disk-store', 10 ), 'The left volume still takes its space.' );
	}

	public function test_the_restore_text_tells_about_the_extra_disks(): void {
		$server = $this->ready_vm();
		$this->disk_for( $server );
		$this->assertTrue( $server->set( array( 'backup_list' => array( array( 'volid' => 'backup-store:backup/vm/5000/x', 'ctime' => time(), 'size' => 1048576 ) ) ) ) );
		ob_start();
		\BeltoftServices\Proxmox\Panels::account( Resource::find( $server->id() ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Extra disks are not part of the backup: they keep their current data and are attached again after the restore.', $html );
		$this->assertStringNotContainsString( "\xE2\x80\x94", $html );
		$this->assertStringContainsString( 'data-disks="1"', $html );
		$this->assertStringNotContainsString( 'Reinstall is unavailable', $html );
		$this->assertStringContainsString( 'Extra disks are kept with their data and stay attached.', $html );
	}
}
