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

	public function test_a_domain_with_dns_hosted_elsewhere_refuses_records_and_the_switch_to_our_servers(): void {
		$r = $this->resource( 'domain' );
		$r->set( array( 'dns_external' => true ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$records = array( 'records' => wp_json_encode( array( array( 'name' => '@', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.1' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, Actions::refusal( null, $r, 'set_records', $records ) );
		$this->assertInstanceOf( \WP_Error::class, Actions::refusal( null, $r, 'set_nameservers', array( 'mode' => 'ours' ) ) );
		$this->assertNull( Actions::refusal( null, $r, 'set_nameservers', array( 'mode' => 'custom', 'hosts' => 'ns1.custom.net,ns2.custom.net' ) ), 'Another set of name servers is allowed.' );
		$this->assertSame( array(), $dns->calls() );
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

	public function test_switching_to_ours_refuses_a_zone_it_did_not_make_and_keeps_one_it_made(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->resource( 'domain' );
		$r->set( array( 'ns_mode' => 'custom' ) );
		$dns = new FakeDns();
		$dns->create_zone( 'actions-example.net', array( 'ns1.example.net', 'ns2.example.net' ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$op = new FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		add_filter( 'pre_wp_mail', '__return_true' );

		$result = Actions::run( $r, 'set_nameservers', array( 'mode' => 'ours' ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array(), $op->calls(), 'Nothing is changed at the registrar.' );
		$this->assertTrue( (bool) Resource::find( $r->id() )->get( 'zone_conflict_alerted' ) );

		// A zone this domain made earlier (claimed) is fine.
		$r->set( array( 'zone_claimed' => true ) );
		$op->reply( 'PUT', '/domains/123', array() );
		$op->reply( 'PUT', '/domains/123', array() );
		$this->assertTrue( Actions::run( Resource::find( $r->id() ), 'set_nameservers', array( 'mode' => 'ours' ) )->is_done() );
	}

	public function test_a_zone_made_by_switching_to_ours_is_claimed_for_the_next_switch(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$r = $this->resource( 'domain' );
		$r->set( array( 'ns_mode' => 'custom' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array() );
		$op->reply( 'PUT', '/domains/123', array() );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );

		$result = Actions::run( $r, 'set_nameservers', array( 'mode' => 'ours' ) );
		$this->assertTrue( $result->is_done() );
		$this->assertTrue( $result->meta()['zone_claimed'] ?? false );
	}
}
