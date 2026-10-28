<?php
/** Resolver result tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Delegation;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DelegationTest extends TestCase {
	public function test_delegation_ours_other_unknown(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$answer = static fn() => array( 'NS2.EXAMPLE.NET.', 'ns1.example.net.' );
		add_filter( 'beltoft_services_domains_ns_answer', $answer );
		$this->assertSame( 'ours', Delegation::check( 'example.net' ) );
		remove_filter( 'beltoft_services_domains_ns_answer', $answer );
		$other = static fn() => array( 'ns1.elsewhere.net' );
		add_filter( 'beltoft_services_domains_ns_answer', $other );
		$this->assertSame( 'other', Delegation::check( 'example.net' ) );
		remove_filter( 'beltoft_services_domains_ns_answer', $other );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => false );
		$this->assertSame( 'unknown', Delegation::check( 'example.net' ) );
	}

	private function dns_resource( string $domain ): Resource {
		$license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false ) );
		$r = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => $domain, 'mode' => 'dns' ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	public function test_dns_only_uses_the_parent_result_and_stores_parent_ds(): void {
		$cases = array(
			array( array( 'status' => 'support', 'reason' => 'delegated_to_us', 'ds' => false, 'ours' => true ), 'ours', false ),
			array( array( 'status' => 'ok', 'reason' => 'ok', 'ds' => true, 'ours' => true ), 'ours', true ),
			array( array( 'status' => 'ok', 'reason' => 'ok', 'ds' => true, 'ours' => false ), 'other', true ),
			array( array( 'status' => 'unknown', 'reason' => 'budget', 'ds' => null, 'ours' => false ), 'unknown', null ),
			array( array( 'status' => 'support', 'reason' => 'unsupported_name', 'ds' => null, 'ours' => false ), 'unknown', null ),
		);
		foreach ( $cases as $i => $case ) {
			$r = $this->dns_resource( "parent-$i.example.com" );
			$filter = static fn() => $case[0];
			add_filter( 'beltoft_services_dns_proof_result', $filter );
			$this->assertSame( $case[1], Delegation::refresh( $r ) );
			remove_filter( 'beltoft_services_dns_proof_result', $filter );
			$fresh = Resource::find( $r->id() );
			$this->assertSame( $case[1], $fresh->get( 'delegation' ) );
			$this->assertSame( $case[2], $fresh->get( 'parent_ds' ) );
			$this->assertGreaterThan( 0, (int) $fresh->get( 'delegation_checked' ) );
		}
	}

	public function test_dns_only_keeps_the_rate_limit_and_domain_driver_the_system_resolver(): void {
		$r = $this->dns_resource( 'rate.example.com' );
		$r->set( array( 'delegation_checked' => time() ) );
		$this->assertWPError( Delegation::refresh( $r, true ) );

		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'ns1.example.net', 'ns2.example.net' ) );
		add_filter( 'beltoft_services_dns_proof_result', static fn() => array( 'status' => 'unknown', 'reason' => 'x', 'ds' => true, 'ours' => false ) );
		$license = $this->service_license( $this->service_product( 'domain' ), array( 'fire' => false ) );
		$d = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'reg.example.com', 'mode' => 'register' ) );
		$this->assertSame( 'ours', Delegation::refresh( $d ) );
		$this->assertNull( Resource::find( $d->id() )->get( 'parent_ds' ) );
	}
}
