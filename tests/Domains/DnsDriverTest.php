<?php
/** DNS hosting ownership and lifecycle tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DnsDriverTest extends TestCase {
	private function resource( string $name, int $user, string $driver = 'dns' ): Resource {
		$product = $this->service_product( $driver );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r = Resource::create( $license, 'dns' === $driver ? new DnsDriver() : new DomainDriver(), array(), array( 'domain' => $name, 'mode' => $driver ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	public function test_dns_hosting_creates_unsigned_zone_without_registrar_call(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		$op = new FakeClient();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'elsewhere.example.net' ) );
		$r = $this->resource( 'hosted-example.net', self::factory()->user->create() );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$this->assertFalse( $dns->zone( 'hosted-example.net' )['secured'] );
		$this->assertSame( 'other', $r->get( 'delegation' ) );
		$this->assertSame( array(), $op->calls() );
		$this->assertTrue( ( new DnsDriver() )->pause( $r )->is_done() );
		$this->assertNotNull( $dns->zone( 'hosted-example.net' ) );
	}

	public function test_second_customer_cannot_take_existing_zone(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'elsewhere.example.net' ) );
		$first = $this->resource( 'conflict-example.net', self::factory()->user->create() );
		$this->assertTrue( ( new DnsDriver() )->provision( $first )->is_done() );
		$second = $this->resource( 'conflict-example.net', self::factory()->user->create() );
		$this->assertFalse( ( new DnsDriver() )->provision( $second )->is_done() );
		$this->assertNotNull( $dns->zone( 'conflict-example.net' ) );
	}

	public function test_delete_keeps_zone_when_same_customer_registers_it(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'elsewhere.example.net' ) );
		$user = self::factory()->user->create();
		$hosting = $this->resource( 'adopt-example.net', $user );
		$this->assertTrue( ( new DnsDriver() )->provision( $hosting )->is_done() );
		$domain = $this->resource( 'adopt-example.net', $user, 'domain' );
		$this->assertTrue( \BeltoftServices\Domains\Zone::prepare( $domain )->is_done() );
		$this->assertTrue( ( new DnsDriver() )->delete( $hosting )->is_done() );
		$this->assertNotNull( $dns->zone( 'adopt-example.net' ) );
	}

	public function test_dns_provision_resumes_after_zone_created_before_step_saved(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'elsewhere.example.net' ) );
		$r = $this->resource( 'dns-crash-example.net', self::factory()->user->create() );
		$r->set( array( 'steps' => array( 'claim' ), 'zone_claimed' => true ) );
		$dns->create_zone( 'dns-crash-example.net', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$this->assertCount( 1, array_filter( $dns->calls(), static fn( $call ) => 'create_zone' === $call[0] ) );
	}
}
