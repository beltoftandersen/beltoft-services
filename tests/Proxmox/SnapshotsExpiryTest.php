<?php
/**
 * Customer snapshots, phase D: the hourly expiry job, the clamp when paid slots end, the notices and the admin
 * rows. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Panels;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\SnapshotExpiry;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class SnapshotsExpiryTest extends SnapshotsTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_transient( ProxmoxJobs::SNAP_SYNCED );
		as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		delete_transient( ProxmoxJobs::SNAP_SYNCED );
		as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );
		parent::tear_down();
	}

	/**
	 * The pending act jobs: list of {id, action, at}.
	 *
	 * @return array<int, array{id: int, action: string, at: int}>
	 */
	private function queued(): array {
		$out = array();

		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) ) as $action ) {
			$args = $action->get_args();
			$date = $action->get_schedule()->get_date();
			$out[] = array( 'id' => (int) $args[0], 'action' => (string) $args[1], 'at' => $date ? $date->getTimestamp() : time() );
		}

		usort( $out, static fn( $a, $b ) => $a['at'] <=> $b['at'] );

		return $out;
	}

	/**
	 * Changes entries of the server's list.
	 *
	 * @param Resource $r     Server.
	 * @param int      $index Entry index.
	 * @param array    $patch Values.
	 */
	private function change( Resource $r, int $index, array $patch ): void {
		$list = Snapshots::entries( $this->fresh( $r ) );
		$list[ $index ] = array_merge( $list[ $index ], $patch );
		$this->assertTrue( $this->fresh( $r )->set( array( Snapshots::LIST => $list ) ) );
	}

	private function mails_about( string $text ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== stripos( (string) $m['message'], $text ) ) );
	}

	// -- The hourly job --------------------------------------------------------------------------

	public function test_the_hourly_job_is_scheduled(): void {
		ProxmoxJobs::schedule();
		$this->assertTrue( as_has_scheduled_action( ProxmoxJobs::SNAPSHOTS, array(), Jobs::GROUP ) );
		ProxmoxJobs::unschedule();
		$this->assertFalse( as_has_scheduled_action( ProxmoxJobs::SNAPSHOTS, array(), Jobs::GROUP ) );
	}

	public function test_only_a_server_with_a_snapshot_due_gets_the_expiry_queued_and_nothing_asks_proxmox(): void {
		$due   = $this->server();
		$later = $this->ready_vm();
		$none  = $this->ready_vm();
		$this->take( $due, 'old' );
		$this->take( $later, 'young' );
		$this->change( $due, 0, array( 'expires_at' => time() - 5 ) );
		set_transient( ProxmoxJobs::SNAP_SYNCED, 1, DAY_IN_SECONDS );
		$seen = count( $this->fake->calls() );

		ProxmoxJobs::snapshots();

		$this->assertSame( array( array( $due->id(), 'snapshot_expire' ) ), array_map( static fn( $q ) => array( $q['id'], $q['action'] ), $this->queued() ) );
		$this->assertCount( $seen, $this->fake->calls(), 'The job reads meta only.' );
		unset( $none );
	}

	public function test_the_jobs_are_spaced_by_the_batch_delay(): void {
		$a = $this->server();
		$b = $this->ready_vm();
		$this->take( $a, 'a' );
		$this->take( $b, 'b' );
		$this->change( $a, 0, array( 'expires_at' => time() - 5 ) );
		$this->change( $b, 0, array( 'expires_at' => time() - 5 ) );

		ProxmoxJobs::snapshots();

		$queued = $this->queued();
		$this->assertCount( 2, $queued );
		$this->assertGreaterThanOrEqual( ProxmoxJobs::BATCH_DELAY - 2, $queued[1]['at'] - $queued[0]['at'] );
	}

	public function test_the_kill_switch_stops_the_job_and_a_paused_server_and_the_off_switch_do_not(): void {
		$r = $this->server();
		$this->take( $r, 'old' );
		$this->change( $r, 0, array( 'expires_at' => time() - 5 ) );

		$this->settings( array( 'pause_all' => 1 ) );
		ProxmoxJobs::snapshots();
		$this->assertSame( array(), $this->queued() );

		$this->settings( array( 'pause_all' => 0, 'snapshots_enabled' => 0 ) );
		$this->assertTrue( $this->fresh( $r )->transition( 'paused', 'test' ) );
		ProxmoxJobs::snapshots();
		$this->assertCount( 1, $this->queued() );

		$this->run_jobs();
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ), 'It ran on the paused server, with the customer switch off.' );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
	}

	public function test_a_daily_sync_rides_along_once_a_day_and_drops_what_proxmox_lost(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'lost' );
		$this->fake->task( 'DELETE', '/nodes/node-a/qemu/5000/snapshot/' . $entry['snap'] );

		ProxmoxJobs::snapshots();
		$this->assertSame( array( 'snapshot_sync' ), array_column( $this->queued(), 'action' ) );
		$this->run_jobs();
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );

		$this->take( $r, 'again' );
		ProxmoxJobs::snapshots();
		$this->assertSame( array(), $this->queued(), 'The next hour has no sync and nothing is due.' );
	}

	public function test_a_server_without_snapshots_is_not_queued(): void {
		$this->server();
		ProxmoxJobs::snapshots();
		$this->assertSame( array(), $this->queued() );
	}

	public function test_expiry_sends_the_expired_email_only_when_the_setting_is_on(): void {
		$r = $this->server();
		$this->take( $r, 'old' );
		$this->change( $r, 0, array( 'expires_at' => time() - 5 ) );
		$this->mails = array();
		$this->act( $r, 'snapshot_expire' );
		$this->assertSame( array(), $this->mails_about( 'reached the end of its keeping time' ), 'Off by default.' );

		$this->settings( array( 'snapshot_expired_email' => 1 ) );
		$this->take( $r, 'old2' );
		$this->change( $r, 0, array( 'expires_at' => time() - 5 ) );
		$this->act( $r, 'snapshot_expire' );
		$this->assertCount( 1, $this->mails_about( 'reached the end of its keeping time' ) );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
	}

	// -- The clamp -------------------------------------------------------------------------------

	private function paid( int $slots ): void {
		remove_all_filters( 'beltoft_services_snapshot_paid_slots' );
		add_filter( 'beltoft_services_snapshot_paid_slots', static fn() => $slots );
	}

	public function test_when_the_paid_slots_end_the_snapshots_are_shortened_not_deleted_and_the_customer_is_told_once(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$this->paid( 2 );
		$r = $this->ready_vm();
		$this->take( $r, 'a' );
		$this->take( $r, 'b' );
		$this->take( $r, 'c' );
		$this->assertSame( 30, (int) round( ( Snapshots::entries( $this->fresh( $r ) )[0]['expires_at'] - time() ) / DAY_IN_SECONDS ), 'Taken with paid time.' );

		// "a" is 10 days old: past its free week.
		$this->change( $r, 0, array( 'taken_at' => time() - 10 * DAY_IN_SECONDS ) );
		$this->paid( 0 );
		$this->mails = array();
		$now = time();

		$this->assertSame( 3, SnapshotExpiry::clamp( $this->fresh( $r ), $now ) );

		$list = Snapshots::entries( $this->fresh( $r ) );
		$this->assertCount( 3, $list, 'Nothing is deleted.' );
		$this->assertSame( $now + 3 * DAY_IN_SECONDS, $list[0]['expires_at'], 'Past the free window: at least 3 days from now.' );
		$this->assertSame( $list[1]['taken_at'] + 7 * DAY_IN_SECONDS, $list[1]['expires_at'], 'Within the free window: the free time.' );
		$this->assertCount( 3, $this->fake->snapshots( 5000 ) );

		$mails = $this->mails_about( 'allowance went down' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'will be deleted on', (string) $mails[0]['message'] );

		// Again: nothing to shorten, no second email.
		$this->assertSame( 0, SnapshotExpiry::clamp( $this->fresh( $r ), $now + HOUR_IN_SECONDS ) );
		$this->assertCount( 1, $this->mails_about( 'allowance went down' ) );
		$this->assertSame( array(), $this->fresh( $r )->get( SnapshotExpiry::NOTICE ), 'The episode is over.' );
	}

	public function test_snapshots_over_the_count_lose_time_oldest_first_and_the_ones_that_fit_are_left(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$this->paid( 2 );
		$r = $this->ready_vm();
		$this->take( $r, 'a' );
		$this->take( $r, 'b' );
		$this->take( $r, 'c' );
		$this->paid( 1 ); // Two slots now; three snapshots; still paid time.
		$before = Snapshots::entries( $this->fresh( $r ) );

		$this->assertSame( 1, SnapshotExpiry::clamp( $this->fresh( $r ), time() ) );

		$after = Snapshots::entries( $this->fresh( $r ) );
		$this->assertLessThan( $before[0]['expires_at'], $after[0]['expires_at'] );
		$this->assertSame( $before[1]['expires_at'], $after[1]['expires_at'] );
		$this->assertSame( $before[2]['expires_at'], $after[2]['expires_at'] );
	}

	public function test_the_clamp_never_makes_a_snapshot_last_longer(): void {
		$this->on( array( 'snapshot_free_slots' => 2 ) );
		$r = $this->ready_vm();
		$this->take( $r, 'a' );
		$this->change( $r, 0, array( 'expires_at' => time() + HOUR_IN_SECONDS ) );
		$this->take( $r, 'b' );
		$this->settings( array( 'snapshot_free_slots' => 1 ) ); // Over the count of one now.

		SnapshotExpiry::clamp( $this->fresh( $r ), time() );

		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS + 5, Snapshots::entries( $this->fresh( $r ) )[0]['expires_at'] );
		$this->assertSame( array(), $this->mails_about( 'allowance went down' ), 'Nothing was shortened.' );
	}

	public function test_the_hourly_job_runs_the_clamp_before_it_picks(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$this->paid( 1 );
		$r = $this->ready_vm();
		$this->take( $r, 'a' );
		$this->change( $r, 0, array( 'taken_at' => time() - 40 * DAY_IN_SECONDS, 'expires_at' => time() + 20 * DAY_IN_SECONDS ) );
		$this->paid( 0 );
		set_transient( ProxmoxJobs::SNAP_SYNCED, 1, DAY_IN_SECONDS );

		ProxmoxJobs::snapshots();

		$this->assertEqualsWithDelta( time() + 3 * DAY_IN_SECONDS, Snapshots::entries( $this->fresh( $r ) )[0]['expires_at'], 5 );
		$this->assertSame( array(), $this->queued(), 'Shortened, not due yet.' );
	}

	public function test_a_notice_is_told_once_per_reason_and_key(): void {
		$r = $this->server();
		$this->mails = array();
		$this->assertTrue( SnapshotExpiry::notify( $r, 'disk_removed', 'vol-1' ) );
		$this->assertFalse( SnapshotExpiry::notify( $this->fresh( $r ), 'disk_removed', 'vol-1' ) );
		$this->assertTrue( SnapshotExpiry::notify( $this->fresh( $r ), 'disk_removed', 'vol-2' ) );
		$this->assertFalse( SnapshotExpiry::notify( $this->fresh( $r ), 'nonsense', 'x' ) );
		$this->assertCount( 2, $this->mails_about( 'extra disk was removed' ) );
	}

	// -- Admin -----------------------------------------------------------------------------------

	private function admin_html( Resource $r ): string {
		ob_start();
		Panels::admin( $this->fresh( $r ) );

		return (string) ob_get_clean();
	}

	public function test_the_admin_rows_show_count_oldest_next_expiry_error_and_the_running_op(): void {
		$r = $this->server();
		$this->take( $r, 'a' );
		$this->take( $r, 'b' );
		$html = $this->admin_html( $r );

		$this->assertStringContainsString( 'Snapshots', $html );
		$this->assertStringContainsString( '2 of 3 slots, on', $html );
		$this->assertStringContainsString( 'Oldest snapshot', $html );
		$this->assertStringContainsString( 'Next snapshot expiry', $html );
		$this->assertStringContainsString( 'Snapshot error', $html );
		$this->assertStringContainsString( 'Snapshot running', $html );

		$this->fresh( $r )->set( array( Snapshots::ROLLBACK => array( 'id' => 'x', 'phase' => 'rolled' ) ) );
		$this->assertStringContainsString( 'rollback rolled', $this->admin_html( $r ) );
	}

	public function test_the_admin_shows_the_last_snapshot_refusal_keeps_it_through_a_retry_and_clears_it_on_a_success(): void {
		$r = $this->server();
		$this->take( $r, 'a' );
		$entry = Snapshots::entries( $this->fresh( $r ) )[0];
		$this->settings( array( 'snapshot_reserve_gb' => 100000 ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'b' ) );
		$this->assertStringContainsString( 'refused', (string) $this->fresh( $r )->get( Snapshots::ERROR ) );
		$this->assertStringContainsString( 'refused', $this->admin_html( $r ) );

		// A retry (the server is locked) does not hide it.
		$this->fake->set_lock( 5000, 'backup' );
		$this->act( $r, 'snapshot_delete', array( 'id' => $entry['id'] ) );
		$this->assertStringContainsString( 'refused', (string) $this->fresh( $r )->get( Snapshots::ERROR ) );

		$this->fake->set_lock( 5000, '' );
		$this->run_jobs();
		$this->act( $r, 'snapshot_sync' );
		$this->assertNull( $this->fresh( $r )->get( Snapshots::ERROR ) );
	}

	public function test_a_refusal_returned_as_done_is_kept_not_cleared(): void {
		$r = $this->server();
		$this->settings( array( 'snapshot_reserve_gb' => 100000 ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'x' ) );
		$this->assertStringContainsString( 'less than', (string) $this->fresh( $r )->get( Snapshots::ERROR ) );
	}

	public function test_the_admin_has_no_snapshot_rows_when_there_are_none_and_the_switch_is_off(): void {
		$this->settings( array( 'snapshots_enabled' => 0 ) );
		$r = $this->ready_vm();
		$this->assertStringNotContainsString( 'Next snapshot expiry', $this->admin_html( $r ) );
	}

	public function test_the_new_setting_is_off_by_default_and_a_checkbox(): void {
		delete_option( \BeltoftServices\Settings::OPTION );
		$this->assertSame( 0, \BeltoftServices\Settings::get( 'snapshot_expired_email' ) );
	}

	// -- Review fixes ----------------------------------------------------------------------------

	public function test_the_expired_email_is_sent_when_the_delete_took_more_than_one_round(): void {
		$this->settings( array( 'snapshot_expired_email' => 1 ) );
		$r = $this->server();
		$this->take( $r, 'old' );
		$this->change( $r, 0, array( 'expires_at' => time() - 5 ) );
		$this->mails = array();
		$this->fake->next_task( array( 'polls' => 50 ) );

		$this->act( $r, 'snapshot_expire' );
		$this->assertSame( 'deleting', Snapshots::entries( $this->fresh( $r ) )[0]['state'] );

		for ( $i = 0; $i < 60 && Snapshots::entries( $this->fresh( $r ) ); $i++ ) {
			$this->act( $r, 'snapshot_expire' );
		}

		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertCount( 1, $this->mails_about( 'reached the end of its keeping time' ) );
	}

	public function test_several_snapshots_expiring_in_one_round_send_one_email(): void {
		$this->settings( array( 'snapshot_expired_email' => 1 ) );
		$r = $this->server();
		$this->take( $r, 'a' );
		$this->take( $r, 'b' );
		$this->change( $r, 0, array( 'expires_at' => time() - 5 ) );
		$this->change( $r, 1, array( 'expires_at' => time() - 5 ) );
		$this->mails = array();

		$this->act( $r, 'snapshot_expire' );

		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertCount( 1, $this->mails_about( 'reached the end of its keeping time' ) );
	}

	public function test_a_lower_free_days_setting_does_not_send_slots_reduced(): void {
		$this->on( array( 'snapshot_free_days' => 7 ) );
		$r = $this->ready_vm();
		$this->take( $r, 'a' );
		$before = Snapshots::entries( $this->fresh( $r ) )[0]['expires_at'];
		$this->mails = array();
		$this->settings( array( 'snapshot_free_days' => 1 ) );

		$this->assertSame( 0, SnapshotExpiry::clamp( $this->fresh( $r ), time() ) );
		$this->assertSame( $before, Snapshots::entries( $this->fresh( $r ) )[0]['expires_at'] );
		$this->assertSame( array(), $this->mails_about( 'allowance went down' ) );
	}

	public function test_the_hourly_job_does_not_lock_a_server_that_needs_no_clamp(): void {
		$r = $this->server();
		$this->take( $r, 'a' );
		$this->assertFalse( SnapshotExpiry::needs_clamp( $this->fresh( $r ) ) );

		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$this->paid( 1 );
		$p = $this->ready_vm();
		$this->take( $p, 'a' );
		$this->paid( 0 );
		$this->assertTrue( SnapshotExpiry::needs_clamp( $this->fresh( $p ) ), 'Taken on a paid slot, none paid now.' );

		$this->on( array( 'snapshot_free_slots' => 3 ) );
		$this->take( $r, 'b' );
		$this->settings( array( 'snapshot_free_slots' => 1 ) );
		$this->assertTrue( SnapshotExpiry::needs_clamp( $this->fresh( $r ) ), 'More snapshots than slots.' );
	}
}
