<?php
/**
 * The hosting tables and their allocation: container ids, addresses, SSH ports, sites.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\CtIpPool;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Schema;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Hosting\SshPorts;

/**
 * Schema, Records, CtIpPool, SshPorts and Sites.
 */
class RecordsTest extends HostingCase {

	public function test_the_tables_and_their_unique_keys_exist(): void {
		global $wpdb;

		foreach ( Schema::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test.
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		}

		$this->assertSame( array(), Schema::missing_keys() );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::OPTION_DB_VERSION ) );
	}

	public function test_maybe_upgrade_is_quiet_when_the_version_is_current(): void {
		$before = get_option( Schema::OPTION_DB_VERSION );
		Schema::maybe_upgrade();

		$this->assertSame( $before, get_option( Schema::OPTION_DB_VERSION ) );
		$this->assertFalse( get_transient( Schema::RETRY_TRANSIENT ) );
	}

	public function test_open_keeps_the_first_node_and_a_resource_has_one_record(): void {
		$first = Records::open( 501, 'n1' );
		$again = Records::open( 501, 'n2' );

		$this->assertSame( 'n1', $first['node'] );
		$this->assertSame( 'n1', $again['node'] );
		$this->assertNull( $first['vmid'] );
		$this->assertNull( $first['ip'] );
		$this->assertSame( 1, Records::count_on_node( 'n1' ) );
		$this->assertSame( 0, Records::count_on_node( 'n2' ) );
	}

	public function test_container_ids_are_unique_in_the_range_and_skip_listed_ones(): void {
		Records::open( 601, 'n1' );
		Records::open( 602, 'n1' );
		$asked = array();
		$free  = static function ( int $id ) use ( &$asked ): bool {
			$asked[] = $id;

			return true;
		};

		$a = Records::allocate_vmid( 601, 1000, 1002, array( 1000 ), $free );
		$b = Records::allocate_vmid( 602, 1000, 1002, array( 1000 ), $free );

		$this->assertSame( 1001, $a );
		$this->assertSame( 1002, $b );
		$this->assertSame( array( 1001, 1002 ), $asked );
		$this->assertSame( 1001, Records::allocate_vmid( 601, 1000, 1002, array(), $free ), 'A resource keeps its id.' );

		Records::open( 603, 'n1' );
		$this->assertNull( Records::allocate_vmid( 603, 1000, 1002, array( 1000 ), $free ), 'The range is used up.' );
		$this->assertNull( Records::for_resource( 603 )['vmid'] );
	}

	public function test_an_id_the_node_says_is_taken_is_not_kept(): void {
		Records::open( 611, 'n1' );

		$vmid = Records::allocate_vmid( 611, 1000, 1003, array(), static fn( int $id ): bool => 1000 !== $id );

		$this->assertSame( 1001, $vmid );
		$this->assertSame( 1001, Records::for_resource( 611 )['vmid'] );
	}

	public function test_the_same_id_on_another_node_is_fine(): void {
		Records::open( 621, 'n1' );
		Records::open( 622, 'n2' );
		$free = static fn( int $id ): bool => true;

		$this->assertSame( 1000, Records::allocate_vmid( 621, 1000, 1005, array(), $free ) );
		$this->assertSame( 1000, Records::allocate_vmid( 622, 1000, 1005, array(), $free ) );
	}

	public function test_a_free_check_that_throws_keeps_the_record_for_the_retry(): void {
		Records::open( 631, 'n1' );

		try {
			Records::allocate_vmid(
				631,
				1000,
				1005,
				array(),
				static function (): bool {
					throw new \RuntimeException( 'down' );
				}
			);
			$this->fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'down', $e->getMessage() );
		}

		$this->assertSame( 1000, Records::allocate_vmid( 631, 1000, 1005, array(), static fn( int $id ): bool => true ), 'The retry gets the id it already held.' );
	}

	public function test_addresses_come_from_the_node_range_in_order(): void {
		$node = Nodes::get( 'n1' );
		Records::open( 701, 'n1' );
		Records::open( 702, 'n1' );
		Records::open( 703, 'n2' );

		$this->assertSame( '10.200.0.10', CtIpPool::assign( 701, $node, 'n1' ) );
		$this->assertSame( '10.200.0.10', CtIpPool::assign( 701, $node, 'n1' ), 'Idempotent.' );
		$this->assertSame( '10.200.0.11', CtIpPool::assign( 702, $node, 'n1' ) );
		$this->assertSame( '10.200.0.10', CtIpPool::assign( 703, $node, 'n2' ), 'Another node has its own range.' );

		Records::remove( 701 );
		Records::open( 704, 'n1' );
		$this->assertSame( '10.200.0.10', CtIpPool::assign( 704, $node, 'n1' ), 'A freed address is used again.' );
	}

	public function test_the_address_range_is_checked(): void {
		$this->assertSame( array( ip2long( '10.200.0.10' ), ip2long( '10.200.255.254' ) ), CtIpPool::range( '10.200.0.0/16', 10 ) );
		$this->assertSame( array( ip2long( '10.0.0.3' ), ip2long( '10.0.0.6' ) ), CtIpPool::range( '10.0.0.0/29', 3 ) );
		$this->assertNull( CtIpPool::range( '10.0.0.0/29', 7 ) );
		$this->assertNull( CtIpPool::range( '10.0.0.0/8', 1 ) );
		$this->assertNull( CtIpPool::range( '10.0.0.0/33', 1 ) );
		$this->assertNull( CtIpPool::range( 'nope', 1 ) );
		$this->assertNull( CtIpPool::range( '10.0.0.0/24', 0 ) );

		$node = array_merge( Nodes::get( 'n1' ), array( 'lan_cidr' => '10.0.0.0/29', 'ip_first' => 3 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			Records::open( 750 + $i, 'tiny' );
		}

		$got = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$got[] = CtIpPool::assign( 750 + $i, $node, 'tiny' );
		}

		$this->assertSame( array( '10.0.0.3', '10.0.0.4', '10.0.0.5', '10.0.0.6', null ), $got, 'The range is used up.' );
	}

	public function test_ssh_ports_are_unique_per_node_and_the_range_ends(): void {
		$node = array_merge( Nodes::get( 'n1' ), array( 'ssh_port_min' => 22001, 'ssh_port_max' => 22002 ) );
		Records::open( 801, 'n1' );
		Records::open( 802, 'n1' );
		Records::open( 803, 'n1' );

		$this->assertSame( 22001, SshPorts::assign( 801, $node, 'n1' ) );
		$this->assertSame( 22001, SshPorts::assign( 801, $node, 'n1' ) );
		$this->assertSame( 22002, SshPorts::assign( 802, $node, 'n1' ) );
		$this->assertNull( SshPorts::assign( 803, $node, 'n1' ) );
	}

	public function test_the_unique_keys_refuse_a_duplicate_even_without_the_lock(): void {
		Records::open( 901, 'n1' );
		Records::open( 902, 'n1' );

		$this->assertTrue( Records::claim( 901, 'ip', '10.200.9.9' ) );
		$this->assertFalse( Records::claim( 902, 'ip', '10.200.9.9' ) );
		$this->assertTrue( Records::claim( 901, 'ssh_port', 22010 ) );
		$this->assertFalse( Records::claim( 902, 'ssh_port', 22010 ) );
		$this->assertFalse( Records::claim( 902, 'vmid', 5 ), 'Only an address and a port can be claimed.' );
		$this->assertSame( array(), Records::used( 'n1', 'vmid' ) );
	}

	public function test_sites_are_unique_by_slug_and_removed_with_the_account(): void {
		$this->assertTrue( Sites::add( 1001, 'shop', 'Shop', '8.3', 5, array( 'x' => 1 ) ) );
		$this->assertTrue( Sites::add( 1001, 'shop', 'Changed', '8.4', 9 ), 'The same site again is kept as it is.' );
		$this->assertSame( 'Shop', Sites::find( 1001, 'shop' )['label'] );
		$this->assertSame( array( 'x' => 1 ), Sites::find( 1001, 'shop' )['flags'] );

		$this->assertFalse( Sites::add( 1002, 'shop', 'Other', '8.3', 5 ), 'A slug names the preview address: unique over all accounts.' );
		$this->assertNull( Sites::find( 1002, 'shop' ) );
		$this->assertFalse( Sites::add( 1001, 'Bad Slug', 'x', '8.3', 5 ) );
		$this->assertFalse( Sites::add( 1001, '-bad', 'x', '8.3', 5 ) );

		$this->assertTrue( Sites::add( 1001, 'blog', 'Blog', '8.3', 5 ) );
		$this->assertSame( array( 'shop', 'blog' ), array_column( Sites::for_resource( 1001 ), 'slug' ) );

		$this->assertTrue( Sites::remove_all( 1001 ) );
		$this->assertSame( array(), Sites::for_resource( 1001 ) );
	}
}
