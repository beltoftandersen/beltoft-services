<?php
/** Live usage route: one cluster call, guest memory, disk refresh limits. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\LiveUsage;
use BeltoftServices\Proxmox\Operations;
use BeltoftLicenses\Account\View;

require_once __DIR__ . '/MonitoringTestCase.php';

class LiveUsageTest extends MonitoringTestCase {
	private const MEMINFO = "MemTotal:        2097152 kB\nMemFree:          100000 kB\nMemAvailable:    1048576 kB\nBuffers:           1000 kB\n";

	private function route(): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', '/beltoft-services/v1/usage' ) );
	}

	private function calls( string $suffix ): int {
		return count( array_filter( $this->fake->calls(), static fn( $call ) => str_ends_with( $call['path'], $suffix ) ) );
	}

	private function owner( $r ): void {
		wp_set_current_user( (int) $r->license()['user_id'] );
	}

	private function fsinfo(): void {
		$this->fake->set_fsinfo( 5000, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/proxmox/get-fsinfo.json' ), true ) );
	}

	public function test_logged_out_is_refused_and_other_user_sees_nothing_of_it(): void {
		$r = $this->ready_vm();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->route()->get_status() );
		$this->assertSame( 0, $this->calls( '/cluster/resources' ) );
		wp_set_current_user( self::factory()->user->create() );
		$response = $this->route();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), (array) $response->get_data()['resources'], 'Another user gets no entry for this server.' );
		$this->assertSame( 0, $this->calls( '/cluster/resources' ) );
		$this->assertNotNull( $r );
	}

	public function test_memory_comes_from_the_guest_and_cpu_from_the_cluster_row(): void {
		$r = $this->ready_vm();
		$this->owner( $r );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		$this->fsinfo();
		$data = $this->route()->get_data()['resources'];
		$entry = ( (array) $data )[ $r->id() ];
		$this->assertSame( 'running', $entry['power'] );
		$this->assertSame( 50, $entry['mem'] );
		$this->assertIsInt( $entry['cpu'] );
		$this->assertSame( 32, $entry['disk'] );
		$this->assertSame( 'running', Operations::cached_status( $r->id() )['power'], 'The status transient is refreshed from the same call.' );
		$this->assertSame( 50, LiveUsage::cached_memory( $r->id() ) );
	}

	public function test_memory_falls_back_to_pve_when_the_agent_does_not_answer(): void {
		$r = $this->ready_vm();
		$this->owner( $r );
		// No /proc/meminfo seeded: file-read fails.
		$entry = ( (array) $this->route()->get_data()['resources'] )[ $r->id() ];
		$this->assertIsInt( $entry['mem'] );
		$this->assertNull( LiveUsage::cached_memory( $r->id() ), 'A failed reading is not served as a guest figure.' );
		$this->assertSame( 1, $this->calls( '/agent/file-read' ) );
		$this->route();
		$this->assertSame( 1, $this->calls( '/agent/file-read' ), 'The failure is kept, the agent is not asked on every poll.' );
	}

	public function test_one_cluster_call_for_several_vms_within_fifteen_seconds_and_memory_for_a_minute(): void {
		$first = $this->ready_vm();
		$second = $this->ready_vm( 5001 );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		// A fresh disk reading each (a disk refresh verifies the VM with its own cluster call).
		$first->set( array( 'disk_used' => 1, 'disk_total' => 2, 'disk_read_at' => time() ) );
		$second->set( array( 'disk_used' => 1, 'disk_total' => 2, 'disk_read_at' => time() ) );
		$base = $this->calls( '/cluster/resources' );
		$reads = $this->calls( '/agent/file-read' );
		$this->owner( $first );
		$this->route();
		$this->route();
		wp_set_current_user( (int) $second->license()['user_id'] );
		$this->route();
		$this->assertSame( $base + 1, $this->calls( '/cluster/resources' ) );
		$this->assertSame( $reads + 2, $this->calls( '/agent/file-read' ), 'Guest memory is read once a minute per VM (two VMs, two reads).' );
		$this->assertCount( 0, array_filter( $this->fake->calls(), static fn( $c ) => 1 === preg_match( '#/qemu/\d+/status/current$#', $c['path'] ) ), 'No per-VM status call.' );
		delete_transient( LiveUsage::ROWS_PREFIX . 'lab' );
		delete_transient( LiveUsage::MEM_PREFIX . $first->id() );
		wp_set_current_user( (int) $first->license()['user_id'] );
		$this->route();
		$this->assertSame( $base + 2, $this->calls( '/cluster/resources' ) );
	}

	public function test_disk_is_refreshed_at_most_once_in_ten_minutes(): void {
		$r = $this->ready_vm();
		$this->owner( $r );
		$this->fsinfo();
		$this->route();
		$this->assertSame( 1, $this->calls( '/agent/get-fsinfo' ) );
		$this->route();
		$this->assertSame( 1, $this->calls( '/agent/get-fsinfo' ), 'A fresh reading is used.' );
		$r->set( array( 'disk_read_at' => time() - 11 * MINUTE_IN_SECONDS ) );
		$this->route();
		$this->assertSame( 1, $this->calls( '/agent/get-fsinfo' ), 'Not again within ten minutes of the last attempt.' );
		delete_transient( LiveUsage::DISK_PREFIX . $r->id() );
		$this->route();
		$this->assertSame( 2, $this->calls( '/agent/get-fsinfo' ), 'Old reading and ten minutes passed: read once.' );
	}

	public function test_a_stopped_server_has_only_its_power(): void {
		$r = $this->ready_vm();
		$this->owner( $r );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$entry = ( (array) $this->route()->get_data()['resources'] )[ $r->id() ];
		$this->assertSame( array( 'power' => 'stopped', 'cpu' => null, 'mem' => null, 'disk' => null ), $entry );
	}

	public function test_rate_limit(): void {
		$r = $this->ready_vm();
		$this->owner( $r );
		add_filter( 'beltoft_services_rest_rate_limit', static fn() => 2 );
		$this->assertSame( 200, $this->route()->get_status() );
		$this->assertSame( 200, $this->route()->get_status() );
		$this->assertSame( 429, $this->route()->get_status() );
	}

	public function test_server_rows_use_the_guest_memory_when_a_fresh_reading_exists(): void {
		$r = $this->ready_vm();
		set_transient( Operations::STATUS_PREFIX . $r->id(), array( 'power' => 'running', 'cpu' => 0.25, 'mem' => 3221225472 ), HOUR_IN_SECONDS );
		$view = View::license( $r->license() );
		$this->assertSame( 75, array_column( Servers::rows( array( $view ) )[0]['bars'], 'percent', 'key' )['mem'] );
		set_transient( LiveUsage::MEM_PREFIX . $r->id(), array( 'percent' => 40 ), 60 );
		$bars = array_column( Servers::rows( array( $view ) )[0]['bars'], 'percent', 'key' );
		$this->assertSame( 40, $bars['mem'] );
		$this->assertSame( 25, $bars['cpu'] );
	}

	public function test_a_vm_without_our_tag_is_not_read_through_the_agent(): void {
		$r = $this->ready_vm();
		$this->owner( $r );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'description' => 'someone else' ) );
		$entry = ( (array) $this->route()->get_data()['resources'] )[ $r->id() ];
		$this->assertSame( 0, $this->calls( '/agent/file-read' ) );
		$this->assertNull( LiveUsage::cached_memory( $r->id() ) );
		$config_calls = $this->calls( '/qemu/5000/config' );
		delete_transient( LiveUsage::ROWS_PREFIX . 'lab' );
		$this->route();
		$this->assertSame( $config_calls, $this->calls( '/qemu/5000/config' ), 'The outcome is kept a minute: the config is not read again.' );
		$this->assertSame( 0, $this->calls( '/agent/file-read' ) );
	}

	public function test_meminfo_parser(): void {
		$this->assertSame( 50, LiveUsage::meminfo( self::MEMINFO ) );
		$this->assertNull( LiveUsage::meminfo( "MemTotal: 100 kB\n" ) );
	}
}
