<?php
/**
 * Backups in the delete and plan-change operations. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * Delete purges first; a backup-only plan change is quiet; a backup does not break a plan change.
 */
class BackupOperationsTest extends BackupTestCase {

	/**
	 * Calls as "METHOD path" lines.
	 *
	 * @return string[]
	 */
	private function lines(): array {
		return array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], $this->fake->calls() );
	}

	private function change_plan( Resource $r, array $over ): void {
		$plan = array_merge( (array) Resource::find( $r->id() )->get( 'plan' ), $over );
		$this->assertTrue( Resource::find( $r->id() )->change_plan( $plan, 'Plan changed' ) );
	}

	private function retry_now( int $id ): void {
		Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1 ) );
		Jobs::run( $id );
	}

	// -- Delete ------------------------------------------------------------------------------------

	public function test_delete_removes_the_backups_before_the_vm(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 5 ) );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 6 ) );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'deleted', 'test' ) );

		Jobs::run( $r->id() );
		for ( $i = 0; $i < 6 && 'deleted' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			$this->retry_now( $r->id() );
		}

		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertSame( array(), $this->fake->state()['backups']['backup-store'] );

		$lines   = $this->lines();
		$content = array_keys( array_filter( $lines, static fn( $l ) => 0 === strpos( $l, 'DELETE /nodes/node-a/storage/backup-store/content/' ) ) );
		$vm      = array_keys( array_filter( $lines, static fn( $l ) => 'DELETE /nodes/node-a/qemu/' . $vmid === $l ) );

		$this->assertCount( 2, $content );
		$this->assertCount( 1, $vm );
		$this->assertLessThan( $vm[0], max( $content ), 'Every backup delete comes before the VM delete.' );
		$this->assertNull( $this->fake->vm( $vmid ) );
	}

	public function test_a_server_that_never_had_backups_makes_no_backup_call_on_delete(): void {
		$r = $this->ready_vm( array( 'backup_days' => 0 ) );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'deleted', 'test' ) );

		Jobs::run( $r->id() );
		for ( $i = 0; $i < 6 && 'deleted' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			$this->retry_now( $r->id() );
		}

		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertCount( 0, array_filter( $this->lines(), static fn( $l ) => false !== strpos( $l, '/storage/' ) ) );
	}

	public function test_a_failing_purge_is_retried_then_alerts_and_the_delete_goes_on(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 5 ) );
		$this->mails = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->fake->fail_next( 'DELETE', '#/content/#', array( 'message' => 'storage is not online' ) );
		}

		$this->assertTrue( Resource::find( $r->id() )->desire( 'deleted', 'test' ) );
		Jobs::run( $r->id() );

		$this->assertSame( 1, (int) Resource::find( $r->id() )->get( 'backup_purge_tries' ) );
		$this->assertNotNull( $this->fake->vm( $vmid ), 'The VM is still there while the backups are not gone.' );

		$this->retry_now( $r->id() );
		$this->assertSame( 2, (int) Resource::find( $r->id() )->get( 'backup_purge_tries' ) );
		$this->assertNotNull( $this->fake->vm( $vmid ) );

		for ( $i = 0; $i < 6 && 'deleted' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			$this->retry_now( $r->id() );
		}

		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'state' ), 'Three tries, then the delete goes on.' );
		$this->assertNull( $this->fake->vm( $vmid ) );
		$alerts = array_values( array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ) );
		$this->assertCount( 1, $alerts, 'One admin alert (the customer mails are not under test).' );
		$this->assertStringContainsString( 'Backups could not be deleted', $alerts[0]['message'] );
	}

	// -- Plan change -------------------------------------------------------------------------------

	public function test_a_backup_only_plan_change_makes_no_shutdown_and_no_config_write(): void {
		$r = $this->ready_vm( array( 'backup_days' => 0 ) );
		$this->assertFalse( Backups::enabled( $r ) );
		$vmid  = (int) $r->get( 'vmid' );
		$seen  = count( $this->fake->calls() );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );

		$this->change_plan( $r, array( 'backup_days' => 7 ) );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertFalse( $fresh->get( 'plan_dirty' ), 'The change was applied.' );
		$this->assertTrue( Backups::enabled( $fresh ) );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );

		$after = array_slice( $this->lines(), $seen );
		foreach ( $after as $line ) {
			$this->assertDoesNotMatchRegularExpression( '#/status/(shutdown|stop|start|reboot)$|^PUT |^POST .*/config$#', $line, 'No power change and no config write.' );
		}
	}

	public function test_a_plan_change_waits_for_a_running_backup_and_then_applies(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->set_lock( $vmid, 'backup' );

		$this->change_plan( $r, array( 'cores' => 4 ) );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertNotSame( 'failed', $fresh->get( 'state' ), 'Locked by vzdump: retried, not failed.' );
		$this->assertTrue( $fresh->get( 'plan_dirty' ) );
		$this->assertStringContainsString( 'locked', (string) $fresh->get( 'last_error' ) );
		$this->assertSame( 2, (int) $this->fake->vm( $vmid )['config']['cores'] );

		$this->fake->set_lock( $vmid, '' );
		$this->retry_now( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertFalse( $fresh->get( 'plan_dirty' ) );
		$this->assertSame( 4, (int) $this->fake->vm( $vmid )['config']['cores'] );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'], 'The VM was started again.' );
	}

	public function test_removing_backups_queues_their_deletion_for_the_next_run(): void {
		$r = $this->ready_vm();
		ProxmoxJobs::backup();
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		$this->assertCount( 1, $this->fake->state()['backups']['backup-store'] );

		$this->change_plan( $r, array( 'backup_days' => 0 ) );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertTrue( $fresh->get( 'backup_purge' ) );
		$this->assertCount( 1, $this->fake->state()['backups']['backup-store'], 'Nothing is deleted before the nightly run.' );

		ProxmoxJobs::backup();

		$this->assertSame( array(), $this->fake->state()['backups']['backup-store'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'backup_purge' ) );
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ), 'No backup is taken for a plan without backups.' );
	}

	public function test_buying_backups_again_before_the_run_cancels_the_deletion(): void {
		$r = $this->ready_vm();
		ProxmoxJobs::backup();
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );

		$this->change_plan( $r, array( 'backup_days' => 0 ) );
		Jobs::run( $r->id() );
		$this->assertTrue( Resource::find( $r->id() )->get( 'backup_purge' ) );

		$this->change_plan( $r, array( 'backup_days' => 7 ) );
		Jobs::run( $r->id() );
		$this->assertNull( Resource::find( $r->id() )->get( 'backup_purge' ) );

		ProxmoxJobs::backup();
		$this->assertCount( 1, $this->fake->state()['backups']['backup-store'], 'The backup is kept (and no new one is due yet).' );
	}

	public function test_the_vm_record_is_untouched_by_backup_bookkeeping(): void {
		$r = $this->ready_vm();
		ProxmoxJobs::backup();

		$this->assertNotNull( VmRecords::for_resource( $r->id() ) );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
	}
}
