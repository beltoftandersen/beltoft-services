<?php
/**
 * Backups, restore, the safety point and Undo. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Resource;

/**
 * HostingBackups and HostingRestore.
 */
class BackupsTest extends HostingCase {

	/**
	 * The clock.
	 *
	 * @var int
	 */
	private $clock;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		$this->clock_to( strtotime( '2027-03-01 03:00:00 UTC' ) );
		add_filter( 'beltoft_services_now', fn() => $this->clock );
		add_filter( 'pre_wp_mail', function ( $pre, $args ) {
			$this->mails[] = $args;

			return true;
		}, 10, 2 );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_now' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Moves the clock of the plugin and of the fake node.
	 *
	 * @param int $time Unix time.
	 */
	private function clock_to( int $time ): void {
		$this->clock = $time;
		$this->node->set_clock( $time );
	}

	/**
	 * A ready account.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function account( array $plan = array() ): Resource {
		$r = $this->resource( array_merge( array( 'backup_days' => 14 ), $plan ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * One full round of a kind.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $kind nightly or manual.
	 */
	private function round( Resource $r, string $kind = 'nightly' ): Resource {
		$b = new HostingBackups( Resource::find( $r->id() ) );
		$this->assertTrue( $b->start( $kind ) );
		$result = ( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$this->assertTrue( $result->is_done(), $result->error() );

		return Resource::find( $r->id() );
	}

	/**
	 * The backups of a container in the fake storage.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stored_backups(): array {
		return $this->node->state()['backups'];
	}

	public function test_backups_need_days_in_the_plan_and_a_storage_on_the_node(): void {
		$this->assertTrue( HostingBackups::enabled( $this->account() ) );
		$this->assertFalse( HostingBackups::enabled( $this->account( array( 'backup_days' => 0 ) ) ) );

		$this->save_node( array( 'backup_storage' => '' ) );
		$this->assertFalse( HostingBackups::enabled( $this->account() ) );
		$this->assertWPError( \BeltoftServices\Hosting\Nodes::save( 'n9', array() ) );
	}

	public function test_a_round_prepares_dumps_prunes_and_stores_the_list(): void {
		$r = $this->round( $this->account() );

		$prepare = $this->agent->calls_of( 'backup.prepare' );
		$this->assertCount( 1, $prepare );
		$this->assertSame( 1000, $prepare[0]['args']['vmid'] );

		$dump = $this->node->calls_to( '#/vzdump$#', 'POST' );
		$this->assertCount( 1, $dump );
		$this->assertSame( 'pbs-store', $dump[0]['params']['storage'] );
		$this->assertSame( 'snapshot', $dump[0]['params']['mode'] );
		$this->assertSame( '{{guestname}} nightly', $dump[0]['params']['notes-template'] );

		$prune = $this->node->calls_to( '#/prunebackups$#', 'DELETE' );
		$this->assertSame( 'lxc', $prune[0]['params']['type'], 'A container, not a virtual machine.' );
		$this->assertSame( 'keep-daily=14', $prune[0]['params']['prune-backups'] );

		$list = HostingBackups::stored( $r );
		$this->assertCount( 1, $list );
		$this->assertMatchesRegularExpression( '#^pbs-store:backup/ct/1000/2027-03-01T03:00:00Z$#', $list[0]['volid'] );
		$this->assertSame( 'nightly', $list[0]['kind'] );
		$this->assertSame( $this->clock, (int) $r->get( 'backup_ok_at' ) );
		$this->assertSame( 0, (int) $r->get( 'backup_fails' ) );
		$this->assertFalse( ( new HostingBackups( $r ) )->active() );
	}

	public function test_the_nightly_slot_and_the_gap(): void {
		$r = $this->account();
		$b = new HostingBackups( $r );
		$slot = HostingBackups::slot_minute( $r );

		$this->assertGreaterThanOrEqual( 30, $slot );
		$this->assertLessThan( 330, $slot );

		$day = strtotime( '2027-03-02 00:00:00 UTC' );
		$this->assertFalse( $b->due( $day + ( $slot - 1 ) * 60 ), 'Before its slot.' );
		$this->assertTrue( $b->due( $day + $slot * 60 ) );
		$this->assertFalse( $b->due( $day + 8 * HOUR_IN_SECONDS ), 'After the night window.' );

		$r->set( array( 'backup_ok_at' => $day + $slot * 60 - HOUR_IN_SECONDS ) );
		$this->assertFalse( ( new HostingBackups( Resource::find( $r->id() ) ) )->due( $day + $slot * 60 ), 'Done an hour ago.' );

		$off = $this->account( array( 'backup_days' => 0 ) );
		$this->assertFalse( ( new HostingBackups( $off ) )->due( $day + 4 * HOUR_IN_SECONDS ) );
	}

	public function test_the_job_starts_due_accounts_once_and_leaves_the_rest(): void {
		$r    = $this->account();
		$slot = HostingBackups::slot_minute( $r );
		$this->clock_to( strtotime( '2027-03-02 00:00:00 UTC' ) + ( $slot + 1 ) * 60 );

		HostingBackups::run();
		$this->assertCount( 1, HostingBackups::stored( Resource::find( $r->id() ) ) );

		HostingBackups::run();
		$this->assertCount( 1, $this->stored_backups(), 'Not again in the same night.' );

		$this->clock_to( $this->clock + DAY_IN_SECONDS );
		HostingBackups::run();
		$this->assertCount( 2, $this->stored_backups(), 'The next night.' );

		$this->settings( array( 'pause_all' => true ) );
		$this->clock_to( $this->clock + DAY_IN_SECONDS );
		HostingBackups::run();
		$this->assertCount( 2, $this->stored_backups(), 'The kill switch stops it.' );
	}

	public function test_a_dump_that_fails_still_backs_up_and_tells_the_admin(): void {
		$this->agent->fail_next( 'backup.prepare', 'disk full' );
		$r = $this->round( $this->account() );

		$this->assertCount( 1, $this->stored_backups(), 'The backup still ran.' );
		$this->assertTrue( (bool) array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'no fresh database dump' ) ) );
		$this->assertSame( $this->clock, (int) $r->get( 'backup_ok_at' ) );
	}

	public function test_a_failed_vzdump_ends_the_round_counts_and_alerts_on_the_first_and_third(): void {
		$r = $this->account();

		for ( $night = 1; $night <= 4; $night++ ) {
			$this->node->next_task( array( 'exitstatus' => 'storage offline' ) );
			$b = new HostingBackups( Resource::find( $r->id() ) );
			$b->start( 'nightly' );
			$result = ( new HostingBackups( Resource::find( $r->id() ) ) )->step();
			$this->assertTrue( $result->is_done(), 'The round is over, not retried.' );
			$this->assertSame( $night, (int) Resource::find( $r->id() )->get( 'backup_fails' ) );
			$this->assertFalse( ( new HostingBackups( Resource::find( $r->id() ) ) )->active() );
		}

		$this->assertCount( 0, $this->stored_backups() );
		$alerts = array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'Backup failed' ) );
		$this->assertCount( 2, $alerts, 'The first night and the third.' );

		$good = $this->round( $r );
		$this->assertSame( 0, (int) $good->get( 'backup_fails' ), 'A good night ends the run of failures.' );
	}

	public function test_a_pending_task_is_polled_not_repeated(): void {
		$r = $this->account();
		$this->node->next_task( array( 'polls' => 30 ) );
		( new HostingBackups( $r ) )->start( 'nightly' );

		$first = ( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$this->assertTrue( $first->is_poll() );

		for ( $i = 0; $i < 40 && ! ( $done = ( new HostingBackups( Resource::find( $r->id() ) ) )->step() )->is_done(); $i++ ) {
			$this->assertTrue( $done->is_poll() || $done->is_retry() );
		}

		$this->assertCount( 1, $this->node->calls_to( '#/vzdump$#', 'POST' ), 'One vzdump.' );
		$this->assertCount( 1, $this->stored_backups() );
	}

	public function test_the_nightly_prune_keeps_the_plans_days(): void {
		$r = $this->account( array( 'backup_days' => 2 ) );

		for ( $d = 0; $d < 4; $d++ ) {
			$this->clock_to( strtotime( '2027-03-01 03:00:00 UTC' ) + $d * DAY_IN_SECONDS );
			$r = $this->round( $r );
		}

		$days = array_map( static fn( $b ) => gmdate( 'Y-m-d', (int) $b['ctime'] ), $this->stored_backups() );
		sort( $days );
		$this->assertSame( array( '2027-03-03', '2027-03-04' ), $days );
		$this->assertCount( 2, HostingBackups::stored( $r ) );
	}

	public function test_back_up_now_is_limited_to_three_a_day_and_keeps_three_manual_backups(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->clock_to( strtotime( '2027-03-01 10:00:00 UTC' ) + $i * 600 );
			$this->assertSame( array( 'started' => true ), $a->backup_now() );
			HostingBackups::run( $r->id() );
			$this->assertFalse( ( new HostingBackups( Resource::find( $r->id() ) ) )->active() );
		}

		$this->clock_to( $this->clock + 600 );
		$limit = $a->backup_now();
		$this->assertWPError( $limit );
		$this->assertSame( 'limit', $limit->get_error_code() );
		$this->assertCount( 3, $this->stored_backups() );
		$this->assertSame( array( 'manual', 'manual', 'manual' ), array_column( HostingBackups::stored( Resource::find( $r->id() ) ), 'kind' ) );
		$this->assertSame( '{{guestname}} manual', $this->node->calls_to( '#/vzdump$#', 'POST' )[0]['params']['notes-template'] );

		// A day later a fourth is allowed; the oldest manual one goes.
		$this->clock_to( strtotime( '2027-03-02 12:00:00 UTC' ) );
		$this->assertSame( array( 'started' => true ), $a->backup_now() );
		HostingBackups::run( $r->id() );
		$this->assertCount( 3, $this->stored_backups(), 'Three manual backups are kept.' );
		$times = array_column( $this->stored_backups(), 'ctime' );
		$this->assertContains( $this->clock, $times );
	}

	public function test_back_up_now_is_refused_when_unavailable_or_running(): void {
		$r = $this->account();
		( new HostingBackups( $r ) )->start( 'nightly' );
		$this->assertSame( 'busy', ( new HostingBackups( Resource::find( $r->id() ) ) )->now()->get_error_code() );

		$off = $this->account( array( 'backup_days' => 0 ) );
		$this->assertSame( 'not_ready', ( new HostingBackups( $off ) )->now()->get_error_code() );

		$paused = $this->account();
		$paused->set( array( 'desired' => 'paused' ) );
		$this->assertSame( 'not_ready', ( new HostingBackups( Resource::find( $paused->id() ) ) )->now()->get_error_code() );
	}

	public function test_a_reused_container_id_never_shows_the_old_accounts_backups(): void {
		$r    = $this->round( $this->account() );
		$list = ( new HostingBackups( $r ) )->list();
		$this->assertCount( 1, $list );

		// The container id was used by an account that was deleted: its backups carry that account's name.
		$put = $this->node->request( 'PUT', '/nodes/pve1/storage/pbs-store/content/' . $list[0]['volid'], array( 'notes' => 'c9999 nightly' ) );
		$this->assertNotWPError( $put );

		$this->assertSame( array(), ( new HostingBackups( Resource::find( $r->id() ) ) )->list(), 'Another account\'s backup is not listed, so it cannot be restored here.' );
	}

	public function test_only_this_containers_own_backups_are_listed_or_restorable(): void {
		$r = $this->round( $this->account() );
		$this->node->add_ct( array( 'vmid' => 1500, 'node' => 'pve1', 'name' => 'other' ) );
		$this->node->request( 'POST', '/nodes/pve1/vzdump', array( 'vmid' => 1500, 'storage' => 'pbs-store' ) );

		$list = ( new HostingBackups( $r ) )->list();
		$this->assertCount( 1, $list, 'The other container backup is not listed.' );

		$this->assertTrue( HostingBackups::volid_fits( 1000, 'pbs-store:backup/ct/1000/2027-03-01T03:00:00Z' ) );
		// A directory storage names the file instead.
		$this->assertTrue( HostingBackups::volid_fits( 1000, 'local:backup/vzdump-lxc-1000-2027_03_01-03_00_00.tar.zst' ) );
		$this->assertTrue( HostingBackups::volid_fits( 1000, 'local:backup/vzdump-lxc-1000-2027_03_01-03_00_00.tar' ) );
		foreach ( array( 'local:backup/vzdump-lxc-1001-2027_03_01-03_00_00.tar.zst', 'local:backup/vzdump-qemu-1000-2027_03_01-03_00_00.tar.zst', 'local:backup/../vzdump-lxc-1000-2027_03_01-03_00_00.tar', 'local:backup/vzdump-lxc-1000-2027_03_01-03_00_00.tar.zst.notes' ) as $bad ) {
			$this->assertFalse( HostingBackups::volid_fits( 1000, $bad ), $bad );
		}

		foreach ( array( 'pbs-store:backup/ct/1001/2027-03-01T03:00:00Z', 'pbs-store:backup/vm/1000/2027-03-01T03:00:00Z', 'pbs-store:backup/ct/1000/../x', 'backup/ct/1000/2027-03-01T03:00:00Z', "pbs-store:backup/ct/1000/2027-03-01T03:00:00Z\n" ) as $bad ) {
			$this->assertFalse( HostingBackups::volid_fits( 1000, $bad ), $bad );
		}
	}

	public function test_a_deleted_account_loses_its_backups_after_the_container(): void {
		$r = $this->round( $this->account() );
		$this->assertCount( 1, $this->stored_backups() );

		$result = $this->driver->delete( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertCount( 0, $this->stored_backups() );
		$this->assertNull( Records::for_resource( $r->id() ) );
	}

	// -- Restore ------------------------------------------------------------------------------------

	/**
	 * A ready account with one backup and a second site.
	 *
	 * @return array{0: Resource, 1: string, 2: string}
	 */
	private function with_backup(): array {
		$r = $this->account();
		( new HostingActions( $r ) )->add_site( 'blog', 'Blog', '8.3', false );
		$r    = $this->round( Resource::find( $r->id() ) );
		$list = HostingBackups::stored( $r );

		$this->clock_to( $this->clock + HOUR_IN_SECONDS );

		return array( $r, $list[0]['volid'], 'b' . $list[0]['ctime'] );
	}

	public function test_a_whole_account_restore_makes_a_safety_point_restores_and_imports_every_database(): void {
		$this->save_node( array( 'restore_safety' => 'snapshot' ) ); // A node shown to keep snapshots over a forced restore.
		list( $r, $volid, $label ) = $this->with_backup();
		$vmid = Records::for_resource( $r->id() )['vmid'];

		$start = ( new HostingRestore( $r ) )->start( 'account', $volid );
		$this->assertTrue( $start['started'] );
		$this->assertGreaterThanOrEqual( 2, $start['minutes'] );
		$this->assertTrue( HostingRestore::active( Resource::find( $r->id() ) ) );

		HostingRestore::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertFalse( HostingRestore::active( $r ) );
		$this->assertTrue( $r->get( 'restore_last' )['ok'] );

		$safety = HostingRestore::safety( $r );
		$this->assertSame( 'account', $safety['scope'] );
		$this->assertSame( $this->clock + DAY_IN_SECONDS, $safety['expires'] );

		$prepare = $this->agent->calls_of( 'backup.prepare' );
		$this->assertSame( $safety['name'], end( $prepare )['args']['label'], 'The dump and the snapshot share the safety name.' );
		$this->assertArrayHasKey( $safety['name'], $this->node->state()['cts'][ $vmid ]['snapshots'] );

		$ct = $this->node->state()['cts'][ $vmid ];
		$this->assertSame( $volid, $ct['restored_from'] );
		$this->assertSame( 'running', $ct['status'], 'Started again.' );

		$restores = array_filter( $this->agent->state()['restores'], static fn( $x ) => 'backup.db_restore' === $x['op'] );
		$this->assertCount( 2, $restores, 'One database per site.' );
		$this->assertSame( array( $label ), array_values( array_unique( array_column( $restores, 'snapshot' ) ) ) );
		$this->assertContains( 'c' . $r->id() . '_blog', array_column( $restores, 'database' ) );

		$call = $this->node->calls_to( '#/nodes/pve1/lxc$#', 'POST' );
		$this->assertSame( 1, $call[0]['params']['restore'] );
		$this->assertSame( 1, $call[0]['params']['force'] );
	}

	public function test_undo_goes_back_to_the_safety_point_for_files_and_databases(): void {
		$this->save_node( array( 'restore_safety' => 'snapshot' ) ); // A node shown to keep snapshots over a forced restore.
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'account', $volid );
		HostingRestore::run( $r->id() );
		$r      = Resource::find( $r->id() );
		$safety = HostingRestore::safety( $r );
		$before = count( $this->agent->state()['restores'] );

		$undo = ( new HostingRestore( $r ) )->undo();
		$this->assertSame( array( 'started' => true ), $undo );
		HostingRestore::run( $r->id() );

		$vmid = Records::for_resource( $r->id() )['vmid'];
		$this->assertSame( $safety['name'], $this->node->state()['cts'][ $vmid ]['rolled_back_to'] );
		$this->assertSame( 'running', $this->node->state()['cts'][ $vmid ]['status'] );

		$new = array_slice( $this->agent->state()['restores'], $before );
		$this->assertCount( 2, $new );
		$this->assertSame( array( $safety['name'] ), array_values( array_unique( array_column( $new, 'snapshot' ) ) ), 'The databases come from the safety dump.' );
		$this->assertTrue( Resource::find( $r->id() )->get( 'restore_last' )['ok'] );
	}

	public function test_files_and_database_restores_go_to_the_agent_after_a_safety_point(): void {
		list( $r, $volid, $label ) = $this->with_backup();
		$slug = 'w' . $r->id();

		$this->assertTrue( ( new HostingRestore( $r ) )->start( 'files', $volid, $slug )['started'] );
		HostingRestore::run( $r->id() );
		$files = array_values( array_filter( $this->agent->state()['restores'], static fn( $x ) => 'backup.file_restore' === $x['op'] ) );
		$this->assertCount( 1, $files );
		$this->assertSame( array( 'slug' => $slug, 'snapshot' => $label ), array( 'slug' => $files[0]['slug'], 'snapshot' => $files[0]['snapshot'] ) );
		$this->assertNotNull( HostingRestore::safety( Resource::find( $r->id() ) ) );
		$this->assertCount( 0, $this->node->calls_to( '#/nodes/pve1/lxc$#', 'POST' ), 'The container is not restored for one site.' );

		$this->clock_to( $this->clock + HOUR_IN_SECONDS );
		$this->assertTrue( ( new HostingRestore( Resource::find( $r->id() ) ) )->start( 'database', $volid, 'blog' )['started'] );
		HostingRestore::run( $r->id() );
		$dbs = array_values( array_filter( $this->agent->state()['restores'], static fn( $x ) => 'backup.db_restore' === $x['op'] ) );
		$this->assertSame( 'c' . $r->id() . '_blog', $dbs[0]['database'] );
		$this->assertSame( $label, $dbs[0]['snapshot'] );

		$snaps = $this->node->state()['cts'][1000]['snapshots'];
		$this->assertCount( 1, $snaps, 'A new safety point replaced the old one.' );
	}

	public function test_a_failed_safety_dump_stops_the_restore_before_anything_is_changed(): void {
		list( $r, $volid ) = $this->with_backup();
		$this->agent->fail_next( 'backup.prepare', 'dump failed' );
		( new HostingRestore( $r ) )->start( 'account', $volid );

		HostingRestore::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertFalse( HostingRestore::active( $r ) );
		$this->assertFalse( $r->get( 'restore_last' )['ok'] );
		$this->assertCount( 0, $this->node->calls_to( '#/nodes/pve1/lxc$#', 'POST' ), 'The container was not restored.' );
		$this->assertSame( 'running', $this->node->state()['cts'][1000]['status'] );
		$this->assertNull( HostingRestore::safety( $r ) );
		$this->assertTrue( (bool) array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'restore failed' ) ) );
	}

	public function test_a_failed_container_restore_ends_the_round_with_an_alert(): void {
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'account', $volid );
		$this->node->next_task( array() ); // The snapshot.
		$this->node->next_task( array() ); // The shutdown.
		$this->node->next_task( array( 'exitstatus' => 'restore failed: out of space' ) );

		HostingRestore::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertFalse( $r->get( 'restore_last' )['ok'] );
		$this->assertNotNull( HostingRestore::safety( $r ), 'The safety point stays so the customer can undo.' );
		$this->assertFalse( HostingRestore::active( $r ) );
		$this->assertSame( 'running', $this->node->state()['cts'][ Records::for_resource( $r->id() )['vmid'] ]['status'], 'The container the restore stopped is started again.' );
	}

	public function test_a_restore_is_refused_for_a_backup_that_is_not_listed_a_bad_choice_or_while_busy(): void {
		list( $r, $volid ) = $this->with_backup();
		$h = new HostingRestore( $r );

		$this->assertSame( 'not_found', $h->start( 'account', 'pbs-store:backup/ct/1000/2020-01-01T00:00:00Z' )->get_error_code() );
		$this->assertSame( 'not_found', $h->start( 'account', 'pbs-store:backup/ct/1001/2027-03-01T03:00:00Z' )->get_error_code() );
		$this->assertSame( 'invalid', $h->start( 'everything', $volid )->get_error_code() );
		$this->assertSame( 'not_found', $h->start( 'files', $volid, 'ghost' )->get_error_code() );

		$this->assertIsArray( $h->start( 'account', $volid ) );
		$this->assertSame( 'busy', ( new HostingRestore( Resource::find( $r->id() ) ) )->start( 'account', $volid )->get_error_code() );
		$this->assertSame( 'busy', ( new HostingRestore( Resource::find( $r->id() ) ) )->undo()->get_error_code() );

		$off = $this->account( array( 'backup_days' => 0 ) );
		$this->assertSame( 'not_ready', ( new HostingRestore( $off ) )->start( 'account', $volid )->get_error_code() );
		$this->assertSame( 'not_found', ( new HostingRestore( $this->account() ) )->undo()->get_error_code(), 'Nothing to undo.' );
	}

	public function test_the_safety_point_is_kept_24_hours_then_removed(): void {
		list( $r, $volid ) = $this->with_backup();
		( new HostingRestore( $r ) )->start( 'database', $volid, 'w' . $r->id() );
		HostingRestore::run( $r->id() );
		$this->assertNotNull( HostingRestore::safety( Resource::find( $r->id() ) ) );

		$this->clock_to( $this->clock + 23 * HOUR_IN_SECONDS );
		HostingRestore::expire_all();
		$this->assertNotNull( HostingRestore::safety( Resource::find( $r->id() ) ) );

		$this->clock_to( $this->clock + 2 * HOUR_IN_SECONDS );
		HostingRestore::expire_all();
		$this->assertNull( HostingRestore::safety( Resource::find( $r->id() ) ) );
		$this->assertSame( array(), $this->node->state()['cts'][1000]['snapshots'] );
		$this->assertSame( 'not_found', ( new HostingRestore( Resource::find( $r->id() ) ) )->undo()->get_error_code() );
	}

	public function test_the_estimate_follows_the_backup_size(): void {
		$this->assertSame( 2, HostingRestore::estimate_minutes( 1073741824 ) );
		$this->assertSame( 36, HostingRestore::estimate_minutes( 100 * 1073741824 ) );
		add_filter( 'beltoft_services_hosting_restore_mbs', fn() => 100 );
		$this->assertSame( 19, HostingRestore::estimate_minutes( 100 * 1073741824 ) );
		remove_all_filters( 'beltoft_services_hosting_restore_mbs' );
	}

	// -- The account ---------------------------------------------------------------------------------

	public function test_rest_routes_for_backups_and_restore(): void {
		list( $r, $volid ) = $this->with_backup();
		$user = (int) $r->license()['user_id'];
		$base = '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/backups';

		$call = function ( string $method, string $path, int $who, array $body = array() ) use ( $base ) {
			wp_set_current_user( $who );
			$request = new \WP_REST_Request( $method, $base . $path );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );

			return rest_get_server()->dispatch( $request );
		};

		$list = $call( 'GET', '', $user );
		$this->assertSame( 200, $list->get_status() );
		$this->assertTrue( $list->get_data()['enabled'] );
		$this->assertCount( 1, $list->get_data()['backups'] );
		$this->assertNull( $list->get_data()['safety'] );

		$this->assertSame( 202, $call( 'POST', '', $user )->get_status() );
		HostingBackups::run( $r->id() );

		$this->assertSame( 422, $call( 'POST', '/restore', $user, array( 'scope' => 'nothing', 'volid' => $volid ) )->get_status() );
		$this->assertSame( 404, $call( 'POST', '/restore', $user, array( 'scope' => 'account', 'volid' => 'pbs-store:backup/ct/1000/x' ) )->get_status() );
		$ok = $call( 'POST', '/restore', $user, array( 'scope' => 'account', 'volid' => $volid ) );
		$this->assertSame( 202, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 409, $call( 'POST', '/undo', $user )->get_status(), 'A restore is running.' );
		HostingRestore::run( $r->id() );
		$this->assertSame( 202, $call( 'POST', '/undo', $user )->get_status() );

		$stranger = self::factory()->user->create();
		foreach ( array( array( 'GET', '' ), array( 'POST', '' ), array( 'POST', '/restore' ), array( 'POST', '/undo' ) ) as $case ) {
			$this->assertSame( 403, $call( $case[0], $case[1], $stranger, array( 'scope' => 'account', 'volid' => $volid ) )->get_status(), $case[1] );
		}
	}

	public function test_the_panel_lists_backups_and_offers_the_buttons_only_when_idle(): void {
		list( $r, $volid ) = $this->with_backup();

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Backups', $html );
		$this->assertStringContainsString( '1 March 2027', $html );
		$this->assertStringContainsString( 'data-hosting-action="backup_now"', $html );
		$this->assertStringContainsString( 'data-scope="account" data-volid="' . esc_attr( $volid ) . '"', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="undo"', $html );

		( new HostingRestore( Resource::find( $r->id() ) ) )->start( 'database', $volid, 'w' . $r->id() );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$running = (string) ob_get_clean();
		$this->assertStringContainsString( 'A backup or a restore is running.', $running );
		$this->assertStringNotContainsString( 'data-hosting-action="restore"', $running );

		HostingRestore::run( $r->id() );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$done = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-hosting-action="undo"', $done );
		$this->assertStringContainsString( 'A safety copy from before your last restore is kept until', $done );
		$this->assertStringNotContainsString( "\u{2014}", $done );

		$off = $this->account( array( 'backup_days' => 0 ) );
		ob_start();
		HostingAccount::render( $off );
		$this->assertStringNotContainsString( 'Backups', (string) ob_get_clean(), 'No backups section for a plan without backups.' );
	}

	public function test_the_script_knows_the_backup_actions_and_asks_before_restoring(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/hosting.js' );

		foreach ( array( "'/backups'", '/backups/restore', '/backups/undo', 'confirmRestore', 'confirmUndo' ) as $needle ) {
			$this->assertStringContainsString( trim( $needle, "'" ), $js, $needle );
		}

		$this->assertStringNotContainsString( "\u{2014}", $js );
	}

	public function test_a_nightly_dump_that_fails_for_some_databases_tells_the_admin_which(): void {
		$r = $this->account();
		$this->agent->set_dump_fails( array( 'c' . $r->id() . '_w' . $r->id() ) );

		$this->round( $r );

		$this->assertTrue( (bool) array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'were not dumped' ) ) );
		$this->assertCount( 1, $this->stored_backups(), 'The backup still ran.' );
	}
}
