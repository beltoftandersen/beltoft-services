<?php
/** Resolver result tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Delegation;
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
}
