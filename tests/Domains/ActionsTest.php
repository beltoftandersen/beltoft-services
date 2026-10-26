<?php
/** Customer action boundaries and DNS changes. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Actions;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class ActionsTest extends TestCase {
	private function resource( string $driver ): Resource {
		$product = $this->service_product( $driver );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, 'dns' === $driver ? new DnsDriver() : new DomainDriver(), array(), array( 'state' => 'ready', 'driver' => $driver, 'domain' => 'actions-example.net', 'remote_id' => '123' ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	public function test_dns_resource_exposes_only_records_and_delegation(): void {
		$r = $this->resource( 'dns' );
		$this->assertSame( array( 'set_records', 'check_delegation' ), array_keys( ( new DnsDriver() )->customer_actions( $r ) ) );
		$this->assertFalse( ( new DnsDriver() )->act( $r, 'lock', array() )->is_done() );
		$this->assertFalse( ( new DnsDriver() )->act( $r, 'set_nameservers', array() )->is_done() );
	}

	public function test_bad_records_refused_before_dns_call(): void {
		$r = $this->resource( 'dns' );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$bad = array( 'records' => wp_json_encode( array( array( 'name' => '@', 'type' => 'A', 'ttl' => 3600, 'value' => '999.1.1.1' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, Actions::refusal( null, $r, 'set_records', $bad ) );
		$this->assertSame( array(), $dns->calls() );
	}

	public function test_custom_nameservers_clear_dnssec_first(): void {
		$r = $this->resource( 'domain' );
		$r->set( array( 'dnssec_keys' => array( array( 'flags' => 257 ) ) ) );
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array() );
		$op->reply( 'PUT', '/domains/123', array() );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$this->assertTrue( Actions::run( $r, 'set_nameservers', array( 'mode' => 'custom', 'hosts' => 'ns1.custom.net,ns2.custom.net' ) )->is_done() );
		$this->assertSame( false, $op->calls()[0]['body']['is_dnssec_enabled'] );
		$this->assertSame( 'ns1.custom.net', $op->calls()[1]['body']['name_servers'][0]['name'] );
	}

	public function test_switching_back_sets_nameservers_before_dnssec(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$r = $this->resource( 'domain' );
		$r->set( array( 'ns_mode' => 'custom' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array() );
		$op->reply( 'PUT', '/domains/123', array() );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$this->assertTrue( Actions::run( $r, 'set_nameservers', array( 'mode' => 'ours' ) )->is_done() );
		$this->assertTrue( $dns->zone( 'actions-example.net' )['secured'] );
		$this->assertArrayHasKey( 'name_servers', $op->calls()[0]['body'] );
		$this->assertTrue( $op->calls()[1]['body']['is_dnssec_enabled'] );
	}
}
