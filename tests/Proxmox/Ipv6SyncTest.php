<?php
/**
 * IPv6 for servers that exist already: the sweep writes ip6 into ipconfig0 as a pending change and never restarts;
 * a plan change and a restore put it back. FakeClient only, documentation prefix.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\IpHistory;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\FakeClient;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\PowerJobs;
use BeltoftServices\Proxmox\Vm\ServerSyncJobs;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once __DIR__ . '/Backup/BackupTestCase.php';

class Ipv6SyncTest extends BackupTestCase {

	private const ADDRESS = '2001:db8:430c:1:1388::1';

	public function set_up(): void {
		parent::set_up();

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

	private function ipv6_on(): void {
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'ipv6_prefix' => '2001:db8:430c:1::/64', 'fw_enabled' => 1, 'fw_ipfilter' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );
	}

	private function config( bool $current = false ): array {
		return (array) $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config', $current ? array( 'current' => 1 ) : array() );
	}

	/**
	 * ipconfig0 writes made after the mark.
	 */
	private function puts( int $from = 0 ): int {
		return count( array_filter( array_slice( $this->fake->calls(), $from ), static fn( $c ) => 'PUT' === $c['method'] && '/config' === substr( $c['path'], -7 ) && isset( $c['params']['ipconfig0'] ) ) );
	}

	/**
	 * Calls made after the mark that start, stop or reboot.
	 */
	private function power_calls( int $from ): array {
		return array_values( array_filter( array_slice( $this->fake->calls(), $from ), static fn( $c ) => 'POST' === $c['method'] && 1 === preg_match( '#/status/(start|stop|shutdown|reboot|reset)$#D', $c['path'] ) ) );
	}

	public function test_the_sweep_runs_after_the_others(): void {
		$this->assertSame( 25, has_action( Jobs::SWEEP, array( ServerSyncJobs::class, 'ipv6s' ) ) );
	}

	public function test_an_existing_server_gets_ip6_without_a_restart_and_keeps_its_ipv4(): void {
		$r   = $this->ready_vm();
		$old = $this->config()['ipconfig0'];
		$this->assertStringNotContainsString( 'ip6', $old );
		$this->ipv6_on();
		$this->fake->hold_net_rate( 5000 );
		$mark = count( $this->fake->calls() );

		ServerSyncJobs::ipv6s();

		$this->assertSame( $old . ',ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->config()['ipconfig0'] );
		$this->assertSame( $old, $this->config( true )['ipconfig0'], 'The running VM still has the old network.' );
		$this->assertSame( array(), $this->power_calls( $mark ), 'Never restarted.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( self::ADDRESS, $fresh->get( 'ip6' ) );
		$this->assertNotNull( $fresh->get( 'ipv6_at' ) );
		$this->assertNotNull( $fresh->get( 'ipv6_pending_at' ) );
		$this->assertCount( 1, IpHistory::for_address( self::ADDRESS ) );
	}

	public function test_a_second_run_writes_nothing(): void {
		$this->ready_vm();
		$this->ipv6_on();

		$mark = count( $this->fake->calls() );
		ServerSyncJobs::ipv6s();
		$this->assertSame( 1, $this->puts( $mark ) );
		$mark = count( $this->fake->calls() );
		ServerSyncJobs::ipv6s();
		$this->assertSame( 0, $this->puts( $mark ) );
	}

	public function test_a_stopped_server_has_it_at_once_and_no_pending_note(): void {
		$r = $this->ready_vm();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->elapse( 5 );
		$this->ipv6_on();

		ServerSyncJobs::ipv6s();

		$this->assertStringContainsString( 'ip6=' . self::ADDRESS, $this->config( true )['ipconfig0'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'ipv6_pending_at' ) );
	}

	public function test_feature_off_does_nothing(): void {
		$r    = $this->ready_vm();
		$mark = count( $this->fake->calls() );

		ServerSyncJobs::ipv6s();

		$this->assertSame( 0, $this->puts( $mark ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'ip6' ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'ipv6_at' ) );
	}

	public function test_linked_locked_on_hold_and_not_ready_servers_are_skipped(): void {
		$r = $this->ready_vm();
		$this->ipv6_on();

		$r->set( array( 'linked' => true ) );
		ServerSyncJobs::ipv6s();
		$this->assertStringNotContainsString( 'ip6', $this->config()['ipconfig0'], 'Adopted VM.' );
		$r->set( array( 'linked' => null ) );

		$this->fake->set_lock( 5000, 'backup' );
		ServerSyncJobs::ipv6s();
		$this->assertStringNotContainsString( 'ip6', $this->config()['ipconfig0'], 'Locked VM.' );
		$this->fake->set_lock( 5000, '' );

		$r->set( array( 'desired' => 'paused' ) );
		ServerSyncJobs::ipv6s();
		$this->assertStringNotContainsString( 'ip6', $this->config()['ipconfig0'], 'Not wanted ready.' );
		$r->set( array( 'desired' => 'ready' ) );

		$r->set( array( 'state' => 'provisioning' ) );
		ServerSyncJobs::ipv6s();
		$this->assertStringNotContainsString( 'ip6', $this->config()['ipconfig0'], 'Not ready.' );
		$r->set( array( 'state' => 'ready' ) );

		ServerSyncJobs::ipv6s();
		$this->assertStringContainsString( 'ip6=' . self::ADDRESS, $this->config()['ipconfig0'] );
	}

	public function test_a_vm_without_a_public_ipv4_ipconfig_is_left_alone(): void {
		$r = $this->ready_vm();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => 'ip=dhcp' ) );
		$this->ipv6_on();

		ServerSyncJobs::ipv6s();

		$this->assertSame( 'ip=dhcp', $this->config()['ipconfig0'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'ip6' ) );
	}

	public function test_a_wrong_ip6_is_replaced_and_the_ipv4_part_is_copied_as_it_is(): void {
		$r = $this->ready_vm();
		$v4 = $this->config()['ipconfig0'];
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => $v4 . ',ip6=2001:db8:ffff::5/64,gw6=2001:db8:ffff::1' ) );
		$this->ipv6_on();

		ServerSyncJobs::ipv6s();

		$this->assertSame( $v4 . ',ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->config()['ipconfig0'] );
		unset( $r );
	}

	public function test_the_per_run_budget_caps_the_servers_read(): void {
		$this->ready_vm();
		$this->ready_vm();
		$this->ipv6_on();
		$mark = count( $this->fake->calls() );

		$this->assertSame( 20, ServerSyncJobs::IPV6_PER_RUN );
		ServerSyncJobs::ipv6s();
		$this->assertSame( 2, $this->puts( $mark ) );
	}

	public function test_the_pending_note_ends_after_a_boot(): void {
		$r = $this->ready_vm();
		$r->set( array( 'ipv6_at' => 1, 'ipv6_pending_at' => time() - 100 ) );

		PowerJobs::power();
		$this->assertNotNull( Resource::find( $r->id() )->get( 'ipv6_pending_at' ) );

		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->elapse( 5 );
		PowerJobs::power();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/start' );
		$this->fake->elapse( 5 );
		PowerJobs::power();
		$this->assertNull( Resource::find( $r->id() )->get( 'ipv6_pending_at' ) );
	}

	public function test_a_plan_change_repairs_it_too(): void {
		$r = $this->ready_vm();
		$this->ipv6_on();
		$r->change_plan( array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4 ) ), 'test' );
		Jobs::run( $r->id() );

		$this->assertStringContainsString( 'ip6=' . self::ADDRESS, $this->config()['ipconfig0'] );
		$this->assertSame( 4, (int) $this->config()['cores'] );
	}

	public function test_sync_is_idempotent_through_operations(): void {
		$r = $this->ready_vm();
		$this->ipv6_on();
		$this->assertSame( 'written', ( new Operations( $r ) )->reconcile_ipv6() );
		$this->assertSame( 'same', ( new Operations( Resource::find( $r->id() ) ) )->reconcile_ipv6() );
		$this->assertSame( self::ADDRESS, Ipv6::address( Clusters::get( 'lab' ), 5000 ) );
	}
}
