<?php
/**
 * The customer deletes one backup of their hosting. Fakes only: the fake node and the fake agent, never a real node.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Jobs;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

/**
 * The change `backup_delete` of the account, on the shared controller.
 */
class BackupDeleteTest extends HostingCase {

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
			unset( $pre );
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

	private function clock_to( int $time ): void {
		$this->clock = $time;
		$this->node->set_clock( $time );
	}

	/**
	 * A ready account.
	 */
	private function account(): Resource {
		$r = $this->resource( array( 'backup_days' => 14 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );

		return Resource::find( $r->id() );
	}

	/**
	 * An account with $count backups (a day apart, oldest first made), the list stored.
	 *
	 * @param int $count How many.
	 * @return array{0: Resource, 1: string[]} The account and the volume ids, newest first.
	 */
	private function with_backups( int $count ): array {
		$r = $this->account();

		for ( $i = 0; $i < $count; $i++ ) {
			$this->clock_to( strtotime( '2027-03-01 03:00:00 UTC' ) + $i * DAY_IN_SECONDS );
			$this->assertTrue( ( new HostingBackups( Resource::find( $r->id() ) ) )->start( 'manual' ) );
			$result = ( new HostingBackups( Resource::find( $r->id() ) ) )->step();
			$this->assertTrue( $result->is_done(), $result->error() );
		}

		$this->clock_to( $this->clock + HOUR_IN_SECONDS );
		$r = Resource::find( $r->id() );

		return array( $r, array_column( HostingBackups::stored( $r ), 'volid' ) );
	}

	/**
	 * The DELETE calls that went to a backup volume.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function deletes(): array {
		return $this->node->calls_to( '#/content/#', 'DELETE' );
	}

	private function volids_on_node(): array {
		return array_column( $this->node->state()['backups'], 'volid' );
	}

	private function route( Resource $r, string $volid, ?int $user = null ): \WP_REST_Response {
		wp_set_current_user( $user ?? (int) $r->license()['user_id'] );
		$request = new \WP_REST_Request( 'DELETE', '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/backups' );
		$request->set_param( 'volid', $volid );

		return rest_get_server()->dispatch( $request );
	}

	private function events_of( Resource $r ): string {
		return (string) wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) );
	}

	public function test_a_delete_removes_exactly_that_volume_and_updates_the_stored_list(): void {
		list( $r, $ids ) = $this->with_backups( 3 );
		$this->assertCount( 3, $ids );

		$response = $this->route( $r, $ids[1] );
		$this->assertSame( 202, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $this->deletes(), 'Nothing reaches the node inside the request.' );

		$this->run_account( $r );

		$this->assertCount( 1, $this->deletes() );
		$this->assertStringContainsString( rawurlencode( $ids[1] ), $this->deletes()[0]['path'] );
		$this->assertNotContains( $ids[1], $this->volids_on_node() );
		$this->assertContains( $ids[0], $this->volids_on_node() );
		$this->assertContains( $ids[2], $this->volids_on_node() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( $ids[0], $ids[2] ), array_column( HostingBackups::stored( $fresh ), 'volid' ) );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
		$this->assertSame( 'The backup was deleted.', $this->last_outcome( $r )['text'] );
		$this->assertSame( 'backup_delete', $this->last_outcome( $r )['action'] );
		$this->assertStringContainsString( 'deleted at the customer', $this->events_of( $r ) );
		$this->assertNull( $fresh->get( Steps::META ), 'The change leaves nothing behind.' );
	}

	public function test_the_newest_and_the_only_backup_can_be_deleted(): void {
		list( $r, $ids ) = $this->with_backups( 2 );

		$this->assertSame( 202, $this->route( $r, $ids[0] )->get_status() );
		$this->run_account( $r );
		$this->assertSame( array( $ids[1] ), array_column( HostingBackups::stored( Resource::find( $r->id() ) ), 'volid' ), 'The newest went.' );

		$this->assertSame( 202, $this->route( Resource::find( $r->id() ), $ids[1] )->get_status() );
		$this->run_account( $r );
		$this->assertSame( array(), HostingBackups::stored( Resource::find( $r->id() ) ), 'The only one went.' );
		$this->assertSame( array(), $this->volids_on_node() );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
	}

	public function test_a_volume_that_is_not_in_the_list_or_of_another_machine_is_refused(): void {
		list( $r, $ids ) = $this->with_backups( 1 );

		foreach ( array(
			'another container'  => 'pbs-store:backup/ct/1001/2027-03-01T03:00:00Z',
			'not a volume'       => 'nothing',
			'not listed'         => 'pbs-store:backup/ct/1000/2020-01-01T00:00:00Z',
			'a path trick'       => $ids[0] . '/../x',
			'empty'              => '',
		) as $label => $volid ) {
			$out = ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'delete_backup', array( $volid ) );
			$this->assertWPError( $out, $label );
			$this->assertSame( 'invalid', $out->get_error_code(), $label );
		}

		$this->assertSame( 422, $this->route( $r, 'pbs-store:backup/ct/1001/2027-03-01T03:00:00Z' )->get_status() );
		$this->assertSame( array(), $this->deletes() );
		$this->assertFalse( Jobs::acting( $r->id() ), 'A refusal queues nothing.' );
		$this->assertSame( $ids, $this->volids_on_node() );
	}

	public function test_a_stranger_cannot_delete_a_backup(): void {
		list( $r, $ids ) = $this->with_backups( 1 );
		$stranger        = self::factory()->user->create();

		$this->assertSame( 403, $this->route( $r, $ids[0], $stranger )->get_status() );
		$this->assertSame( array(), $this->deletes() );
	}

	public function test_it_is_refused_while_a_backup_a_restore_or_an_undo_runs(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		$h               = static fn() => new HostingActions( Resource::find( $r->id() ) );

		// A backup round.
		$r->set( array( 'backup' => array( 'phase' => 'dump', 'kind' => 'nightly', 'started' => $this->clock ) ) );
		$out = $h()->queue( 'delete_backup', array( $ids[0] ) );
		$this->assertWPError( $out );
		$this->assertSame( 'A backup or a restore is running. Try again when it has finished.', $out->get_error_message() );
		$r->set( array( 'backup' => array( 'phase' => '', 'kind' => 'nightly' ) ) );

		// A restore round, and an undo round (it restores from the safety copy and counts as a restore).
		foreach ( array( 'account', 'undo' ) as $kind ) {
			$r->set( array( 'restore' => array( 'kind' => $kind, 'phase' => 'restore' ) ) );
			$out = $h()->queue( 'delete_backup', array( $ids[0] ) );
			$this->assertWPError( $out, $kind );
			$this->assertSame( 'busy', $out->get_error_code(), $kind );
		}

		// A job that finds one of them under way refuses as well, in the customer's words, and sends nothing.
		$result = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[0] ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'A backup or a restore is running. Try again when it has finished.', $result->meta()[ Jobs::TEXT ] );
		$this->assertNotEmpty( $result->meta()[ Jobs::EXPECTED ] );
		$this->assertSame( array(), $this->deletes() );

		// The page offers no button while one runs.
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'data-hosting-action="backup_delete"', (string) ob_get_clean() );
	}

	public function test_another_change_of_the_account_runs_first_the_delete_is_refused_as_busy(): void {
		list( $r, $ids ) = $this->with_backups( 2 );

		$this->assertIsArray( ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'backup_now' ) );
		$out = ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'delete_backup', array( $ids[0] ) );
		$this->assertWPError( $out );
		$this->assertSame( 'busy', $out->get_error_code() );
		$this->assertSame( array(), $this->deletes() );
	}

	public function test_the_floor_is_ten_deletes_an_hour_per_account(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		$user            = (int) $r->license()['user_id'];
		$key             = 'beltoft-services:hosting-backup-delete:' . $r->id();

		for ( $i = 0; $i < 9; $i++ ) {
			$this->assertFalse( RateLimit::exceeded( $user, $key, 10, HOUR_IN_SECONDS ) );
		}

		// The tenth is still let through...
		$tenth = $this->route( $r, $ids[0] );
		$this->assertSame( 202, $tenth->get_status() );
		$this->run_account( $r );

		// ...the eleventh is not.
		$eleventh = $this->route( Resource::find( $r->id() ), $ids[1] );
		$this->assertSame( 429, $eleventh->get_status() );
		$this->assertSame( 'Too many requests. Please wait a while and try again.', $eleventh->get_data()['message'] );
		$this->assertContains( $ids[1], $this->volids_on_node() );
		$this->assertFalse( Jobs::acting( $r->id() ) );

		// Another account has its own count.
		list( $other, $other_ids ) = $this->with_backups( 1 );
		$this->assertSame( 202, $this->route( $other, $other_ids[0] )->get_status() );
	}

	public function test_a_replay_of_the_job_deletes_once_and_a_volume_already_gone_is_done(): void {
		list( $r, $ids ) = $this->with_backups( 2 );

		$this->assertIsArray( ( new HostingActions( $r ) )->queue( 'delete_backup', array( $ids[0] ) ) );
		$first = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[0] ) );
		$this->assertTrue( $first->is_done(), $first->error() );

		// The answer was lost, so the job runs again: it finds the volume gone and sends nothing.
		$again = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[0] ) );
		$this->assertTrue( $again->is_done(), $again->error() );
		$this->assertCount( 1, $this->deletes() );
		$this->assertSame( 'The backup was deleted.', $again->meta()[ Jobs::TEXT ] );
		$this->assertStringContainsString( 'already gone', $again->event() );
		$this->assertSame( array( $ids[1] ), array_column( HostingBackups::stored( Resource::find( $r->id() ) ), 'volid' ) );
	}

	public function test_a_task_that_is_still_running_is_polled_never_started_again(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		$this->assertIsArray( ( new HostingActions( $r ) )->queue( 'delete_backup', array( $ids[0] ) ) );
		$this->node->next_task( array( 'polls' => 1000 ) );

		$first = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[0] ) );
		$this->assertTrue( $first->is_retry(), 'Still running: look again.' );
		$this->assertCount( 1, $this->deletes() );
		$this->assertIsArray( Resource::find( $r->id() )->get( 'task' ), 'The task id is kept.' );
		$this->assertSame( $ids, array_column( HostingBackups::stored( Resource::find( $r->id() ) ), 'volid' ), 'The list is rewritten only when the volume is confirmed gone.' );

		// The next run polls the stored task: no second delete.
		$next = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[0] ) );
		$this->assertTrue( $next->is_retry() );
		$this->assertCount( 1, $this->deletes() );
	}

	public function test_a_task_that_ends_in_error_but_removed_the_volume_is_a_success(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		$this->assertIsArray( ( new HostingActions( $r ) )->queue( 'delete_backup', array( $ids[0] ) ) );
		$this->node->next_task( array( 'exitstatus' => 'unexpected status', 'effect' => true ) );
		$this->mails = array();

		$result = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[0] ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( array( $ids[1] ), array_column( HostingBackups::stored( Resource::find( $r->id() ) ), 'volid' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_task_that_ends_in_error_and_keeps_the_volume_says_so_and_changes_nothing(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		$this->assertIsArray( ( new HostingActions( $r ) )->queue( 'delete_backup', array( $ids[0] ) ) );
		$this->node->next_task( array( 'exitstatus' => 'unexpected status', 'effect' => false ) );
		$this->mails = array();

		$result = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[0] ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'That backup could not be deleted. It may be protected. Contact support.', $result->meta()[ Jobs::TEXT ] );
		$this->assertNotEmpty( $result->meta()[ Jobs::EXPECTED ] );
		$this->assertSame( $ids, array_column( HostingBackups::stored( Resource::find( $r->id() ) ), 'volid' ) );
		$this->assertContains( $ids[0], $this->volids_on_node() );
	}

	public function test_a_protected_backup_is_refused_early_and_in_the_job_and_never_unprotected(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		$base            = '/nodes/pve1/storage/pbs-store/content/' . rawurlencode( $ids[1] );

		// Staff protected it on the node after the list was stored: the job reads the live list and refuses without a delete call.
		$this->assertNull( $this->node->request( 'PUT', $base, array( 'protected' => 1 ) ) );
		$this->assertIsArray( ( new HostingActions( $r ) )->queue( 'delete_backup', array( $ids[1] ) ) );
		$result = Steps::run( Resource::find( $r->id() ), 'backup_delete', array( $ids[1] ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'That backup could not be deleted. It may be protected. Contact support.', $result->meta()[ Jobs::TEXT ] );
		$this->assertSame( array(), $this->deletes() );

		// Once the stored list knows it, the request refuses before any job.
		$this->clock_to( $this->clock + HOUR_IN_SECONDS );
		$this->assertTrue( ( new HostingBackups( Resource::find( $r->id() ) ) )->start( 'manual' ) );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$this->run_account( $r );
		$out = ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'delete_backup', array( $ids[1] ) );
		$this->assertWPError( $out );
		$this->assertSame( 'That backup could not be deleted. It may be protected. Contact support.', $out->get_error_message() );

		$unprotect = array_filter( $this->node->calls_to( '#/content/#', 'PUT' ), static fn( $c ) => isset( $c['params']['protected'] ) && 0 === (int) $c['params']['protected'] );
		$this->assertSame( array(), array_values( $unprotect ), 'Nothing is unprotected.' );
		$this->assertNotEmpty( array_filter( $this->node->state()['backups'], static fn( $b ) => $b['volid'] === $ids[1] && ! empty( $b['protected'] ) ) );
	}

	public function test_the_restore_safety_copy_is_not_offered_and_is_refused_by_the_request_and_the_job(): void {
		list( $r, $ids ) = $this->with_backups( 1 );
		$this->change( $r, 'restore', array( 'account', $ids[0], '' ) );
		$r      = Resource::find( $r->id() );
		$safety = HostingRestore::safety( $r );
		$this->assertSame( 'vzdump', $safety['mode'] );
		$this->assertContains( $safety['volid'], $this->volids_on_node() );

		// Not in the customer's list and not drawn on the page.
		$this->assertNotContains( $safety['volid'], array_column( HostingBackups::stored( $r ), 'volid' ) );
		ob_start();
		HostingAccount::render( $r );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( esc_attr( $safety['volid'] ), $html );
		$this->assertStringContainsString( 'data-hosting-action="backup_delete"', $html, 'The real backup has its button.' );

		// Sent by hand: refused by the request...
		$out = ( new HostingActions( $r ) )->queue( 'delete_backup', array( $safety['volid'] ) );
		$this->assertWPError( $out );
		$this->assertSame( 'That backup cannot be deleted.', $out->get_error_message() );
		$this->assertSame( 422, $this->route( $r, $safety['volid'] )->get_status() );

		// ...and by the job, whatever got through.
		$seen   = count( $this->node->calls() );
		$result = Steps::run( $r, 'backup_delete', array( $safety['volid'] ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'That backup cannot be deleted.', $result->meta()[ Jobs::TEXT ] );
		$this->assertNotEmpty( $result->meta()[ Jobs::EXPECTED ] );

		$this->assertSame( array(), $this->deletes() );
		$this->assertCount( 0, array_filter( array_slice( $this->node->calls(), $seen ), static fn( $c ) => in_array( $c['method'], array( 'PUT', 'DELETE' ), true ) ), 'The job changed nothing on the node.' );
		$this->assertNotEmpty( array_filter( $this->node->state()['backups'], static fn( $b ) => $b['volid'] === $safety['volid'] && ! empty( $b['protected'] ) ), 'Still protected.' );
		$this->assertNotNull( HostingRestore::safety( Resource::find( $r->id() ) ), 'The undo still works.' );
	}

	public function test_a_delete_next_to_a_safety_copy_keeps_the_stored_list_free_of_it(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		$this->change( $r, 'restore', array( 'account', $ids[0], '' ) );
		$r      = Resource::find( $r->id() );
		$safety = HostingRestore::safety( $r );

		$this->assertSame( 202, $this->route( $r, $ids[1] )->get_status() );
		$this->run_account( $r );

		$stored = HostingBackups::stored( Resource::find( $r->id() ) );
		$this->assertSame( array( $ids[0] ), array_column( $stored, 'volid' ) );
		$this->assertNotContains( 'safety', array_column( $stored, 'kind' ) );
		$this->assertContains( $safety['volid'], $this->volids_on_node(), 'The safety copy is still on the node.' );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
	}

	public function test_a_delete_gives_no_back_up_now_start_back_and_starts_no_backup(): void {
		list( $r, $ids ) = $this->with_backups( 2 );
		Resource::find( $r->id() )->set( array( 'backup_manual_log' => array( $this->clock - 600, $this->clock - 300 ) ) );
		$log = (array) Resource::find( $r->id() )->get( 'backup_manual_log' );
		$this->assertCount( 2, $log );
		$dumps = count( $this->node->calls_to( '#/vzdump$#', 'POST' ) );

		$this->assertSame( 202, $this->route( $r, $ids[0] )->get_status() );
		$this->run_account( $r );
		$this->assertSame( 202, $this->route( Resource::find( $r->id() ), $ids[1] )->get_status() );
		$this->run_account( $r );

		$this->assertSame( $log, (array) Resource::find( $r->id() )->get( 'backup_manual_log' ), 'The day\'s count is untouched.' );
		$this->assertSame( $dumps, count( $this->node->calls_to( '#/vzdump$#', 'POST' ) ), 'No new backup is started.' );
	}

	public function test_the_change_is_one_of_the_shared_controller_and_is_never_run_inline(): void {
		$this->assertSame( array( 'backup_delete', 'Deleting a backup' ), Steps::table()['delete_backup'] );
		$this->assertContains( 'backup_delete', Steps::actions() );
		$this->assertSame( 'delete_backup', Steps::method_of( 'backup_delete' ) );

		list( $r, $ids ) = $this->with_backups( 1 );
		$out = ( new HostingActions( $r ) )->delete_backup( $ids[0] );
		$this->assertWPError( $out );
		$this->assertSame( array(), $this->deletes() );
	}

	public function test_the_page_draws_the_button_only_when_ready_and_idle_and_the_dialog_says_when_it_is_the_only_backup(): void {
		list( $r, $ids ) = $this->with_backups( 1 );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$one = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-hosting-action="backup_delete" data-volid="' . esc_attr( $ids[0] ) . '"', $one );
		$this->assertStringContainsString( 'It cannot be undone and you cannot restore from it afterwards.', $one );
		$this->assertStringContainsString( 'This is your only backup. You will have no backup until the next one is made tonight.', $one );
		$this->assertStringContainsString( '1 March 2027', $one );
		$this->assertStringNotContainsString( "\u{2014}", $one );
		$this->assertStringNotContainsString( "\u{00B7}", $one );

		// A second backup: no only-backup sentence.
		$this->clock_to( $this->clock + DAY_IN_SECONDS );
		$this->assertTrue( ( new HostingBackups( Resource::find( $r->id() ) ) )->start( 'manual' ) );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$two = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $two, 'data-hosting-action="backup_delete"' ) );
		$this->assertStringNotContainsString( 'This is your only backup', $two );

		// A change of the account runs: no button.
		( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'restore', array( 'database', array_column( HostingBackups::stored( Resource::find( $r->id() ) ), 'volid' )[0], 'w' . $r->id() ) );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'data-hosting-action="backup_delete"', (string) ob_get_clean() );
	}

	public function test_the_script_asks_first_with_the_pages_text_and_calls_the_delete_route(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/hosting.js' );

		$this->assertStringContainsString( "case 'backup_delete':", $js );
		$this->assertStringContainsString( "[ 'DELETE', base + '/backups?volid=' + encodeURIComponent(", $js );
		$this->assertStringContainsString( "data-confirm", $js );
		$this->assertSame( 2, substr_count( $js, "'backup_now', 'backup_delete'" ), 'Locked while a change runs, and drawn at once.' );
	}

	public function test_the_route_is_documented_with_its_argument(): void {
		$routes = rest_get_server()->get_routes();
		$key    = '/' . Routes::NAMESPACE . '/resources/(?P<id>\d+)/hosting/backups';
		$found  = array_filter( array_keys( $routes ), static fn( $k ) => 0 === strpos( $k, $key ) && '/restore' !== substr( $k, strlen( $key ) ) );

		$this->assertNotEmpty( $found );
		$methods = array();

		foreach ( $routes[ reset( $found ) ] as $handler ) {
			$methods = array_merge( $methods, array_keys( $handler['methods'] ) );

			if ( isset( $handler['methods']['DELETE'] ) ) {
				$this->assertSame( 'The backup, from the list.', $handler['args']['volid']['description'] );
				$this->assertTrue( $handler['args']['volid']['required'] );
			}
		}

		$this->assertContains( 'DELETE', $methods );
	}
}
