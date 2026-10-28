<?php
/**
 * Customer snapshots, phase C: what a reinstall, a restore, an extra disk, a server delete and a plan change do
 * with the snapshots of a server. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class SnapshotsInteractionTest extends SnapshotsTestCase {

	private function rebuild( int $id ): void {
		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $id )->get( 'state' ); $i++ ) {
			Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $id );
			$this->run_jobs();
		}

		$this->assertSame( 'ready', Resource::find( $id )->get( 'state' ), (string) Resource::find( $id )->get( 'last_error' ) );
	}

	private function reinstall( Resource $r ): void {
		Jobs::act( $r->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
	}

	private function disk_mails(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== stripos( (string) $m['subject'], 'snapshots' ) ) );
	}

	// -- Reinstall (D8) --------------------------------------------------------------------------

	public function test_a_reinstall_deletes_the_snapshots_before_it_destroys_the_vm(): void {
		$r = $this->server();
		$this->take( $r, 'one' );
		$this->take( $r, 'two' );
		$seen = count( $this->fake->calls() );

		$this->reinstall( $r );
		$this->rebuild( $r->id() );

		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		$lines = array_slice( $this->lines(), $seen );
		$flat  = implode( "\n", $lines );
		$last  = strrpos( $flat, 'DELETE /nodes/node-a/qemu/5000/snapshot/' );
		$vm    = strpos( $flat, 'DELETE /nodes/node-a/qemu/5000' . "\n" );
		$this->assertNotFalse( $last );
		$this->assertCount( 2, array_filter( $lines, static fn( $l ) => 0 === strpos( $l, 'DELETE /nodes/node-a/qemu/5000/snapshot/' ) ) );
		$this->assertNotFalse( $vm );
		$this->assertLessThan( $vm, $last, 'Both snapshots go first.' );
		$this->assertNull( $this->fresh( $r )->get( 'wipe_snapshots' ) );
		$this->assertSame( array(), $this->admin_mails() );
	}

	public function test_the_purge_runs_once_per_attempt_not_every_round(): void {
		$r = $this->server();
		$this->take( $r, 'one' );
		$this->reinstall( $r );
		$this->rebuild( $r->id() );
		$deletes = array_filter( $this->lines(), static fn( $l ) => 0 === strpos( $l, 'DELETE /nodes/node-a/qemu/5000/snapshot/' ) );
		$this->assertCount( 1, $deletes );
	}

	public function test_a_reinstall_of_a_server_with_an_extra_disk_deletes_the_snapshots_before_the_boot_volume_is_detached(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$this->take( $r, 'one' );
		$seen = count( $this->fake->calls() );

		$this->reinstall( $r );
		$this->assertSame( 'boot', $this->fresh( $r )->get( 'wipe' ) );
		$this->rebuild( $r->id() );

		$lines  = array_slice( $this->lines(), $seen );
		$flat   = implode( "\n", $lines );
		$snap   = strpos( $flat, 'DELETE /nodes/node-a/qemu/5000/snapshot/' );
		$detach = strpos( $flat, 'PUT /nodes/node-a/qemu/5000/config' );
		$this->assertNotFalse( $snap );
		$this->assertLessThan( $detach, $snap );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		$this->assertSame( 'ready', $this->fresh( $disk )->get( 'state' ) );
		$this->assertSame( 'qcow2', $this->fresh( $r )->get( 'boot_format' ) );
	}

	public function test_the_fake_refuses_to_destroy_a_volume_a_snapshot_still_holds(): void {
		$r = $this->server();
		$this->take( $r, 'one' );
		$config = $this->config();
		$volid  = explode( ',', $config['scsi0'] )[0];
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi0' ) ) );
		$unused = array_search( $volid, $this->config(), true );
		$this->assertSame( 'unused0', $unused );
		$this->assertWPError( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'unused0' ) ) );
		$this->assertSame( $volid, $this->config()['unused0'] );
	}

	public function test_a_snapshot_that_cannot_be_deleted_holds_the_wipe_back_and_the_admin_is_told_once(): void {
		$r = $this->server();
		$this->take( $r, 'stuck' );
		$this->fake->set_lock( 5000, 'backup' );
		$seen = count( $this->fake->calls() );

		$this->reinstall( $r );

		for ( $i = 0; $i < 5; $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
		}

		$after = array_slice( $this->lines(), $seen );
		$this->assertSame( array(), array_filter( $after, static fn( $l ) => 'DELETE /nodes/node-a/qemu/5000' === $l || false !== strpos( $l, '/status/' ) || 0 === strpos( $l, 'PUT ' ) || 0 === strpos( $l, 'POST /nodes/node-a/qemu/5000/config' ) ), 'The wipe never ran.' );
		$this->assertCount( 1, Snapshots::entries( $this->fresh( $r ) ) );
		$told = array_filter( $this->admin_mails(), static fn( $m ) => false !== strpos( (string) $m['message'], 'reinstall waits' ) );
		$this->assertCount( 1, $told, 'Once, not every round.' );

		$this->fake->set_lock( 5000, '' );
		$this->rebuild( $r->id() );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		$this->assertNull( $this->fresh( $r )->get( 'wipe_snapshot_tries' ) );
	}

	public function test_a_reinstall_of_a_server_without_snapshots_asks_proxmox_nothing_about_them(): void {
		$r = $this->server();
		$seen = count( $this->fake->calls() );
		$this->reinstall( $r );
		$this->rebuild( $r->id() );
		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => false !== strpos( $l, '/snapshot' ) ) );
	}

	// -- Restore (D9) ----------------------------------------------------------------------------

	public function test_a_restore_forgets_the_snapshots_and_a_new_one_can_be_taken(): void {
		$r = $this->server();
		$this->take( $r, 'before' );
		$volid = $this->backup_volid( $r );

		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$fresh = $this->fresh( $r );
		$this->assertNull( $fresh->get( 'restore' ), (string) $fresh->get( 'last_error' ) );
		$this->assertSame( array(), Snapshots::entries( $fresh ) );
		$this->assertNull( $fresh->get( Snapshots::OP ) );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		Jobs::run( $r->id() );
		$this->assertSame( 0, Snapshots::used( $this->fresh( $r ) ) );
		$this->assertSame( 'after', $this->take( $this->fresh( $r ), 'after' )['label'] );
	}

	public function test_a_restore_of_a_server_without_snapshots_makes_no_snapshot_call(): void {
		$r     = $this->server();
		$volid = $this->backup_volid( $r );
		$seen  = count( $this->fake->calls() );
		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => false !== strpos( $l, '/snapshot' ) ) );
	}

	public function test_the_restore_form_text_warns_about_the_snapshots(): void {
		$r = $this->server();
		$this->take( $r, 'x' );
		$this->assertStringContainsString( 'snapshots', strtolower( Snapshots::restore_warning( $this->fresh( $r ) ) ) );
		$this->assertSame( '', Snapshots::restore_warning( $this->server() ) );
	}

	public function test_the_reinstall_form_text_names_the_number_of_snapshots_that_go(): void {
		$r = $this->server();
		$this->assertSame( '', Snapshots::reinstall_warning( $r ) );
		$this->take( $r, 'one' );
		$this->assertStringContainsString( '1 snapshot', Snapshots::reinstall_warning( $this->fresh( $r ) ) );
		$this->take( $r, 'two' );
		$this->assertStringContainsString( '2 snapshots', Snapshots::reinstall_warning( $this->fresh( $r ) ) );
		$this->assertStringNotContainsString( '—', Snapshots::reinstall_warning( $this->fresh( $r ) ) . Snapshots::restore_warning( $this->fresh( $r ) ) );
	}

	// -- Extra disk (D10, D11) -------------------------------------------------------------------

	public function test_deleting_an_extra_disk_deletes_the_snapshots_that_cover_it_and_tells_the_customer_once(): void {
		$r      = $this->server();
		$before = $this->take( $r, 'before the disk' );
		$disk   = $this->disk_for( $this->fresh( $r ) );
		$after  = $this->take( $r, 'with the disk' );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->mails = array();

		$this->assertSame( array( 'with the disk' ), array_column( Snapshots::covering( $this->fresh( $r ), 'disk-store:5000/' . $disk->get( 'volname' ) ), 'label' ) );

		$done = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );
		$this->assertTrue( $done->is_done(), $done->error() );

		$left = Snapshots::entries( $this->fresh( $r ) );
		$this->assertSame( array( $before['id'] ), array_column( $left, 'id' ), 'Only the snapshot that covered the disk went.' );
		$this->assertArrayNotHasKey( $after['snap'], $this->fake->snapshots( 5000 ) );
		$this->assertArrayHasKey( $before['snap'], $this->fake->snapshots( 5000 ) );
		$this->assertArrayNotHasKey( 'scsi1', $this->config() );

		$mails = $this->disk_mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'extra disk was removed', (string) $mails[0]['message'] );

		// A second round (a retried job) is quiet.
		$again = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );
		$this->assertTrue( $again->is_done() );
		$this->assertCount( 1, $this->disk_mails() );
	}

	public function test_deleting_a_disk_nothing_covers_sends_no_email_and_touches_no_snapshot(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->mails = array();
		$done = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );
		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertSame( array(), $this->disk_mails() );
		$this->assertSame( array(), array_filter( $this->lines(), static fn( $l ) => 0 === strpos( $l, 'DELETE /nodes/node-a/qemu/5000/snapshot/' ) ) );
	}

	public function test_the_disk_delete_waits_for_a_snapshot_task_and_removes_the_disk_after_it(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$this->take( $r, 'covers' );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->fake->next_task( array( 'polls' => 50 ) );

		$first = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );
		$this->assertTrue( $first->is_retry() );
		$this->assertArrayHasKey( 'scsi1', $this->config(), 'The disk is still attached while the snapshot goes.' );
		$this->assertSame( array(), $this->disk_mails() );

		$done = null;
		for ( $i = 0; $i < 60 && ( null === $done || ! $done->is_done() ); $i++ ) {
			$done = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );
		}

		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		$this->assertArrayNotHasKey( 'scsi1', $this->config() );
		$this->assertCount( 1, $this->disk_mails() );
	}

	public function test_a_disk_detached_for_an_unpaid_subscription_keeps_the_snapshots_and_blocks_a_rollback(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$a    = $this->take( $r, 'A' );

		$paused = Registry::get( 'proxmox-disk' )->pause( $this->fresh( $disk ) );
		$this->assertTrue( $paused->is_done(), $paused->error() );
		$this->fresh( $disk )->set( $paused->meta() );
		$this->assertTrue( $this->fresh( $disk )->transition( 'paused', 'test' ) );

		$this->assertSame( array( $a['id'] ), array_column( Snapshots::entries( $this->fresh( $r ) ), 'id' ) );
		$this->assertArrayHasKey( $a['snap'], $this->fake->snapshots( 5000 ) );
		$this->assertSame( array(), $this->disk_mails() );
		$this->assertNotNull( Actions::check( $this->fresh( $r ), 'snapshot_rollback', array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' ) ) );
		$this->assertSame( array(), array_filter( array_slice( $this->lines(), -6 ), static fn( $l ) => false !== strpos( $l, '/rollback' ) ) );
	}

	// -- Server delete, plan changes -------------------------------------------------------------

	public function test_a_server_with_snapshots_is_deleted(): void {
		$r = $this->server();
		$this->take( $r, 'one' );
		$this->take( $r, 'two' );
		$this->settings( array( 'deletion_mode' => 'on' ) );

		$this->assertTrue( $this->fresh( $r )->desire( 'deleted', 'test' ) );
		Jobs::run( $r->id() );

		$this->assertNull( $this->fake->vm( 5000 ) );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		$this->assertSame( 'deleted', $this->fresh( $r )->get( 'state' ), (string) $this->fresh( $r )->get( 'last_error' ) );
	}

	public function test_a_server_delete_waits_for_a_snapshot_task_that_is_running(): void {
		$r = $this->server();
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->fake->next_task( array( 'polls' => 14 ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'slow' ) );
		$this->assertSame( 'snapshot', $this->fresh( $r )->get( 'task' )['step'] ?? '', 'The take is still running.' );

		$this->assertTrue( $this->fresh( $r )->desire( 'deleted', 'test' ) );
		Jobs::run( $r->id() );
		$this->assertNotNull( $this->fake->vm( 5000 ), 'Not deleted while the snapshot task runs.' );

		for ( $i = 0; $i < 12 && null !== $this->fake->vm( 5000 ); $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
		}

		$this->assertNull( $this->fake->vm( 5000 ) );
		$this->assertSame( 'deleted', $this->fresh( $r )->get( 'state' ), (string) $this->fresh( $r )->get( 'last_error' ) );
	}

	public function test_a_plan_upgrade_with_a_snapshot_present_grows_the_disk(): void {
		$r = $this->server();
		$a = $this->take( $r, 'A' );
		$plan = (array) $this->fresh( $r )->get( 'plan' );
		$plan['disk_gb']   = 60;
		$plan['cores']     = 4;
		$plan['memory_mb'] = 8192;
		$this->assertTrue( $this->fresh( $r )->change_plan( $plan, 'Plan changed' ) );
		Jobs::run( $r->id() );

		$fresh  = $this->fresh( $r );
		$config = $this->config();
		$this->assertSame( 'ready', $fresh->get( 'state' ), (string) $fresh->get( 'last_error' ) );
		$this->assertFalse( (bool) $fresh->get( 'plan_dirty' ) );
		$this->assertStringContainsString( 'size=60G', $config['scsi0'] );
		$this->assertSame( 4, (int) $config['cores'] );
		$this->assertArrayHasKey( $a['snap'], $this->fake->snapshots( 5000 ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_make_larger_on_an_extra_disk_with_a_snapshot_present_grows_it(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$a    = $this->take( $r, 'A' );
		$plan = (array) $this->fresh( $disk )->get( 'plan' );
		$plan['disk_gb'] = 100;
		$this->fresh( $disk )->set( array( 'plan' => $plan ) );

		$grown = Registry::get( 'proxmox-disk' )->update_plan( $this->fresh( $disk ) );

		$this->assertTrue( $grown->is_done(), $grown->error() );
		$this->assertStringContainsString( 'size=100G', $this->config()['scsi1'] );
		$this->assertArrayHasKey( $a['snap'], $this->fake->snapshots( 5000 ) );
		$this->assertSame( array( $a['id'] ), array_column( Snapshots::entries( $this->fresh( $r ) ), 'id' ) );
	}

	// -- Review fixes ----------------------------------------------------------------------------

	public function test_a_later_removal_of_a_disk_that_reuses_a_volume_id_mails_again(): void {
		$r = $this->server();
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->mails = array();

		foreach ( array( 'one', 'two' ) as $label ) {
			$disk = $this->disk_for( $this->fresh( $r ) );
			$this->take( $r, $label );
			$done = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );
			$this->assertTrue( $done->is_done(), $done->error() );
		}

		$this->assertCount( 2, $this->disk_mails() );
	}

	public function test_a_disk_nothing_covers_does_not_look_at_the_server_task_or_snapshots(): void {
		$r    = $this->server();
		$this->take( $r, 'before' );
		$disk = $this->disk_for( $r );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->assertTrue( $this->fresh( $r )->set( array( 'task' => array( 'step' => 'plan', 'phase' => '', 'upid' => 'UPID:node-a:0000:0000:0000:qmstart:5000:x:', 'node' => 'node-a' ) ) ) );
		$seen = count( $this->fake->calls() );

		$done = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );

		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertNull( $this->fresh( $r )->get( Snapshots::DROP_WAITS ) );
		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => false !== strpos( $l, '/snapshot' ) ) );
	}

	public function test_a_disk_delete_does_not_wait_for_ever_on_a_task_of_another_job(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$this->take( $r, 'covers' );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->assertTrue( $this->fresh( $r )->set( array( 'task' => array( 'step' => 'plan', 'phase' => '', 'upid' => 'UPID:node-a:0000:0000:0000:qmstart:5000:x:', 'node' => 'node-a' ) ) ) );

		$done = null;
		for ( $i = 0; $i < 12 && ( null === $done || ! $done->is_done() ); $i++ ) {
			$done = Registry::get( 'proxmox-disk' )->delete( $this->fresh( $disk ) );
		}

		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		$this->assertArrayNotHasKey( 'scsi1', $this->config() );
	}

	public function test_the_reinstall_alert_is_sent_once_by_a_flag_not_by_its_text(): void {
		$r = $this->server();
		$this->take( $r, 'stuck' );
		$this->fake->set_lock( 5000, 'backup' );
		$this->reinstall( $r );

		for ( $i = 0; $i < 5; $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
			if ( 2 === $i ) {
				// The answer changes: a dedupe on the text would mail again.
				$this->fake->set_lock( 5000, 'vzdump' );
			}
		}

		$told = array_filter( $this->admin_mails(), static fn( $m ) => false !== strpos( (string) $m['message'], 'reinstall waits' ) );
		$this->assertCount( 1, $told );
		$this->assertSame( 1, (int) $this->fresh( $r )->get( 'wipe_snapshots_alerted' ) );

		$this->fake->set_lock( 5000, '' );
		$this->rebuild( $r->id() );
		$this->assertNull( $this->fresh( $r )->get( 'wipe_snapshots_alerted' ) );
	}

	public function test_a_reinstall_whose_vm_vanished_while_it_waited_forgets_the_snapshot_list(): void {
		$r = $this->server();
		$this->take( $r, 'a' );
		$this->fake->set_lock( 5000, 'backup' );
		$this->reinstall( $r );
		$this->assertCount( 1, Snapshots::entries( $this->fresh( $r ) ) );

		// The VM is destroyed outside the plugin (its snapshots with it).
		$this->fake->set_lock( 5000, '' );
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->task( 'DELETE', '/nodes/node-a/qemu/5000', array( 'purge' => 1 ) );
		$this->assertNull( $this->fake->vm( 5000 ) );

		$this->rebuild( $r->id() );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertNull( $this->fresh( $r )->get( Snapshots::OP ) );
	}
}
