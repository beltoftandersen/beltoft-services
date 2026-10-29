<?php
/**
 * Private network allocation without a Proxmox connection.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Product;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\PostgresProfile;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;
use BeltoftServices\Tests\Support\FakeDriver;

class PrivateNetsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()['private_ips'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()['private_nets'] ) );
	}

	private function resource( int $user_id ): Resource {
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product, array( 'user_id' => $user_id, 'fire' => false ) );
		$result  = Resource::create( $license, new FakeDriver(), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $result );
		return $result;
	}

	public function test_pool_validates_seed_and_allocates_exclusively(): void {
		$this->assertFalse( PrivateNets::add( 1000, '198.51.100.0/28' ) );
		$this->assertFalse( PrivateNets::add( 1000, '10.64.0.1/28' ) );
		$this->assertFalse( PrivateNets::add( 0, '10.64.0.0/28' ) );
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		$this->assertFalse( PrivateNets::add( 1001, '10.64.0.0/28' ) );
		$this->assertFalse( PrivateNets::add( 1000, '10.64.0.16/28' ) );
		$this->assertTrue( PrivateNets::add( 1001, '10.64.0.16/28' ) );

		$a = PrivateNets::hold( 11 );
		$this->assertSame( $a, PrivateNets::hold( 11 ) );
		$this->assertSame( 1000, $a['vlan'] );
		$this->assertSame( 1001, PrivateNets::hold( 12 )['vlan'] );
		$this->assertNull( PrivateNets::hold( 13 ) );
	}

	public function test_addresses_are_bound_to_the_resource_owner_and_network(): void {
		PrivateNets::add( 1000, '10.64.0.0/28' );
		PrivateNets::hold( 11 );
		$one = $this->resource( 11 );
		$two = $this->resource( 11 );
		$other = $this->resource( 12 );

		$this->assertSame( '10.64.0.2', PrivateNets::address( 11, $one->id() ) );
		$this->assertSame( '10.64.0.2', PrivateNets::address( 11, $one->id() ) );
		$this->assertSame( '10.64.0.3', PrivateNets::address( 11, $two->id() ) );
		$this->assertNull( PrivateNets::address( 12, $one->id() ) );
		$this->assertNull( PrivateNets::address( 11, $other->id() ) );
		$this->assertFalse( PrivateNets::retire( 11 ), 'The network remains held while a VM has an address.' );
		$this->assertTrue( PrivateNets::release_address( $one->id() ) );
		$this->assertTrue( PrivateNets::release_address( $two->id() ) );
		$this->assertTrue( PrivateNets::retire( 11 ) );
		$this->assertSame( 'quarantine', PrivateNets::for_user( 11 )['state'] );
		$this->assertNull( PrivateNets::hold( 12 ), 'A quarantined VLAN cannot be assigned to another customer.' );
		$this->assertSame( 0, PrivateNets::reclaim(), 'The 30-day quarantine has not elapsed.' );
		$this->assertSame( 'held', PrivateNets::hold( 11 )['state'], 'The original customer can return during quarantine.' );
	}

	public function test_postgres_profile_has_only_a_private_interface(): void {
		$net = array( 'cidr' => '10.64.0.0/28', 'vlan' => 1000 );
		$vm  = PostgresProfile::vm( $net, '10.64.0.2', 'vmbr1', 2, 4096, 60 );
		$this->assertSame( 'virtio,bridge=vmbr1,tag=1000,firewall=1', $vm['config']['net1'] );
		$this->assertSame( 'ip=10.64.0.2/28', $vm['config']['ipconfig1'] );
		$this->assertSame( 60, $vm['requirements']['disk_gb'] );
		$this->assertArrayNotHasKey( 'disk_gb', $vm['config'] );
		$this->assertArrayNotHasKey( 'net0', $vm['config'] );
		$this->assertArrayNotHasKey( 'gateway', $vm['config'] );
		$this->assertTrue( is_wp_error( PostgresProfile::vm( $net, '10.64.0.1', 'vmbr1', 2, 4096, 60 ) ) );
		$this->assertTrue( is_wp_error( PostgresProfile::vm( array( 'cidr' => '198.51.100.0/28', 'vlan' => 1000 ), '198.51.100.2', 'vmbr1', 2, 4096, 60 ) ) );
		$this->assertTrue( is_wp_error( PostgresProfile::vm( $net, '10.64.0.2', 'vmbr1', 2, 512, 60 ) ) );
		$this->assertTrue( is_wp_error( PostgresProfile::vm( $net, '10.64.0.2', 'abcdefghijklmnop', 2, 4096, 60 ) ), 'Linux bridge names cannot exceed 15 characters.' );
		$this->assertSame( 'postgresql://app:PASSWORD@10.64.0.2:5432/appdb?sslmode=require', PostgresProfile::connection( '10.64.0.2', 'appdb', 'app' ) );
		$this->assertNull( PostgresProfile::connection( '10.64.0.2', 'bad name', 'app' ) );
	}

	public function test_an_empty_network_is_reused_only_after_quarantine(): void {
		global $wpdb;
		PrivateNets::add( 1000, '10.64.0.0/28' );
		PrivateNets::hold( 11 );
		$this->assertTrue( PrivateNets::retire( 11 ) );
		$this->assertSame( 0, PrivateNets::reclaim() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test clock fixture.
		$wpdb->update( Schema::tables()['private_nets'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'user_id' => 11 ) );
		$this->assertSame( 1, PrivateNets::reclaim() );
		$this->assertNull( PrivateNets::for_user( 11 ) );
		$this->assertSame( 1000, PrivateNets::hold( 12 )['vlan'] );
	}

	public function test_releasing_the_last_address_quarantines_and_the_sweep_reclaims(): void {
		global $wpdb;
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		$one = $this->resource( 11 );
		$two = $this->resource( 11 );
		$this->assertNotNull( PrivateNets::reserve( 11, $one->id() ) );
		$this->assertNotNull( PrivateNets::reserve( 11, $two->id() ) );
		$this->assertTrue( PrivateNets::release_and_retire( $one->id() ) );
		$this->assertSame( 'held', PrivateNets::for_user( 11 )['state'] );
		$this->assertTrue( PrivateNets::release_and_retire( $two->id() ) );
		$this->assertSame( 'quarantine', PrivateNets::for_user( 11 )['state'] );
		$this->assertTrue( PrivateNets::release_and_retire( $two->id() ), 'Cleanup is idempotent.' );
		$this->assertNotFalse( has_action( Jobs::SWEEP, array( PrivateNets::class, 'reclaim' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test clock fixture.
		$wpdb->update( Schema::tables()['private_nets'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'user_id' => 11 ) );
		$this->assertSame( 1, PrivateNets::reclaim() );
		$this->assertNull( PrivateNets::for_user( 11 ) );
	}

	public function test_reserve_takes_one_pool_lock_and_seed_does_not_scan_all_networks(): void {
		$queries = array();
		$track = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $track );
		try {
			$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
			$one = $this->resource( 11 );
			$this->assertSame( '10.64.0.2', PrivateNets::reserve( 11, $one->id() )['address'] );
		} finally {
			remove_filter( 'query', $track );
		}
		$locks = array_filter( $queries, static fn( $sql ) => str_contains( $sql, 'GET_LOCK(' ) && str_contains( $sql, '_privnet_0' ) );
		$full_scans = array_filter( $queries, static fn( $sql ) => str_contains( $sql, 'SELECT vlan, cidr FROM' ) );
		$this->assertCount( 2, $locks, 'Seed and one atomic reservation each take the pool lock once.' );
		$this->assertCount( 0, $full_scans );
	}

	public function test_seed_uses_unique_keys_without_a_full_pool_read(): void {
		$queries = array();
		$track = static function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; };
		add_filter( 'query', $track );
		try {
			$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
			$this->assertFalse( PrivateNets::add( 1001, '10.64.0.0/28' ) );
		} finally {
			remove_filter( 'query', $track );
		}
		$this->assertCount( 0, array_filter( $queries, static fn( $sql ) => str_contains( $sql, 'SELECT vlan, cidr FROM' ) ) );
	}
}
