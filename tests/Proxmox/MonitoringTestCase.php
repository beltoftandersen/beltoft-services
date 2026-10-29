<?php
/** Shared fake VM for monitoring tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

abstract class MonitoringTestCase extends TestCase {
	protected FakeClient $fake;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'm', 32 ) ) );
		$this->settings( array( 'pause_all' => 0, 'site_tag' => 'monitor-test' ) );
		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		$this->fake->add_node( 'node-a' );
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53',
			'prefix' => '24', 'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => '9000 = Debian',
		) ) );
	}

	public function tear_down(): void {
		global $wpdb;
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		parent::tear_down();
	}

	protected function ready_vm( int $vmid = 5000, string $email = '' ): Resource {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) + ( '' !== $email ? array( 'email' => $email ) : array() ) );
		$r = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		$this->assertSame( $vmid, VmRecords::allocate( $r->id(), 'lab', $vmid, $vmid, array(), static fn() => true ) );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready' ) ) );
		$this->fake->add_vm( array(
			'vmid' => $vmid, 'node' => 'node-a', 'status' => 'running',
			'config' => array( 'description' => Ownership::tag( $r ), 'memory' => 4096, 'cores' => 2, 'agent' => '1' ),
		) );
		return Resource::find( $r->id() );
	}
}
