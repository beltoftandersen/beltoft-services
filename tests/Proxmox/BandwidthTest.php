<?php
/**
 * The plan's network speed limit on the public NIC (net0 rate=). FakeClient only: nothing reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\NetConfig;
use BeltoftServices\Proxmox\Panels;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class BandwidthTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();

		// The recurring jobs only read a fake cluster that keeps its state, as on staging: start the cluster again with that.
		delete_option( FakeClient::OPTION );
		$this->fake = new FakeClient( true );
		$this->fake->add_node( 'node-a' );
		$this->seed_capacity();
		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'name' => 'ubuntu-26-template', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'agent' => '1', 'memory' => 512, 'cores' => 1 ) ) );
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'persist' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );
	}

	public function tear_down(): void {
		delete_option( FakeClient::OPTION );
		parent::tear_down();
	}

	private function net0( int $vmid = 5000, bool $current = false ): string {
		$config = $this->fake->request( 'GET', '/nodes/node-a/qemu/' . $vmid . '/config', $current ? array( 'current' => 1 ) : array() );

		return (string) ( $config['net0'] ?? '' );
	}

	/** Changes the plan the way Lifecycle does and runs the driver's update. */
	private function change_to( Resource $r, array $over ): Resource {
		$plan = array_merge( (array) Resource::find( $r->id() )->get( 'plan' ), $over );
		$this->assertTrue( Resource::find( $r->id() )->change_plan( $plan, 'test' ) );
		$result = Registry::get( 'proxmox' )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_done(), 'update_plan: ' . wp_json_encode( $result ) );

		return Resource::find( $r->id() );
	}

	private function puts_to_config(): array {
		return $this->calls( 'PUT', '/config' );
	}

	public function test_the_field_is_a_number_defaulting_to_no_limit(): void {
		$field = ( new ProxmoxDriver() )->plan_fields()['net_mbit'];
		$this->assertSame( 'number', $field[0] );
		$this->assertSame( 0, $field[3] );
		$this->assertArrayNotHasKey( 'net_mbit', ( new DbDriver() )->plan_fields(), 'A database is private only: no speed field.' );
	}

	public function test_rate_helpers_convert_mbit_to_mb_per_second(): void {
		$this->assertSame( '125', NetConfig::rate_for( 1000 ) );
		$this->assertSame( '12.5', NetConfig::rate_for( 100 ) );
		$this->assertSame( '0.125', NetConfig::rate_for( 1 ) );
		$this->assertNull( NetConfig::rate_for( 0 ) );
		$this->assertSame( 'virtio=AA,bridge=vmbr0,rate=62.5,firewall=1', NetConfig::with_rate( 'virtio=AA,bridge=vmbr0,rate=3,firewall=1', 500 ) );
		$this->assertSame( 'virtio=AA,bridge=vmbr0,firewall=1', NetConfig::with_rate( 'virtio=AA,bridge=vmbr0,rate=3,firewall=1', 0 ) );
		$this->assertTrue( NetConfig::has_rate( 'virtio=AA,rate=12.50', 100 ) );
		$this->assertTrue( NetConfig::has_rate( 'virtio=AA', 0 ) );
		$this->assertFalse( NetConfig::has_rate( 'virtio=AA', 100 ) );
	}

	public function test_a_new_build_without_a_limit_has_no_rate(): void {
		$this->ready_vm();
		$this->assertStringNotContainsString( 'rate=', $this->net0() );
	}

	public function test_a_new_build_keeps_net0_byte_for_byte_except_the_rate(): void {
		$limited = $this->ready_vm( array( 'net_mbit' => 1000 ) );
		$plain   = $this->ready_vm();

		$one = $this->net0( (int) $limited->get( 'vmid' ) );
		$two = $this->net0( (int) $plain->get( 'vmid' ) );

		$this->assertStringNotContainsString( 'rate=', $two );
		$this->assertStringEndsWith( ',rate=125', $one );
		// Everything but the rate (and each server's own MAC) is the same string.
		$this->assertSame( NetConfig::format( array_diff_key( NetConfig::parse( $two ), array( 'virtio' => 1 ) ) ), NetConfig::format( array_diff_key( NetConfig::parse( NetConfig::with_rate( $one, 0 ) ), array( 'virtio' => 1 ) ) ) );
		$this->assertSame( substr( $one, 0, -9 ), NetConfig::with_rate( $one, 0 ) );
	}

	public function test_a_plan_change_rewrites_only_the_rate_and_zero_removes_it(): void {
		$r     = $this->ready_vm();
		$plain = $this->net0();

		$r = $this->change_to( $r, array( 'net_mbit' => 500 ) );
		$this->assertSame( $plain . ',rate=62.5', $this->net0() );

		$r = $this->change_to( $r, array( 'net_mbit' => 200 ) );
		$this->assertSame( $plain . ',rate=25', $this->net0() );

		$this->change_to( $r, array( 'net_mbit' => 0 ) );
		$this->assertSame( $plain, $this->net0() );
	}

	public function test_a_rate_change_does_not_restart_and_sends_nothing_when_unchanged(): void {
		$r = $this->ready_vm( array( 'net_mbit' => 1000 ) );
		$this->change_to( $r, array( 'net_mbit' => 1000 ) );

		$this->assertSame( array(), $this->calls( 'POST', '/status/stop' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		$seen = count( $this->puts_to_config() );
		$this->change_to( Resource::find( $r->id() ), array( 'net_mbit' => 1000 ) );
		$this->assertCount( $seen, $this->puts_to_config(), 'Same speed: no config write.' );
	}

	public function test_a_plan_change_with_a_resize_also_changes_the_rate(): void {
		$r = $this->ready_vm();
		$this->change_to( $r, array( 'net_mbit' => 100, 'cores' => 4 ) );
		$this->assertStringEndsWith( ',rate=12.5', $this->net0() );
		$this->assertSame( 4, (int) $this->fake->vm( 5000 )['config']['cores'] );
	}

	public function test_net1_is_never_touched(): void {
		$r = $this->ready_vm();
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'net1' => 'virtio=AA:BB:CC:DD:EE:01,bridge=vmbr1,tag=40' ) ) );

		$this->change_to( $r, array( 'net_mbit' => 500 ) );

		$this->assertSame( 'virtio=AA:BB:CC:DD:EE:01,bridge=vmbr1,tag=40', $this->fake->vm( 5000 )['config']['net1'] );
	}

	public function test_the_reconcile_fixes_a_drifted_server(): void {
		$r     = $this->ready_vm( array( 'net_mbit' => 1000 ) );
		$right = $this->net0();
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'net0' => NetConfig::with_rate( $right, 0 ) ) ) );

		ProxmoxJobs::net_rates();

		$this->assertSame( $right, $this->net0() );
		$this->assertSame( array(), $this->calls( 'POST', '/status/stop' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( 1000.0, (float) Resource::find( $r->id() )->get( 'net_rate_mbit' ) );
	}

	public function test_the_reconcile_runs_from_the_existing_sweep(): void {
		$this->assertSame( 22, has_action( \BeltoftServices\Jobs::SWEEP, array( ProxmoxJobs::class, 'net_rates' ) ) );
	}

	public function test_a_checked_server_is_not_read_again_within_a_day(): void {
		$this->ready_vm( array( 'net_mbit' => 1000 ) );
		ProxmoxJobs::net_rates();
		$seen = count( $this->calls( 'GET', '/config' ) );

		ProxmoxJobs::net_rates();

		$this->assertCount( $seen, $this->calls( 'GET', '/config' ) );
	}

	public function test_a_pending_change_is_left_pending_and_applied_at_the_next_restart(): void {
		$r     = $this->ready_vm( array( 'net_mbit' => 1000 ) );
		$plain = NetConfig::with_rate( $this->net0(), 0 );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'net0' => $plain ) ) );
		$this->fake->hold_net_rate( 5000 );

		ProxmoxJobs::net_rates();

		$this->assertSame( $plain . ',rate=125', $this->net0(), 'The default view shows the pending value.' );
		$this->assertSame( $plain, $this->net0( 5000, true ), 'The running NIC still has the old one.' );
		$this->assertSame( array(), $this->calls( 'POST', '/status/stop' ), 'No restart for it.' );
		$this->assertSame( array(), $this->calls( 'POST', '/status/reboot' ) );

		$writes = count( $this->puts_to_config() );
		Resource::find( $r->id() )->set( array( 'net_rate_at' => 1 ) );
		ProxmoxJobs::net_rates();
		$this->assertCount( $writes, $this->puts_to_config(), 'A pending change is not written again.' );

		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/reboot' );
		$this->assertSame( $plain . ',rate=125', $this->net0( 5000, true ), 'Applied at the next restart.' );
	}

	public function test_the_reconcile_skips_linked_locked_not_ready_and_legacy_plans(): void {
		$r = $this->ready_vm( array( 'net_mbit' => 1000 ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'net0' => NetConfig::with_rate( $this->net0(), 0 ) ) ) );
		$plain = $this->net0();

		$r->set( array( 'linked' => true ) );
		ProxmoxJobs::net_rates();
		$this->assertSame( $plain, $this->net0(), 'Adopted VM.' );
		$r->set( array( 'linked' => null ) );

		$this->fake->set_lock( 5000, 'backup' );
		ProxmoxJobs::net_rates();
		$this->assertSame( $plain, $this->net0(), 'A VM with a lock is left to that operation.' );
		$this->fake->set_lock( 5000, '' );

		$r->set( array( 'desired' => 'paused' ) );
		ProxmoxJobs::net_rates();
		$this->assertSame( $plain, $this->net0(), 'Not wanted ready.' );
		$r->set( array( 'desired' => 'ready' ) );

		$plan = (array) Resource::find( $r->id() )->get( 'plan' );
		unset( $plan['net_mbit'] );
		Resource::find( $r->id() )->set( array( 'plan' => $plan ) );
		ProxmoxJobs::net_rates();
		$this->assertSame( $plain, $this->net0(), 'A plan from before the field is left as it is.' );
	}

	public function test_a_server_without_net0_gets_no_write(): void {
		$r = $this->ready_vm( array( 'net_mbit' => 1000 ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'net0' ) ) );
		$seen = count( $this->puts_to_config() );

		$this->assertTrue( ( new \BeltoftServices\Proxmox\Operations( Resource::find( $r->id() ) ) )->reconcile_rate() );

		$this->assertCount( $seen, $this->puts_to_config() );
		$this->assertArrayNotHasKey( 'net0', $this->fake->vm( 5000 )['config'] );
	}

	public function test_the_speed_label_is_plain(): void {
		$this->assertSame( '500 Mbit/s', Panels::speed_label( 500 ) );
		$this->assertSame( '1 Gbit/s', Panels::speed_label( 1000 ) );
		$this->assertSame( '2.5 Gbit/s', Panels::speed_label( 2500 ) );
	}

	public function test_the_overview_shows_the_limit_only_when_set(): void {
		$with = $this->ready_vm( array( 'net_mbit' => 1000 ) );
		ob_start();
		Panels::account( Resource::find( $with->id() ) );
		$this->assertStringContainsString( '1 Gbit/s', (string) ob_get_clean() );

		$none = $this->ready_vm();
		ob_start();
		Panels::account( Resource::find( $none->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Gbit/s', $html );
		$this->assertStringNotContainsString( 'Mbit/s', $html );
	}
}
