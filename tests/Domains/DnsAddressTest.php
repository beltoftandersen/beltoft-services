<?php
/** DNS address checks. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAddress;
use BeltoftServices\Tests\TestCase;

class DnsAddressTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		DnsAddress::flush();
	}

	protected function tearDown(): void {
		remove_all_filters( 'beltoft_services_dns_resolve' );
		remove_all_filters( 'beltoft_services_dns_own_ips' );
		remove_all_filters( 'beltoft_services_dns_zone_ns' );
		DnsAddress::flush();
		parent::tearDown();
	}

	/** One address inside and one just outside every refused range. */
	public function blocked_ranges(): array {
		return array(
			'0/8' => array( '0.1.2.3', '1.0.0.1' ),
			'10/8' => array( '10.200.1.1', '11.0.0.1' ),
			'100.64/10' => array( '100.127.255.255', '100.128.0.1' ),
			'127/8' => array( '127.0.0.1', '128.0.0.1' ),
			'169.254/16' => array( '169.254.169.254', '169.255.0.1' ),
			'172.16/12' => array( '172.31.255.1', '172.32.0.1' ),
			'192.0.0/24' => array( '192.0.0.9', '192.0.1.1' ),
			'192.0.2/24' => array( '192.0.2.10', '192.0.3.1' ),
			'192.168/16' => array( '192.168.1.1', '192.169.0.1' ),
			'198.18/15' => array( '198.19.5.5', '198.20.0.1' ),
			'198.51.100/24' => array( '198.51.100.7', '198.51.101.1' ),
			'203.0.113/24' => array( '203.0.113.7', '203.0.114.1' ),
			'224/4' => array( '239.255.255.255', '223.255.255.1' ),
			'240/4' => array( '255.255.255.255', '239.1.1.1' ),
			'::/128' => array( '::', '::2' ),
			'::1/128' => array( '::1', '::3' ),
			'::ffff:0:0/96' => array( '::ffff:8.8.8.8', '::fffe:808:808' ),
			'64:ff9b::/96' => array( '64:ff9b::808:808', '64:ff9c::1' ),
			'100::/64' => array( '100::1', '100:0:0:1::1' ),
			'2001:db8::/32' => array( '2001:db8:ffff::1', '2001:db9::1' ),
			'2002::/16' => array( '2002:808:808::1', '2003::1' ),
			'fc00::/7' => array( 'fdff::1', 'fe00::1' ),
			'fe80::/10' => array( 'febf::1', 'fec0::1' ),
			'ff00::/8' => array( 'ff02::1', 'fe9f::1' ),
		);
	}

	/** @dataProvider blocked_ranges */
	public function test_every_special_range_is_refused( string $blocked, string $outside ): void {
		$this->assertFalse( DnsAddress::is_public( $blocked ), $blocked );
		// The neighbour outside the range is only public when no other range holds it.
		if ( ! in_array( $outside, array( '239.1.1.1', 'fe9f::1', 'fe00::1', 'fec0::1', '::2', '::3', '::fffe:808:808', '100:0:0:1::1' ), true ) ) { $this->assertTrue( DnsAddress::is_public( $outside ), $outside ); }
	}

	public function test_public_addresses_and_garbage(): void {
		foreach ( array( '8.8.8.8', '93.184.216.34', '2606:4700:4700::1111', '2a00:1450:4001::1' ) as $ip ) { $this->assertTrue( DnsAddress::is_public( $ip ), $ip ); }
		foreach ( array( '', 'not-an-ip', '1.2.3', '999.1.1.1', 'example.com' ) as $ip ) { $this->assertFalse( DnsAddress::is_public( $ip ), $ip ); }
	}

	public function test_public_ips_drops_private_addresses(): void {
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $host ) => 'ns.example.net' === $host ? array( '8.8.8.8', '10.0.0.1', '127.0.0.1', '2001:db8::1', '2606:4700::1', '8.8.8.8' ) : $pre, 10, 2 );
		$this->assertSame( array( '8.8.8.8', '2606:4700::1' ), DnsAddress::public_ips( 'NS.example.net.' ) );
		$this->assertSame( array(), DnsAddress::public_ips( "bad host\n" ) );
	}

	public function test_our_ips_come_from_our_name_servers_and_the_filter(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.ours.test, ns2.ours.test' ) );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $host ) => array( 'ns1.ours.test' => array( '203.0.113.5', '8.8.4.4' ), 'ns2.ours.test' => array( '1.1.1.1' ) )[ $host ] ?? $pre, 10, 2 );
		add_filter( 'beltoft_services_dns_own_ips', static fn() => array( '9.9.9.9', '10.1.1.1' ) );
		$this->assertSame( array( '8.8.4.4', '1.1.1.1', '9.9.9.9' ), DnsAddress::our_ips() );
	}

	public function test_parent_servers_are_normalised_and_capped(): void {
		add_filter( 'beltoft_services_dns_zone_ns', static fn( $pre, $zone ) => 'com' === $zone ? array_merge( array( 'A.GTLD.net.', 'a.gtld.net' ), array_map( static fn( $i ) => "n$i.gtld.net", range( 1, 12 ) ) ) : $pre, 10, 2 );
		$names = DnsAddress::parent_servers( 'com' );
		$this->assertCount( 8, $names );
		$this->assertSame( 'a.gtld.net', $names[0] );
		$this->assertSame( 'n1.gtld.net', $names[1] );
	}
}
