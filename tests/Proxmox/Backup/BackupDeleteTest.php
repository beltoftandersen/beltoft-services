<?php
/**
 * The customer deletes one of their own server backups. Only the fake client is used: nothing here reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Backup;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Resource;

/**
 * Action backup_delete of a server: offered, refused, run, idempotent.
 */
class BackupDeleteTest extends BackupTestCase {

	/**
	 * Seeds backups of the VM in the fake and stores the listing the panel shows.
	 *
	 * @param Resource $r      Resource.
	 * @param int      $count  How many.
	 * @param array<int, array<string, mixed>> $extra Overrides per index.
	 * @return string[] Volume ids, newest first.
	 */
	private function seed( Resource $r, int $count, array $extra = array() ): array {
		$vmid = (int) $r->get( 'vmid' );
		$base = time() + 1000;

		for ( $i = 0; $i < $count; $i++ ) {
			$this->fake->add_backup( 'backup-store', array_merge( array( 'vmid' => $vmid, 'ctime' => $base - $i * 100 ), $extra[ $i ] ?? array() ) );
		}

		$list = ( new Backups( Resource::find( $r->id() ) ) )->list();
		$this->assertIsArray( $list );
		( new Backups( Resource::find( $r->id() ) ) )->remember( $list );

		return array_column( $list, 'volid' );
	}

	private function owner( Resource $r ): int {
		return (int) $r->license()['user_id'];
	}

	private function route( Resource $r, string $action, array $args ): \WP_REST_Response {
		wp_set_current_user( $this->owner( $r ) );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/' . $action );
		$request->set_param( 'args', $args );

		return rest_get_server()->dispatch( $request );
	}

	private function deletes(): array {
		return array_values( array_filter( $this->fake->calls(), static fn( $c ) => 'DELETE' === $c['method'] && false !== strpos( $c['path'], '/content/' ) ) );
	}

	private function result( Resource $r ): array {
		return (array) Resource::find( $r->id() )->get( Jobs::RESULT );
	}

	private function events_of( Resource $r ): string {
		return (string) wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) );
	}

	public function test_it_is_offered_with_backups_and_never_for_a_linked_server_or_a_restore_under_way(): void {
		$r = $this->ready_vm();

		$this->assertArrayHasKey( 'backup_delete', Actions::offered( Resource::find( $r->id() ) ) );
		$this->assertArrayNotHasKey( 'backup_delete', Actions::buttons( array( 'backup_delete' => 'x', 'start' => 'Start' ), Resource::find( $r->id() ) ), 'A table button draws it, not a generic one.' );

		$none = $this->ready_vm( array( 'backup_days' => 0 ) );
		$this->assertArrayNotHasKey( 'backup_delete', Actions::offered( $none ) );

		$r->set( array( 'restore' => array( 'volid' => 'x', 'phase' => 'restore', 'ctime' => 1 ) ) );
		$this->assertArrayNotHasKey( 'backup_delete', Actions::offered( Resource::find( $r->id() ) ) );

		$linked = $this->ready_vm();
		$linked->set( array( 'linked' => true ) );
		$this->assertArrayNotHasKey( 'backup_delete', Actions::offered( Resource::find( $linked->id() ) ) );
	}

	public function test_one_delete_removes_exactly_that_volume_and_updates_the_stored_list(): void {
		$r     = $this->ready_vm();
		$ids   = $this->seed( $r, 3 );
		$other = $this->ready_vm();
		$this->seed( $other, 1 );
		$seen = count( $this->fake->calls() );

		$response = $this->route( $r, 'backup_delete', array( 'volid' => $ids[1] ) );
		$this->assertSame( 202, $response->get_status() );
		$this->run_jobs();
		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[1] ) );

		$left = array_column( $this->fake->state()['backups']['backup-store'], 'volid' );
		$this->assertNotContains( $ids[1], $left );
		$this->assertContains( $ids[0], $left );
		$this->assertContains( $ids[2], $left );
		$this->assertCount( 3, $left, 'Two of this server and one of the other server remain.' );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( $ids[0], $ids[2] ), array_column( (array) $fresh->get( 'backup_list' ), 'volid' ) );
		$this->assertTrue( $this->result( $r )['ok'] );
		$this->assertSame( 'The backup was deleted.', $this->result( $r )['text'] );
		$this->assertStringContainsString( 'deleted at the customer', $this->events_of( $r ) );
		$this->assertNotEmpty( array_slice( $this->fake->calls(), $seen ) );
		$this->assertEmpty( $this->calls( 'PUT', '/content/' . rawurlencode( $ids[0] ) ), 'Nothing is unprotected.' );
	}

	public function test_the_only_and_the_newest_backup_can_be_deleted(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 1 );

		$this->assertTrue( Resource::find( $r->id() ) instanceof Resource );
		$panel = Backups::panel( Resource::find( $r->id() ) );
		$this->assertTrue( $panel['can_delete'] );

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );

		$this->assertSame( array(), $this->fake->state()['backups']['backup-store'] ?? array() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'backup_list' ) );
		$this->assertTrue( $this->result( $r )['ok'] );
		$this->assertFalse( Backups::panel( Resource::find( $r->id() ) )['can_delete'], 'Nothing left to delete.' );
	}

	public function test_a_volume_of_another_machine_or_of_the_wrong_shape_is_refused_before_a_job(): void {
		$r     = $this->ready_vm();
		$other = $this->ready_vm();
		$mine  = $this->seed( $r, 1 );
		$theirs = $this->seed( $other, 1 );
		$seen  = count( $this->fake->calls() );

		foreach ( array(
			'another machine'  => $theirs[0],
			'another storage'  => 'disk-store:backup/vm/' . $r->get( 'vmid' ) . '/x',
			'an image volume'  => 'disk-store:vm-' . $r->get( 'vmid' ) . '-disk-0',
			'a path trick'     => $mine[0] . '/../' . $theirs[0],
			'nothing'          => '',
		) as $label => $volid ) {
			$response = $this->route( $r, 'backup_delete', array( 'volid' => $volid ) );
			$this->assertSame( 422, $response->get_status(), $label );
			$this->assertSame( 'Choose one of your backups.', $response->get_data()['message'], $label );
		}

		$this->assertSame( $seen, count( $this->fake->calls() ), 'Nothing reached the cluster.' );
		$this->assertCount( 0, $this->deletes() );
	}

	public function test_a_volume_that_is_not_in_the_live_listing_is_never_deleted(): void {
		$r      = $this->ready_vm();
		$record = \BeltoftServices\Proxmox\Vm\VmRecords::for_resource( $r->id() );
		$born   = (int) strtotime( $record['created_at'] . ' UTC' );
		$vmid   = (int) $r->get( 'vmid' );
		$old    = 'backup-store:backup/vm/' . $vmid . '/before-the-record';

		// A backup of the same VMID that is older than this server's record belongs to an earlier customer.
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $born - 500, 'volid' => $old ) );
		$this->seed( $r, 1 );

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $old ) );

		$this->assertContains( $old, array_column( $this->fake->state()['backups']['backup-store'], 'volid' ) );
		$this->assertCount( 0, $this->deletes() );
	}

	public function test_it_is_refused_while_a_restore_or_a_backup_runs(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 2 );

		$r->set( array( 'backup_task' => array( 'upid' => 'UPID:node-a:0001:0002:0003:vzdump:' . $r->get( 'vmid' ) . ':x@pve!t:', 'started' => time() ) ) );
		$response = $this->route( $r, 'backup_delete', array( 'volid' => $ids[0] ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'A backup is running. Try again when it has finished.', $response->get_data()['message'] );
		$this->assertFalse( Backups::panel( Resource::find( $r->id() ) )['can_delete'] );

		// The job refuses as well, with the same words for the customer, and changes nothing.
		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );
		$this->assertFalse( $this->result( $r )['ok'] );
		$this->assertSame( 'A backup is running. Try again when it has finished.', $this->result( $r )['text'] );
		$this->assertCount( 0, $this->deletes() );

		$r->set( array( 'backup_task' => null, 'restore' => array( 'volid' => $ids[1], 'phase' => 'restore', 'ctime' => 1 ) ) );
		$this->assertSame( 'A restore is running. Try again when it has finished.', Actions::check( Resource::find( $r->id() ), 'backup_delete', array( 'volid' => $ids[0] ) ) );
		$this->assertCount( 0, $this->deletes() );
	}

	public function test_a_linked_server_and_a_plan_without_backups_are_refused(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 1 );
		$r->set( array( 'linked' => true ) );
		$this->assertSame( 'This is not available for this server. Please contact support.', Actions::check( Resource::find( $r->id() ), 'backup_delete', array( 'volid' => $ids[0] ) ) );

		$none = $this->ready_vm( array( 'backup_days' => 0 ) );
		$this->assertSame( 'Backups are not available for this service.', Actions::check( $none, 'backup_delete', array( 'volid' => 'backup-store:backup/vm/' . $none->get( 'vmid' ) . '/x' ) ) );
	}

	public function test_the_floor_is_ten_deletes_an_hour_per_service(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 1 );
		wp_set_current_user( $this->owner( $r ) );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertFalse( Actions::floor_exceeded( $r, 'backup_delete' ), 'Request ' . ( $i + 1 ) );
		}

		$response = $this->route( $r, 'backup_delete', array( 'volid' => $ids[0] ) );
		$this->assertSame( 429, $response->get_status() );

		$other = $this->ready_vm();
		$this->assertFalse( Actions::floor_exceeded( $other, 'backup_delete' ), 'Another service has its own count.' );
	}

	public function test_a_replay_deletes_once_and_a_volume_already_gone_is_done(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 2 );

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );
		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );

		$this->assertCount( 1, $this->deletes(), 'The second run found it gone and sent nothing.' );
		$this->assertTrue( $this->result( $r )['ok'] );
		$this->assertStringContainsString( 'already gone', $this->events_of( $r ) );
		$this->assertSame( array( $ids[1] ), array_column( (array) Resource::find( $r->id() )->get( 'backup_list' ), 'volid' ) );
	}

	public function test_a_task_that_ends_in_error_but_removed_the_volume_is_a_success(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 2 );
		$this->fake->next_task( array( 'exitstatus' => 'unexpected status', 'effect' => true ) );
		$this->mails = array();

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );

		$this->assertTrue( $this->result( $r )['ok'] );
		$this->assertSame( array( $ids[1] ), array_column( (array) Resource::find( $r->id() )->get( 'backup_list' ), 'volid' ) );
		$this->assertCount( 0, $this->mails, 'Staff are not mailed.' );
	}

	public function test_a_task_that_ends_in_error_and_keeps_the_volume_says_so_and_changes_nothing(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 2 );
		$this->fake->next_task( array( 'exitstatus' => 'unexpected status', 'effect' => false ) );
		$this->mails = array();

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );

		$result = $this->result( $r );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'That backup could not be deleted. It may be protected. Contact support.', $result['text'] );
		$this->assertSame( $ids, array_column( (array) Resource::find( $r->id() )->get( 'backup_list' ), 'volid' ), 'The panel still shows what exists.' );
		$this->assertContains( $ids[0], array_column( $this->fake->state()['backups']['backup-store'], 'volid' ) );
		$this->assertCount( 0, $this->mails, 'An expected refusal mails nobody.' );
	}

	public function test_a_protected_backup_is_refused_and_never_unprotected(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 2, array( 1 => array( 'protected' => 1 ) ) );
		$this->mails = array();

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[1] ) );

		$result = $this->result( $r );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'That backup could not be deleted. It may be protected. Contact support.', $result['text'] );
		$this->assertContains( $ids[1], array_column( $this->fake->state()['backups']['backup-store'], 'volid' ) );
		$this->assertSame( 1, (int) array_values( array_filter( $this->fake->state()['backups']['backup-store'], static fn( $b ) => $b['volid'] === $ids[1] ) )[0]['protected'] );
		$this->assertSame( array(), array_values( array_filter( $this->fake->calls(), static fn( $c ) => 'PUT' === $c['method'] && false !== strpos( $c['path'], '/content/' ) ) ), 'No call ever sets protected.' );
		$this->assertCount( 0, $this->mails );
	}

	public function test_a_transport_error_is_tried_again_and_a_later_run_finishes_it(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 2 );
		$this->fake->fail_next( 'DELETE', '#/content/#', new \WP_Error( 'http_request_failed', 'timeout' ) );

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );

		$this->assertContains( $ids[0], array_column( $this->fake->state()['backups']['backup-store'], 'volid' ), 'Not deleted yet.' );
		$this->assertSame( $ids, array_column( (array) Resource::find( $r->id() )->get( 'backup_list' ), 'volid' ) );

		Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
		$this->run_jobs();
		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );

		$this->assertNotContains( $ids[0], array_column( $this->fake->state()['backups']['backup-store'], 'volid' ) );
	}

	public function test_the_ownership_check_comes_before_the_delete(): void {
		$r   = $this->ready_vm();
		$ids = $this->seed( $r, 1 );
		// Somebody changed the description: the tag line is gone.
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/' . (int) $r->get( 'vmid' ) . '/config', array( 'description' => 'someone else' ) );

		Jobs::act( $r->id(), 'backup_delete', array( 'volid' => $ids[0] ) );

		$this->assertCount( 0, $this->deletes() );
		$this->assertContains( $ids[0], array_column( $this->fake->state()['backups']['backup-store'], 'volid' ) );
	}

	public function test_the_server_page_draws_the_delete_button_only_when_the_card_says_so_and_the_dialog_says_the_rest(): void {
		$template = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/account/vm.php' );
		$script   = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$panels   = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Proxmox/Vm/Panels.php' );

		$this->assertStringContainsString( "if ( ! empty( \$backups['can_delete'] ) )", $template );
		$this->assertStringContainsString( 'bs-btn bs-btn--sm bs-btn--danger" data-backup-delete=', $template );
		$this->assertStringContainsString( "1 === count( \$backups['list'] ) ? ' data-backup-only=\"1\"'", $template );
		$this->assertStringContainsString( 'danger: true', substr( $script, (int) strpos( $script, 'dropBackup && ! dropBackup.disabled' ), 900 ) );
		$this->assertStringContainsString( "send( panel, 'backup_delete', { volid:", $script );
		$this->assertStringContainsString( 'This is your only backup. You will have no backup until the next one is made tonight.', $panels );
		$this->assertStringContainsString( 'It cannot be undone and you cannot restore from it afterwards.', $panels );

		// A card that cannot delete (a backup running, a restore under way, nothing listed) has the flag off.
		$r = $this->ready_vm();
		$this->assertFalse( Backups::panel( Resource::find( $r->id() ) )['can_delete'], 'Nothing listed.' );
		$this->seed( $r, 2 );
		$this->assertTrue( Backups::panel( Resource::find( $r->id() ) )['can_delete'] );
		$r->set( array( 'restore' => array( 'volid' => 'x', 'phase' => 'restore', 'ctime' => 1 ) ) );
		$this->assertFalse( Backups::panel( Resource::find( $r->id() ) )['can_delete'] );
	}
}
