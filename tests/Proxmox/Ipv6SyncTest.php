<?php
/**
 * IPv6 for NEW servers only: a server without meta ip6 is never given an address (no sweep, plan change and restore leave
 * its ipconfig0 alone); a server built with IPv6 gets a lost address back, never restarted. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\FakeClient;
use BeltoftServices\Proxmox\Vm\Operations;
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

	/** An IPv6 server: built when the cluster had a prefix, so it has meta ip6 and the address in ipconfig0. */
	private function ipv6_vm(): Resource {
		$this->ipv6_on();
		$r = $this->ready_vm();
		$r->set( array( 'ip6' => self::ADDRESS ) );

		return Resource::find( $r->id() );
	}

	public function test_there_is_no_sweep_for_existing_servers(): void {
		$this->assertFalse( has_action( Jobs::SWEEP, array( ServerSyncJobs::class, 'ipv6s' ) ) );
		$this->assertFalse( method_exists( ServerSyncJobs::class, 'ipv6s' ) );
	}

	public function test_a_server_without_ip6_meta_is_never_given_an_address(): void {
		$r   = $this->ready_vm();
		$old = $this->config()['ipconfig0'];
		$this->ipv6_on();
		$mark = count( $this->fake->calls() );

		$this->assertSame( 'skipped', ( new Operations( $r ) )->reconcile_ipv6() );
		$r->change_plan( array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4 ) ), 'test' );
		Jobs::run( $r->id() );

		$this->assertSame( 4, (int) $this->config()['cores'], 'The plan change itself went through.' );
		$this->assertSame( $old, $this->config()['ipconfig0'] );
		$this->assertSame( 0, $this->puts( $mark ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'ip6' ) );
	}

	public function test_a_restore_step_leaves_an_ipv4_server_alone(): void {
		$r   = $this->ready_vm();
		$old = $this->config()['ipconfig0'];
		$this->ipv6_on();

		( new Operations( $r ) )->reconcile_ipv6();

		$this->assertSame( $old, $this->config()['ipconfig0'] );
	}

	public function test_a_server_with_ip6_gets_a_lost_address_back_without_a_restart(): void {
		$r = $this->ipv6_vm();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => 'ip=192.0.2.50/24,gw=192.0.2.1' ) );
		$mark = count( $this->fake->calls() );

		$this->assertSame( 'written', ( new Operations( $r ) )->reconcile_ipv6() );

		$this->assertSame( 'ip=192.0.2.50/24,gw=192.0.2.1,ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->config()['ipconfig0'] );
		$this->assertSame( $this->config()['ipconfig0'], $this->config( true )['ipconfig0'], 'Proxmox shows the change at once: nothing is pending.' );
		$this->assertSame( array(), $this->power_calls( $mark ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( 'same', ( new Operations( Resource::find( $r->id() ) ) )->reconcile_ipv6() );
	}

	public function test_a_plan_change_repairs_a_server_with_ip6(): void {
		$r = $this->ipv6_vm();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => 'ip=192.0.2.50/24,gw=192.0.2.1' ) );
		$r->change_plan( array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4 ) ), 'test' );
		Jobs::run( $r->id() );

		$this->assertStringContainsString( 'ip6=' . self::ADDRESS, $this->config()['ipconfig0'] );
		$this->assertSame( 4, (int) $this->config()['cores'] );
	}

	public function test_feature_off_and_special_servers_do_nothing(): void {
		$r = $this->ipv6_vm();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => 'ip=192.0.2.50/24,gw=192.0.2.1' ) );
		$r->set( array( 'linked' => true ) );
		$this->assertSame( 'skipped', ( new Operations( Resource::find( $r->id() ) ) )->reconcile_ipv6() );
		$r->set( array( 'linked' => null ) );

		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => 'ip=dhcp' ) );
		$this->assertSame( 'skipped', ( new Operations( Resource::find( $r->id() ) ) )->reconcile_ipv6() );
		$this->assertSame( 'ip=dhcp', $this->config()['ipconfig0'] );
	}

	public function test_ipconfig0_is_not_pending_in_the_fake_like_real_pve(): void {
		$this->ready_vm();
		$this->fake->hold_net_rate( 5000 );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => 'ip=192.0.2.60/24,gw=192.0.2.1' ) );

		$this->assertSame( 'ip=192.0.2.60/24,gw=192.0.2.1', $this->config()['ipconfig0'] );
		$this->assertSame( 'ip=192.0.2.60/24,gw=192.0.2.1', $this->config( true )['ipconfig0'] );
	}
}
