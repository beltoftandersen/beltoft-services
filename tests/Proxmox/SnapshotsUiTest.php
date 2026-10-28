<?php
/**
 * Snapshots, phase E: what the server card shows. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Progress;
use BeltoftServices\Proxmox\Panels;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class SnapshotsUiTest extends SnapshotsTestCase {

	private function card( Resource $r ): string {
		ob_start();
		Panels::account( $this->fresh( $r ) );

		return (string) ob_get_clean();
	}

	public function test_the_block_is_absent_when_the_switch_is_off_and_there_are_no_snapshots(): void {
		$r = $this->server();
		$this->settings( array( 'snapshots_enabled' => 0 ) );

		$html = $this->card( $r );

		$this->assertStringNotContainsString( 'data-snapshots', $html );
		$this->assertStringNotContainsString( 'Take a snapshot', $html );
		$this->assertNull( Panels::snapshots( $this->fresh( $r ) ) );
	}

	public function test_with_the_switch_off_the_list_stays_with_delete_only(): void {
		$r = $this->server();
		$e = $this->take( $r, 'before' );
		$this->settings( array( 'snapshots_enabled' => 0 ) );

		$html = $this->card( $r );

		$this->assertStringContainsString( 'data-snapshots', $html );
		$this->assertStringContainsString( 'before', $html );
		$this->assertStringContainsString( 'data-snapshot-delete="' . $e['id'] . '"', $html );
		$this->assertStringNotContainsString( 'data-snapshot-rollback', $html );
		$this->assertStringNotContainsString( 'data-action="snapshot_take"', $html );
		$this->assertStringNotContainsString( 'data-snapshot-dialog', $html );
		$this->assertStringNotContainsString( 'data-snapshot-working', $html );
		$this->assertArrayHasKey( 'snapshot_delete', Snapshots::offered( $this->fresh( $r ) ) );
		$this->assertArrayNotHasKey( 'snapshot_take', Snapshots::offered( $this->fresh( $r ) ) );
		$this->assertArrayNotHasKey( 'snapshot_rollback', Snapshots::offered( $this->fresh( $r ) ) );
		$this->assertNull( Snapshots::check( $this->fresh( $r ), 'snapshot_delete', array( 'id' => $e['id'] ) ) );

		$this->act( $r, 'snapshot_delete', array( 'id' => $e['id'] ) );
		$this->assertSame( 0, Snapshots::used( $this->fresh( $r ) ) );
		$this->assertStringNotContainsString( 'data-snapshots', $this->card( $r ) );
	}

	public function test_while_something_else_runs_the_list_stays_without_buttons(): void {
		$r = $this->server();
		$e = $this->take( $r, 'keep' );
		$this->assertTrue( $this->fresh( $r )->set( array( 'plan_dirty' => true ) ) );

		$html = $this->card( $r );

		$this->assertStringContainsString( 'data-snapshots', $html );
		$this->assertStringContainsString( 'keep', $html );
		$this->assertStringContainsString( 'Working on your server, snapshot actions are back when it is done.', $html );
		$this->assertStringNotContainsString( 'data-snapshot-rollback', $html );
		$this->assertStringNotContainsString( 'data-snapshot-delete', $html );
		$this->assertStringNotContainsString( 'data-action="snapshot_take"', $html );

		$this->assertTrue( $this->fresh( $r )->set( array( 'plan_dirty' => false, Snapshots::ROLLBACK => array( 'id' => $e['id'], 'phase' => 'rolling' ) ) ) );
		$html = $this->card( $r );
		$this->assertStringContainsString( 'data-snapshot-working', $html );
		$this->assertStringNotContainsString( 'data-snapshot-delete', $html );
		$this->assertNotNull( Snapshots::check( $this->fresh( $r ), 'snapshot_delete', array( 'id' => $e['id'] ) ), 'An open operation refuses a delete.' );
	}

	public function test_an_empty_list_shows_the_slot_line_and_the_take_form(): void {
		$r = $this->server();
		$this->on( array( 'snapshot_free_slots' => 1 ) );

		$html = $this->card( $r );

		$this->assertStringContainsString( 'data-snapshots', $html );
		$this->assertStringContainsString( '0 of 1 used', $html );
		$this->assertStringContainsString( 'You have no snapshots yet.', $html );
		$this->assertStringContainsString( 'data-action="snapshot_take"', $html );
		$this->assertStringContainsString( 'Name (optional)', $html );
		$this->assertStringContainsString( 'Free snapshots are kept 7 days.', $html );
		$this->assertStringContainsString( 'Includes every disk. The server is off for a moment while it goes back. The password and SSH key go back to the ones saved.', $html );
		$this->assertStringNotContainsString( 'data-snapshot-full', $html );
		$this->assertStringNotContainsString( '—', $html );
	}

	public function test_the_days_come_from_the_setting(): void {
		$r = $this->server();
		$this->on( array( 'snapshot_free_days' => 1 ) );

		$this->assertStringContainsString( 'Free snapshots are kept 1 day.', $this->card( $r ) );
	}

	public function test_a_row_shows_label_dates_and_whether_it_is_consistent_with_its_two_buttons(): void {
		$r = $this->server();
		$this->on( array( 'snapshot_free_slots' => 3 ) );
		$entry = $this->take( $r, 'before upgrade' );

		$html = $this->card( $r );

		$this->assertStringContainsString( '1 of 3 used', $html );
		$this->assertStringContainsString( 'before upgrade', $html );
		$this->assertStringContainsString( 'data-snapshot="' . $entry['id'] . '"', $html );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['expires_at'] ), $html );
		$this->assertSame( $entry['consistent'] ? 1 : 0, substr_count( $html, ', consistent' ) );
		$this->assertSame( $entry['consistent'] ? 0 : 1, substr_count( $html, 'taken without the guest agent' ) );
		$this->assertStringContainsString( 'data-snapshot-rollback="' . $entry['id'] . '"', $html );
		$this->assertStringContainsString( 'Go back to this snapshot', $html );
		$this->assertStringContainsString( 'data-snapshot-delete="' . $entry['id'] . '"', $html );
		$this->assertStringContainsString( 'ROLLBACK', $html, 'The word to type is on the page.' );
		$this->assertStringContainsString( 'data-action="snapshot_rollback"', $html );
	}

	public function test_a_snapshot_taken_without_the_agent_says_so(): void {
		$r = $this->server();
		$entry = $this->take( $r, 'plain' );
		$list = Snapshots::entries( $this->fresh( $r ) );
		$list[0]['consistent'] = false;
		$this->assertTrue( $this->fresh( $r )->set( array( Snapshots::LIST => $list ) ) );

		$this->assertStringContainsString( 'taken without the guest agent', $this->card( $r ) );
		$this->assertSame( $entry['id'], $list[0]['id'] );
	}

	public function test_with_all_slots_used_the_take_form_gives_way_to_a_line_about_deleting_one(): void {
		$r = $this->server();
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$this->take( $r, 'only' );

		$html = $this->card( $r );

		$this->assertStringContainsString( '1 of 1 used', $html );
		$this->assertStringContainsString( 'data-snapshot-full', $html );
		$this->assertStringContainsString( 'All your snapshot slots are in use. Delete a snapshot first.', $html );
		$this->assertStringNotContainsString( 'data-action="snapshot_take"', $html );
		$this->assertStringNotContainsString( 'woocommerce-button" href', $html );
	}

	public function test_an_entry_that_is_being_made_or_deleted_has_no_buttons(): void {
		$r = $this->server();
		$this->take( $r, 'busy' );
		$list = Snapshots::entries( $this->fresh( $r ) );
		$list[0]['state'] = 'deleting';
		$this->assertTrue( $this->fresh( $r )->set( array( Snapshots::LIST => $list ) ) );

		$html = $this->card( $r );

		$this->assertStringContainsString( 'being deleted', $html );
		$this->assertStringNotContainsString( 'data-snapshot-rollback="', $html );
		$this->assertStringNotContainsString( 'data-snapshot-delete="', $html );
	}

	public function test_the_reinstall_and_restore_forms_warn_only_when_snapshots_exist(): void {
		$r = $this->server();
		$html = $this->card( $r );

		$this->assertStringNotContainsString( 'data-snapshot-warning', $html );

		$this->take( $r, 'one' );
		$html = $this->card( $r );

		$this->assertStringContainsString( 'Reinstalling deletes 1 snapshot of this server.', $html );
		$this->assertSame( 1, substr_count( $html, 'Reinstalling deletes 1 snapshot of this server.' ) );
		$this->assertStringNotContainsString( 'Restoring a backup deletes', $html, 'No backup, no restore form.' );

		$this->assertTrue( $this->fresh( $r )->set( array( 'backup_list' => array( array( 'volid' => 'backup-store:backup/vzdump-qemu-5000.vma', 'ctime' => time(), 'size' => 1048576 ) ) ) ) );
		$this->assertStringContainsString( 'Restoring a backup deletes the snapshots of this server.', $this->card( $r ) );
	}

	public function test_an_extra_disk_that_snapshots_cover_shows_a_remove_warning(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$this->assertStringNotContainsString( 'data-snapshot-disk-warning', $this->card( $r ) );

		$this->take( $r, 'with disk' );
		$html = $this->card( $r );

		// The warning sits with the Remove link; the test disk has no subscription, so no link and no warning, but the data is there.
		$this->assertSame( 1, \BeltoftServices\Proxmox\DiskPanels::server( $this->fresh( $r ) )['items'][0]['snapshots_covering'] );
		$this->assertSame( '', \BeltoftServices\Proxmox\DiskPanels::server( $this->fresh( $r ) )['items'][0]['remove_url'] );
		$this->assertStringNotContainsString( 'data-snapshot-disk-warning', $html );
		$this->assertNotNull( $disk );
	}

	public function test_the_vm_script_gets_the_delete_question(): void {
		do_action( 'beltoft_services_account_assets', 'beltoft-services-account' );
		$data = wp_scripts()->get_data( 'beltoft-services-vm', 'data' );

		$this->assertStringContainsString( 'snapshotDelete', (string) $data );
	}

	public function test_take_delete_and_rollback_show_as_working(): void {
		$r = $this->server();
		$e = $this->take( $r, 'x' );

		Progress::mark( $this->fresh( $r ), 'snapshot_take', 'Take a snapshot' );
		$p = Progress::build( $this->fresh( $r ) );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'Take a snapshot', $p['title'] );

		Progress::mark( $this->fresh( $r ), 'snapshot_delete', 'Delete a snapshot' );
		$p = Progress::build( $this->fresh( $r ) );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'Delete a snapshot', $p['title'] );

		Progress::mark( $this->fresh( $r ), 'snapshot_rollback', 'Roll back to a snapshot' );
		$p = Progress::build( $this->fresh( $r ) );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'rollback', $p['kind'] );
		$this->assertSame( 'Going back to your snapshot', $p['title'] );
		$this->assertNotEmpty( $e['id'] );
	}

	public function test_the_card_is_busy_while_a_snapshot_action_is_marked(): void {
		$r = $this->server();
		Progress::mark( $this->fresh( $r ), 'snapshot_take', 'Take a snapshot' );

		$this->assertStringContainsString( 'Busy', $this->card( $r ) );
	}

	public function test_the_dialog_notice_cannot_catch_the_panel_messages(): void {
		$r = $this->server();
		$this->take( $r, 'x' );

		$html = $this->card( $r );

		$this->assertStringNotContainsString( 'data-notice', $html, 'The panel notice is the only data-notice on the page.' );
		$this->assertStringContainsString( 'data-dialog-notice', $html );
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$this->assertStringContainsString( 'dialog[open] [data-dialog-notice]', $js );
		$this->assertStringContainsString( "removeAttribute( 'open' )", $js );
	}

	public function test_the_form_ids_are_unique_per_server(): void {
		$a = $this->server();
		$b = $this->ready_vm();
		$this->take( $a, 'a' );

		$this->assertStringContainsString( 'beltoft-vm-snapshot-label-' . $a->id() . '"', $this->card( $a ) );
		$this->assertStringContainsString( 'beltoft-vm-rollback-confirm-' . $a->id() . '"', $this->card( $a ) );
		$this->assertStringContainsString( 'beltoft-vm-snapshot-label-' . $b->id() . '"', $this->card( $b ) );
	}

	public function test_a_server_that_does_not_accept_actions_shows_the_list_without_buttons(): void {
		$r = $this->server();
		$this->take( $r, 'kept' );

		$panel = Panels::snapshots( $this->fresh( $r ), false );
		$this->assertSame( 'working', $panel['mode'] );

		$this->assertTrue( $this->fresh( $r )->set( array( 'desired' => 'paused' ) ) );
		ob_start();
		Panels::account( $this->fresh( $r ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-snapshots', $html );
		$this->assertStringContainsString( 'kept', $html );
		$this->assertStringNotContainsString( 'data-snapshot-delete', $html );
		$this->assertStringNotContainsString( 'data-snapshot-rollback', $html );
	}

	public function test_an_adopted_server_shows_its_list_read_only(): void {
		$r = $this->server();
		$this->take( $r, 'adopted' );
		$this->assertTrue( $this->fresh( $r )->set( array( 'linked' => true ) ) );

		$panel = Panels::snapshots( $this->fresh( $r ), true );

		$this->assertSame( 'readonly', $panel['mode'] );
		ob_start();
		Panels::account( $this->fresh( $r ) );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-snapshot-delete', $html );
		$this->assertStringNotContainsString( 'data-snapshot-working', $html );
	}

	public function test_a_rollback_records_when_it_queued_the_plan(): void {
		$r = $this->server();
		$e = $this->take( $r, 'back' );

		$this->act( $r, 'snapshot_rollback', array( 'id' => $e['id'], 'confirm' => 'ROLLBACK' ) );

		$fresh = $this->fresh( $r );
		$this->assertGreaterThan( time() - 60, (int) $fresh->get( 'snapshot_rolled_at' ) );
		$this->assertNull( $fresh->get( Snapshots::ROLLBACK ) );
	}
}
