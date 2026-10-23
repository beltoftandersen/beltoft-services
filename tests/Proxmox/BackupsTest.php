<?php
/**
 * Backups core: the due rules, the nightly step, the listing, the purge, the variation sibling. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * Backups against the fake cluster.
 */
class BackupsTest extends BackupTestCase {

	private function step( Resource $r ): \BeltoftServices\Drivers\StepResult {
		return ( new Backups( Resource::find( $r->id() ) ) )->step();
	}

	// -- Due rules ---------------------------------------------------------------------------------

	public function test_due_rules(): void {
		$r   = $this->ready_vm();
		$now = time();

		$this->assertTrue( Backups::enabled( $r ) );
		$this->assertTrue( Backups::due( $r, $now ), 'Never backed up.' );

		$r->set( array( 'backup_last_at' => $now - 19 * HOUR_IN_SECONDS ) );
		$this->assertFalse( Backups::due( Resource::find( $r->id() ), $now ), 'Less than 20 hours ago.' );

		$r->set( array( 'backup_last_at' => $now - 21 * HOUR_IN_SECONDS ) );
		$this->assertTrue( Backups::due( Resource::find( $r->id() ), $now ) );

		$r->set( array( 'backup_last_at' => $now, 'backup_task' => array( 'upid' => 'UPID:node-a:1', 'node' => 'node-a', 'started' => $now ) ) );
		$this->assertTrue( Backups::due( Resource::find( $r->id() ), $now ), 'A task still to collect.' );

		$r->set( array( 'backup_task' => null, 'backup_last_at' => 0, 'state' => 'paused' ) );
		$this->assertFalse( Backups::due( Resource::find( $r->id() ), $now ), 'Only a ready server.' );

		$r->set( array( 'state' => 'ready', 'linked' => true ) );
		$this->assertFalse( Backups::enabled( Resource::find( $r->id() ) ), 'An adopted machine.' );
		$r->set( array( 'linked' => null ) );

		$r->set( array( 'plan' => array_merge( $r->get( 'plan' ), array( 'backup_days' => 0 ) ) ) );
		$this->assertFalse( Backups::due( Resource::find( $r->id() ), $now ), 'No backup days on the plan.' );
		$r->set( array( 'plan' => array_merge( $r->get( 'plan' ), array( 'backup_days' => 7 ) ) ) );
		$this->assertTrue( Backups::due( Resource::find( $r->id() ), $now ) );

		$this->save_cluster( array( 'backup_storage' => '' ) );
		$this->assertFalse( Backups::due( Resource::find( $r->id() ), $now ), 'The cluster has no backup storage.' );
	}

	// -- The step ----------------------------------------------------------------------------------

	public function test_a_backup_runs_is_recorded_and_pruned_to_the_plan_days(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );

		$result = $this->step( $r );
		$this->assertTrue( $result->is_done() );

		$fresh = Resource::find( $r->id() );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'backup_ok_at' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'backup_last_at' ) );
		$this->assertNull( $fresh->get( 'backup_task' ) );
		$this->assertSame( 0, (int) $fresh->get( 'backup_fails' ) );
		$this->assertSame( '', (string) $fresh->get( 'backup_error' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		$dump = $this->calls( 'POST', '/vzdump' );
		$this->assertCount( 1, $dump );
		$this->assertSame(
			array(
				'vmid'           => $vmid,
				'storage'        => 'backup-store',
				'mode'           => 'snapshot',
				'notes-template' => '{{guestname}}',
			),
			$dump[0]['params']
		);

		$prune = $this->calls( 'DELETE', '/prunebackups' );
		$this->assertCount( 1, $prune );
		$this->assertSame( '/nodes/node-a/storage/backup-store/prunebackups', $prune[0]['path'] );
		$this->assertSame(
			array(
				'vmid'          => $vmid,
				'type'          => 'qemu',
				'prune-backups' => 'keep-daily=7',
			),
			$prune[0]['params']
		);

		$list = ( new Backups( $fresh ) )->list();
		$this->assertCount( 1, $list );
	}

	public function test_a_running_backup_is_collected_by_a_later_round(): void {
		$r = $this->ready_vm();
		$this->fake->next_task( array( 'polls' => 5 ) );

		$first = $this->step( $r );
		$this->assertTrue( $first->is_retry() );
		$this->assertIsArray( Resource::find( $r->id() )->get( 'backup_task' ) );
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ) );

		$second = $this->step( $r );

		$this->assertTrue( $second->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ), 'The task was polled, never started again.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'backup_task' ) );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'backup_ok_at' ) );
	}

	public function test_three_failures_in_a_row_alert_the_admin_once_and_the_server_is_unaffected(): void {
		$r          = $this->ready_vm();
		$this->mails = array(); // The customer's "ready" mail is not under test.

		for ( $night = 1; $night <= 4; $night++ ) {
			$this->fake->fail_next( 'POST', '#/vzdump$#', array( 'message' => 'storage is not online' ) );
			$result = $this->step( $r );
			$this->assertTrue( $result->is_done(), 'A failed night is over, not retried at once.' );

			$fresh = Resource::find( $r->id() );
			$this->assertSame( $night, (int) $fresh->get( 'backup_fails' ) );
			$this->assertStringContainsString( 'storage is not online', (string) $fresh->get( 'backup_error' ) );
			$this->assertSame( 'ready', $fresh->get( 'state' ), 'The server is unaffected.' );
			$this->assertSame( 'running', $this->fake->vm( (int) $fresh->get( 'vmid' ) )['status'] );
			$this->assertCount( $night >= 3 ? 1 : 0, $this->mails, 'One alert, on the third night.' );
		}

		$this->assertSame( array( 'admin@example.com' ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Backup failed 3 nights in a row', $this->mails[0]['message'] );

		// A good night clears the count and the error.
		$this->step( $r );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 0, (int) $fresh->get( 'backup_fails' ) );
		$this->assertSame( '', (string) $fresh->get( 'backup_error' ) );
	}

	public function test_a_failed_task_is_counted(): void {
		$r = $this->ready_vm();
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: job failed with err -5' ) );

		$this->assertTrue( $this->step( $r )->is_done() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 1, (int) $fresh->get( 'backup_fails' ) );
		$this->assertStringContainsString( 'job failed', (string) $fresh->get( 'backup_error' ) );
		$this->assertNull( $fresh->get( 'backup_task' ) );
		$this->assertCount( 0, $this->calls( 'DELETE', '/prunebackups' ), 'No prune after a failed backup.' );
	}

	// -- Listing, restore safety, purge ------------------------------------------------------------

	public function test_the_listing_hides_backups_older_than_the_vm_record_and_other_vms(): void {
		$r      = $this->ready_vm();
		$vmid   = (int) $r->get( 'vmid' );
		$record = VmRecords::for_resource( $r->id() );
		$born   = (int) strtotime( $record['created_at'] . ' UTC' );

		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $born - 100, 'volid' => 'backup-store:backup/vm/' . $vmid . '/old' ) );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $born + 100, 'volid' => 'backup-store:backup/vm/' . $vmid . '/new' ) );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid + 1, 'ctime' => $born + 100 ) );

		$list = ( new Backups( $r ) )->list();

		$this->assertSame( array( 'backup-store:backup/vm/' . $vmid . '/new' ), array_column( $list, 'volid' ) );
		$this->assertSame( array( 'volid', 'ctime', 'size' ), array_keys( $list[0] ) );
	}

	public function test_the_listing_is_newest_first(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$now  = time() + 1000;

		foreach ( array( 5, 30, 10 ) as $age ) {
			$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $now - $age ) );
		}

		$times = array_column( ( new Backups( $r ) )->list(), 'ctime' );
		$sorted = $times;
		rsort( $sorted );

		$this->assertSame( $sorted, $times );
	}

	public function test_purge_deletes_every_listed_backup_and_never_the_ones_it_hides(): void {
		$r      = $this->ready_vm();
		$vmid   = (int) $r->get( 'vmid' );
		$record = VmRecords::for_resource( $r->id() );
		$born   = (int) strtotime( $record['created_at'] . ' UTC' );

		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $born + 10 ) );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $born + 20 ) );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $born - 500 ) );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid + 1, 'ctime' => $born + 20 ) );

		$result = ( new Backups( $r ) )->purge();

		$this->assertTrue( $result->is_done() );
		$this->assertCount( 2, array_filter( $this->fake->calls(), static fn( $c ) => 'DELETE' === $c['method'] && false !== strpos( $c['path'], '/content/' ) ), 'One DELETE per volume' );
		$left = array_map( static fn( $b ) => array( $b['vmid'], $b['ctime'] ), $this->fake->state()['backups']['backup-store'] );
		$this->assertContains( array( $vmid, $born - 500 ), $left, 'An older backup is not touched.' );
		$this->assertContains( array( $vmid + 1, $born + 20 ), $left, 'Another VM is not touched.' );
		$this->assertCount( 2, $left );
	}

	public function test_purge_retries_when_a_delete_is_refused(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 10 ) );
		$this->fake->fail_next( 'DELETE', '#/content/#', array( 'message' => 'permission check failed' ) );

		$result = ( new Backups( $r ) )->purge();

		$this->assertTrue( $result->is_retry() );
		$this->assertCount( 1, $this->fake->state()['backups']['backup-store'] );
		$this->assertTrue( ( new Backups( $r ) )->purge()->is_done() );
		$this->assertCount( 0, $this->fake->state()['backups']['backup-store'] );
	}

	public function test_the_ownership_check_comes_before_a_write(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );

		// Somebody changed the description: the tag line is gone.
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/' . $vmid . '/config', array( 'description' => 'someone else' ) );

		$this->assertTrue( $this->step( $r )->is_done() );
		$this->assertCount( 0, $this->calls( 'POST', '/vzdump' ) );
		$this->assertSame( 1, (int) Resource::find( $r->id() )->get( 'backup_fails' ) );

		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 10 ) );
		$this->assertTrue( ( new Backups( $r ) )->purge()->is_retry() );
		$this->assertCount( 0, array_filter( $this->fake->calls(), static fn( $c ) => 'DELETE' === $c['method'] && false !== strpos( $c['path'], '/content/' ) ) );
	}

	// -- Variation sibling -------------------------------------------------------------------------

	public function test_sibling_finds_the_variation_with_or_without_backups(): void {
		$v = $this->variable_product();

		$with = Backups::sibling( $v['a-None'], true );
		$this->assertNotNull( $with );
		$this->assertSame( $v['a-Daily']->get_id(), $with->get_id(), 'Same tier, only backups differ.' );

		$without = Backups::sibling( $v['b-Daily'], false );
		$this->assertSame( $v['b-None']->get_id(), $without->get_id() );

		$this->assertNull( Backups::sibling( $v['a-None'], false ), 'Already without backups: no other state to offer.' );
		$this->assertNull( Backups::sibling( $v['a-Daily'], true ), 'Already with backups.' );
	}

	public function test_sibling_is_null_without_a_matching_variation(): void {
		$plain = $this->service_product( 'proxmox', array(), array( 'variations' => array( array(), array() ) ) );
		$first = wc_get_product( $plain->get_children()[0] );

		$this->assertNull( Backups::sibling( $first, true ), 'A single attribute and no backup variation.' );
		$this->assertNull( Backups::sibling( $first, false ) );

		$v = $this->variable_product();
		$v['a-Daily']->set_status( 'private' );
		$v['a-Daily']->save();

		$this->assertNull( Backups::sibling( $v['a-None'], true ), 'An unpublished sibling is not offered.' );
	}
}
