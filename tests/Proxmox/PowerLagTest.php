<?php
/**
 * A status that lags behind a power task: no false error on start, reinstall and delete. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Pve\DiskCapacity;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once __DIR__ . '/Backup/BackupTestCase.php';

class PowerLagTest extends BackupTestCase {
	private const BOOT = 'disk-store:5000/vm-5000-disk-0.raw';

	private function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk    = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		Jobs::run( $disk->id() );

		return Resource::find( $disk->id() );
	}

	/** A server with a real boot volume and one extra disk. */
	private function server_with_disk(): Resource {
		$server = $this->ready_vm();
		$this->assertSame( self::BOOT, $this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-disk-0.raw', 'size' => '30G', 'vmid' => 5000, 'format' => 'raw' ) ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => self::BOOT . ',size=30G' ) ) );
		$disk = $this->disk_for( $server );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );

		return Resource::find( $server->id() );
	}

	private function paths( int $seen ): array {
		return array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], array_slice( $this->fake->calls(), $seen ) );
	}

	private function power( int $seen ): array {
		return array_values( array_filter( $this->paths( $seen ), static fn( $l ) => (bool) preg_match( '#/status/(shutdown|stop|start)$#', $l ) ) );
	}

	private function rebuild( int $id ): void {
		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $id )->get( 'state' ); $i++ ) {
			Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $id );
			$this->run_jobs();
		}
		$this->assertSame( 'ready', Resource::find( $id )->get( 'state' ), (string) Resource::find( $id )->get( 'last_error' ) );
	}

	// -- 2.3 start re-reads ----------------------------------------------------------------------

	public function test_a_start_whose_status_lags_by_one_read_is_no_error(): void {
		$r = $this->ready_vm();
		Jobs::act( $r->id(), 'shutdown', array() );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->fake->lag_status( 5000, 1 );
		$this->pauses = array();
		$before       = count( $this->calls( 'POST', '/status/start' ) );

		Jobs::act( $r->id(), 'start', array() );

		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( array( 2 ), $this->pauses, 'One re-read, two seconds later.' );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'last_error' ) );
		$this->assertCount( $before + 1, $this->calls( 'POST', '/status/start' ), 'The start is not sent twice.' );
	}

	public function test_a_start_that_lags_two_reads_is_still_found_running(): void {
		$r = $this->ready_vm();
		Jobs::act( $r->id(), 'shutdown', array() );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->fake->lag_status( 5000, 2 );
		$this->pauses = array();

		Jobs::act( $r->id(), 'start', array() );

		$this->assertSame( array( 2, 2 ), $this->pauses );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'last_error' ) );
	}

	public function test_a_shutdown_whose_status_lags_is_found_off_by_the_look_after_and_the_page_power_is_right(): void {
		$r = $this->ready_vm();
		\BeltoftServices\Proxmox\Vm\Operations::remember_power( $r->id(), 'running' );
		$this->fake->lag_status( 5000, 3 );
		$this->pauses = array();

		Jobs::act( $r->id(), 'shutdown', array() );

		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->assertNotEmpty( $this->pauses, 'Looked again with short waits.' );
		$this->assertSame( 'stopped', \BeltoftServices\Proxmox\Vm\Operations::cached_status( $r->id() )['power'] );
		$this->assertFalse( as_has_scheduled_action( \BeltoftServices\Proxmox\Vm\ProxmoxJobs::POWER_AFTER, array( $r->id() ), Jobs::GROUP ), 'Nothing left to follow up.' );
	}

	public function test_a_shutdown_that_still_shows_running_says_so_and_one_follow_up_keeps_the_power_right(): void {
		$r = $this->ready_vm();
		\BeltoftServices\Proxmox\Vm\Operations::remember_power( $r->id(), 'running' );
		$this->fake->lag_status_seconds( 5000, 60 ); // Longer than the look after the task.
		$this->pauses = array();

		$result = ( new ProxmoxDriver() )->act( Resource::find( $r->id() ), 'shutdown', array() );

		$this->assertTrue( $result->is_done() );
		$this->assertSame( 'Shutdown requested; the server is still powering off', $result->event() );
		$this->assertStringNotContainsString( 'left running', $result->event() );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'stopped_by_customer' ), 'Not an outage.' );
		$this->assertTrue( as_has_scheduled_action( \BeltoftServices\Proxmox\Vm\ProxmoxJobs::POWER_AFTER, array( $r->id() ), Jobs::GROUP ) );

		// A second shutdown does not queue a second follow-up.
		\BeltoftServices\Proxmox\Vm\PowerJobs::follow_up( $r->id() );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Proxmox\Vm\ProxmoxJobs::POWER_AFTER, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		// The page still says running until the follow-up reads the VM, which by then is off.
		$this->assertSame( 'running', \BeltoftServices\Proxmox\Vm\Operations::cached_status( $r->id() )['power'] );
		$this->fake->elapse( 90 );
		\BeltoftServices\Proxmox\Vm\PowerJobs::after( $r->id() );

		$this->assertSame( 'stopped', \BeltoftServices\Proxmox\Vm\Operations::cached_status( $r->id() )['power'] );
		$events = \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] );
		$this->assertStringContainsString( 'Server stopped', wp_json_encode( $events ) );
	}

	public function test_the_follow_up_leaves_a_vm_that_still_runs_alone(): void {
		$r = $this->ready_vm();
		\BeltoftServices\Proxmox\Vm\Operations::remember_power( $r->id(), 'stopped' );
		$seen = count( $this->fake->calls() );

		\BeltoftServices\Proxmox\Vm\PowerJobs::after( $r->id() );

		$this->assertSame( 'running', \BeltoftServices\Proxmox\Vm\Operations::cached_status( $r->id() )['power'], 'The reading wins.' );
		$this->assertEmpty( $this->power( $seen ), 'Nothing is sent to the VM.' );
		\BeltoftServices\Proxmox\Vm\PowerJobs::after( 999999 );
	}

	public function test_a_database_shutdown_shares_the_look_after(): void {
		$r = $this->ready_vm();
		$r->set( array( 'driver' => 'proxmox-db' ) );
		$this->fake->lag_status_seconds( 5000, 60 );

		$result = ( new \BeltoftServices\Proxmox\Vm\Actions( Resource::find( $r->id() ) ) )->run( 'shutdown', array() );

		$this->assertSame( 'Shutdown requested; the server is still powering off', $result->event() );
		$this->assertTrue( as_has_scheduled_action( \BeltoftServices\Proxmox\Vm\ProxmoxJobs::POWER_AFTER, array( $r->id() ), Jobs::GROUP ) );
	}

	public function test_the_status_says_pending_while_the_vm_has_not_followed_the_shutdown_then_the_real_power(): void {
		$r = $this->ready_vm();
		\BeltoftServices\Proxmox\Vm\Operations::remember_power( $r->id(), 'running' );
		$this->fake->lag_status_seconds( 5000, 60 );
		( new ProxmoxDriver() )->act( Resource::find( $r->id() ), 'shutdown', array() );
		Resource::find( $r->id() )->set( array( Jobs::RESULT => array( 'action' => 'shutdown', 'ok' => true, 'at' => time(), 'text' => '' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( (int) Resource::find( $r->id() )->license()['user_id'] );
		$status = static function ( int $id ): array {
			return rest_do_request( new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $id ) )->get_data();
		};

		$first = $status( $r->id() );
		$this->assertTrue( $first['power_pending'], 'Not claimed while the VM shows running.' );
		$this->assertFalse( $first['power_failed'] );
		$this->assertSame( 'running', $first['power'] );

		// The VM is seen off by the next status: the cache follows at once and nothing is pending.
		$this->fake->elapse( 61 );
		$second = $status( $r->id() );
		$this->assertFalse( $second['power_pending'] );
		$this->assertFalse( $second['power_failed'] );
		$this->assertSame( 'stopped', $second['power'] );
		$this->assertSame( 'stopped', \BeltoftServices\Proxmox\Vm\Operations::cached_status( $r->id() )['power'] );
	}

	public function test_a_shutdown_the_vm_never_follows_is_failed_after_the_wait_and_a_start_is_the_same(): void {
		$r = $this->ready_vm();
		\BeltoftServices\Proxmox\Vm\Operations::remember_power( $r->id(), 'running' );
		$this->fake->lag_status_seconds( 5000, 100000 );
		( new ProxmoxDriver() )->act( Resource::find( $r->id() ), 'shutdown', array() );
		$set = static function ( int $id, string $action, int $age ): Resource {
			Resource::find( $id )->set( array( Jobs::RESULT => array( 'action' => $action, 'ok' => true, 'at' => time() - $age, 'text' => '' ) ) );

			return Resource::find( $id );
		};

		$within = \BeltoftServices\Proxmox\Network\PowerWatch::view( $set( $r->id(), 'shutdown', 10 ) );
		$this->assertTrue( $within['power_pending'] );

		$over = \BeltoftServices\Proxmox\Network\PowerWatch::view( $set( $r->id(), 'shutdown', \BeltoftServices\Proxmox\Network\PowerWatch::WINDOW + 5 ) );
		$this->assertFalse( $over['power_pending'] );
		$this->assertTrue( $over['power_failed'], 'The page is told not to claim it.' );

		// A failed result, an old one and an action without a wanted power say nothing.
		$old = \BeltoftServices\Proxmox\Network\PowerWatch::view( $set( $r->id(), 'shutdown', 3600 ) );
		$this->assertFalse( $old['power_pending'] || $old['power_failed'] );
		$reboot = \BeltoftServices\Proxmox\Network\PowerWatch::view( $set( $r->id(), 'reboot', 5 ) );
		$this->assertFalse( $reboot['power_pending'] || $reboot['power_failed'] );

		// A start: the VM is off, the job said ok, the VM does not run yet (status lags), then it does.
		$this->fake->lag_status_seconds( 5000, 60 );
		$fresh = Resource::find( $r->id() );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		( new ProxmoxDriver() )->act( $fresh, 'start', array() );
		$begin = \BeltoftServices\Proxmox\Network\PowerWatch::view( $set( $r->id(), 'start', 5 ) );
		$this->assertTrue( $begin['power_pending'] || 'running' === $begin['power'] );
		$this->fake->elapse( 61 );
		$end = \BeltoftServices\Proxmox\Network\PowerWatch::view( Resource::find( $r->id() ) );
		$this->assertFalse( $end['power_pending'] );
		$this->assertSame( 'running', $end['power'] );
	}

	public function test_a_vm_that_never_starts_is_a_wait_for_a_minute_after_the_settle(): void {
		$r = $this->ready_vm();
		Jobs::act( $r->id(), 'shutdown', array() );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->fake->next_task( array( 'effect' => false ) ); // The task ends OK, the VM stays off.
		$this->pauses = array();

		$result = ( new ProxmoxDriver() )->act( Resource::find( $r->id() ), 'start', array() );

		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( array_fill( 0, 10, 2 ), $this->pauses, 'Read every 2 seconds for 20 seconds.' );
		$this->assertTrue( $result->is_poll(), 'A start the status does not show yet is a wait, not an error.' );
		$this->assertSame( 60, $result->poll_budget() );
		$this->assertSame( 5, $result->poll_seconds() );
		$this->assertStringContainsString( 'did not leave the VM running', $result->error() );
	}

	public function test_a_resume_whose_start_status_lags_is_no_error(): void {
		$r = $this->ready_vm();
		$driver = new ProxmoxDriver();
		$this->assertTrue( $driver->pause( Resource::find( $r->id() ) )->is_done() );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->fake->lag_status( 5000, 2 );

		$result = $driver->resume( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	// -- 2.2 faster reinstall --------------------------------------------------------------------

	public function test_reinstall_of_a_server_without_disks_uses_stop_only(): void {
		$server = $this->ready_vm();
		$seen   = count( $this->fake->calls() );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild( $server->id() );

		$power = $this->power( $seen );
		$this->assertSame( 'POST /nodes/node-a/qemu/5000/status/stop', $power[0] );
		$this->assertNotContains( 'POST /nodes/node-a/qemu/5000/status/shutdown', $power );
		$this->assertCount( 1, $this->calls( 'DELETE', '/qemu/5000' ) );
		$this->assertNull( Resource::find( $server->id() )->get( 'wipe_power' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_reinstall_without_disks_with_a_status_that_lags_is_one_clean_run(): void {
		$server = $this->ready_vm();
		$this->fake->lag_status( 5000, 2 );
		$seen = count( $this->fake->calls() );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		Jobs::run( $server->id() );
		$this->assertStringNotContainsString( 'wanted state', (string) Resource::find( $server->id() )->get( 'last_error' ) );
		$this->rebuild( $server->id() );

		$this->assertSame( array( 'POST /nodes/node-a/qemu/5000/status/stop', 'POST /nodes/node-a/qemu/5000/status/start' ), $this->power( $seen ) );
		$this->assertStringNotContainsString( 'wanted state', (string) wp_json_encode( Resource::find( $server->id() )->get( 'last_error' ) ) );
	}

	public function test_a_stop_that_shows_running_for_longer_waits_without_a_false_error(): void {
		$server = $this->ready_vm();
		$this->fake->lag_status_seconds( 5000, 30 ); // More than the 20 seconds one run settles for.
		$seen = count( $this->fake->calls() );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		Jobs::run( $server->id() );
		$mid = Resource::find( $server->id() );
		$this->assertSame( 'stop', $mid->get( 'wipe_power' ) );
		$this->assertStringContainsString( 'waiting for the server to stop', (string) $mid->get( 'waiting' ), 'A wait, shown as Waiting.' );
		$this->assertSame( '', (string) $mid->get( 'last_error' ), 'Not an error.' );
		$this->assertSame( 0, $mid->get( 'attempts' ), 'Not a counted attempt.' );

		$this->rebuild( $server->id() );

		$stops = array_filter( $this->power( $seen ), static fn( $l ) => str_ends_with( $l, '/status/stop' ) );
		$this->assertCount( 1, $stops, 'The stop is not sent again to a VM that is already off (that would be an error).' );
		$this->assertCount( 1, $this->calls( 'DELETE', '/qemu/5000' ) );
	}

	public function test_reinstall_with_extra_disks_shuts_down_gracefully_first_and_does_not_stop_a_stopped_vm(): void {
		$server = $this->server_with_disk();
		$seen   = count( $this->fake->calls() );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild( $server->id() );

		$power = $this->power( $seen );
		$this->assertSame( 'POST /nodes/node-a/qemu/5000/status/shutdown', $power[0] );
		$this->assertNotContains( 'POST /nodes/node-a/qemu/5000/status/stop', $power, 'The shutdown worked: no plug pull.' );
		$this->assertNull( Resource::find( $server->id() )->get( 'wipe' ), 'The boot replacement finished.' );
	}

	public function test_reinstall_with_extra_disks_stops_when_the_shutdown_leaves_the_vm_running(): void {
		$server = $this->server_with_disk();
		$this->fake->next_task( array( 'exitstatus' => 'VM quit/powerdown failed - got timeout', 'effect' => false ) );
		$seen = count( $this->fake->calls() );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild( $server->id() );

		$power = $this->power( $seen );
		$this->assertSame( array( 'POST /nodes/node-a/qemu/5000/status/shutdown', 'POST /nodes/node-a/qemu/5000/status/stop' ), array_slice( $power, 0, 2 ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_reinstall_with_extra_disks_and_a_vm_still_shutting_down_is_not_stopped_twice(): void {
		$server = $this->server_with_disk();
		$this->fake->lag_status( 5000, 1 ); // The shutdown is done but the status shows running once.
		$seen = count( $this->fake->calls() );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild( $server->id() );

		$power = $this->power( $seen );
		$this->assertSame( 'POST /nodes/node-a/qemu/5000/status/shutdown', $power[0] );
		$this->assertNotContains( 'POST /nodes/node-a/qemu/5000/status/stop', $power );
	}

	// -- Delete: the same lag, nothing else changed ------------------------------------------------

	public function test_a_delete_whose_status_lags_after_the_shutdown_is_no_error_and_stays_graceful(): void {
		$r = $this->ready_vm();
		$this->fake->lag_status( 5000, 2 );
		$seen = count( $this->fake->calls() );

		$result = ( new ProxmoxDriver() )->delete( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$paths = $this->paths( $seen );
		$this->assertContains( 'POST /nodes/node-a/qemu/5000/status/shutdown', $paths );
		$this->assertNotContains( 'POST /nodes/node-a/qemu/5000/status/stop', $paths );
		$this->assertNull( $this->fake->vm( 5000 ) );
	}

	public function test_a_delete_with_a_stop_that_is_slow_to_show_waits_instead_of_a_wanted_state_error(): void {
		$r = $this->ready_vm();
		$this->fake->next_task( array( 'exitstatus' => 'VM quit/powerdown failed - got timeout', 'effect' => false ) ); // Shutdown times out.
		$this->fake->lag_status_seconds( 5000, 60 );
		$result = ( new ProxmoxDriver() )->delete( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_poll() );
		$this->assertStringContainsString( 'waiting for the server to stop', $result->error() );
		$this->assertNotEmpty( $this->calls( 'POST', '/status/stop' ), 'The stop follows the shutdown that left the VM running.' );
		$this->assertNotNull( $this->fake->vm( 5000 ), 'Nothing is deleted while the VM shows as running.' );
	}

	public function test_the_fake_status_lag_shows_the_old_status_for_the_given_reads_only(): void {
		$this->ready_vm();
		$this->fake->lag_status( 5000, 2 );
		$this->assertTrue( $this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' ) !== null );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'], 'The real state changed at once.' );

		$read = fn() => $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/status/current' )['status'];
		$this->assertSame( array( 'running', 'running', 'stopped', 'stopped' ), array( $read(), $read(), $read(), $read() ) );
	}

	public function test_a_guest_that_ignores_acpi_is_stopped_after_the_shutdown_in_pause_and_delete(): void {
		foreach ( array( 'pause', 'delete' ) as $operation ) {
			$r = $this->ready_vm();
			$this->fake->next_task( array( 'exitstatus' => 'VM quit/powerdown failed - got timeout', 'effect' => false ) );
			$seen = count( $this->fake->calls() );

			$result = ( new ProxmoxDriver() )->$operation( Resource::find( $r->id() ) );
			$vmid   = (int) Resource::find( $r->id() )->get( 'vmid' ) ?: (int) \BeltoftServices\Proxmox\Vm\VmRecords::for_resource( $r->id() )['vmid'];

			$this->assertTrue( $result->is_done(), $operation . ': ' . $result->error() );
			$power = $this->power( $seen );
			$this->assertSame( array( 'POST /nodes/node-a/qemu/' . $vmid . '/status/shutdown', 'POST /nodes/node-a/qemu/' . $vmid . '/status/stop' ), array_slice( $power, 0, 2 ), $operation );
		}
	}

	public function test_the_waiting_message_carries_the_failed_stop_exit_status(): void {
		$r = $this->ready_vm();
		$this->fake->next_task( array( 'exitstatus' => 'VM quit/powerdown failed - got timeout', 'effect' => false ) );
		$this->fake->next_task( array( 'exitstatus' => 'stop failed: busy', 'effect' => false ) );

		$result = ( new ProxmoxDriver() )->delete( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_retry() );
		$this->assertStringContainsString( 'waiting for the server to stop (the stop task failed: stop failed: busy)', $result->error() );
	}
}
