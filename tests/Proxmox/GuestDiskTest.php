<?php
/** The parser uses a sanitized reply captured from the temporary live test VM. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\MetricsRoute;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\ProxmoxJobs;

require_once __DIR__ . '/MonitoringTestCase.php';

class GuestDiskTest extends MonitoringTestCase {
	private function fixture(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/proxmox/get-fsinfo.json' ), true );
	}

	private function agent_calls(): array {
		return array_values( array_filter( $this->fake->calls(), static fn( $call ) => str_ends_with( $call['path'], '/agent/get-fsinfo' ) ) );
	}

	public function test_parser_uses_only_the_root_from_the_captured_reply(): void {
		$this->assertSame( array( 'used' => 884944896, 'total' => 2790285312 ), GuestDisk::root( $this->fixture() ) );
		$this->assertNull( GuestDisk::root( array( 'result' => array( $this->fixture()['result'][0] ) ) ) );
		$this->assertNull( GuestDisk::root( array( 'result' => array( array( 'mountpoint' => '/', 'used-bytes' => 10 ) ) ) ) );
		$this->assertNull( GuestDisk::root( array( 'result' => array( array( 'mountpoint' => '/', 'used-bytes' => 20, 'total-bytes' => 10 ) ) ) ) );
	}

	public function test_read_stores_root_and_uses_the_six_hour_cache(): void {
		$r = $this->ready_vm();
		$this->fake->set_fsinfo( 5000, $this->fixture() );
		$this->assertSame( array( 'used' => 884944896, 'total' => 2790285312 ), GuestDisk::read( $r ) );
		$this->assertCount( 1, $this->agent_calls() );
		$this->assertSame( GuestDisk::cached( $r ), GuestDisk::read( $r ) );
		$this->assertCount( 1, $this->agent_calls() );
		$this->assertGreaterThan( time() - 10, (int) $r->get( 'disk_read_at' ) );
		$r->set( array( 'disk_read_at' => time() - 6 * HOUR_IN_SECONDS - 1 ) );
		$this->assertSame( array( 'used' => 884944896, 'total' => 2790285312 ), GuestDisk::read( $r ) );
		$this->assertCount( 2, $this->agent_calls() );
	}

	public function test_agent_failure_is_cached_for_one_hour(): void {
		$r = $this->ready_vm();
		$this->fake->fail_next( 'GET', '#/agent/get-fsinfo$#', new \WP_Error( 'agent_missing', 'Agent unavailable.' ) );
		$this->assertNull( GuestDisk::read( $r ) );
		$this->assertNull( GuestDisk::read( $r ) );
		$this->assertCount( 1, $this->agent_calls() );
		$r->set( array( 'disk_fail_at' => time() - HOUR_IN_SECONDS - 1 ) );
		$this->fake->set_fsinfo( 5000, $this->fixture() );
		$this->assertNotNull( GuestDisk::read( $r ) );
		$this->assertCount( 2, $this->agent_calls() );
	}

	public function test_stopped_vm_makes_no_agent_call(): void {
		$r = $this->ready_vm();
		Operations::remember_power( $r->id(), 'stopped' );
		$this->assertNull( GuestDisk::read( $r ) );
		$this->assertCount( 0, $this->agent_calls() );
	}

	public function test_customer_route_reads_only_cached_meta(): void {
		$r = $this->ready_vm();
		wp_set_current_user( (int) $r->license()['user_id'] );
		$r->set( array( 'disk_used' => 10, 'disk_total' => 100, 'disk_read_at' => time() ) );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/metrics' );
		$request->set_param( 'id', $r->id() );
		$request->set_param( 'range', 'hour' );
		$this->assertSame( array( 'used' => 10, 'total' => 100 ), MetricsRoute::get( $request )->get_data()['disk'] );
		$this->assertCount( 0, $this->agent_calls() );
		$r->set( array( 'disk_read_at' => time() - 6 * HOUR_IN_SECONDS - 1 ) );
		$this->assertNull( MetricsRoute::get( $request )->get_data()['disk'] );
	}

	public function test_disk_job_batches_25_and_skips_resources_not_ready(): void {
		$r = $this->ready_vm();
		$this->fake->set_fsinfo( 5000, $this->fixture() );
		$stored = get_option( Clusters::OPTION );
		$stored['lab']['persist'] = 1;
		update_option( Clusters::OPTION, $stored );
		update_option( FakeClient::OPTION, $this->fake->state() );
		$list = array( array( 'resource' => $r->id(), 'cluster' => 'lab', 'vmid' => 5000, 'node' => 'node-a' ) );
		for ( $i = 1; $i < 26; ++$i ) {
			$list[] = array( 'resource' => 900000 + $i, 'cluster' => 'lab', 'vmid' => 5000 + $i, 'node' => 'node-a' );
		}
		set_transient( ProxmoxJobs::RUNNING_PREFIX . 'lab', $list, HOUR_IN_SECONDS );
		ProxmoxJobs::disk();
		$this->assertSame( 25, get_transient( ProxmoxJobs::DISK_QUEUE )['cursor'] );
		$this->assertCount( 1, $this->agent_calls() );
		$this->assertTrue( (bool) as_has_scheduled_action( ProxmoxJobs::DISK_BATCH, array(), Jobs::GROUP ) );
		as_unschedule_all_actions( ProxmoxJobs::DISK_BATCH, array(), Jobs::GROUP );
		ProxmoxJobs::disk_batch();
		$this->assertFalse( get_transient( ProxmoxJobs::DISK_QUEUE ) );
		$this->assertCount( 1, $this->agent_calls() );
		$r->set( array( 'state' => 'paused' ) );
		ProxmoxJobs::disk();
		$this->assertCount( 1, $this->agent_calls(), 'A paused resource is not read.' );
		delete_option( FakeClient::OPTION );
	}
}
