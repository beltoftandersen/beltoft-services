<?php
/**
 * The IP filter rollout: the private address follows an attach and a detach, a set that does not read back never
 * turns the filter on, and the read-only report. Only the fake client is used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Cli\Commands;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Resource;

require_once __DIR__ . '/FirewallTestCase.php';
require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

/**
 * A client that silently drops the entries written to the public ipfilter set.
 */
class DroppingClient extends FirewallClient {

	/**
	 * Drop entry writes to ipfilter-net0.
	 *
	 * @var bool
	 */
	public $drop_entries = false;

	public function request( string $method, string $path, array $params = array() ) {
		if ( $this->drop_entries && 'POST' === strtoupper( $method ) && substr( $path, -20 ) === '/ipset/ipfilter-net0' ) {
			return null;
		}

		return parent::request( $method, $path, $params );
	}
}

class IpfilterTest extends FirewallTestCase {

	public function set_up(): void {
		parent::set_up();

		add_filter( 'beltoft_services_private_settle', '__return_zero' );
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_private_settle' );
		parent::tear_down();
	}

	/**
	 * A running server with the filter on.
	 */
	private function filtered(): Resource {
		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_ipfilter' => '1', 'private_bridge' => 'vmbr1' ) );
		$this->fake->set_osinfo( (int) $r->get( 'vmid' ), array( 'id' => 'ubuntu', 'name' => 'Ubuntu' ) );

		return $this->apply_default( $r );
	}

	private function apply_default( Resource $r ): Resource {
		$r      = Resource::find( $r->id() );
		$normal = Firewall::normalise( array( $this->rule( 'tcp', 22 ) ), $r );
		$this->assertIsArray( $normal, is_wp_error( $normal ) ? $normal->get_error_message() : '' );
		$this->assertGreaterThan( 0, Firewall::store( $r, true, $normal, true, 1 ) );
		Jobs::act( $r->id(), 'fw_apply', array() );

		return Resource::find( $r->id() );
	}

	public function test_a_vps_keeps_its_public_only_set_and_the_filter_on(): void {
		$r  = $this->filtered();
		$vm = $this->fake->vm( (int) $r->get( 'vmid' ) );

		$this->assertSame( 1, (int) $vm['firewall_options']['ipfilter'] );
		$this->assertSame( array( 'ipfilter-net0' ), array_keys( $vm['firewall_ipsets'] ) );
	}

	public function test_attach_puts_the_private_address_in_the_set_and_detach_removes_it(): void {
		$r    = $this->filtered();
		$vmid = (int) $r->get( 'vmid' );

		Jobs::act( $r->id(), 'private_attach', array() );
		$this->assertSame( 'attached', Resource::find( $r->id() )->get( 'private_state' ) );
		$vm = $this->fake->vm( $vmid );
		$this->assertSame( 1, (int) $vm['firewall_options']['ipfilter'] );
		$this->assertSame( array( (string) PrivateNets::address_for_resource( $r->id() ) ), array_column( $vm['firewall_ipsets']['ipfilter-net1'], 'cidr' ) );
		$this->assertSame( array( (string) $r->get( 'ip' ) ), array_column( $vm['firewall_ipsets']['ipfilter-net0'], 'cidr' ) );

		Jobs::act( $r->id(), 'private_detach', array() );
		$vm = $this->fake->vm( $vmid );
		$this->assertArrayNotHasKey( 'ipfilter-net1', $vm['firewall_ipsets'], 'No private address stays allowed.' );
		$this->assertSame( 1, (int) $vm['firewall_options']['ipfilter'] );
		$this->assertSame( array( (string) $r->get( 'ip' ) ), array_column( $vm['firewall_ipsets']['ipfilter-net0'], 'cidr' ) );
	}

	public function test_attach_leaves_the_sets_alone_while_the_filter_is_off(): void {
		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1', 'private_bridge' => 'vmbr1' ) );
		$this->fake->set_osinfo( (int) $r->get( 'vmid' ), array( 'id' => 'ubuntu', 'name' => 'Ubuntu' ) );
		Jobs::act( $r->id(), 'private_attach', array() );

		$vm = $this->fake->vm( (int) $r->get( 'vmid' ) );
		$this->assertSame( 'attached', Resource::find( $r->id() )->get( 'private_state' ) );
		$this->assertArrayNotHasKey( 'firewall_ipsets', $vm );
	}

	public function test_a_set_that_does_not_read_back_never_turns_the_filter_on(): void {
		$this->fake = new DroppingClient();
		$this->fake->drop_entries = true;
		$this->fake->add_node( 'node-a' );
		$this->seed_capacity();
		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'name' => 'tpl', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'agent' => '1', 'memory' => 512, 'cores' => 1 ) ) );
		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_ipfilter' => '1' ) );
		$this->apply_default( $r );

		$this->assertArrayNotHasKey( 'ipfilter', $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_options'] );
	}

	public function test_a_blank_address_never_becomes_a_net1_only_filter_on_a_vps(): void {
		list( $r ) = $this->ready();
		$blank = $this->blank_ip( $r );
		$this->assertTrue( PrivateNets::add( 1001, '10.64.0.16/28' ) );
		PrivateNets::reserve( (int) $blank->license()['user_id'], $blank->id() );
		$this->assertNull( Firewall::expected_ipsets( $blank, true ) );
		$this->assertNull( Firewall::expected_ipsets( $blank, false ) );
	}

	private function blank_ip( Resource $r ): Resource {
		$r->set( array( 'ip' => '' ) );

		return Resource::find( $r->id() );
	}

	public function test_the_report_lists_expected_actual_and_filter_state_and_changes_nothing(): void {
		$r = $this->filtered();
		\WP_CLI::reset();
		$before = count( array_filter( $this->fake->calls(), static fn( $c ) => 'GET' !== $c['method'] ) );

		( new Commands() )->ipfilter_report( array( 'lab' ), array( 'format' => 'json' ) );
		$rows = json_decode( \WP_CLI::$lines[0][1], true );
		$this->assertSame( 'ok', $rows[0]['status'] );
		$this->assertSame( 'on', $rows[0]['ipfilter'] );
		$this->assertSame( 'net0=' . $r->get( 'ip' ), $rows[0]['expected'] );
		$this->assertSame( $rows[0]['expected'], $rows[0]['actual'] );

		// A wrong address in the set, and the filter off.
		$vmid = (int) $r->get( 'vmid' );
		$base = '/nodes/node-a/qemu/' . $vmid . '/firewall';
		$this->fake->request( 'DELETE', $base . '/ipset/ipfilter-net0/' . $r->get( 'ip' ) );
		$this->fake->request( 'POST', $base . '/ipset/ipfilter-net0', array( 'cidr' => '192.0.2.200' ) );
		\WP_CLI::reset();
		( new Commands() )->ipfilter_report( array( 'lab' ), array( 'format' => 'json' ) );
		$rows = json_decode( \WP_CLI::$lines[0][1], true );
		$this->assertStringStartsWith( 'mismatch', $rows[0]['status'] );

		$this->fake->request( 'PUT', $base . '/options', array( 'ipfilter' => 0 ) );
		$this->fake->request( 'DELETE', $base . '/ipset/ipfilter-net0/192.0.2.200' );
		$this->fake->request( 'POST', $base . '/ipset/ipfilter-net0', array( 'cidr' => (string) $r->get( 'ip' ) ) );
		\WP_CLI::reset();
		( new Commands() )->ipfilter_report( array( 'lab' ), array( 'format' => 'json' ) );
		$rows = json_decode( \WP_CLI::$lines[0][1], true );
		$this->assertSame( 'sets ready, filter off', $rows[0]['status'] );

		$writes = array_filter( $this->fake->calls(), static fn( $c ) => 'GET' !== $c['method'] );
		// Only the test's own six manual writes after the report started count.
		$this->assertCount( $before + 5, $writes, 'The report itself wrote nothing.' );
	}
}
