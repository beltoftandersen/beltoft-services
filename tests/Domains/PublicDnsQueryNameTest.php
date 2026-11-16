<?php
/** The names the system resolver is asked: lowercase, one trailing dot, so the search list never applies. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Delegation;
use BeltoftServices\Domains\DnsAddress;
use BeltoftServices\Domains\PublicDns;
use BeltoftServices\Tests\TestCase;

class PublicDnsQueryNameTest extends TestCase {
	/** @var string[] */
	private array $asked = array();

	protected function setUp(): void {
		parent::setUp();
		$this->asked = array();
		// TestCase answers every resolve filter with an empty list; the real branch needs it removed.
		remove_all_filters( 'beltoft_services_dns_resolve' );
		remove_all_filters( 'beltoft_services_dns_zone_ns' );
		remove_all_filters( 'beltoft_services_domains_ns_answer' );
		PublicDns::$reader = function ( string $fqdn, int $flag ) { $this->asked[] = $fqdn; return array(); };
		$this->settings( array( 'domain_nameservers' => "ns1.example.net,ns2.example.net" ) );
	}

	protected function tearDown(): void {
		PublicDns::$reader = null;
		parent::tearDown();
	}

	public function test_address_lookups_end_with_a_dot(): void {
		DnsAddress::all_public_ips( 'Mail' );
		DnsAddress::all_public_ips( 'host.example.com' );
		$this->assertSame( array( 'mail.', 'mail.', 'host.example.com.', 'host.example.com.' ), $this->asked, 'A and AAAA, each with one trailing dot.' );
	}

	public function test_a_name_server_lookup_ends_with_a_dot(): void {
		DnsAddress::parent_servers( 'example.com' );
		$this->assertSame( array( 'example.com.' ), $this->asked );
	}

	public function test_the_delegation_check_asks_with_a_dot_and_without_a_warning(): void {
		Delegation::check( 'example.com' );
		$this->assertSame( array( 'example.com.' ), $this->asked );
	}

	public function test_a_failed_delegation_lookup_is_unknown(): void {
		PublicDns::$reader = static fn() => false;
		$this->assertSame( 'unknown', Delegation::check( 'example.com' ) );
	}

	public function test_shape_reads_each_type_from_a_canned_resolver_result(): void {
		$records = array(
			array( 'type' => 'A', 'ip' => '192.0.2.1' ),
			array( 'type' => 'AAAA', 'ipv6' => '2001:DB8::1' ),
			array( 'type' => 'MX', 'pri' => 10, 'target' => 'mail.example.com' ),
			array( 'type' => 'TXT', 'txt' => 'joined', 'entries' => array( 'v=spf1 ', 'include:x ~all' ) ),
			array( 'type' => 'TXT', 'txt' => 'plain' ),
			array( 'type' => 'CNAME', 'target' => 'host.example.net' ),
			array( 'type' => 'NS', 'target' => 'ns1.example.net' ),
		);
		$this->assertSame( array( '192.0.2.1' ), PublicDns::shape( $records, 'A' ) );
		$this->assertSame( array( '2001:db8::1' ), PublicDns::shape( $records, 'AAAA' ) );
		$this->assertSame( array( '10 mail.example.com' ), PublicDns::shape( $records, 'MX' ) );
		$this->assertSame( array( 'v=spf1 include:x ~all', 'plain' ), PublicDns::shape( $records, 'TXT' ) );
		$this->assertSame( array( 'host.example.net' ), PublicDns::shape( $records, 'CNAME' ) );
		$this->assertSame( array( 'ns1.example.net' ), PublicDns::shape( $records, 'NS' ) );
		$this->assertSame( array(), PublicDns::shape( array(), 'A' ) );
	}

	public function test_resolve_asks_with_a_dot_for_every_type_and_a_failed_lookup_is_dns_transport(): void {
		foreach ( array( 'A', 'AAAA', 'TXT', 'MX', 'CNAME', 'NS' ) as $type ) {
			PublicDns::resolve( 'Example.COM', $type );
		}
		$this->assertSame( array_fill( 0, 6, 'example.com.' ), $this->asked );
		PublicDns::$reader = static fn() => false;
		$this->assertSame( 'dns_transport', PublicDns::resolve( 'example.com', 'TXT' )->get_error_code() );
	}

	public function test_the_visitor_view_readers_ask_through_the_one_reader(): void {
		\BeltoftServices\Domains\DnsQuery::addresses( 'Site.Example.com', 'AAAA' );
		\BeltoftServices\Domains\DnsQuery::texts( 'site.example.com.' );
		$this->assertSame( array( 'site.example.com.', 'site.example.com.' ), $this->asked );
	}
}
