<?php
/** DNS zone and DS order tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Zone;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class ZoneTest extends TestCase {
	public function test_primary_query_uses_configured_local_address(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.beltoft.net,ns2.beltoft.net', 'domain_dns_primary_query_address' => '10.10.10.2' ) );
		$this->assertSame( '10.10.10.2', DnsQuery::address( 'ns1.beltoft.net' ) );
		$this->assertSame( 'ns2.beltoft.net', DnsQuery::address( 'ns2.beltoft.net' ) );
	}

	private function resource( string $name = 'example.com' ): Resource {
		$product = $this->service_product( 'domain' );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'remote_id' => '123' ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	public function test_zone_created_with_our_ns_and_ds_sent_to_registrar(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$result = Zone::ensure( $this->resource() );
		$this->assertTrue( $result->is_done() );
		$this->assertTrue( $dns->zone( 'example.com' )['secured'] );
		$this->assertSame( true, $op->calls()[0]['body']['is_dnssec_enabled'] );
	}

	public function test_no_zone_when_nameservers_unset(): void {
		$this->settings( array( 'domain_nameservers' => '' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$this->assertTrue( Zone::ensure( $this->resource() )->is_done() );
		$this->assertSame( array(), $dns->calls() );
	}

	public function test_remove_clears_dnssec_before_zone_delete(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		$dns->create_zone( 'remove.example.com', Zone::nameservers() );
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$r = $this->resource( 'remove.example.com' );
		$r->set( array( 'dnssec_keys' => array( array( 'flags' => 257 ) ), 'zone_claimed' => true ) );
		$this->assertTrue( Zone::remove( $r )->is_done() );
		$this->assertSame( false, $op->calls()[0]['body']['is_dnssec_enabled'] );
		$this->assertNull( $dns->zone( 'remove.example.com' ) );
	}

	public function test_remove_waits_for_secondary_copy_to_be_cleared(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		$dns->create_zone( 'remove.example.com', Zone::nameservers() );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_dns_query', static fn() => 123 );
		$r = $this->resource( 'remove.example.com' );
		$r->set( array( 'zone_claimed' => true ) );
		$this->assertTrue( Zone::remove( $r )->is_retry() );
		$this->assertNull( $dns->zone( 'remove.example.com' ) );
	}

	public function test_prepared_zone_can_be_reused_after_step_save_crash(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$r = $this->resource( 'crash-example.com' );
		$this->assertTrue( Zone::prepare( $r )->is_done() );
		$this->assertTrue( Zone::prepare( Resource::find( $r->id() ) )->is_done() );
		$this->assertCount( 1, array_filter( $dns->calls(), static fn( $call ) => 'create_zone' === $call[0] ) );
	}

	public function test_registration_waits_until_both_nameservers_serve_the_zone(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_dns_query', static fn( $value, $server, $domain, $type ) => 'ns2.example.net' === $server && 6 === $type ? new \WP_Error( 'dns_missing', 'Not transferred yet.' ) : $value, 10, 4 );
		$r = $this->resource( 'wait-example.com' );
		$result = Zone::prepare( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 300, $result->delay() );
		$this->assertNotNull( $dns->zone( 'wait-example.com' ) );
	}

	public function test_sandbox_fake_zone_does_not_publish_fake_dnssec_key(): void {
		$this->settings( array( 'domain_api_mode' => 'sandbox', 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$r = $this->resource( 'sandbox-example.com' );
		$result = Zone::ensure( $r );
		$this->assertTrue( $result->is_done() );
		$this->assertFalse( $dns->zone( 'sandbox-example.com' )['secured'] );
		$this->assertSame( array(), $result->meta()['dnssec_keys'] );
	}
}
