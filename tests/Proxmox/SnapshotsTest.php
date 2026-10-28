<?php
/**
 * Customer snapshots, phase B: take, delete, roll back, the internal actions and the rules around them. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\InternalActions;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

require_once __DIR__ . '/SnapshotsTestCase.php';

class SnapshotsTest extends SnapshotsTestCase {

	// -- Settings and rules ----------------------------------------------------------------------

	public function test_snapshots_are_off_by_default_and_the_settings_have_their_defaults(): void {
		delete_option( Settings::OPTION );
		$this->assertSame( 0, Settings::get( 'snapshots_enabled' ) );
		$this->assertSame( 1, Settings::get( 'snapshot_free_slots' ) );
		$this->assertSame( 7, Settings::get( 'snapshot_free_days' ) );
		$this->assertSame( 30, Settings::get( 'snapshot_paid_days' ) );
		$this->assertSame( 10, Settings::get( 'snapshot_max_slots' ) );
		$this->assertSame( 0, Settings::get( 'snapshot_reserve_gb' ) );

		$this->settings( array( 'task_wait' => 5, 'admin_email' => 'admin@example.com' ) );
		$r = $this->ready_vm();
		$this->assertFalse( Snapshots::enabled( $r ) );
		$this->assertSame( array(), array_filter( array_keys( Actions::offered( $r ) ), static fn( $a ) => 0 === strpos( $a, 'snapshot_' ) ) );
		$this->assertNotNull( Actions::check( $r, 'snapshot_take', array( 'label' => 'x' ) ) );
	}

	public function test_the_settings_are_clamped(): void {
		$clean = \BeltoftServices\Proxmox\ProxmoxSettings::sanitize( array( 'snapshot_free_days' => 0, 'snapshot_max_slots' => 999, 'snapshot_free_slots' => -3, 'snapshot_reserve_gb' => -1 ) );
		$this->assertSame( 1, $clean['snapshot_free_days'] );
		$this->assertSame( 50, $clean['snapshot_max_slots'] );
		$this->assertSame( 0, $clean['snapshot_free_slots'] );
		$this->assertSame( 0, $clean['snapshot_reserve_gb'] );
	}

	public function test_a_new_server_gets_snapshots_and_its_boot_volume_is_recorded_as_qcow2(): void {
		$r = $this->server();
		$this->assertSame( 'qcow2', $r->get( 'boot_format' ) );
		$this->assertTrue( Snapshots::enabled( $r ) );
		$this->assertArrayHasKey( 'snapshot_take', Actions::offered( $r ) );
		$this->assertArrayNotHasKey( 'snapshot_delete', Actions::offered( $r ), 'Nothing to delete yet.' );
		$this->assertArrayNotHasKey( 'snapshot_rollback', Actions::offered( $r ), 'Nothing to roll back to yet.' );
		$this->assertArrayNotHasKey( 'snapshot_expire', Actions::offered( $r ), 'The internal actions are never offered.' );
	}

	public function test_an_old_raw_server_gets_no_snapshots_and_neither_does_one_with_a_raw_extra_disk(): void {
		$this->on();
		add_filter( 'beltoft_services_proxmox_disk_format', static fn() => 'raw' );
		$raw = $this->ready_vm();
		remove_all_filters( 'beltoft_services_proxmox_disk_format' );
		$this->assertSame( 'raw', $raw->get( 'boot_format' ) );
		$this->assertFalse( Snapshots::enabled( $raw ) );
		$this->assertArrayNotHasKey( 'snapshot_take', Actions::offered( $raw ) );
		$this->assertNotNull( Actions::check( $raw, 'snapshot_take', array( 'label' => 'x' ) ) );
		$calls = count( $this->fake->calls() );
		Jobs::act( $raw->id(), 'snapshot_take', array( 'label' => 'x' ) );
		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $calls ), static fn( $l ) => false !== strpos( $l, '/snapshot' ) ), 'No snapshot call for a raw server.' );

		$server = $this->ready_vm();
		$this->assertTrue( Snapshots::enabled( $server ) );
		add_filter( 'beltoft_services_proxmox_disk_format', static fn() => 'raw' );
		$disk = $this->disk_for( $server );
		remove_all_filters( 'beltoft_services_proxmox_disk_format' );
		$this->assertStringEndsWith( '.raw', (string) $disk->get( 'volname' ) );
		$this->assertFalse( Snapshots::enabled( $this->fresh( $server ) ) );
	}

	public function test_an_unknown_boot_format_and_a_linked_server_get_none(): void {
		$r = $this->server();
		$r->set( array( 'boot_format' => null ) );
		$this->assertFalse( Snapshots::enabled( $this->fresh( $r ) ), 'A server built before the format was recorded is raw until proved otherwise.' );
		$r->set( array( 'boot_format' => 'qcow2', 'linked' => true ) );
		$this->assertFalse( Snapshots::enabled( $this->fresh( $r ) ) );
		$this->assertArrayNotHasKey( 'snapshot_take', Actions::offered( $this->fresh( $r ) ) );
	}

	public function test_slots_days_and_the_paid_seam(): void {
		$r = $this->server();
		$this->on( array( 'snapshot_free_slots' => 1, 'snapshot_max_slots' => 2 ) );
		$this->assertSame( 1, Snapshots::slots( $r ) );
		$this->assertSame( 7, Snapshots::days_for( $r ) );
		add_filter( 'beltoft_services_snapshot_paid_slots', static fn() => 5 );
		$this->assertSame( 2, Snapshots::slots( $r ), 'Free and paid slots never go above the maximum.' );
		$this->assertSame( 30, Snapshots::days_for( $r ), 'A server with a paid slot keeps its snapshots longer.' );
	}

	public function test_the_label_is_cleaned_and_must_be_unique_and_the_slots_must_not_be_full(): void {
		$r = $this->server();
		$this->on( array( 'snapshot_free_slots' => 2 ) );
		$this->assertSame( 'Before the upgrade 2.0_a-b', Snapshots::label_from( array( 'label' => "  Before the\n upgrade 2.0_a-b!! " ) ) );
		$this->assertSame( 40, strlen( Snapshots::label_from( array( 'label' => str_repeat( 'a', 90 ) ) ) ) );
		$this->assertMatchesRegularExpression( '/^Snapshot \d{4}-\d\d-\d\d \d\d:\d\d$/', Snapshots::label_from( array( 'label' => '!!!' ) ) );
		$this->assertMatchesRegularExpression( '/^Snapshot /', Snapshots::label_from( array() ) );

		$this->take( $r, 'One' );
		$r = $this->fresh( $r );
		$this->assertSame( 'You already have a snapshot with that name.', Actions::check( $r, 'snapshot_take', array( 'label' => 'one' ) ) );
		$this->take( $r, 'Two' );
		$this->assertSame( 'All your snapshot slots are in use. Delete a snapshot first.', Actions::check( $this->fresh( $r ), 'snapshot_take', array( 'label' => 'Three' ) ) );
		$this->assertArrayNotHasKey( 'snapshot_take', Actions::offered( $this->fresh( $r ) ) );
	}

	public function test_the_checks_of_delete_and_rollback(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'One' );
		$r     = $this->fresh( $r );
		$this->assertNull( Actions::check( $r, 'snapshot_delete', array( 'id' => $entry['id'] ) ) );
		$this->assertNotNull( Actions::check( $r, 'snapshot_delete', array( 'id' => 'bs0123456789' ) ) );
		$this->assertNotNull( Actions::check( $r, 'snapshot_delete', array( 'id' => array( 'x' ) ) ) );
		$this->assertNotNull( Actions::check( $r, 'snapshot_delete', array() ) );
		$this->assertTrue( Snapshots::owns( $r, $entry['id'] ) );
		$this->assertFalse( Snapshots::owns( $r, $entry['snap'] ), 'A Proxmox name is not an id.' );
		$this->assertStringContainsString( 'Type ROLLBACK', (string) Actions::check( $r, 'snapshot_rollback', array( 'id' => $entry['id'] ) ) );
		$this->assertStringContainsString( 'Type ROLLBACK', (string) Actions::check( $r, 'snapshot_rollback', array( 'id' => $entry['id'], 'confirm' => 'rollback' ) ) );
		$this->assertNull( Actions::check( $r, 'snapshot_rollback', array( 'id' => $entry['id'], 'confirm' => 'ROLLBACK' ) ) );
		$this->assertSame( 'Choose one of your snapshots.', Actions::check( $r, 'snapshot_rollback', array( 'id' => 'aaaaaaaa', 'confirm' => 'ROLLBACK' ) ) );
		$this->assertArrayHasKey( 'snapshot_delete', Actions::offered( $r ) );
		$this->assertArrayHasKey( 'snapshot_rollback', Actions::offered( $r ) );
		$this->assertArrayNotHasKey( 'snapshot_take', Actions::buttons( array( 'snapshot_take' => 'x', 'start' => 'y' ), $r ), 'The actions with arguments get forms.' );
	}

	public function test_the_actions_go_through_the_route_and_are_rate_limited(): void {
		$r     = $this->server();
		$owner = (int) $r->license()['user_id'];
		$this->assertSame( 202, $this->route( $r->id(), $owner, 'snapshot_take', array( 'label' => 'viaroute' ) )->get_status() );
		$this->assertSame( 403, $this->route( $r->id(), $owner, 'snapshot_rollback', array( 'id' => 'aaaaaaaa', 'confirm' => 'ROLLBACK' ) )->get_status(), 'Not offered: there is nothing to roll back to.' );
		$this->assertContains( $this->route( $r->id(), $owner, 'snapshot_expire' )->get_status(), array( 400, 403, 404, 422 ), 'The internal actions are no customer route.' );

		$statuses = array();
		foreach ( range( 1, 6 ) as $n ) {
			$statuses[] = $this->route( $r->id(), $owner, 'snapshot_take', array( 'label' => 'n' . $n ) )->get_status();
		}
		$this->assertSame( array( 202, 202, 202, 202, 429, 429 ), $statuses, 'Five takes an hour: the first one above counted.' );
	}

	// -- Take ------------------------------------------------------------------------------------

	public function test_a_snapshot_is_taken_and_covers_every_disk_including_backup_zero(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$this->assertStringContainsString( 'backup=0', (string) $this->config()['scsi1'] );

		$before = time();
		$entry  = $this->take( $r, 'Before upgrade' );
		$fresh  = $this->fresh( $r );

		$this->assertSame( 'ok', $entry['state'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}$/', $entry['id'] );
		$this->assertMatchesRegularExpression( '/^bs[a-f0-9]{10}$/', $entry['snap'] );
		$this->assertGreaterThanOrEqual( $before, $entry['taken_at'] );
		$this->assertSame( $entry['taken_at'] + 7 * DAY_IN_SECONDS, $entry['expires_at'] );
		$this->assertCount( 2, $entry['covers'] );
		$this->assertContains( explode( ',', (string) $this->config()['scsi0'] )[0], $entry['covers'], 'The boot disk is covered.' );
		$this->assertContains( 'disk-store:5000/' . $disk->get( 'volname' ), $entry['covers'], 'The extra disk with backup=0 is covered.' );
		$this->assertSame( Actions::agent_ok( $fresh ), $entry['consistent'] );
		$this->assertNull( $fresh->get( Snapshots::OP ) );
		$this->assertStringContainsString( 'Customer took the snapshot "Before upgrade"', $this->events_of( $r ) );

		$post = $this->calls( 'POST', '/qemu/5000/snapshot' );
		$this->assertCount( 1, $post );
		$this->assertSame( $entry['snap'], $post[0]['params']['snapname'] );
		$this->assertSame( 'Before upgrade', $post[0]['params']['description'] );
		$this->assertSame( 0, $post[0]['params']['vmstate'], 'Disk only: no memory state.' );
		$this->assertSame( array( $entry['snap'] ), array_keys( $this->fake->snapshots( 5000 ) ) );
		$this->assertNotEmpty( $this->calls( 'GET', '/storage/disk-store/status' ), 'The host space is read before a take.' );
	}

	public function test_a_snapshot_of_a_server_that_is_off_is_not_marked_consistent(): void {
		$r = $this->server();
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$entry = $this->take( $r, 'Off' );
		$this->assertFalse( $entry['consistent'] );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'], 'A take does not start or stop anything.' );
	}

	public function test_a_take_is_refused_when_the_storage_is_short_of_space_and_nothing_is_written(): void {
		$r = $this->server();
		$this->on( array( 'snapshot_reserve_gb' => 5000 ) ); // The fake storage is 4 TB.
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$this->act( $r, 'snapshot_take', array( 'label' => 'big' ) );

		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertSame( array(), $this->calls( 'POST', '/qemu/5000/snapshot' ) );
		$this->assertCount( 1, $this->admin_mails() );
		$this->assertStringContainsString( 'less than 5000 GB free', $this->events_of( $r ) );
	}

	public function test_the_space_guard_defaults_to_the_disk_reserve_and_fails_closed(): void {
		$r = $this->server();
		$this->assertSame( (int) Settings::get( 'disk_reserve_gb' ), Snapshots::reserve_gb() );
		$this->on( array( 'snapshot_reserve_gb' => 12 ) );
		$this->assertSame( 12, Snapshots::reserve_gb() );

		$this->fake->fail_next( 'GET', '#/storage/disk-store/status$#', array( 'status' => 500, 'message' => 'storage offline' ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'blind' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/qemu/5000/snapshot' ), 'A storage that cannot be read gives no snapshot.' );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
	}

	public function test_the_capacity_reading_is_wanted_when_snapshots_are_on(): void {
		set_transient( 'beltoft_services_disk_products', 'no', HOUR_IN_SECONDS );
		$this->settings( array( 'snapshots_enabled' => 0 ) );
		$this->assertFalse( DiskCapacity::wanted() );
		$this->settings( array( 'snapshots_enabled' => 1 ) );
		$this->assertTrue( DiskCapacity::wanted() );
	}

	public function test_a_take_waits_while_the_server_is_locked_and_writes_nothing(): void {
		$r = $this->server();
		$this->fake->set_lock( 5000, 'backup' );
		$this->act( $r, 'snapshot_take', array( 'label' => 'locked' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/qemu/5000/snapshot' ) );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );

		$this->fake->set_lock( 5000, '' );
		$this->assertSame( 'ok', $this->take( $r, 'locked' )['state'] );
	}

	public function test_a_take_waits_for_a_backup_that_is_running(): void {
		$r = $this->server();
		$this->fake->next_task( array( 'polls' => 99 ) );
		$upid = $this->fake->task( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => 5000, 'storage' => 'backup-store' ) );
		$this->assertIsString( $upid );
		$r->set( array( 'backup_task' => array( 'upid' => $upid, 'node' => 'node-a', 'started' => time() ) ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'busy' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/qemu/5000/snapshot' ) );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
	}

	public function test_a_task_that_is_still_running_is_waited_for_and_one_snapshot_is_made(): void {
		$r = $this->server();
		$this->fake->next_task( array( 'polls' => 10 ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'slow' ) );
		$mid = Snapshots::entries( $this->fresh( $r ) );
		$this->assertCount( 1, $mid );
		$this->assertSame( 'creating', $mid[0]['state'], 'Written down before Proxmox is asked, and not final while the task runs.' );
		$this->assertSame( 'take', $this->fresh( $r )->get( Snapshots::OP )['op'] );
		$this->assertArrayHasKey( 'snapshot_take', Actions::offered( $this->fresh( $r ) ), 'The retry of a take in flight stays offered.' );

		for ( $i = 0; $i < 4 && 'ok' !== ( Snapshots::entries( $this->fresh( $r ) )[0]['state'] ?? '' ); $i++ ) {
			$this->act( $r, 'snapshot_take', array( 'label' => 'slow' ) );
		}

		$this->assertSame( 'ok', Snapshots::entries( $this->fresh( $r ) )[0]['state'] );
		$this->assertCount( 1, $this->calls( 'POST', '/qemu/5000/snapshot' ), 'One snapshot, however many rounds.' );
		$this->assertNull( $this->fresh( $r )->get( Snapshots::OP ) );
	}

	public function test_a_crash_between_the_entry_and_the_task_finds_the_snapshot_and_does_not_repeat_it(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'once' );
		// The marker survived a crash after the snapshot was made: the retry finds it listed and finishes.
		$this->fresh( $r )->set( array( Snapshots::LIST => array( array_merge( $entry, array( 'state' => 'creating' ) ) ), Snapshots::OP => array( 'op' => 'take', 'id' => $entry['id'], 'phase' => 'sent', 'at' => time() ) ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'once' ) );
		$this->assertSame( 'ok', Snapshots::entries( $this->fresh( $r ) )[0]['state'] );
		$this->assertCount( 1, $this->calls( 'POST', '/qemu/5000/snapshot' ) );
		$this->assertNull( $this->fresh( $r )->get( Snapshots::OP ) );
	}

	public function test_a_failed_take_removes_its_entry_and_alerts_the_admin(): void {
		$r = $this->server();
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: snapshot failed - no space' ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'nope' ) );

		$fresh = $this->fresh( $r );
		$this->assertSame( array(), Snapshots::entries( $fresh ) );
		$this->assertNull( $fresh->get( Snapshots::OP ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ), 'A failed action never changes the service state.' );
		$this->assertNotEmpty( $this->admin_mails() );
		$this->assertStringContainsString( 'Snapshot failed', $this->events_of( $r ) );
		foreach ( array_filter( $this->mails, static fn( $m ) => 'admin@example.com' !== ( (array) $m['to'] )[0] ) as $mail ) {
			$this->assertStringNotContainsString( 'no space', (string) $mail['message'], 'Proxmox text never reaches the customer.' );
		}
	}

	public function test_a_snapshot_of_a_raw_volume_is_refused_by_the_plugin_and_by_the_fake(): void {
		$r = $this->server();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi5' => 'disk-store:5000/vm-5000-odd.raw,size=1G' ) );
		$err = $this->fake->request( 'POST', '/nodes/node-a/qemu/5000/snapshot', array( 'snapname' => 'bsaaaaaaaaaa' ) );
		$this->assertWPError( $err );
		$this->assertStringContainsString( 'snapshot feature is not available', $err->get_error_message() );

		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$asked = count( $this->calls( 'POST', '/qemu/5000/snapshot' ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'raw' ) );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertCount( $asked, $this->calls( 'POST', '/qemu/5000/snapshot' ), 'The plugin never asks Proxmox for it.' );
		$this->assertNotEmpty( $this->admin_mails() );
	}

	// -- Delete ----------------------------------------------------------------------------------

	public function test_a_snapshot_is_deleted_and_the_slot_is_free_again(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$r     = $this->ready_vm();
		$entry = $this->take( $r, 'gone' );
		$this->assertNotNull( Actions::check( $this->fresh( $r ), 'snapshot_take', array( 'label' => 'other' ) ) );

		$this->act( $r, 'snapshot_delete', array( 'id' => $entry['id'] ) );

		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		$this->assertCount( 1, $this->calls( 'DELETE', '/snapshot/' . $entry['snap'] ) );
		$this->assertNull( Actions::check( $this->fresh( $r ), 'snapshot_take', array( 'label' => 'other' ) ) );
	}

	public function test_deleting_a_snapshot_that_is_already_gone_just_drops_it(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'vanished' );
		$this->fake->task( 'DELETE', '/nodes/node-a/qemu/5000/snapshot/' . $entry['snap'] );
		$this->act( $r, 'snapshot_delete', array( 'id' => $entry['id'] ) );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertCount( 1, $this->calls( 'DELETE', '/snapshot/' . $entry['snap'] ), 'Only the one that made it vanish: no second delete.' );
	}

	public function test_a_snapshot_the_plugin_does_not_own_is_never_touched(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'ours' );
		$this->assertIsString( $this->fake->task( 'POST', '/nodes/node-a/qemu/5000/snapshot', array( 'snapname' => 'manual' ) ) );
		$this->assertIsString( $this->fake->task( 'POST', '/nodes/node-a/qemu/5000/snapshot', array( 'snapname' => 'bs0123456789' ) ) );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();

		$this->act( $r, 'snapshot_delete', array( 'id' => 'manual' ) );
		$this->act( $r, 'snapshot_delete', array( 'id' => 'bs0123456789' ) );
		$this->assertSame( array(), $this->calls( 'DELETE', '/snapshot/manual' ) );
		$this->assertSame( array(), $this->calls( 'DELETE', '/snapshot/bs0123456789' ) );

		$this->act( $r, 'snapshot_purge' );
		$this->act( $r, 'snapshot_expire' );
		$this->act( $r, 'snapshot_sync' );
		$this->assertSame( array(), $this->calls( 'DELETE', '/snapshot/manual' ) );
		$this->assertSame( array(), $this->calls( 'DELETE', '/snapshot/bs0123456789' ) );
		$this->assertArrayHasKey( 'manual', $this->fake->snapshots( 5000 ) );
		$this->assertArrayHasKey( 'bs0123456789', $this->fake->snapshots( 5000 ) );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ), 'Purge deleted the one that is ours.' );
		$this->assertArrayNotHasKey( $entry['snap'], $this->fake->snapshots( 5000 ) );
		$this->assertNotEmpty( $this->admin_mails(), 'A bs snapshot nobody knows about is reported, not deleted.' );
	}

	// -- Internal actions ------------------------------------------------------------------------

	public function test_the_driver_takes_the_internal_actions_and_the_expiry_deletes_only_what_is_due(): void {
		$driver = Registry::get( 'proxmox' );
		$this->assertInstanceOf( InternalActions::class, $driver );
		$this->assertSame( array( 'snapshot_expire', 'snapshot_purge', 'snapshot_sync' ), $driver->internal_actions() );

		$r   = $this->server();
		$old = $this->take( $r, 'old' );
		$new = $this->take( $r, 'new' );
		$list = Snapshots::entries( $this->fresh( $r ) );
		$list[0]['expires_at'] = time() - 10;
		$this->fresh( $r )->set( array( Snapshots::LIST => $list ) );

		$this->act( $r, 'snapshot_expire' );

		$left = Snapshots::entries( $this->fresh( $r ) );
		$this->assertCount( 1, $left );
		$this->assertSame( $new['id'], $left[0]['id'] );
		$this->assertSame( array( $new['snap'] ), array_keys( $this->fake->snapshots( 5000 ) ) );
		unset( $old );
	}

	public function test_the_internal_actions_also_run_on_a_paused_server_and_not_on_a_deleted_one(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'kept' );
		$list  = Snapshots::entries( $this->fresh( $r ) );
		$list[0]['expires_at'] = time() - 10;
		$this->fresh( $r )->set( array( Snapshots::LIST => $list ) );
		$this->assertTrue( $this->fresh( $r )->transition( 'paused', 'test' ) );

		$this->act( $r, 'snapshot_expire' );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ), 'Expiry runs while the server is paused.' );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		unset( $entry );
	}

	public function test_purge_all_and_drop_covering_are_seams_for_the_interactions(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$one  = $this->take( $r, 'one' );
		$two  = $this->take( $r, 'two' );
		$boot = $one['covers'][0];

		$result = ( new Snapshots( $this->fresh( $r ) ) )->drop_covering( 'disk-store:5000/' . $disk->get( 'volname' ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ), 'Both snapshots cover the extra disk.' );

		$three = $this->take( $r, 'three' );
		$this->assertSame( array(), ( new Snapshots( $this->fresh( $r ) ) )->drop_covering( 'disk-store:5000/vm-5000-nothing.qcow2' )->meta() );
		$this->assertCount( 1, Snapshots::entries( $this->fresh( $r ) ), 'A volume nothing covers deletes nothing.' );

		$result = ( new Snapshots( $this->fresh( $r ) ) )->purge_all();
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ) );
		unset( $two, $three, $boot );
	}

	public function test_sync_drops_an_entry_proxmox_no_longer_lists(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'lost' );
		$this->fake->task( 'DELETE', '/nodes/node-a/qemu/5000/snapshot/' . $entry['snap'] );
		$this->assertCount( 1, Snapshots::entries( $this->fresh( $r ) ) );
		$this->act( $r, 'snapshot_sync' );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
	}

	public function test_forget_all_clears_the_list_and_reads_the_remote_list(): void {
		$r = $this->server();
		$this->take( $r, 'a' );
		$result = ( new Snapshots( $this->fresh( $r ) ) )->forget_all();
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );
	}

	// -- FakeClient: the live facts --------------------------------------------------------------

	public function test_the_fake_rolls_back_to_an_older_snapshot_while_the_later_ones_survive(): void {
		$this->server();
		$vm = '/nodes/node-a/qemu/5000';
		$this->assertIsString( $this->fake->task( 'POST', $vm . '/snapshot', array( 'snapname' => 'a1' ) ) );
		$this->fake->request( 'PUT', $vm . '/config', array( 'cores' => 4 ) );
		$this->assertIsString( $this->fake->task( 'POST', $vm . '/snapshot', array( 'snapname' => 'b2' ) ) );
		$this->assertIsString( $this->fake->task( 'POST', $vm . '/snapshot/a1/rollback' ) );
		$this->assertSame( 2, (int) $this->config()['cores'] );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'], 'Proxmox leaves the VM stopped after a rollback.' );
		$this->assertSame( array( 'a1', 'b2' ), array_keys( $this->fake->snapshots( 5000 ) ) );
		$this->assertIsString( $this->fake->task( 'POST', $vm . '/snapshot/b2/rollback' ), 'Rollback to a later one works too.' );
		$this->assertSame( 4, (int) $this->config()['cores'] );
	}

	public function test_the_fake_gives_a_grown_disk_its_old_size_back_and_keeps_a_later_disk_as_unused(): void {
		$r    = $this->server();
		$disk = $this->disk_for( $r );
		$vm   = '/nodes/node-a/qemu/5000';
		$this->fake->task( 'POST', $vm . '/snapshot', array( 'snapname' => 'a1' ) );
		$this->fake->task( 'PUT', $vm . '/resize', array( 'disk' => 'scsi1', 'size' => '60G' ) );
		$late = $this->disk_for( $r );
		$this->assertStringContainsString( 'size=60G', $this->config()['scsi1'] );
		$this->fake->task( 'POST', $vm . '/snapshot/a1/rollback' );

		$config = $this->config();
		$this->assertStringContainsString( 'size=50G', $config['scsi1'] );
		$this->assertArrayNotHasKey( 'scsi2', $config );
		$this->assertSame( 'disk-store:5000/' . $late->get( 'volname' ), $config['unused0'] );
		$cfg = $this->fake->request( 'GET', $vm . '/snapshot/a1/config' );
		$this->assertStringContainsString( 'backup=0', $cfg['scsi1'], 'The snapshot holds the backup=0 disk too.' );
		unset( $disk );
	}

	public function test_the_fake_drops_the_snapshots_with_the_vm_and_with_a_restore(): void {
		$r  = $this->server();
		$vm = '/nodes/node-a/qemu/5000';
		$this->fake->task( 'POST', $vm . '/snapshot', array( 'snapname' => 'a1' ) );
		$this->assertCount( 1, $this->fake->snapshots( 5000 ) );

		$volid = $this->backup_volid( $r );
		$this->fake->task( 'POST', $vm . '/status/stop' );
		$this->fake->task( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 5000, 'archive' => $volid, 'force' => 1, 'storage' => 'disk-store' ) );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ), 'A restore recreates the VM.' );

		$this->fake->task( 'POST', $vm . '/snapshot', array( 'snapname' => 'a2' ) );
		$this->assertIsString( $this->fake->task( 'DELETE', $vm ) );
		$this->assertSame( array(), $this->fake->snapshots( 5000 ), 'Deleting the VM purges them.' );
	}

	public function test_the_fake_refuses_a_bad_or_used_name_and_a_locked_vm(): void {
		$this->server();
		$vm = '/nodes/node-a/qemu/5000';
		foreach ( array( '1abc', 'a b', 'current', str_repeat( 'a', 41 ) ) as $name ) {
			$this->assertWPError( $this->fake->request( 'POST', $vm . '/snapshot', array( 'snapname' => $name ) ), $name );
		}
		$this->assertIsString( $this->fake->task( 'POST', $vm . '/snapshot', array( 'snapname' => 'ok1' ) ) );
		$this->assertWPError( $this->fake->request( 'POST', $vm . '/snapshot', array( 'snapname' => 'ok1' ) ) );
		$this->fake->set_lock( 5000, 'backup' );
		$this->assertWPError( $this->fake->request( 'POST', $vm . '/snapshot', array( 'snapname' => 'ok2' ) ) );
		$this->assertWPError( $this->fake->request( 'DELETE', $vm . '/snapshot/ok1' ) );
		$this->assertWPError( $this->fake->request( 'POST', $vm . '/snapshot/ok1/rollback' ) );
	}

	// -- Roll back -------------------------------------------------------------------------------

	/**
	 * A server with an extra disk and a snapshot A; afterwards the plan is bigger (boot 40, extra disk 60), a
	 * second extra disk is attached, and a snapshot B is taken.
	 *
	 * @return array{0: Resource, 1: Resource, 2: Resource, 3: array<string, mixed>, 4: array<string, mixed>}
	 */
	private function after_changes(): array {
		$server = $this->server();
		$one    = $this->disk_for( $server );
		$a      = $this->take( $server, 'A' );

		$plan = (array) $this->fresh( $server )->get( 'plan' );
		$plan['disk_gb'] = 40;
		$this->assertTrue( $this->fresh( $server )->change_plan( $plan, 'Plan changed' ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'ready', $this->fresh( $server )->get( 'state' ) );

		$plan = (array) $one->get( 'plan' );
		$plan['disk_gb'] = 60;
		$one->set( array( 'plan' => $plan ) );
		$grown = Registry::get( 'proxmox-disk' )->update_plan( $this->fresh( $one ) );
		$this->assertTrue( $grown->is_done(), $grown->error() );

		$two = $this->disk_for( $this->fresh( $server ) );
		$b   = $this->take( $server, 'B' );

		$config = $this->config();
		$this->assertStringContainsString( 'size=40G', $config['scsi0'] );
		$this->assertStringContainsString( 'size=60G', $config['scsi1'] );
		$this->assertArrayHasKey( 'scsi2', $config );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		return array( $server, $one, $two, $a, $b );
	}

	private function rollback( Resource $r, array $entry ): void {
		$this->act( $r, 'snapshot_rollback', array( 'id' => $entry['id'], 'confirm' => 'ROLLBACK' ) );
	}

	public function test_a_rollback_puts_the_paid_state_back_and_starts_the_server(): void {
		list( $server, $one, $two, $a, $b ) = $this->after_changes();
		$seen = count( $this->fake->calls() );

		$this->rollback( $server, $a );

		$fresh = $this->fresh( $server );
		$this->assertNull( $fresh->get( Snapshots::ROLLBACK ), (string) $fresh->get( 'last_error' ) );
		$this->assertStringContainsString( 'Server rolled back to the snapshot "A"', $this->events_of( $server ) );

		// Proxmox left the server stopped with the old config; the plugin attached the later disk again and grew the extra disk.
		$config = $this->config();
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'], 'The plan change starts it next.' );
		$this->assertStringContainsString( 'size=30G', $config['scsi0'], 'The boot disk is grown by the plan change that follows.' );
		$this->assertStringContainsString( 'size=60G', $config['scsi1'], 'The extra disk is grown back to its paid size.' );
		$this->assertStringStartsWith( 'disk-store:5000/' . $two->get( 'volname' ), $config['scsi2'], 'The disk bought after the snapshot is attached again.' );
		$this->assertCount( 0, array_filter( array_keys( $config ), static fn( $k ) => 0 === strpos( (string) $k, 'unused' ) ) );
		$this->assertTrue( (bool) $fresh->get( 'plan_dirty' ) );
		$this->assertTrue( (bool) $fresh->get( 'was_running' ) );
		$this->assertStringContainsString( 'Plan re-applied after rollback', $this->events_of( $server ) );

		Jobs::run( $server->id() );
		$config = $this->config();
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'Started again: it was running before.' );
		$this->assertStringContainsString( 'size=40G', $config['scsi0'], 'The boot disk is back at the paid size.' );
		$this->assertSame( array( $a['snap'], $b['snap'] ), array_keys( $this->fake->snapshots( 5000 ) ), 'Both snapshots survive the rollback to the older one.' );
		$this->assertCount( 2, Snapshots::entries( $this->fresh( $server ) ) );
		$this->assertSame( 'ready', $this->fresh( $one )->get( 'state' ) );
		$this->assertSame( 'ready', $this->fresh( $two )->get( 'state' ) );

		$lines = array_slice( $this->lines(), $seen );
		$at    = static function ( string $line ) use ( $lines ) {
			$found = array_keys( array_filter( $lines, static fn( $l ) => $l === $line ) );

			return $found ? $found[0] : null;
		};
		$roll    = $at( 'POST /nodes/node-a/qemu/5000/snapshot/' . $a['snap'] . '/rollback' );
		$attach  = $at( 'POST /nodes/node-a/qemu/5000/config' );
		$grow    = $at( 'PUT /nodes/node-a/qemu/5000/resize' );
		$start   = $at( 'POST /nodes/node-a/qemu/5000/status/start' );
		$this->assertNotNull( $roll );
		$this->assertLessThan( $attach, $roll );
		$this->assertLessThan( $grow, $attach, 'Attach before grow.' );
		$this->assertLessThan( $start, $grow, 'Grow before the start.' );
		$this->assertSame( array(), array_filter( $lines, static fn( $l ) => 'DELETE' === substr( $l, 0, 6 ) ), 'A rollback deletes nothing.' );
	}

	public function test_a_server_that_was_off_stays_off_after_a_rollback(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		\BeltoftServices\Proxmox\Operations::remember_power( $server->id(), 'stopped' );

		$this->rollback( $server, $a );

		$this->assertNull( $this->fresh( $server )->get( Snapshots::ROLLBACK ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->assertCount( 1, $this->calls( 'POST', '/status/start' ), 'Only the first start, when the server was built.' );
	}

	public function test_a_rollback_stops_the_server_gracefully_first(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$seen   = count( $this->fake->calls() );
		$this->rollback( $server, $a );
		$lines = array_slice( $this->lines(), $seen );
		$this->assertContains( 'POST /nodes/node-a/qemu/5000/status/shutdown', $lines );
		$this->assertLessThan( array_search( 'POST /nodes/node-a/qemu/5000/snapshot/' . $a['snap'] . '/rollback', $lines, true ), array_search( 'POST /nodes/node-a/qemu/5000/status/shutdown', $lines, true ) );
	}

	public function test_a_rollback_task_that_is_still_running_is_waited_for_not_started_again(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$this->fake->next_task( array() );                // The shutdown.
		$this->fake->next_task( array( 'polls' => 10 ) ); // The rollback.

		$this->rollback( $server, $a );
		$this->assertCount( 1, $this->calls( 'POST', '/rollback' ) );
		$this->assertSame( 'rolling', $this->fresh( $server )->get( Snapshots::ROLLBACK )['phase'] );
		$this->assertArrayHasKey( 'snapshot_rollback', Actions::offered( $this->fresh( $server ) ), 'A rollback in flight is finished whatever the switch says.' );

		for ( $i = 0; $i < 4 && null !== $this->fresh( $server )->get( Snapshots::ROLLBACK ); $i++ ) {
			$this->rollback( $server, $a );
		}

		$this->assertCount( 1, $this->calls( 'POST', '/rollback' ), 'One rollback, however many rounds.' );
		$this->assertNull( $this->fresh( $server )->get( Snapshots::ROLLBACK ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_a_failed_rollback_starts_the_server_again_and_tells_the_admin_and_the_customer(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$this->fake->next_task( array() ); // The shutdown.
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: rollback failed - storage error' ) );

		$this->rollback( $server, $a );

		$fresh = $this->fresh( $server );
		$this->assertNull( $fresh->get( Snapshots::ROLLBACK ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'A server that ran is started again.' );
		$this->assertStringContainsString( 'Rollback failed', $this->events_of( $server ) );
		$this->assertNotEmpty( $this->admin_mails() );
		foreach ( array_filter( $this->mails, static fn( $m ) => 'admin@example.com' !== ( (array) $m['to'] )[0] ) as $mail ) {
			$this->assertStringNotContainsString( 'storage error', (string) $mail['message'], 'Proxmox text never reaches the customer.' );
		}
		$this->assertCount( 1, Snapshots::entries( $fresh ), 'The snapshot is still there.' );
	}

	public function test_a_rollback_is_refused_while_an_extra_disk_is_detached_and_works_again_after(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		$a      = $this->take( $server, 'A' );

		$paused = Registry::get( 'proxmox-disk' )->pause( $this->fresh( $disk ) );
		$this->assertTrue( $paused->is_done(), $paused->error() );
		$disk = $this->fresh( $disk );
		$disk->set( $paused->meta() );
		$this->assertTrue( $this->fresh( $disk )->transition( 'paused', 'test' ) );

		$text = 'This server has an extra disk that is detached because its subscription is not active. Rolling back is possible again once the disk is active or removed.';
		$this->assertSame( $text, Actions::disk_block( $this->fresh( $server ), 'rollback' ) );
		$this->assertSame( $text, Actions::check( $this->fresh( $server ), 'snapshot_rollback', array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' ) ) );
		$calls = count( $this->fake->calls() );
		$this->rollback( $server, $a );
		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $calls ), static fn( $l ) => false !== strpos( $l, '/rollback' ) ), 'The server is not touched.' );

		// A failed disk needs support.
		$this->assertTrue( $this->fresh( $disk )->transition( 'failed', 'test' ) );
		$this->assertStringContainsString( 'needs attention from support before a rollback', (string) Actions::disk_block( $this->fresh( $server ), 'rollback' ) );
	}

	public function test_a_rollback_in_flight_finishes_when_a_disk_changes_between_rounds(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$this->fresh( $server )->set( array( Snapshots::ROLLBACK => array( 'id' => $a['id'], 'phase' => 'rolled', 'was_running' => true, 'at' => time() ) ) );
		$this->assertNull( Actions::check( $this->fresh( $server ), 'snapshot_rollback', array( 'id' => $a['id'] ) ), 'No confirm word is asked again for a rollback under way.' );
	}

	public function test_a_rollback_to_a_snapshot_that_left_proxmox_is_refused_and_dropped(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$this->fake->task( 'DELETE', '/nodes/node-a/qemu/5000/snapshot/' . $a['snap'] );
		$this->rollback( $server, $a );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $server ) ) );
		$this->assertSame( array(), $this->calls( 'POST', '/rollback' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'Nothing was stopped.' );
	}

	public function test_the_rolled_back_hook_runs_with_the_disks_back_and_before_the_plan_is_queued(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$seen   = array();
		add_filter(
			'beltoft_services_snapshot_rolled_back',
			function ( $result, $resource, $record ) use ( &$seen ) {
				$seen[] = array( $this->fake->vm( 5000 )['status'], (int) $record['vmid'], $resource instanceof Resource, (bool) $resource->get( 'plan_dirty' ) );

				return $result;
			},
			10,
			3
		);

		$this->rollback( $server, $a );

		$this->assertSame( array( array( 'stopped', 5000, true, false ) ), $seen );
		$this->assertTrue( (bool) $this->fresh( $server )->get( 'plan_dirty' ) );
	}

	public function test_a_hook_that_asks_for_a_retry_holds_the_plan_back(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		add_filter( 'beltoft_services_snapshot_rolled_back', static fn() => StepResult::retry( 0, 'hook: not yet' ) );
		$this->rollback( $server, $a );
		$fresh = $this->fresh( $server );
		$this->assertEmpty( $fresh->get( 'plan_dirty' ), 'The plan is not queued while the hook asks for a retry.' );
		$this->assertSame( 'rolled', $fresh->get( Snapshots::ROLLBACK )['phase'], 'It goes on at the same place.' );
		remove_all_filters( 'beltoft_services_snapshot_rolled_back' );
		$this->rollback( $server, $a );
		$this->assertNull( $this->fresh( $server )->get( Snapshots::ROLLBACK ) );
		$this->assertCount( 1, $this->calls( 'POST', '/rollback' ), 'The rollback itself ran once.' );
	}

	public function test_a_restore_still_reapplies_the_plan_as_before(): void {
		// The restore shares RestoreSteps with the rollback: its texts and its marker are unchanged.
		$server = $this->server();
		$volid  = $this->backup_volid( $server );
		Jobs::act( $server->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
		$this->assertNull( $this->fresh( $server )->get( 'restore' ) );
		$this->assertStringContainsString( 'Plan re-applied after restore', $this->events_of( $server ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	// -- Review fixes ----------------------------------------------------------------------------

	private function stale_op( Resource $r, array $entry, int $age = 3 * HOUR_IN_SECONDS ): void {
		$this->fresh( $r )->set( array( Snapshots::LIST => array( array_merge( $entry, array( 'state' => 'creating' ) ) ), Snapshots::OP => array( 'op' => 'take', 'id' => $entry['id'], 'phase' => 'sent', 'at' => time() - $age ) ) );
	}

	public function test_a_take_without_a_label_resumes_by_its_op_not_by_its_label(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$r = $this->ready_vm();
		$this->fake->next_task( array( 'polls' => 10 ) );
		$this->act( $r, 'snapshot_take' );
		$entries = Snapshots::entries( $this->fresh( $r ) );
		$this->assertCount( 1, $entries );

		// The retry minutes later makes another date label: the op still owns the entry.
		$this->fresh( $r )->set( array( Snapshots::LIST => array( array_merge( $entries[0], array( 'label' => 'Snapshot 2001-01-01 00:00' ) ) ) ) );
		$this->assertNull( Snapshots::check( $this->fresh( $r ), 'snapshot_take', array() ) );

		for ( $i = 0; $i < 4 && 'ok' !== ( Snapshots::entries( $this->fresh( $r ) )[0]['state'] ?? '' ); $i++ ) {
			$this->act( $r, 'snapshot_take' );
		}

		$this->assertCount( 1, Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertSame( 'ok', Snapshots::entries( $this->fresh( $r ) )[0]['state'] );
		$this->assertCount( 1, $this->calls( 'POST', '/qemu/5000/snapshot' ) );
	}

	public function test_a_snapshot_that_vanishes_during_a_rollback_ends_it_cleanly(): void {
		$server = $this->server();
		$a      = $this->take( $server, 'A' );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fresh( $server )->set( array( Snapshots::LIST => array(), Snapshots::ROLLBACK => array( 'id' => $a['id'], 'phase' => 'stopping', 'was_running' => true, 'at' => time() ) ) );

		$this->rollback( $server, $a );

		$fresh = $this->fresh( $server );
		$this->assertNull( $fresh->get( Snapshots::ROLLBACK ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'A server that ran is started again.' );
		$this->assertTrue( (bool) $fresh->get( 'plan_dirty' ), 'The plan is queued again.' );
		$this->assertNotEmpty( $this->mails, 'The customer is told, and the admin alerted.' );
		$this->assertNotEmpty( $this->admin_mails() );
		$this->assertSame( array(), $this->calls( 'POST', '/rollback' ) );
	}

	public function test_nothing_else_starts_while_a_take_or_a_rollback_is_open(): void {
		$r = $this->server();
		$a = $this->take( $r, 'A' );
		$this->fake->next_task( array( 'polls' => 10 ) );
		$this->act( $r, 'snapshot_take', array( 'label' => 'slow' ) );
		$open  = $this->fresh( $r );
		$slow  = array_values( array_filter( Snapshots::entries( $open ), static fn( $e ) => 'slow' === $e['label'] ) )[0];
		$busy  = 'Another snapshot action is still running on this server. Try again in a few minutes.';
		$roll  = array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' );

		$this->assertSame( 'creating', $slow['state'] );
		$this->assertSame( $busy, Snapshots::check( $open, 'snapshot_delete', array( 'id' => $a['id'] ) ) );
		$this->assertSame( $busy, Snapshots::check( $open, 'snapshot_rollback', $roll ) );
		$this->assertSame( $busy, Snapshots::check( $open, 'snapshot_delete', array( 'id' => $slow['id'] ) ), 'An entry that is still being made is not deleted either.' );
		$this->assertNull( Snapshots::check( $open, 'snapshot_take', array( 'label' => 'slow' ) ), 'The take that owns the op resumes.' );

		$this->act( $r, 'snapshot_delete', array( 'id' => $a['id'] ) );
		$this->assertCount( 2, Snapshots::entries( $this->fresh( $r ) ) );
		$this->assertSame( array(), $this->calls( 'DELETE', '/snapshot/' ) );

		// A rollback in flight: another id, a take and a delete are refused; its own id resumes.
		$b = $this->take( $this->server(), 'unused' );
		unset( $b );
		$this->fresh( $r )->set( array( Snapshots::OP => null, Snapshots::LIST => array( $a ), Snapshots::ROLLBACK => array( 'id' => $a['id'], 'phase' => 'rolling', 'was_running' => true, 'at' => time() ) ) );
		$mid = $this->fresh( $r );
		$this->assertSame( $busy, Snapshots::check( $mid, 'snapshot_rollback', array( 'id' => 'deadbeef', 'confirm' => 'ROLLBACK' ) ) );
		$this->assertSame( $busy, Snapshots::check( $mid, 'snapshot_take', array( 'label' => 'x' ) ) );
		$this->assertSame( $busy, Snapshots::check( $mid, 'snapshot_delete', array( 'id' => $a['id'] ) ) );
		$this->assertNull( Snapshots::check( $mid, 'snapshot_rollback', $roll ) );
		$this->assertSame( $a['id'], $this->fresh( $r )->get( Snapshots::ROLLBACK )['id'], 'The marker was not overwritten.' );
	}

	public function test_an_entry_that_is_not_ok_is_not_deleted_by_a_customer(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'A' );
		$this->fresh( $r )->set( array( Snapshots::LIST => array( array_merge( $entry, array( 'state' => 'creating' ) ) ), Snapshots::OP => null ) );
		$this->assertSame( 'Choose one of your snapshots.', Snapshots::check( $this->fresh( $r ), 'snapshot_delete', array( 'id' => $entry['id'] ) ) );
		$this->act( $r, 'snapshot_delete', array( 'id' => $entry['id'] ) );
		$this->assertSame( array(), $this->calls( 'DELETE', '/snapshot/' ) );
		$this->assertCount( 1, Snapshots::entries( $this->fresh( $r ) ) );
	}

	public function test_a_snapshot_action_never_takes_over_the_task_of_another_job(): void {
		$r = $this->server();
		$upid = $this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/reboot' );
		$this->assertIsString( $upid );
		$foreign = array( 'step' => 'reboot', 'phase' => '', 'upid' => $upid, 'node' => 'node-a' );
		$r->set( array( 'task' => $foreign ) );
		$polls = count( $this->calls( 'GET', '/tasks/' ) );

		$this->act( $r, 'snapshot_take', array( 'label' => 'early' ) );

		$this->assertSame( $foreign, $this->fresh( $r )->get( 'task' ), 'The other job\'s task is neither polled to the end nor cleared.' );
		$this->assertSame( $polls, count( $this->calls( 'GET', '/tasks/' ) ), 'It was not even polled.' );
		$this->assertSame( array(), $this->calls( 'POST', '/qemu/5000/snapshot' ) );
		$this->assertSame( array(), Snapshots::entries( $this->fresh( $r ) ) );

		// Once the owner has cleared its task, the snapshot goes ahead.
		$this->fresh( $r )->set( array( 'task' => null ) );
		$this->assertSame( 'ok', $this->take( $r, 'early' )['state'] );
	}

	public function test_a_take_that_ran_out_of_time_is_closed_and_the_entry_dropped(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'A' );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$gone        = array_merge( $entry, array( 'id' => 'abcdef01', 'snap' => 'bs0000000000', 'label' => 'ghost' ) );
		$this->fresh( $r )->set( array( Snapshots::LIST => array( $entry, array_merge( $gone, array( 'state' => 'creating' ) ) ), Snapshots::OP => array( 'op' => 'take', 'id' => 'abcdef01', 'phase' => 'sent', 'at' => time() - 3 * HOUR_IN_SECONDS ) ) );

		$this->act( $r, 'snapshot_delete', array( 'id' => $entry['id'] ) );

		$fresh = $this->fresh( $r );
		$this->assertNull( $fresh->get( Snapshots::OP ) );
		$this->assertNull( Snapshots::entry( $fresh, 'abcdef01' ), 'A creating entry whose snapshot does not exist is dropped.' );
		$this->assertCount( 1, $this->admin_mails(), 'The admin hears about it once.' );
	}

	public function test_a_take_that_ran_out_of_time_keeps_a_snapshot_that_exists(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'A' );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$this->stale_op( $r, $entry );

		$this->act( $r, 'snapshot_sync' );

		$fresh = $this->fresh( $r );
		$this->assertNull( $fresh->get( Snapshots::OP ) );
		$this->assertSame( 'ok', Snapshots::entry( $fresh, $entry['id'] )['state'] );
		$this->assertCount( 1, $this->admin_mails() );

		$this->act( $r, 'snapshot_sync' );
		$this->assertCount( 1, $this->admin_mails(), 'Once.' );
	}

	public function test_a_take_inside_its_age_limit_stays_open(): void {
		$r     = $this->server();
		$entry = $this->take( $r, 'A' );
		$this->stale_op( $r, $entry, HOUR_IN_SECONDS );
		$this->act( $r, 'snapshot_sync' );
		$this->assertSame( 'take', $this->fresh( $r )->get( Snapshots::OP )['op'] );
	}

	public function test_snapshots_are_not_offered_while_something_else_changes_the_server(): void {
		$r = $this->server();
		$this->take( $r, 'A' );
		$a = Snapshots::entries( $this->fresh( $r ) )[0];
		$this->assertTrue( Snapshots::enabled( $this->fresh( $r ) ) );

		$cases = array(
			'restore marker' => array( 'restore' => array( 'volid' => 'x', 'phase' => 'task' ) ),
			'wipe'           => array( 'wipe' => true ),
			'pending op'     => array( 'op' => 'update_plan' ),
			'plan change'    => array( 'plan_dirty' => true ),
			'not ready'      => array( 'state' => 'provisioning' ),
			'not desired'    => array( 'desired' => 'paused' ),
		);

		foreach ( $cases as $name => $meta ) {
			$r = $this->fresh( $r );
			$old = array();
			foreach ( $meta as $k => $unused ) {
				$old[ $k ] = $r->get( $k );
			}
			$this->assertTrue( $r->set( $meta ), $name );
			$busy = $this->fresh( $r );
			$this->assertFalse( Snapshots::enabled( $busy ), $name );
			$this->assertArrayNotHasKey( 'snapshot_take', Snapshots::offered( $busy ), $name );
			$this->assertArrayNotHasKey( 'snapshot_rollback', Snapshots::offered( $busy ), $name );
			$this->assertNotNull( Snapshots::check( $busy, 'snapshot_take', array( 'label' => 'n' ) ), $name );
			$this->assertNotNull( Snapshots::check( $busy, 'snapshot_rollback', array( 'id' => $a['id'], 'confirm' => 'ROLLBACK' ) ), $name );
			$this->assertTrue( $this->fresh( $r )->set( $old ), $name );
		}

		$this->assertTrue( Snapshots::enabled( $this->fresh( $r ) ) );
	}

	public function test_the_disk_block_texts_are_whole_sentences_per_action(): void {
		$server = $this->server();
		$disk   = $this->disk_for( $server );
		$disk->set( array( 'state' => 'paused' ) );
		foreach ( array( 'restore' => 'Restore is possible', 'reinstall' => 'Reinstall is possible', 'rollback' => 'Rolling back is possible' ) as $action => $part ) {
			$this->assertStringContainsString( $part, (string) Actions::disk_block( $this->fresh( $server ), $action ) );
		}
		$disk->set( array( 'state' => 'failed' ) );
		foreach ( array( 'restore', 'reinstall' ) as $action ) {
			$this->assertStringContainsString( 'before a ' . $action, (string) Actions::disk_block( $this->fresh( $server ), $action ) );
		}
		$this->assertStringContainsString( 'before a rollback', (string) Actions::disk_block( $this->fresh( $server ), 'rollback' ) );
	}
}
