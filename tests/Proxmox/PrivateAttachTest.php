<?php
/**
 * The private network of a customer server (plan B): net1 only, a guest file, one restart. Only the fake client is used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Cli\PrivateNetCommand;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\NetworkTab;
use BeltoftServices\Proxmox\PrivateAttach;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class PrivateAttachTest extends TestCase {

	private FakeClient $fake;

	public function set_up(): void {
		parent::set_up();
		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		add_filter( 'beltoft_services_private_settle', '__return_zero' );
		$this->wipe();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5 ) );
		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		add_filter( 'beltoft_services_drivers', static function ( $drivers ) {
			$drivers['proxmox'] = new ProxmoxDriver();
			return $drivers;
		} );
		$this->fake->add_node( 'node-a' );
		$this->seed_capacity();
		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'name' => 'tpl', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'agent' => '1', 'memory' => 512, 'cores' => 1 ) ) );
		$this->save_cluster( 'vmbr1' );
		foreach ( range( 10, 24 ) as $octet ) {
			IpPool::add( '192.0.2.' . $octet );
		}
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		remove_all_filters( 'beltoft_services_private_settle' );
		delete_option( Clusters::OPTION );
		$this->wipe();
		parent::tear_down();
	}

	private function wipe(): void {
		global $wpdb;
		foreach ( array( 'vms', 'ips', 'private_ips', 'private_nets' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()[ $table ] ) );
		}
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	private function save_cluster( string $private_bridge ): void {
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006', 'token_id' => 'svc@pve!beltoft',
			'token_secret' => 'secret-secret-secret-1', 'client' => 'fake', 'pool_api' => 'pools', 'nodes' => 'node-a',
			'storage' => 'disk-store', 'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9', 'private_bridge' => $private_bridge,
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24', 'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => '9000 = Ubuntu', 'default_template' => '9000',
		) ) );
	}

	private function ready( string $os = 'ubuntu' ): Resource {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );
		Jobs::run( $resource->id() );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ), (string) wp_json_encode( array( $fresh->get( 'error' ), $fresh->get( 'waiting' ), $fresh->get( 'steps' ) ) ) );
		if ( '' !== $os ) {
			$this->fake->set_osinfo( (int) $fresh->get( 'vmid' ), array( 'id' => $os, 'name' => ucfirst( $os ) ) );
		}
		return $fresh;
	}

	private function calls( string $method, string $suffix ): array {
		return array_values( array_filter( $this->fake->calls(), static fn( $c ) => $c['method'] === $method && str_ends_with( $c['path'], $suffix ) ) );
	}

	private function attached( string $os = 'ubuntu' ): Resource {
		$r = $this->ready( $os );
		Jobs::act( $r->id(), 'private_attach', array() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'attached', $fresh->get( 'private_state' ) );
		return $fresh;
	}

	public function test_attach_writes_net1_only_the_netplan_file_and_restarts_once(): void {
		$r    = $this->ready();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->calls(); // The build's own calls are before this point.
		$before = count( $this->fake->calls() );
		Jobs::act( $r->id(), 'private_attach', array() );
		$fresh = Resource::find( $r->id() );

		$this->assertSame( 'attached', $fresh->get( 'private_state' ) );
		$config = $this->fake->vm( $vmid )['config'];
		$this->assertMatchesRegularExpression( '/^virtio=BC:24:11:[0-9A-F:]{8},bridge=vmbr1,tag=1000,firewall=1$/', $config['net1'] );
		$this->assertArrayNotHasKey( 'ipconfig1', $config );

		$mine = array_slice( $this->fake->calls(), $before );
		foreach ( $mine as $call ) {
			$this->assertArrayNotHasKey( 'ipconfig1', $call['params'] ?? array(), 'Never ipconfig1 on a customer server.' );
		}
		$order = array();
		foreach ( $mine as $call ) {
			if ( 'POST' === $call['method'] && preg_match( '#/(status/shutdown|status/start|agent/file-write)$#', $call['path'], $m ) ) {
				$order[] = $m[1];
			}
		}
		$this->assertSame( array( 'agent/file-write', 'status/shutdown', 'status/start' ), $order, 'One restart, after the file.' );

		$file = $this->fake->guest_file( $vmid, PrivateAttach::NETPLAN_FILE );
		$this->assertStringContainsString( 'macaddress: "' . strtolower( PrivateAttach::mac_of( $config['net1'] ) ) . '"', $file );
		$this->assertStringContainsString( '- ' . PrivateNets::address_for_resource( $r->id() ) . '/28', $file );
		$this->assertStringNotContainsString( 'gateway', $file );
		$this->assertStringNotContainsString( 'nameservers', $file );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );

		// The private side is open under a DROP policy (a server with the dashboard firewall).
		$private = array_values( array_filter( $this->fake->vm( $vmid )['firewall_rules'], static fn( $rule ) => \BeltoftServices\Proxmox\Firewall::PRIVATE_RULE === (string) ( $rule['comment'] ?? '' ) ) );
		$this->assertCount( 1, $private, 'One bs-sys:private rule.' );
		$this->assertSame( 'net1', $private[0]['iface'] );
		$this->assertSame( 'ACCEPT', $private[0]['action'] );
		$this->assertSame( '10.64.0.2', PrivateNets::address_for_resource( $r->id() ) );
	}

	public function test_a_second_attach_changes_nothing(): void {
		$r      = $this->attached();
		$before = count( $this->fake->calls() );
		Jobs::act( $r->id(), 'private_attach', array() );
		$writes = array_filter( array_slice( $this->fake->calls(), $before ), static fn( $c ) => 'GET' !== $c['method'] );
		$this->assertSame( array(), array_values( $writes ) );
	}

	public function test_almalinux_gets_a_networkmanager_keyfile_and_not_netplan(): void {
		$r    = $this->attached( 'almalinux' );
		$vmid = (int) $r->get( 'vmid' );
		$file = $this->fake->guest_file( $vmid, PrivateAttach::NM_FILE );
		$this->assertStringContainsString( 'type=ethernet', $file );
		$this->assertStringContainsString( 'method=manual', $file );
		$this->assertStringContainsString( 'address1=10.64.0.2/28', $file );
		$this->assertStringContainsString( 'never-default=true', $file );
		$this->assertStringContainsString( 'mac-address=' . $r->get( 'private_mac' ), $file );
		$this->assertNull( $this->fake->guest_file( $vmid, PrivateAttach::NETPLAN_FILE ) );
	}

	public function test_an_unknown_system_gets_no_file_but_the_card_and_a_notice(): void {
		$r    = $this->attached( 'arch' );
		$vmid = (int) $r->get( 'vmid' );
		$this->assertNull( $this->fake->guest_file( $vmid, PrivateAttach::NETPLAN_FILE ) );
		$this->assertNull( $this->fake->guest_file( $vmid, PrivateAttach::NM_FILE ) );
		$this->assertArrayHasKey( 'net1', $this->fake->vm( $vmid )['config'] );
		$this->assertSame( 'manual', Resource::find( $r->id() )->get( 'private_notice' ) );
		$this->assertNotSame( '', PrivateAttach::view( Resource::find( $r->id() ) )['notice'] );
	}

	public function test_debian_is_netplan_and_the_family_filter_can_change_it(): void {
		$this->assertSame( 'netplan', PrivateAttach::family( 'debian' ) );
		$this->assertSame( 'nm', PrivateAttach::family( 'Rocky' ) );
		$this->assertSame( 'nm', PrivateAttach::family( 'rhel' ) );
		$this->assertSame( 'none', PrivateAttach::family( 'mswindows' ) );
		add_filter( 'beltoft_services_private_family', static fn( $f, $id ) => 'rocky' === $id ? 'none' : $f, 10, 2 );
		$this->assertSame( 'none', PrivateAttach::family( 'rocky' ) );
	}

	public function test_mac_and_address_are_validated_before_any_content_is_built(): void {
		$this->assertTrue( PrivateAttach::valid_mac( 'bc:24:11:00:10:11' ) );
		foreach ( array( '', 'bc:24:11:00:10', 'bc-24-11-00-10-11', "bc:24:11:00:10:11\n", 'zz:24:11:00:10:11', 'bc:24:11:00:10:11:22' ) as $bad ) {
			$this->assertFalse( PrivateAttach::valid_mac( $bad ), $bad );
			$this->assertNull( PrivateAttach::attach_file( 'netplan', $bad, '10.64.0.2', '10.64.0.0/28' ) );
		}
		foreach ( array( '10.64.0.1', '10.64.0.15', '10.64.1.2', '8.8.8.8', "10.64.0.2\nfoo", '10.64.0.2/28', '' ) as $bad ) {
			$this->assertNull( PrivateAttach::attach_file( 'netplan', 'bc:24:11:00:10:11', $bad, '10.64.0.0/28' ), $bad );
			$this->assertNull( PrivateAttach::attach_file( 'nm', 'bc:24:11:00:10:11', $bad, '10.64.0.0/28' ), $bad );
		}
		$this->assertNull( PrivateAttach::attach_file( 'none', 'bc:24:11:00:10:11', '10.64.0.2', '10.64.0.0/28' ) );
		$this->assertNotNull( PrivateAttach::attach_file( 'netplan', 'BC:24:11:00:10:11', '10.64.0.14', '10.64.0.0/28' ) );
		$this->assertSame( 'BC:24:11:00:10:11', PrivateAttach::mac_of( 'virtio=bc:24:11:00:10:11,bridge=vmbr1' ) );
		$this->assertSame( '', PrivateAttach::mac_of( 'virtio,bridge=vmbr1' ) );
	}

	public function test_no_working_agent_refuses_and_changes_nothing(): void {
		$r    = $this->ready( '' );
		$vmid = (int) $r->get( 'vmid' );
		Jobs::act( $r->id(), 'private_attach', array() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( '', (string) $fresh->get( 'private_state' ) );
		$this->assertSame( 'agent', $fresh->get( 'private_notice' ) );
		$this->assertNull( PrivateNets::for_resource( $r->id() ), 'No address was reserved.' );
		$this->assertArrayNotHasKey( 'net1', $this->fake->vm( $vmid )['config'] );
		$this->assertSame( array(), $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertStringContainsString( 'qemu-guest-agent', PrivateAttach::view( $fresh )['notice'] );
		$this->assertFalse( $fresh->get( Jobs::RESULT )['ok'] );
	}

	public function test_a_foreign_second_nic_is_refused_and_left_alone(): void {
		$r    = $this->ready();
		$vmid = (int) $r->get( 'vmid' );
		$vm   = $this->fake->vm( $vmid );
		$vm['config']['net1'] = 'virtio=AA:AA:AA:AA:AA:01,bridge=vmbr5';
		$this->fake->add_vm( $vm );
		Jobs::act( $r->id(), 'private_attach', array() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'occupied', $fresh->get( 'private_notice' ) );
		$this->assertSame( 'virtio=AA:AA:AA:AA:AA:01,bridge=vmbr5', $this->fake->vm( $vmid )['config']['net1'] );
		$this->assertNull( PrivateNets::for_resource( $r->id() ) );
		$this->assertSame( array(), $this->calls( 'POST', '/status/shutdown' ) );
		// Detach does not remove a card that is not ours either.
		Jobs::act( $r->id(), 'private_detach', array() );
		$this->assertSame( 'virtio=AA:AA:AA:AA:AA:01,bridge=vmbr5', $this->fake->vm( $vmid )['config']['net1'] );
		$this->assertSame( array(), $this->calls( 'POST', '/status/shutdown' ) );
	}

	public function test_check_refuses_attach_of_a_stopped_server_before_any_call(): void {
		$r = $this->ready();
		\BeltoftServices\Proxmox\Operations::remember_power( $r->id(), 'stopped' );
		$this->assertNotNull( Actions::check( $r, 'private_attach', array() ) );
		\BeltoftServices\Proxmox\Operations::remember_power( $r->id(), 'running' );
		$this->assertNull( Actions::check( $r, 'private_attach', array() ) );
	}

	public function test_detach_replaces_the_file_removes_net1_releases_the_address_and_is_idempotent(): void {
		$r    = $this->attached();
		$vmid = (int) $r->get( 'vmid' );
		Jobs::act( $r->id(), 'private_detach', array() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( '', (string) $fresh->get( 'private_state' ) );
		$this->assertArrayNotHasKey( 'net1', $this->fake->vm( $vmid )['config'] );
		$this->assertNull( PrivateNets::for_resource( $r->id() ) );
		$this->assertSame( "# Private network removed.\nnetwork:\n  version: 2\n", $this->fake->guest_file( $vmid, PrivateAttach::NETPLAN_FILE ) );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );

		$before = count( $this->fake->calls() );
		Jobs::act( $r->id(), 'private_detach', array() );
		$writes = array_filter( array_slice( $this->fake->calls(), $before ), static fn( $c ) => 'GET' !== $c['method'] );
		$this->assertSame( array(), array_values( $writes ), 'A second detach does nothing.' );
	}

	public function test_detach_of_a_networkmanager_guest_writes_a_disabled_profile(): void {
		$r    = $this->attached( 'rocky' );
		$vmid = (int) $r->get( 'vmid' );
		Jobs::act( $r->id(), 'private_detach', array() );
		$file = $this->fake->guest_file( $vmid, PrivateAttach::NM_FILE );
		$this->assertStringContainsString( 'autoconnect=false', $file );
		$this->assertStringContainsString( 'method=disabled', $file );
		$this->assertStringNotContainsString( 'address1', $file );
	}

	public function test_the_address_is_released_only_after_the_removal_is_verified(): void {
		$r    = $this->attached();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->fail_next( 'PUT', '#/qemu/' . $vmid . '/config$#', array( 'status' => 500, 'message' => 'scripted' ) );
		Jobs::act( $r->id(), 'private_detach', array() );
		$fresh = Resource::find( $r->id() );
		$this->assertArrayHasKey( 'net1', $this->fake->vm( $vmid )['config'] );
		$this->assertNotNull( PrivateNets::for_resource( $r->id() ), 'Still reserved while the card is there.' );
		$this->assertSame( 'detaching', $fresh->get( 'private_state' ) );
		// The retry finishes it.
		$fresh->set( array( 'act_attempts' => 0 ) );
		Jobs::act( $r->id(), 'private_detach', array() );
		$this->assertNull( PrivateNets::for_resource( $r->id() ) );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'private_state' ) );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
	}

	public function test_the_network_stays_while_another_server_holds_an_address(): void {
		$one = $this->attached();
		$this->assertSame( '10.64.0.2', PrivateNets::address_for_resource( $one->id() ) );
		$owner = (int) $one->license()['user_id'];
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $owner ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );
		Jobs::run( $resource->id() );
		$two = Resource::find( $resource->id() );
		$this->fake->set_osinfo( (int) $two->get( 'vmid' ), array( 'id' => 'debian' ) );
		Jobs::act( $two->id(), 'private_attach', array() );
		$this->assertSame( '10.64.0.3', PrivateNets::address_for_resource( $two->id() ) );
		$view = PrivateAttach::view( Resource::find( $two->id() ) );
		$this->assertCount( 1, $view['others'] );
		$this->assertSame( '10.64.0.2', $view['others'][0]['address'] );
		Jobs::act( $one->id(), 'private_detach', array() );
		$row = PrivateNets::for_resource( $two->id() );
		$this->assertSame( 'held', $row['state'], 'The network stays with its owner.' );
	}

	public function test_the_network_tab_follows_the_rules(): void {
		$r = $this->ready();
		$tabs = array( 'overview' => 'Overview', 'access' => 'Access', 'reinstall' => 'Reinstall' );
		$out  = NetworkTab::tabs( $tabs, $r->id() );
		$this->assertSame( array( 'overview', 'access', 'network', 'reinstall' ), array_keys( $out ) );
		$this->assertContains( 'network', NetworkTab::slugs( array( 'access' ) ) );

		$this->save_cluster( '' );
		$this->assertSame( $tabs, NetworkTab::tabs( $tabs, $r->id() ), 'No private bridge, no tab.' );
		$this->save_cluster( 'vmbr1' );

		$this->wipe_nets();
		$this->assertSame( $tabs, NetworkTab::tabs( $tabs, $r->id() ), 'Nothing to reserve, no tab.' );
		$this->assertSame( array(), Actions::offered( $r ) === array() ? array() : array_intersect_key( Actions::offered( $r ), array( 'private_attach' => 1 ) ) );
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		$this->assertArrayHasKey( 'private_attach', Actions::offered( $r ) );

		$r->set( array( 'linked' => true ) );
		$this->assertSame( $tabs, NetworkTab::tabs( Resource::find( $r->id() ) ? $tabs : $tabs, $r->id() ), 'An adopted server has no tab.' );
		$this->assertArrayNotHasKey( 'private_attach', Actions::offered( Resource::find( $r->id() ) ) );
	}

	public function test_the_tab_prints_state_address_network_and_the_check(): void {
		$r = $this->attached();
		ob_start();
		NetworkTab::pane( 'network', $r->id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Attached', $html );
		$this->assertStringContainsString( '10.64.0.2', $html );
		$this->assertStringContainsString( '10.64.0.0/28', $html );
		$this->assertStringContainsString( 'ip -br a', $html );
		$this->assertStringContainsString( 'data-net-detach', $html );
		$this->assertStringNotContainsString( 'data-net-attach', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		ob_start();
		NetworkTab::pane( 'firewall', $r->id() );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_cli_adds_and_lists_networks(): void {
		$this->wipe_nets();
		\WP_CLI::reset();
		$cli = new PrivateNetCommand();
		$cli->add( array( '1001', '10.64.0.16/28' ), array() );
		$this->assertStringContainsString( 'added', \WP_CLI::text() );
		\WP_CLI::reset();
		$cli->list_( array(), array() );
		$this->assertStringContainsString( "1001\t10.64.0.16/28\tfree", \WP_CLI::text() );
		foreach ( array( array( '1002', '10.64.0.16/28' ), array( '1001', '10.64.0.32/28' ), array( 'x', '10.64.0.48/28' ), array( '1003', '10.64.0.5/28' ), array( '1003', '8.8.8.0/28' ) ) as $bad ) {
			try {
				$cli->add( $bad, array() );
				$this->fail( 'Expected an error for ' . implode( ' ', $bad ) );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_a_reinstall_keeps_the_private_network_and_writes_the_file_again(): void {
		$r    = $this->attached();
		$vmid = (int) $r->get( 'vmid' );
		$mac  = (string) $r->get( 'private_mac' );
		$this->fake->default_osinfo( array( 'id' => 'ubuntu' ) );
		$writes_before = count( $this->calls( 'POST', '/agent/file-write' ) );

		Jobs::act( $r->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
			$this->run_jobs();
		}

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ), (string) wp_json_encode( array( $fresh->get( 'error' ), $fresh->get( 'waiting' ), $fresh->get( 'steps' ), $fresh->get( 'private_op' ) ) ) );
		$this->assertSame( 'attached', $fresh->get( 'private_state' ) );
		$config = $this->fake->vm( $vmid )['config'];
		$this->assertStringContainsString( 'bridge=vmbr1,tag=1000', $config['net1'] );
		$this->assertArrayNotHasKey( 'ipconfig1', $config );
		$this->assertSame( '10.64.0.2', PrivateNets::address_for_resource( $r->id() ), 'The same address.' );
		$this->assertGreaterThan( $writes_before, count( $this->calls( 'POST', '/agent/file-write' ) ), 'The file is written again.' );
		$this->assertStringContainsString( '10.64.0.2/28', (string) $this->fake->guest_file( $vmid, PrivateAttach::NETPLAN_FILE ) );
		$this->assertSame( PrivateAttach::mac_of( $config['net1'] ), (string) Resource::find( $r->id() )->get( 'private_mac' ) );
		unset( $mac );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$comments = array_map( static fn( $rule ) => (string) ( $rule['comment'] ?? '' ), $this->fake->vm( (int) Resource::find( $r->id() )->get( 'vmid' ) )['firewall_rules'] );
		$this->assertContains( \BeltoftServices\Proxmox\Firewall::PRIVATE_RULE, $comments, 'The rebuilt server keeps its private side open.' );
	}

	public function test_a_reinstall_whose_agent_never_answers_still_ends_ready_and_asks_for_attention(): void {
		$r = $this->attached();
		$this->fake->default_osinfo( null );
		Jobs::act( $r->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$deadline = false;
		for ( $i = 0; $i < 25 && 'ready' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			$cur = Resource::find( $r->id() );
			$op  = $cur->get( 'private_op' );
			if ( ! $deadline && is_array( $op ) && ! empty( $op['deadline'] ) ) {
				$op['deadline'] = time() - 1;
				$cur->set( array( 'private_op' => $op ) );
				$deadline = true;
			}
			$cur->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
			$this->run_jobs();
		}
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'failed', $fresh->get( 'private_state' ) );
		$this->assertSame( 'failed', $fresh->get( 'private_notice' ) );
		$this->assertNotNull( PrivateNets::for_resource( $r->id() ), 'The address stays reserved.' );
	}

	private function wipe_nets(): void {
		global $wpdb;
		foreach ( array( 'private_ips', 'private_nets' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()[ $table ] ) );
		}
	}
}
