<?php
/**
 * Faster actions: waits are polls that come back in seconds, the status lag is settled inside the run, and the run
 * budget caps one run. FakeClient only; time passes through the fake's own clock (elapse), nothing sleeps.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class FasterActionsTest extends SnapshotsTestCase {

	private function power_writes(): array {
		return array_values( array_filter( $this->lines(), static fn( $l ) => (bool) preg_match( '#^POST .*/status/(shutdown|stop|start)$#', $l ) ) );
	}

	/** Pending customer actions of the plugin: scheduled time by action id. */
	private function pending_acts(): array {
		$times = array();

		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) ) as $id => $action ) {
			$times[ (int) $id ] = $action->get_schedule()->get_date()->getTimestamp();
		}

		return $times;
	}

	/** Runs the pending customer action of the plugin as the runner would, and returns how far away it was queued. */
	private function run_pending_act(): int {
		$pending = $this->pending_acts();
		$this->assertCount( 1, $pending, 'One follow-up is queued.' );

		$id = (int) array_key_first( $pending );
		$in = $pending[ $id ] - time();

		\ActionScheduler::runner()->process_action( $id, 'Tests' );

		return $in;
	}

	// -- Delete and reinstall ---------------------------------------------------------------------

	public function test_a_delete_whose_status_lags_eight_seconds_is_one_run_with_one_shutdown_and_no_retry(): void {
		$r = $this->ready_vm();
		$this->fake->lag_status_seconds( 5000, 8 );
		$this->pauses = array();
		$seen         = count( $this->fake->calls() );

		$result = ( new ProxmoxDriver() )->delete( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertNull( $this->fake->vm( 5000 ) );
		$this->assertSame( array( 'POST /nodes/node-a/qemu/5000/status/shutdown' ), array_values( array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => (bool) preg_match( '#^POST .*/status/#', $l ) ) ), 'One shutdown, no plug pull.' );
		$this->assertSame( array( 2, 2, 2, 2 ), $this->pauses, 'It looked every two seconds until the status caught up.' );
	}

	public function test_a_reinstall_whose_stop_shows_late_reaches_the_destroy_in_the_same_run(): void {
		$server = $this->ready_vm();
		$this->fake->lag_status_seconds( 5000, 8 );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		Jobs::run( $server->id() );

		$this->assertCount( 1, $this->calls( 'DELETE', '/qemu/5000' ), 'The wipe got to the destroy in one run.' );
		$this->assertSame( '', (string) Resource::find( $server->id() )->get( 'last_error' ) );
		$this->assertSame( '', (string) Resource::find( $server->id() )->get( 'waiting' ) );
	}

	public function test_a_delete_that_waits_longer_than_the_settle_is_a_wait_and_nothing_is_written_twice(): void {
		$r = $this->ready_vm();
		$this->fake->lag_status_seconds( 5000, 45 );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'deleted', 'test' ) );

		Jobs::run( $r->id() );

		$mid = Resource::find( $r->id() );
		$this->assertSame( 0, $mid->get( 'attempts' ), 'Not a counted attempt.' );
		$this->assertSame( '', (string) $mid->get( 'last_error' ), 'Not a red error.' );
		$this->assertNotSame( '', (string) $mid->get( 'waiting' ) );
		$this->assertLessThanOrEqual( time() + 6, $mid->get( 'next_attempt_at' ), 'Back in seconds, not after the 60 second floor.' );

		for ( $i = 0; $i < 6 && 'deleted' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
		}

		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'state' ), (string) Resource::find( $r->id() )->get( 'last_error' ) );
		$this->assertCount( 1, array_filter( $this->power_writes(), static fn( $l ) => str_ends_with( $l, '/shutdown' ) ), 'One shutdown across every run.' );
		$this->assertCount( 1, $this->calls( 'DELETE', '/qemu/5000' ) );
	}

	// -- Start check -------------------------------------------------------------------------------

	public function test_a_start_the_status_shows_late_is_a_wait_then_done_without_a_second_start_or_a_stale_error(): void {
		$r = $this->ready_vm();
		Jobs::act( $r->id(), 'shutdown', array() );
		$this->fake->lag_status_seconds( 5000, 30 );
		$starts = count( $this->calls( 'POST', '/status/start' ) );

		Jobs::act( $r->id(), 'start', array() );

		$mid = Resource::find( $r->id() );
		$this->assertSame( 0, (int) $mid->get( 'act_attempts' ) );
		$this->assertSame( '', (string) $mid->get( 'last_error' ), 'No false error.' );
		$this->assertStringContainsString( 'did not leave the VM running', (string) $mid->get( 'waiting' ) );
		$this->assertLessThanOrEqual( 6, $this->run_pending_act_in() );

		// Ten seconds of the lag are left; the next look finds the VM running and does not start it again.
		$this->run_pending_act();

		$after = Resource::find( $r->id() );
		$this->assertSame( '', (string) $after->get( 'last_error' ) );
		$this->assertSame( '', (string) $after->get( 'waiting' ) );
		$this->assertCount( $starts + 1, $this->calls( 'POST', '/status/start' ), 'The start is sent once.' );
	}

	/** How many seconds away the one pending customer action is (without running it). */
	private function run_pending_act_in(): int {
		$pending = $this->pending_acts();
		$this->assertCount( 1, $pending );

		return (int) reset( $pending ) - time();
	}

	public function test_a_resume_whose_start_is_not_listed_for_half_a_minute_waits_and_ends_clean(): void {
		$r      = $this->ready_vm();
		$driver = new ProxmoxDriver();
		$this->assertTrue( $driver->pause( Resource::find( $r->id() ) )->is_done() );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'paused', 'test' ) );
		Jobs::run( $r->id() );
		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'state' ) );

		$this->fake->lag_status_seconds( 5000, 30 );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'ready', 'test' ) );
		Jobs::run( $r->id() );

		$mid = Resource::find( $r->id() );
		$this->assertSame( 'paused', $mid->get( 'state' ) );
		$this->assertSame( 0, $mid->get( 'attempts' ) );
		$this->assertSame( '', (string) $mid->get( 'last_error' ) );
		$this->assertNotSame( '', (string) $mid->get( 'waiting' ) );

		$this->fake->elapse( 30 );
		$mid->set( array( 'next_attempt_at' => time() - 1 ) );
		Jobs::run( $r->id() );

		$end = Resource::find( $r->id() );
		$this->assertSame( 'ready', $end->get( 'state' ), (string) $end->get( 'last_error' ) );
		$this->assertSame( '', (string) $end->get( 'last_error' ) );
		$this->assertSame( '', (string) $end->get( 'waiting' ) );
	}

	// -- Run budget --------------------------------------------------------------------------------

	public function test_the_run_budget_caps_the_wait_for_a_task_and_the_rest_is_a_poll(): void {
		$server = $this->server();
		$this->fake->next_task( array( 'polls' => 10 ) ); // 20 seconds of task: inside the 25 second task wait.

		add_filter( 'beltoft_services_run_budget', static fn() => 6 );
		$this->act( $server, 'snapshot_take', array( 'label' => 'slow' ) );

		$this->assertSame( 'creating', Snapshots::entries( $this->fresh( $server ) )[0]['state'], 'Six seconds of the budget are not enough: not finished.' );
		$this->assertNotSame( '', (string) $this->fresh( $server )->get( 'waiting' ) );
		$this->assertSame( '', (string) $this->fresh( $server )->get( 'last_error' ) );
		$this->assertLessThanOrEqual( 6, $this->run_pending_act_in() );

		remove_all_filters( 'beltoft_services_run_budget' );

		for ( $hops = 0; $this->pending_acts() && $hops < 6; $hops++ ) {
			$this->run_pending_act();
		}

		$this->assertNotSame( 'creating', Snapshots::entries( $this->fresh( $server ) )[0]['state'] );
		$this->assertCount( 1, $this->calls( 'POST', '/snapshot' ), 'The task was followed, not started again.' );
	}

	// -- Rollback ----------------------------------------------------------------------------------

	public function test_a_rollback_with_a_slow_stop_and_a_slow_task_comes_back_in_seconds_each_time_and_writes_once(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$seen   = count( $this->fake->calls() );

		$this->fake->lag_status_seconds( 5000, 8 );
		$this->fake->next_task( array() );                // The shutdown.
		$this->fake->next_task( array( 'polls' => 10 ) ); // The rollback, 20 seconds.
		add_filter( 'beltoft_services_run_budget', static fn() => 12 );

		$this->act( $server, 'snapshot_rollback', array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' ) );

		$hops = 0;
		while ( $this->pending_acts() && $hops < 12 ) {
			$this->assertLessThanOrEqual( 6, $this->run_pending_act(), 'Every hop is due in seconds.' );
			++$hops;
		}

		$fresh = $this->fresh( $server );
		$this->assertNull( $fresh->get( Snapshots::ROLLBACK ), (string) $fresh->get( 'last_error' ) );
		$this->assertSame( 0, (int) $fresh->get( 'act_attempts' ) );
		$this->assertSame( '', (string) $fresh->get( 'last_error' ) );
		$this->assertSame( '', (string) $fresh->get( 'waiting' ) );
		$this->assertCount( 1, array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => str_ends_with( $l, '/rollback' ) ), 'One rollback.' );
		$this->assertCount( 1, array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => str_ends_with( $l, '/status/shutdown' ) ), 'One shutdown.' );
	}

	// -- Review fixes ------------------------------------------------------------------------------

	private function fail_tasks( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->fake->next_task( array( 'exitstatus' => 'storage full', 'effect' => false ) );
		}
	}

	public function test_a_failed_stop_is_one_shutdown_and_one_stop_then_a_counted_retry_not_a_resend_every_five_seconds(): void {
		$r = $this->ready_vm();
		$this->fail_tasks( 20 );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'paused', 'test' ) );
		$seen = count( $this->fake->calls() );

		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 1, $fresh->get( 'attempts' ), 'Counted.' );
		$this->assertNotSame( '', $fresh->get( 'last_error' ) );
		$this->assertSame( '', (string) $fresh->get( 'waiting' ), 'Not a free wait.' );
		$this->assertGreaterThanOrEqual( time() + 59, $fresh->get( 'next_attempt_at' ), 'The backoff applies.' );
		$writes = array_values( array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => (bool) preg_match( '#^POST .*/status/#', $l ) ) );
		$this->assertSame( array( 'POST /nodes/node-a/qemu/5000/status/shutdown', 'POST /nodes/node-a/qemu/5000/status/stop' ), $writes );

		// The job that comes back too early (a sweep, a wake) writes nothing.
		Jobs::run( $r->id() );
		$this->assertCount( 2, array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => (bool) preg_match( '#^POST .*/status/#', $l ) ) );
	}

	public function test_a_hard_stop_that_the_status_shows_late_for_half_a_minute_is_sent_once_by_a_reinstall(): void {
		$server = $this->ready_vm();
		$this->fake->lag_status_seconds( 5000, 70 );
		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );

		for ( $i = 0; $i < 3; $i++ ) {
			Resource::find( $server->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $server->id() );
		}

		$this->assertCount( 1, array_filter( $this->lines(), static fn( $l ) => str_ends_with( $l, 'POST /nodes/node-a/qemu/5000/status/stop' ) ), 'Exactly one stop.' );
		$this->assertSame( '', (string) Resource::find( $server->id() )->get( 'last_error' ) );
		$this->assertCount( 0, $this->calls( 'DELETE', '/qemu/5000' ), 'Nothing is destroyed while the VM is shown running.' );
	}

	public function test_the_kill_switch_turned_on_in_the_middle_of_a_run_stops_the_next_write(): void {
		$r = $this->ready_vm();
		// The shutdown fails, so the next round would send a stop; the switch is turned on while the run waits.
		$this->fake->next_task( array( 'exitstatus' => 'VM quit/powerdown failed - got timeout', 'effect' => false ) );
		add_filter(
			'beltoft_services_proxmox_pause',
			function () {
				return function ( int $seconds ): void {
					$this->settings( array( 'pause_all' => 1 ) );
				};
			},
			30
		);

		$result = ( new ProxmoxDriver() )->pause( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_retry() );
		$this->assertCount( 1, $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertCount( 0, $this->calls( 'POST', '/status/stop' ), 'No write after the switch.' );
		$this->assertStringContainsString( 'paused', $result->error() );
		$this->assertFalse( $result->is_poll() );
		$this->assertGreaterThan( time() + 3600, $result->wait_until(), 'A free wait that never fails the service.' );
	}

	public function test_a_rollback_whose_server_never_stops_ends_in_the_admin_alert_with_few_writes(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$this->fake->lag_status_seconds( 5000, 1000000 ); // The status shows running for good.
		$this->mails        = array();
		$seen               = count( $this->fake->calls() );

		$skew = 0;
		add_filter( 'beltoft_services_now', static function ( $t ) use ( &$skew ) { return $t + $skew; } );

		for ( $run = 1; $run <= 90 && ! $this->mails; $run++ ) {
			Jobs::act( $server->id(), 'snapshot_rollback', array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' ) );
			as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );
			$skew += 6;
		}

		$this->assertNotEmpty( $this->mails, 'The admin is told.' );
		// Bound: the wait of shutdown timeout + 60 seconds (about 10 to 30 looks), then five counted retries and the failure.
		$this->assertLessThanOrEqual( 40, $run );
		$shutdowns = count( array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => str_ends_with( $l, '/status/shutdown' ) ) );
		$this->assertLessThanOrEqual( 10, $shutdowns, 'A shutdown at most once a minute of waiting and once per counted retry.' );
		$this->assertCount( 0, array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => str_ends_with( $l, '/rollback' ) ), 'The rollback is never sent to a server that does not stop.' );
	}

	// -- Kill switch -------------------------------------------------------------------------------

	public function test_the_kill_switch_still_stops_everything(): void {
		$r = $this->ready_vm();
		$this->settings( array( 'pause_all' => 1 ) );
		$seen = count( $this->fake->calls() );

		Jobs::act( $r->id(), 'shutdown', array() );
		Jobs::run( $r->id() );

		$this->assertSame( array(), array_slice( $this->lines(), $seen ) );
	}
}
