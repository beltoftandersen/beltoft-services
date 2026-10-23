<?php
/**
 * The nightly backup run. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * The chain: one server per action, one backup at a time.
 */
class BackupJobsTest extends BackupTestCase {

	private function next_scheduled(): bool {
		return as_has_scheduled_action( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
	}

	/**
	 * Runs the follow-up action that is queued (its 60 second delay is skipped).
	 */
	private function follow_up(): void {
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		ProxmoxJobs::backup_next();
	}

	public function test_the_cron_is_scheduled_at_half_past_three(): void {
		ProxmoxJobs::schedule();

		$this->assertTrue( as_has_scheduled_action( ProxmoxJobs::BACKUP, array(), Jobs::GROUP ) );

		$actions = as_get_scheduled_actions( array( 'hook' => ProxmoxJobs::BACKUP, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) );
		$action  = reset( $actions );
		$this->assertSame( '30 3 * * *', (string) $action->get_schedule()->get_recurrence() );

		ProxmoxJobs::unschedule();
		$this->assertFalse( as_has_scheduled_action( ProxmoxJobs::BACKUP, array(), Jobs::GROUP ) );
	}

	public function test_the_chain_backs_up_one_server_after_the_other_and_stops(): void {
		$one = $this->ready_vm();
		$two = $this->ready_vm();

		ProxmoxJobs::backup();
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ), 'One server per action.' );
		$this->assertTrue( $this->next_scheduled() );

		$this->follow_up();
		$dumps = $this->calls( 'POST', '/vzdump' );
		$this->assertCount( 2, $dumps );
		$this->assertSame(
			array( (int) $one->get( 'vmid' ), (int) $two->get( 'vmid' ) ),
			array_map( static fn( $c ) => $c['params']['vmid'], $dumps )
		);
		$this->assertTrue( $this->next_scheduled(), 'It looks once more.' );

		$this->follow_up();
		$this->assertCount( 2, $this->calls( 'POST', '/vzdump' ), 'Nothing is due: no third backup.' );
		$this->assertFalse( $this->next_scheduled(), 'And it stops.' );

		foreach ( array( $one, $two ) as $r ) {
			$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'backup_ok_at' ) );
		}
	}

	public function test_a_server_that_is_not_due_or_has_no_backups_is_left_alone(): void {
		$none = $this->ready_vm( array( 'backup_days' => 0 ) );
		$done = $this->ready_vm();
		$done->set( array( 'backup_last_at' => time() - HOUR_IN_SECONDS ) );

		ProxmoxJobs::backup();

		$this->assertCount( 0, $this->calls( 'POST', '/vzdump' ) );
		$this->assertFalse( $this->next_scheduled() );
		$this->assertSame( 0, (int) Resource::find( $none->id() )->get( 'backup_ok_at' ) );
	}

	public function test_pause_all_stops_the_run_and_the_chain(): void {
		$this->ready_vm();
		$this->ready_vm();

		$this->settings( array( 'pause_all' => 1 ) );
		ProxmoxJobs::backup();
		$this->assertCount( 0, $this->calls( 'POST', '/vzdump' ) );
		$this->assertFalse( $this->next_scheduled() );

		$this->settings( array( 'pause_all' => 0 ) );
		ProxmoxJobs::backup();
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ) );

		$this->settings( array( 'pause_all' => 1 ) );
		$this->follow_up();
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ), 'The second server waits.' );
		$this->assertFalse( $this->next_scheduled() );
	}

	public function test_a_backup_still_running_is_polled_again_and_blocks_the_next_server(): void {
		$one = $this->ready_vm();
		$two = $this->ready_vm();
		$this->fake->next_task( array( 'polls' => 8 ) );

		ProxmoxJobs::backup();
		$this->assertIsArray( Resource::find( $one->id() )->get( 'backup_task' ) );
		$this->assertTrue( $this->next_scheduled() );

		$this->follow_up();
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ), 'The same server is polled, no second vzdump starts.' );

		$this->follow_up();
		$this->assertNull( Resource::find( $one->id() )->get( 'backup_task' ) );
		$this->assertGreaterThan( 0, (int) Resource::find( $one->id() )->get( 'backup_ok_at' ) );

		$this->follow_up();
		$this->assertCount( 2, $this->calls( 'POST', '/vzdump' ) );
		$this->assertSame( (int) $two->get( 'vmid' ), $this->calls( 'POST', '/vzdump' )[1]['params']['vmid'] );
	}

	public function test_a_failed_backup_is_not_tried_again_in_the_same_run(): void {
		$one = $this->ready_vm();
		$this->fake->fail_next( 'POST', '#/vzdump$#', array( 'message' => 'storage is not online' ) );

		ProxmoxJobs::backup();
		$this->follow_up();
		$this->follow_up();

		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ) );
		$this->assertSame( 1, (int) Resource::find( $one->id() )->get( 'backup_fails' ) );
		$this->assertFalse( $this->next_scheduled() );
	}

	public function test_the_removal_of_backups_of_a_server_that_lost_them_runs_in_the_next_run(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );

		ProxmoxJobs::backup();
		$this->assertCount( 1, $this->fake->state()['backups']['backup-store'] );
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );

		$r = Resource::find( $r->id() );
		$r->set( array( 'plan' => array_merge( $r->get( 'plan' ), array( 'backup_days' => 0 ) ), 'backup_purge' => true ) );

		ProxmoxJobs::backup();

		$this->assertSame( array(), $this->fake->state()['backups']['backup-store'] );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'backup_purge' ) );
		$this->assertNull( $fresh->get( 'backup_ok_at' ) );
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ), 'No new backup was made.' );
		$this->assertSame( $vmid, (int) $fresh->get( 'vmid' ) );

		// Backups bought again after the removal: the server is due at once, not 20 hours later.
		$fresh->set( array( 'plan' => array_merge( $fresh->get( 'plan' ), array( 'backup_days' => 7 ) ) ) );
		$this->assertTrue( Backups::due( Resource::find( $r->id() ), time() ) );
	}
}
