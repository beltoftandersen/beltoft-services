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
}
