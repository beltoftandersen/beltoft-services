<?php
/**
 * A server built in a cluster with IPv6 on: the address in ipconfig0, the meta, the log, the ipfilter set, delete.
 * Documentation prefix and the fake client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftServices\Cli\Commands;
use BeltoftServices\Proxmox\Network\IpHistory;
use BeltoftServices\Proxmox\Network\IpWho;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

require_once __DIR__ . '/FirewallTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/WpCliStub.php';

class Ipv6BuildTest extends FirewallTestCase {

	protected const PREFIX = '2001:db8:430c:1::/64';

	/**
	 * Turns IPv6 on for the cluster (needs the firewall and the filter).
	 */
	protected function ipv6_on(): void {
		$this->save_cluster( array( 'ipv6_prefix' => self::PREFIX, 'fw_enabled' => '1', 'fw_ipfilter' => '1' ) );
	}

	public function test_a_build_without_a_prefix_is_as_before(): void {
		list( $r ) = $this->ready();
		$vm        = $this->fake->vm( 5000 );

		$this->assertStringStartsWith( 'ip=192.0.2.', $vm['config']['ipconfig0'] );
		$this->assertStringNotContainsString( 'ip6', $vm['config']['ipconfig0'] );
		$this->assertNull( $r->get( 'ip6' ) );
		$this->assertSame( array(), IpHistory::for_address( '2001:db8:430c:1:1388::1' ) );
	}

	public function test_a_build_with_a_prefix_writes_ip6_meta_and_log(): void {
		$this->ipv6_on();
		list( $r ) = $this->ready();
		$vm        = $this->fake->vm( 5000 );
		$ip4       = (string) $r->get( 'ip' );

		$this->assertSame( 'ip=' . $ip4 . '/24,gw=192.0.2.1,ip6=2001:db8:430c:1:1388::1/64,gw6=2001:db8:430c:1::1', $vm['config']['ipconfig0'] );
		$this->assertSame( '2001:db8:430c:1:1388::1', $r->get( 'ip6' ) );

		$log = IpHistory::for_address( '2001:db8:430c:1:1388::1' );
		$this->assertCount( 1, $log );
		$this->assertSame( Ipv6::KIND, $log[0]['kind'] );
		$this->assertSame( $r->id(), $log[0]['resource_id'] );
		$this->assertNull( $log[0]['ended_at'] );
	}

	public function test_the_log_row_opens_once_and_delete_closes_it(): void {
		$this->ipv6_on();
		list( $r ) = $this->ready();

		IpHistory::start_once( Ipv6::KIND, '2001:db8:430c:1:1388::1', $r->id() );
		$this->assertCount( 1, IpHistory::for_address( '2001:db8:430c:1:1388::1' ) );

		$driver = \BeltoftServices\Drivers\Registry::get( 'proxmox' );
		$done   = $driver->delete( Resource::find( $r->id() ) );
		$this->assertTrue( $done->is_done() );
		$this->assertNull( VmRecords::for_resource( $r->id() ) );

		$log = IpHistory::for_address( '2001:db8:430c:1:1388::1' );
		$this->assertCount( 1, $log );
		$this->assertNotNull( $log[0]['ended_at'] );
	}

	public function test_a_reused_vmid_gives_the_same_address_and_the_log_keeps_both_holders(): void {
		$this->ipv6_on();
		list( $first ) = $this->ready();
		\BeltoftServices\Drivers\Registry::get( 'proxmox' )->delete( Resource::find( $first->id() ) );

		list( $second ) = $this->ready();
		$this->assertSame( 5000, VmRecords::for_resource( $second->id() )['vmid'] );
		$this->assertSame( '2001:db8:430c:1:1388::1', $second->get( 'ip6' ) );

		$log = IpHistory::for_address( '2001:db8:430c:1:1388::1' );
		$this->assertCount( 2, $log );
		$this->assertSame( array( $first->id(), $second->id() ), array_column( $log, 'resource_id' ) );
		$this->assertNotNull( $log[0]['ended_at'] );
		$this->assertNull( $log[1]['ended_at'] );
	}

	public function test_who_finds_the_holders_of_an_ipv6_address_through_the_log_and_the_derivation(): void {
		$this->ipv6_on();
		list( $first ) = $this->ready();
		\BeltoftServices\Drivers\Registry::get( 'proxmox' )->delete( Resource::find( $first->id() ) );
		list( $second ) = $this->ready();

		foreach ( array( '2001:db8:430c:1:1388::1', '2001:DB8:430C:1:1388:0:0:1', '2001:db8:430c:1:1388:0:0:77' ) as $spelling ) {
			$found = IpWho::lookup( $spelling );
			$this->assertCount( 2, $found, $spelling );
			$this->assertSame( array( (string) $first->id(), (string) $second->id() ), array_column( $found, 'resource_id' ), $spelling );
			$this->assertSame( array( Ipv6::KIND, Ipv6::KIND ), array_column( $found, 'kind' ) );
			$this->assertSame( '', $found[1]['until'] );
			$this->assertNotSame( '', $found[0]['until'] );
		}

		$this->assertSame( array(), IpWho::lookup( '2001:db8:430c:1:9999::1' ) );
		$this->assertSame( array(), IpWho::lookup( '2001:db8:ffff:1:1388::1' ) );
	}

	public function test_a_holder_before_the_log_is_found_through_the_derivation(): void {
		$this->ipv6_on();
		list( $r ) = $this->ready();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Proxmox\Vm\Schema::tables()['ip_log'] );

		$found = IpWho::lookup( '2001:db8:430c:1:1388::1' );
		$this->assertCount( 1, $found );
		$this->assertSame( (string) $r->id(), $found[0]['resource_id'] );
		$this->assertStringContainsString( 'held before holdings were logged', $found[0]['note'] );
	}

	public function test_the_commands_take_ipv6(): void {
		\WP_CLI::reset();
		$cli = new Commands();
		$this->ipv6_on();
		list( $r ) = $this->ready();

		$cli->who( array( '2001:db8:430c:1:1388::1' ), array( 'format' => 'json' ) );
		$this->assertStringContainsString( '"resource_id":"' . $r->id() . '"', str_replace( ' ', '', \WP_CLI::$lines[0][1] ?? '' ) );

		try {
			$cli->who( array( 'not-an-ip' ), array() );
			$this->fail( 'A bad address is refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'IPv4 or IPv6', $e->getMessage() );
		}
	}
}
