<?php
/** Tests a private NIC against only the fake Proxmox client. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\PrivateNic;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class StartDuringPrivateAttachClient extends FakeClient {
	private bool $started = false;
	public function request( string $method, string $path, array $params = array() ) {
		if ( ! $this->started && 'PUT' === $method && str_ends_with( $path, '/qemu/5000/config' ) ) {
			$this->started = true;
			$vm = $this->vm( 5000 );
			$this->add_vm( array_merge( $vm, array( 'status' => 'running' ) ) );
		}
		return parent::request( $method, $path, $params );
	}
}

class PrivateNicTest extends TestCase {
	private FakeClient $fake;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()['private_ips'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()['private_nets'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()['vms'] ) );
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'p', 32 ) ) );
		$this->settings( array( 'site_tag' => 'private-nic-test' ) );
		$this->fake = new FakeClient();
		$this->fake->add_node( 'node-a' );
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr0', 'private_bridge' => 'vmbr1',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53',
			'prefix' => '24', 'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => '9000 = Debian',
		) ) );
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Clusters::OPTION );
		parent::tear_down();
	}

	private function vm( string $status = 'stopped', array $config = array() ): Resource {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		$this->assertSame( 5000, VmRecords::allocate( $r->id(), 'lab', 5000, 5000, array(), static fn() => true ) );
		$this->fake->add_vm( array(
			'vmid' => 5000, 'node' => 'node-a', 'status' => $status,
			'config' => array_merge( array( 'description' => Ownership::tag( $r ), 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0' ), $config ),
		) );
		return $r;
	}

	private function writes(): array {
		return array_values( array_filter( $this->fake->calls(), static fn( $call ) => 'PUT' === $call['method'] && str_contains( $call['path'], '/config' ) ) );
	}

	public function test_attach_writes_only_private_nic_and_retries_without_a_second_write(): void {
		$r = $this->vm();
		$first = PrivateNic::attach( $r, $this->fake );
		$this->assertFalse( is_wp_error( $first ), is_wp_error( $first ) ? $first->get_error_code() . ': ' . $first->get_error_message() : '' );
		$this->assertTrue( $first['changed'] );
		$this->assertSame( '10.64.0.2', $first['address'] );
		$this->assertCount( 1, $this->writes() );
		$this->assertSame( 'virtio,bridge=vmbr1,tag=1000,firewall=1', $this->writes()[0]['params']['net1'] );
		$this->assertSame( 'ip=10.64.0.2/28', $this->writes()[0]['params']['ipconfig1'] );
		$this->assertArrayNotHasKey( 'net0', $this->writes()[0]['params'] );
		$second = PrivateNic::attach( $r, $this->fake );
		$this->assertFalse( $second['changed'] );
		$this->assertCount( 1, $this->writes() );
	}

	public function test_attach_uses_one_pool_lock_for_network_and_address(): void {
		$r = $this->vm();
		$locks = array();
		$track = static function ( $sql ) use ( &$locks ) {
			if ( str_contains( $sql, 'GET_LOCK(' ) && str_contains( $sql, '_privnet_0' ) ) {
				$locks[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $track );
		try {
			$this->assertFalse( is_wp_error( PrivateNic::attach( $r, $this->fake ) ) );
		} finally {
			remove_filter( 'query', $track );
		}
		$this->assertCount( 1, $locks, 'One lock protects both claims.' );
	}

	public function test_refuses_a_running_vm_or_an_occupied_second_nic(): void {
		$r = $this->vm( 'running' );
		$this->assertSame( 'vm_running', PrivateNic::attach( $r, $this->fake )->get_error_code() );
		$this->assertNull( PrivateNets::for_user( (int) $r->license()['user_id'] ) );
		$this->assertCount( 0, $this->writes() );
		$this->fake->add_vm( array( 'vmid' => 5000, 'node' => 'node-a', 'status' => 'stopped', 'config' => array(
			'description' => Ownership::tag( $r ), 'net1' => 'virtio=11:22:33:44:55:66,bridge=vmbr0',
		) ) );
		$this->assertSame( 'private_nic_occupied', PrivateNic::attach( $r, $this->fake )->get_error_code() );
		$this->assertNull( PrivateNets::for_user( (int) $r->license()['user_id'] ), 'A foreign NIC must not allocate a customer VLAN.' );
		$this->assertCount( 0, $this->writes() );
	}

	public function test_refuses_a_vm_without_its_ownership_tag_before_allocating(): void {
		$r = $this->vm( 'stopped', array( 'description' => 'another customer' ) );
		$this->assertSame( 'not_ours', PrivateNic::attach( $r, $this->fake )->get_error_code() );
		$this->assertNull( PrivateNets::for_user( (int) $r->license()['user_id'] ) );
		$this->assertCount( 0, $this->writes() );
	}

	public function test_retry_keeps_the_address_after_a_rejected_write(): void {
		$r = $this->vm();
		$this->fake->fail_next( 'PUT', '#/qemu/5000/config$#', array( 'status' => 400, 'message' => 'configuration digest changed' ) );
		$failed = PrivateNic::attach( $r, $this->fake );
		$this->assertTrue( is_wp_error( $failed ) );
		$this->assertNull( PrivateNets::address_for_resource( $r->id() ), 'A definite 400 rejection must release the address.' );
		$this->assertNull( PrivateNets::for_user( (int) $r->license()['user_id'] ), 'A failed first attach must not hold a VLAN.' );
		$done = PrivateNic::attach( $r, $this->fake );
		$this->assertFalse( is_wp_error( $done ) );
		$this->assertSame( '10.64.0.2', $done['address'] );
	}

	public function test_ambiguous_server_error_records_pending_reservation_for_retry(): void {
		$r = $this->vm();
		$this->fake->fail_next( 'PUT', '#/qemu/5000/config$#', array( 'status' => 500, 'message' => 'request timed out after submission' ) );
		$first = PrivateNic::attach( $r, $this->fake );
		$this->assertTrue( is_wp_error( $first ) );
		$this->assertSame( '10.64.0.2', PrivateNets::address_for_resource( $r->id() ) );
		$this->assertSame( 1, Resource::find( $r->id() )->get( 'private_nic_pending' ) );
		$this->assertFalse( is_wp_error( PrivateNic::attach( $r, $this->fake ) ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'private_nic_pending' ) );
	}

	public function test_a_later_rejection_does_not_release_an_earlier_ambiguous_attach(): void {
		$r = $this->vm();
		$this->fake->fail_next( 'PUT', '#/qemu/5000/config$#', array( 'status' => 500, 'message' => 'ambiguous first write' ) );
		$this->assertTrue( is_wp_error( PrivateNic::attach( $r, $this->fake ) ) );
		$this->fake->fail_next( 'PUT', '#/qemu/5000/config$#', array( 'status' => 400, 'message' => 'second write rejected' ) );
		$this->assertTrue( is_wp_error( PrivateNic::attach( $r, $this->fake ) ) );
		$this->assertSame( '10.64.0.2', PrivateNets::address_for_resource( $r->id() ) );
		$this->assertSame( 1, Resource::find( $r->id() )->get( 'private_nic_pending' ) );
	}

	public function test_retry_accepts_a_generated_mac_but_refuses_vlans_trunked_by_someone_else(): void {
		$r = $this->vm();
		$done = PrivateNic::attach( $r, $this->fake );
		$this->assertFalse( is_wp_error( $done ) );
		$this->fake->add_vm( array( 'vmid' => 5000, 'node' => 'node-a', 'status' => 'running', 'config' => array(
			'description' => Ownership::tag( $r ),
			'net1' => 'virtio=11:22:33:44:55:66,bridge=vmbr1,tag=1000,firewall=1',
			'ipconfig1' => 'ip=10.64.0.2/28',
		) ) );
		$this->assertFalse( PrivateNic::attach( $r, $this->fake )['changed'] );
		$this->fake->add_vm( array( 'vmid' => 5000, 'node' => 'node-a', 'status' => 'stopped', 'config' => array(
			'description' => Ownership::tag( $r ),
			'net1' => 'virtio=11:22:33:44:55:66,bridge=vmbr1,tag=1000,firewall=1,trunks=1001',
			'ipconfig1' => 'ip=10.64.0.2/28',
		) ) );
		$this->assertSame( 'private_nic_occupied', PrivateNic::attach( $r, $this->fake )->get_error_code() );
		$this->fake->add_vm( array( 'vmid' => 5000, 'node' => 'node-a', 'status' => 'stopped', 'config' => array(
			'description' => Ownership::tag( $r ),
			'net1' => 'virtio=11:22:33:44:55:66,bridge=vmbr1,tag=1000,firewall=1,trunks',
			'ipconfig1' => 'ip=10.64.0.2/28',
		) ) );
		$this->assertSame( 'private_nic_occupied', PrivateNic::attach( $r, $this->fake )->get_error_code() );
	}

	public function test_no_bridge_setting_refuses_before_a_cluster_call_or_allocation(): void {
		$r = $this->vm();
		$clusters = get_option( Clusters::OPTION );
		$clusters['lab']['private_bridge'] = '';
		update_option( Clusters::OPTION, $clusters );
		$this->assertSame( 'private_bridge_missing', PrivateNic::attach( $r, $this->fake )->get_error_code() );
		$this->assertNull( PrivateNets::for_user( (int) $r->license()['user_id'] ) );
		$this->assertCount( 0, $this->writes() );
	}

	public function test_license_transfer_moves_stopped_vm_to_new_customer_vlan(): void {
		$r = $this->vm();
		$old_user = (int) $r->license()['user_id'];
		$this->assertFalse( is_wp_error( PrivateNic::attach( $r, $this->fake ) ) );
		$this->assertTrue( PrivateNets::add( 1001, '10.64.0.16/28' ) );
		$new_user = self::factory()->user->create();
		$this->assertTrue( Licenses::update( (int) $r->license()['id'], array( 'user_id' => $new_user ) ) );

		$moved = PrivateNic::attach( $r, $this->fake );
		$this->assertFalse( is_wp_error( $moved ), is_wp_error( $moved ) ? $moved->get_error_message() : '' );
		$this->assertSame( 1001, $moved['network']['vlan'] );
		$this->assertSame( '10.64.0.18', $moved['address'] );
		$this->assertSame( 'quarantine', PrivateNets::for_user( $old_user )['state'] );
		$this->assertSame( $new_user, PrivateNets::for_resource( $r->id() )['user_id'] );
		$this->assertStringContainsString( 'tag=1001', $this->fake->vm( 5000 )['config']['net1'] );
	}

	public function test_license_transfer_waits_for_stop_without_allocating_a_new_vlan(): void {
		$r = $this->vm();
		$old_user = (int) $r->license()['user_id'];
		$this->assertFalse( is_wp_error( PrivateNic::attach( $r, $this->fake ) ) );
		$this->assertTrue( PrivateNets::add( 1001, '10.64.0.16/28' ) );
		$new_user = self::factory()->user->create();
		$this->assertTrue( Licenses::update( (int) $r->license()['id'], array( 'user_id' => $new_user ) ) );
		$vm = $this->fake->vm( 5000 );
		$this->fake->add_vm( array_merge( $vm, array( 'status' => 'running' ) ) );

		$this->assertSame( 'private_transfer_requires_stop', PrivateNic::attach( $r, $this->fake )->get_error_code() );
		$this->assertNull( PrivateNets::for_user( $new_user ) );
		$this->assertSame( $old_user, PrivateNets::for_resource( $r->id() )['user_id'] );
		$this->assertStringContainsString( 'tag=1000', $this->fake->vm( 5000 )['config']['net1'] );
	}

	public function test_start_race_keeps_reservation_pending_until_the_vm_stops(): void {
		$this->fake = new StartDuringPrivateAttachClient();
		$this->fake->add_node( 'node-a' );
		$r = $this->vm();
		$result = PrivateNic::attach( $r, $this->fake );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'private_nic_pending', $result->get_error_code() );
		$this->assertSame( '10.64.0.2', PrivateNets::address_for_resource( $r->id() ) );
		$this->assertSame( 'private_nic_pending', PrivateNic::attach( $r, $this->fake )->get_error_code() );
		$this->assertCount( 1, $this->writes() );
		$vm = $this->fake->vm( 5000 );
		$this->fake->add_vm( array_merge( $vm, array( 'status' => 'stopped' ) ) );
		$this->assertFalse( PrivateNic::attach( $r, $this->fake )['changed'] );
	}
}
