<?php
/**
 * The IPv6 address of a server: derived from the VMID, documentation prefix only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Tests\TestCase;

class Ipv6Test extends TestCase {

	private const PREFIX = '2001:db8:430c:1::/64';

	public function set_up(): void {
		parent::set_up();
		delete_option( Clusters::OPTION );
	}

	public function tear_down(): void {
		delete_option( Clusters::OPTION );
		parent::tear_down();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cluster( string $prefix = self::PREFIX, string $gateway = '' ): array {
		return array( 'ipv6_prefix' => $prefix, 'ipv6_gateway' => $gateway );
	}

	public function test_address_follows_the_vmid(): void {
		$this->assertSame( '2001:db8:430c:1:c8::1', Ipv6::address( $this->cluster(), 200 ) );
		$this->assertSame( '2001:db8:430c:1:3e7::1', Ipv6::address( $this->cluster(), 999 ) );
		$this->assertSame( '2001:db8:430c:1:ffff::1', Ipv6::address( $this->cluster(), 65535 ) );
		$this->assertSame( '2001:db8:430c:1:1::1', Ipv6::address( $this->cluster(), 1 ) );
	}

	public function test_off_or_out_of_range_gives_null(): void {
		$this->assertNull( Ipv6::address( $this->cluster(), 0 ) );
		$this->assertNull( Ipv6::address( $this->cluster(), 65536 ) );
		$this->assertNull( Ipv6::address( $this->cluster(), -5 ) );
		$this->assertNull( Ipv6::address( $this->cluster( '' ), 200 ) );
		$this->assertNull( Ipv6::address( array(), 200 ) );
		$this->assertNull( Ipv6::address( $this->cluster( 'not an address' ), 200 ) );
		$this->assertNull( Ipv6::address( $this->cluster( '2001:db8:430c:1::/48' ), 200 ) );
		$this->assertNull( Ipv6::address( $this->cluster( '2001:db8:430c:1::1/64' ), 200 ), 'host bits set' );
		$this->assertNull( Ipv6::address( $this->cluster( '192.0.2.0/64' ), 200 ) );
		$this->assertSame( '', Ipv6::ipconfig( $this->cluster( '' ), 200 ) );
	}

	public function test_no_two_vmids_share_an_address(): void {
		$seen = array();

		foreach ( range( 1, 65535, 7 ) as $vmid ) {
			$seen[ Ipv6::address( $this->cluster(), $vmid ) ] = true;
		}

		$this->assertCount( count( range( 1, 65535, 7 ) ), $seen );
	}

	public function test_block_is_the_reserved_80(): void {
		$this->assertSame( '2001:db8:430c:1:c8::/80', Ipv6::block( $this->cluster(), 200 ) );
		$this->assertNull( Ipv6::block( $this->cluster( '' ), 200 ) );
	}

	public function test_input_is_normalised(): void {
		$upper = $this->cluster( '2001:0DB8:430C:0001:0000:0000:0000:0000/64' );

		$this->assertSame( '2001:db8:430c:1:c8::1', Ipv6::address( $upper, 200 ) );
		$this->assertSame( '2001:db8::1', Ipv6::normalise( '2001:0DB8:0:0:0:0:0:1' ) );
		$this->assertNull( Ipv6::normalise( '192.0.2.1' ) );
		$this->assertNull( Ipv6::normalise( 'fe80::1%eth0' ) );
		$this->assertNull( Ipv6::normalise( '2001:db8::1/64' ) );
	}

	public function test_gateway_defaults_to_the_first_address_and_ipconfig_has_both(): void {
		$this->assertSame( '2001:db8:430c:1::1', Ipv6::gateway( $this->cluster() ) );
		$this->assertSame( '2001:db8:430c:1::5', Ipv6::gateway( $this->cluster( self::PREFIX, '2001:DB8:430c:1::5' ) ) );
		$this->assertSame( 'ip6=2001:db8:430c:1:c8::1/64,gw6=2001:db8:430c:1::1', Ipv6::ipconfig( $this->cluster(), 200 ) );
		$this->assertNull( Ipv6::gateway( $this->cluster( '' ) ) );
	}

	public function test_vmid_for_round_trip_and_refusal_outside_every_prefix(): void {
		update_option(
			Clusters::OPTION,
			array(
				'a' => array( 'ipv6_prefix' => self::PREFIX ),
				'b' => array( 'ipv6_prefix' => '2001:db8:9999::/64' ),
				'c' => array( 'ipv6_prefix' => '' ),
			)
		);

		$this->assertSame( array( 'cluster' => 'a', 'vmid' => 5001 ), Ipv6::vmid_for( Ipv6::address( $this->cluster(), 5001 ) ) );
		$this->assertSame( array( 'cluster' => 'b', 'vmid' => 200 ), Ipv6::vmid_for( Ipv6::address( $this->cluster( '2001:db8:9999::/64' ), 200 ) ) );
		// Any address in the VMID's /80 maps to it.
		$this->assertSame( array( 'cluster' => 'a', 'vmid' => 200 ), Ipv6::vmid_for( '2001:db8:430c:1:c8:1:2:3' ) );
		$this->assertNull( Ipv6::vmid_for( '2001:db8:430c:1::1' ), 'the host block belongs to no VMID' );
		$this->assertNull( Ipv6::vmid_for( '2001:db8:1111:c8::1' ) );
		$this->assertNull( Ipv6::vmid_for( '192.0.2.5' ) );
		$this->assertNull( Ipv6::vmid_for( 'junk' ) );
	}
}
