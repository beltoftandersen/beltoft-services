<?php
/**
 * Private network allocation without a Proxmox connection.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftServices\Product;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\PrivateNets;
use BeltoftServices\Proxmox\Vm\Schema;
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
		$this->assertTrue( PrivateNets::release_and_retire( $one->id() ) );
		$this->assertSame( 'held', PrivateNets::for_user( 11 )['state'], 'One address left: not quarantined yet.' );
		$this->assertTrue( PrivateNets::release_and_retire( $two->id() ) );
		$this->assertSame( 'quarantine', PrivateNets::for_user( 11 )['state'] );
		$this->assertNull( PrivateNets::hold( 12 ), 'A quarantined VLAN cannot be assigned to another customer.' );
		$this->assertSame( 0, PrivateNets::reclaim(), 'The 30-day quarantine has not elapsed.' );
		$this->assertSame( 'held_return', PrivateNets::hold( 11 )['state'], 'The original customer can return during quarantine.' );
	}

	public function test_reclaim_skips_an_unready_schema_and_a_busy_pool_lock(): void {
		$queries = array();
		$track = static function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; };
		update_option( Schema::OPTION_DB_VERSION, '1' );
		add_filter( 'query', $track );
		try {
			$this->assertSame( 0, PrivateNets::reclaim() );
		} finally {
			remove_filter( 'query', $track );
		}
		$this->assertCount( 0, array_filter( $queries, static fn( $sql ) => str_contains( $sql, 'beltoft_services_private_nets' ) ), 'A pre-v2 site must not query the private tables.' );

		update_option( Schema::OPTION_DB_VERSION, Schema::DB_VERSION );
		$busy = static fn( $sql ) => str_contains( $sql, 'GET_LOCK(' ) && str_contains( $sql, '_privnet_0' ) ? 'SELECT 0' : $sql;
		add_filter( 'query', $busy );
		try {
			$this->assertSame( 0, PrivateNets::reclaim(), 'A busy pool should leave the sweep action successful.' );
		} finally {
			remove_filter( 'query', $busy );
		}
	}

	public function test_failed_first_attach_preserves_a_returning_customers_quarantine(): void {
		global $wpdb;
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		$this->assertNotNull( PrivateNets::hold( 11 ) );
		$this->assertTrue( PrivateNets::retire( 11 ) );
		$started = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test clock fixture.
		$wpdb->update( Schema::tables()['private_nets'], array( 'updated_at' => $started ), array( 'user_id' => 11 ) );
		$resource = $this->resource( 11 );
		$this->assertSame( '10.64.0.2', PrivateNets::reserve( 11, $resource->id() )['address'] );
		$this->assertTrue( PrivateNets::abandon_address( 11, $resource->id() ) );
		$after = PrivateNets::for_user( 11 );
		$this->assertSame( 'quarantine', $after['state'] );
		$this->assertSame( $started, $after['updated_at'], 'A failed return must preserve the original 30-day clock.' );
		$this->assertNull( PrivateNets::hold( 12 ) );
		$this->assertSame( 0, PrivateNets::reclaim() );
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

	private function seed( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->assertTrue( PrivateNets::add( 1000 + $i, '10.64.0.' . ( 16 * $i ) . '/28' ) );
		}
	}

	public function test_a_customer_holds_several_networks_up_to_the_maximum_and_the_filter_changes_it(): void {
		$this->seed( 8 );
		$this->assertSame( 1000, PrivateNets::hold( 11 )['vlan'] );
		for ( $i = 2; $i <= 5; $i++ ) {
			$net = PrivateNets::create_for_user( 11, 'Net ' . $i );
			$this->assertNotNull( $net );
			$this->assertSame( 'Net ' . $i, $net['name'] );
			$this->assertSame( 'held', $net['state'] );
		}
		$nets = PrivateNets::nets_for_user( 11 );
		$this->assertCount( 5, $nets );
		$this->assertSame( 'Network 1', $nets[0]['label'], 'An empty name reads as Network N by position.' );
		$this->assertSame( 'Net 2', $nets[1]['label'] );
		$this->assertSame( 1000, PrivateNets::for_user( 11 )['vlan'], 'The default network is the lowest.' );

		$this->assertFalse( PrivateNets::can_create( 11 ) );
		$this->assertNull( PrivateNets::create_for_user( 11, 'Sixth' ), 'The sixth is refused.' );
		$this->assertCount( 5, PrivateNets::nets_for_user( 11 ) );

		add_filter( 'beltoft_services_private_networks_max', static fn() => 6 );
		try {
			$this->assertTrue( PrivateNets::can_create( 11 ) );
			$this->assertNotNull( PrivateNets::create_for_user( 11, 'Sixth' ) );
		} finally {
			remove_all_filters( 'beltoft_services_private_networks_max' );
		}
		$this->assertNull( PrivateNets::create_for_user( 11, '' ), 'A name is needed.' );
		$this->assertNull( PrivateNets::create_for_user( 11, str_repeat( 'x', 41 ) ) );
		$this->assertSame( 1006, PrivateNets::hold( 12 )['vlan'], 'Another customer gets the next free one.' );
	}

	public function test_reserve_takes_only_the_customers_own_held_network_and_zero_is_the_default(): void {
		$this->seed( 4 );
		PrivateNets::hold( 11 );
		$second = PrivateNets::create_for_user( 11, 'Second' );
		$one    = $this->resource( 11 );
		$two    = $this->resource( 11 );
		$other  = $this->resource( 12 );

		$default = PrivateNets::reserve( 11, $one->id() );
		$this->assertSame( 1000, $default['network']['vlan'] );
		$this->assertSame( '10.64.0.2', $default['address'] );

		$chosen = PrivateNets::reserve( 11, $two->id(), $second['id'] );
		$this->assertSame( 1001, $chosen['network']['vlan'] );
		$this->assertSame( '10.64.0.18', $chosen['address'] );
		$this->assertSame( $chosen, PrivateNets::reserve( 11, $two->id() ), 'A server that holds an address keeps its network.' );

		$this->assertNull( PrivateNets::reserve( 12, $other->id(), $second['id'] ), 'Not the customer\'s network.' );
		$this->assertNull( PrivateNets::for_resource( $other->id() ) );

		// A quarantined network of the customer is not handed out by id either.
		$third = PrivateNets::create_for_user( 11, 'Third' );
		$four  = $this->resource( 11 );
		$this->assertNotNull( PrivateNets::reserve( 11, $four->id(), $third['id'] ) );
		$this->assertTrue( PrivateNets::release_and_retire( $four->id() ) );
		$this->assertSame( 'quarantine', array_column( PrivateNets::rows(), 'state', 'vlan' )[1002] );
		$five = $this->resource( 11 );
		$this->assertNull( PrivateNets::reserve( 11, $five->id(), $third['id'] ) );
		$this->assertNull( PrivateNets::for_resource( $five->id() ) );
	}

	public function test_reserve_can_create_the_network_in_the_same_step_and_a_retry_adds_none(): void {
		$this->seed( 3 );
		$one = $this->resource( 11 );
		$got = PrivateNets::reserve( 11, $one->id(), 0, 'Fresh' );
		$this->assertSame( 'Fresh', $got['network']['name'] );
		$this->assertSame( '10.64.0.2', $got['address'] );
		$this->assertSame( $got, PrivateNets::reserve( 11, $one->id(), 0, 'Fresh' ) );
		$this->assertCount( 1, PrivateNets::nets_for_user( 11 ), 'The retry did not create a second network.' );
	}

	public function test_the_last_server_leaving_the_second_network_quarantines_only_that_one(): void {
		$this->seed( 3 );
		PrivateNets::hold( 11 );
		$second = PrivateNets::create_for_user( 11, 'Second' );
		$one    = $this->resource( 11 );
		$two    = $this->resource( 11 );
		PrivateNets::reserve( 11, $one->id() );
		PrivateNets::reserve( 11, $two->id(), $second['id'] );

		$this->assertTrue( PrivateNets::release_and_retire( $two->id() ) );
		$nets = PrivateNets::nets_for_user( 11 );
		$this->assertCount( 1, $nets, 'The emptied network is no longer listed.' );
		$this->assertSame( 'held', $nets[0]['state'] );
		$this->assertSame( 1000, PrivateNets::for_user( 11 )['vlan'], 'The default stays.' );
		$rows = array_column( PrivateNets::rows(), 'state', 'vlan' );
		$this->assertSame( 'quarantine', $rows[1001] );
		$this->assertSame( 'held', $rows[1000] );
	}

	public function test_a_new_network_reuses_the_customers_own_quarantined_one_before_a_free_one(): void {
		global $wpdb;
		$this->seed( 3 );
		PrivateNets::hold( 11 );
		$second = PrivateNets::create_for_user( 11, 'Second' );
		$two    = $this->resource( 11 );
		PrivateNets::reserve( 11, $two->id(), $second['id'] );
		$this->assertTrue( PrivateNets::release_and_retire( $two->id() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: quarantine began a while ago.
		$wpdb->update( Schema::tables()['private_nets'], array( 'updated_at' => '2026-10-01 10:00:00' ), array( 'id' => $second['id'] ) );

		$again = PrivateNets::create_for_user( 11, 'Again' );
		$this->assertSame( $second['id'], $again['id'] );
		$this->assertSame( 'held_return', $again['state'] );
		$this->assertSame( 'Again', $again['name'] );
		$this->assertSame( '2026-10-01 10:00:00', $again['updated_at'], 'The quarantine timestamp is kept.' );
		$this->assertSame( 1, PrivateNets::free_count(), 'The free network was not touched.' );
	}

	public function test_a_freed_network_loses_its_name(): void {
		$this->seed( 2 );
		$one = $this->resource( 11 );
		PrivateNets::reserve( 11, $one->id(), 0, 'Temp' );
		$this->assertTrue( PrivateNets::abandon_address( 11, $one->id() ) );
		$this->assertSame( 'free', array_column( PrivateNets::rows(), 'state', 'vlan' )[1000] );
		$this->assertSame( '', array_column( PrivateNets::rows(), 'name', 'vlan' )[1000] );
	}
}
