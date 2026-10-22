<?php
/**
 * The IP pool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Locks;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Tests\TestCase;

/**
 * Allocation of addresses to resources. Documentation addresses (192.0.2.0/24) only.
 */
class IpPoolTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * Addresses of the rows in the pool, by state.
	 *
	 * @param string $state State.
	 * @return string[]
	 */
	private function addresses( string $state ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return $wpdb->get_col( $wpdb->prepare( 'SELECT address FROM ' . Schema::tables()['ips'] . ' WHERE state = %s ORDER BY id', $state ) );
	}

	public function test_hold_is_idempotent_and_exclusive(): void {
		$this->assertSame( 5, IpPool::add( '192.0.2.8/29', '192.0.2.9' ), 'six hosts, the gateway is not added' );
		$first = IpPool::hold( 1 );

		$this->assertSame( 'held', $first['state'] );
		$this->assertSame( 1, $first['resource_id'] );
		$this->assertSame( $first, IpPool::hold( 1 ), 'the same resource gets the same row' );

		$second = IpPool::hold( 2 );
		$this->assertNotSame( $first['id'], $second['id'] );
		$this->assertNotSame( $first['address'], $second['address'] );
		$this->assertSame( 2, $second['resource_id'] );
		$this->assertCount( 3, $this->addresses( 'free' ), 'five addresses, two held' );
	}

	public function test_hold_hands_out_the_lowest_id_and_releases_the_lock(): void {
		global $wpdb;

		IpPool::add( '192.0.2.16/29' );
		$this->assertSame( '192.0.2.17', IpPool::hold( 1 )['address'] );
		$this->assertSame( '192.0.2.18', IpPool::hold( 2 )['address'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', Locks::name( 'ippool', 0 ) ) ), 'the lock is released' );
	}

	public function test_one_address_per_resource(): void {
		global $wpdb;

		IpPool::add( '192.0.2.24/30' );
		$a = IpPool::hold( 7 );
		$this->assertTrue( IpPool::assign( 7 ) );
		$this->assertSame( $a['id'], IpPool::hold( 7 )['id'], 'an assigned resource keeps its address' );
		$this->assertSame( 'assigned', IpPool::hold( 7 )['state'] );

		// The unique key is the backstop: a second row for resource 7 is refused by the database.
		$other = IpPool::hold( 8 );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test.
		$ok = $wpdb->update( Schema::tables()['ips'], array( 'resource_id' => 7 ), array( 'id' => $other['id'] ) );
		$wpdb->suppress_errors( $suppress );
		$this->assertFalse( $ok );
	}

	public function test_pool_exhaustion_returns_null(): void {
		IpPool::add( '192.0.2.28/31' );

		$this->assertNotNull( IpPool::hold( 1 ) );
		$this->assertNotNull( IpPool::hold( 2 ) );
		$this->assertNull( IpPool::hold( 3 ) );
		$this->assertSame( 0, IpPool::free_count() );

		$this->assertTrue( IpPool::release( 1 ) );
		$this->assertSame( 1, IpPool::free_count() );
		$this->assertSame( '192.0.2.28', IpPool::hold( 3 )['address'] );
	}

	public function test_empty_pool_returns_null(): void {
		$this->assertNull( IpPool::hold( 1 ) );
		$this->assertSame( 0, IpPool::free_count() );
	}

	public function test_blocked_addresses_are_never_handed_out(): void {
		IpPool::add( '192.0.2.32/30' );
		$this->assertTrue( IpPool::block( '192.0.2.33' ) );

		$this->assertSame( '192.0.2.34', IpPool::hold( 1 )['address'] );
		$this->assertNull( IpPool::hold( 2 ), 'the blocked one is skipped' );
		$this->assertFalse( IpPool::block( '192.0.2.34' ), 'an address in use cannot be blocked' );
		$this->assertTrue( IpPool::release( 1 ) );
		$this->assertSame( array( '192.0.2.33' ), $this->addresses( 'blocked' ), 'releasing never frees a blocked address' );

		$this->assertTrue( IpPool::unblock( '192.0.2.33' ) );
		$this->assertSame( '192.0.2.33', IpPool::hold( 2 )['address'] );
		$this->assertFalse( IpPool::block( '192.0.2.99' ), 'an unknown address' );
	}

	public function test_cidr_add_expands_hosts(): void {
		$this->assertSame( 6, IpPool::add( '192.0.2.40/29' ) );
		$this->assertSame( array( '192.0.2.41', '192.0.2.42', '192.0.2.43', '192.0.2.44', '192.0.2.45', '192.0.2.46' ), $this->addresses( 'free' ), 'no network or broadcast address' );
		$this->assertSame( 0, IpPool::add( '192.0.2.40/29' ), 'adding again adds nothing' );

		$this->assertSame( 2, IpPool::add( '192.0.2.48/31' ), '/31 has two usable addresses' );
		$this->assertSame( 1, IpPool::add( '192.0.2.50/32' ), '/32 is one address' );
		$this->assertSame( 1, IpPool::add( '192.0.2.60' ), 'a bare address' );
		$this->assertSame( 10, IpPool::free_count() );
	}

	public function test_add_stores_prefix_and_gateway_and_keeps_the_gateway_out_of_the_pool(): void {
		IpPool::add( '192.0.2.64/29', '192.0.2.65' );
		$row = IpPool::hold( 1 );

		$this->assertSame( '192.0.2.66', $row['address'] );
		$this->assertSame( 29, $row['prefix'] );
		$this->assertSame( '192.0.2.65', $row['gateway'] );
		$this->assertNotContains( '192.0.2.65', $this->addresses( 'free' ) );

		IpPool::add( '192.0.2.200' );
		$this->assertSame( 32, $this->row_prefix( '192.0.2.200' ), 'a bare address defaults to /32' );
	}

	private function row_prefix( string $address ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT prefix FROM ' . Schema::tables()['ips'] . ' WHERE address = %s', $address ) );
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function rejected_inputs(): array {
		return array(
			'not an address'    => array( 'banana', null ),
			'ipv6'              => array( '2001:db8::/64', null ),
			'prefix too big'    => array( '192.0.2.0/33', null ),
			'too many hosts'    => array( '198.51.100.0/16', null ),
			'bad gateway'       => array( '192.0.2.80/29', 'not-an-ip' ),
			'octet out of range' => array( '192.0.2.300', null ),
			'empty'             => array( '', null ),
		);
	}

	/**
	 * @dataProvider rejected_inputs
	 */
	public function test_add_refuses_what_it_cannot_expand( string $input, ?string $gateway ): void {
		$this->assertSame( 0, IpPool::add( $input, $gateway ) );
		$this->assertSame( 0, IpPool::free_count() );
	}

	public function test_free_count_is_cached_and_cleared_on_every_change(): void {
		IpPool::add( '192.0.2.88/29' );
		$this->assertSame( 6, IpPool::free_count() );
		$this->assertSame( 6, get_transient( IpPool::FREE_TRANSIENT ) );

		IpPool::hold( 1 );
		$this->assertFalse( get_transient( IpPool::FREE_TRANSIENT ), 'hold clears it' );
		$this->assertSame( 5, IpPool::free_count() );

		IpPool::block( '192.0.2.90' );
		$this->assertSame( 4, IpPool::free_count() );
		IpPool::unblock( '192.0.2.90' );
		$this->assertSame( 5, IpPool::free_count() );
		IpPool::release( 1 );
		$this->assertSame( 6, IpPool::free_count() );
		IpPool::add( '192.0.2.96' );
		$this->assertSame( 7, IpPool::free_count() );
	}

	public function test_assign_and_release_need_a_held_address(): void {
		IpPool::add( '192.0.2.104/30' );

		$this->assertFalse( IpPool::assign( 5 ), 'nothing held' );
		$this->assertFalse( IpPool::release( 5 ) );
		IpPool::hold( 5 );
		$this->assertTrue( IpPool::assign( 5 ) );
		$this->assertTrue( IpPool::assign( 5 ), 'assigning twice is fine' );
		$this->assertTrue( IpPool::release( 5 ) );
		$this->assertFalse( IpPool::release( 5 ) );
	}

	public function test_for_resource_reads_the_held_row(): void {
		IpPool::add( '192.0.2.112/30' );

		$this->assertNull( IpPool::for_resource( 3 ) );
		$row = IpPool::hold( 3 );
		$this->assertSame( $row, IpPool::for_resource( 3 ) );
	}

	/**
	 * Holds a named lock on a second database connection, so it blocks this one.
	 *
	 * @param string $name Lock name.
	 */
	private function lock_elsewhere( string $name ): \mysqli {
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$res   = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );
		$this->assertSame( '1', (string) $res->fetch_row()[0], 'the other connection holds the lock' );

		return $other;
	}

	public function test_a_busy_pool_throws_and_changes_nothing_until_the_lock_is_released(): void {
		IpPool::add( '192.0.2.120/30' );
		add_filter( 'beltoft_services_alloc_lock_wait', fn() => 1 );
		$other = $this->lock_elsewhere( Locks::name( 'ippool', 0 ) );

		try {
			IpPool::hold( 1 );
			$this->fail( 'hold must not guess when the pool is busy' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 2, IpPool::free_count() );
		}
		$this->assertNull( IpPool::for_resource( 1 ) );

		foreach ( array( 'block', 'unblock' ) as $method ) {
			try {
				IpPool::$method( '192.0.2.121' );
				$this->fail( $method . ' must throw when the pool is busy' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( array(), $this->addresses( 'blocked' ) );
			}
		}

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( Locks::name( 'ippool', 0 ) ) . "')" );
		$other->close();

		$this->assertSame( '192.0.2.121', IpPool::hold( 1 )['address'] );
		$this->assertFalse( IpPool::block( '192.0.2.250' ), 'no such address is false, not an exception' );
	}
}
