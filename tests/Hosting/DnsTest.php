<?php
/**
 * DNS for hosting names: preview, our name servers, external domains, and the dns and verify steps.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Records as DnsRecords;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\Provision;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

/**
 * SiteDns, SiteDomains and the two steps.
 */
class DnsTest extends HostingCase {

	/**
	 * Content values of one set of a zone in the fake DNS.
	 *
	 * @param string $zone Zone.
	 * @param string $name Name without the final dot.
	 * @param string $type Type.
	 * @return string[]
	 */
	private function contents( string $zone, string $name, string $type ): array {
		foreach ( (array) $this->dns->zone( $zone )['rrsets'] as $set ) {
			if ( rtrim( $set['name'], '.' ) === $name && $set['type'] === $type ) {
				return array_column( $set['records'], 'content' );
			}
		}

		return array();
	}

	/**
	 * A ready domain of the customer on our name servers.
	 *
	 * @param int    $user User id.
	 * @param string $name Domain.
	 * @param array<string, mixed> $meta Extra meta.
	 */
	private function domain( int $user, string $name, array $meta = array() ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->assertTrue( $this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * A provisioned account, its user and its node settings.
	 *
	 * @return array{0: Resource, 1: int, 2: array<string, mixed>}
	 */
	private function account(): array {
		$r = $this->resource();
		$this->provision_fully( $r );
		$r = Resource::find( $r->id() );

		return array( $r, (int) $r->license()['user_id'], (array) Nodes::get( 'n1' ) );
	}

	public function test_provisioning_publishes_the_preview_name_and_verifies_the_site(): void {
		$r    = $this->resource();
		$last = $this->provision_fully( $r );
		$host = 'w' . $r->id() . '.preview.example.test';

		$this->assertSame( array( '198.51.100.7' ), $this->contents( 'preview.example.test', $host, 'A' ) );
		$this->assertSame( array( '2001:db8::7' ), $this->contents( 'preview.example.test', $host, 'AAAA' ) );
		$this->assertSame( 'active', SiteDomains::find( $host )['state'] );
		$this->assertSame( 'preview', SiteDomains::find( $host )['kind'] );
		$this->assertContains( 'dns', $last->meta()['steps'] );
		$this->assertContains( 'verify', $last->meta()['steps'] );

		$verify = $this->agent->calls_of( 'site.verify' );
		$this->assertCount( 1, $verify );
		$this->assertSame( $host, $verify[0]['args']['host'] );
	}

	public function test_the_dns_step_runs_after_the_edge_and_before_ready(): void {
		$steps = Provision::STEPS;

		$this->assertSame( array( 'edge', 'dns', 'verify', 'mail', 'ready' ), array_slice( $steps, -5 ) );
	}

	public function test_a_node_without_an_edge_address_fails_the_dns_step_for_good(): void {
		$this->save_node( array( 'edge_ip' => '', 'edge_ipv6' => '' ) );
		$r = $this->resource();

		$result = $this->provision( $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'no edge address', $result->error() );
		$this->assertContains( 'edge', Resource::find( $r->id() )->get( 'steps' ), 'The earlier steps stay.' );
	}

	public function test_a_missing_preview_zone_in_our_dns_fails_and_a_dns_error_retries(): void {
		$this->dns->delete_zone( 'preview.example.test' );
		$r      = $this->resource();
		$result = $this->provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'does not exist', $result->error() );

		$this->dns->create_zone( 'preview.example.test', array( 'ns1.example.test.' ) );
		$this->dns = new class() extends \BeltoftServices\Domains\FakeDns {
			public function zone( string $name ): array|\WP_Error|null {
				return new \WP_Error( 'down', 'down' );
			}
		};
		$again = $this->provision( $r );
		$this->assertTrue( $again->is_retry() );
		$this->assertStringContainsString( 'could not be read', $again->error() );
	}

	public function test_a_verify_that_does_not_answer_200_is_a_retry(): void {
		$this->agent->fail_next( 'site.verify', 'connection refused' );
		$r = $this->resource();

		$first = $this->provision( $r );
		$this->assertTrue( $first->is_retry() );
		$this->assertStringContainsString( 'connection refused', $first->error() );
		$this->assertContains( 'dns', Resource::find( $r->id() )->get( 'steps' ) );

		$this->provision_fully( $r );
		$this->assertCount( 2, $this->agent->calls_of( 'site.verify' ) );
	}

	public function test_a_domain_on_our_name_servers_replaces_the_address_sets_and_keeps_the_rest(): void {
		list( $r, $user, $node ) = $this->account();
		$this->domain( $user, 'shop.example' );
		$this->assertTrue( DnsRecords::replace( 'shop.example', array(
			array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '203.0.113.9' ),
			array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'keep me' ),
		) ) );

		$done = SiteDns::add_domain( $r, 'w' . $r->id(), 'www.shop.example', $node );

		$this->assertSame( array( 'kind' => 'ours', 'state' => 'active' ), $done );
		$this->assertSame( array( '198.51.100.7' ), $this->contents( 'shop.example', 'www.shop.example', 'A' ), 'Replaced, not added to.' );
		$this->assertSame( array( '2001:db8::7' ), $this->contents( 'shop.example', 'www.shop.example', 'AAAA' ) );
		$this->assertNotSame( array(), $this->contents( 'shop.example', 'shop.example', 'TXT' ), 'Nothing else is touched.' );

		$hosts = array_column( EdgeMap::routes( $r->id(), false ), 'host' );
		$this->assertContains( 'www.shop.example', $hosts );
		$this->assertContains( 'w' . $r->id() . '.preview.example.test', $hosts );
	}

	public function test_our_zone_refusals(): void {
		list( $r, $user, $node ) = $this->account();
		$slug  = 'w' . $r->id();
		$other = self::factory()->user->create();
		$this->domain( $other, 'theirs.example' );
		$this->domain( $user, 'alias.example' );
		$this->dns->replace_rrsets( 'alias.example', array( array( 'name' => 'www.alias.example.', 'type' => 'CNAME', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => 'x.example.net.', 'disabled' => false ) ) ) ) );
		$this->domain( $user, 'paused.example', array( 'records_off' => true ) );

		$this->assertStringContainsString( 'alias (CNAME)', (string) SiteDns::publish_ours( $user, 'www.alias.example', $node ) );
		$this->assertSame( array(), $this->contents( 'alias.example', 'www.alias.example', 'A' ) );

		// Someone else's zone, a paused one: not ours for this customer, so it is an external name, never written.
		$this->assertSame( 'external', SiteDns::add_domain( $r, $slug, 'www.theirs.example', $node )['kind'] );
		$this->assertSame( array(), $this->contents( 'theirs.example', 'www.theirs.example', 'A' ) );
		$this->assertSame( 'external', SiteDns::add_domain( $r, $slug, 'www.paused.example', $node )['kind'] );
		$this->assertSame( array(), $this->contents( 'paused.example', 'www.paused.example', 'A' ) );

		$this->assertStringContainsString( 'not in one of your domains', (string) SiteDns::publish_ours( $user, 'www.nowhere.example', $node ) );
		$this->assertStringContainsString( 'not valid', (string) SiteDns::add_domain( $r, $slug, 'bad_name', $node ) );
		$this->assertStringContainsString( 'preview zone', (string) SiteDns::add_domain( $r, $slug, 'x.preview.example.test', $node ) );
		$this->assertStringContainsString( 'does not exist', (string) SiteDns::add_domain( $r, 'nosite', 'www.theirs.example', $node ) );
	}

	public function test_an_external_domain_waits_until_it_points_at_the_edge(): void {
		list( $r, , $node ) = $this->account();
		$slug = 'w' . $r->id();

		$calls = count( $this->dns->calls() );
		$this->assertSame( array( 'kind' => 'external', 'state' => 'waiting' ), SiteDns::add_domain( $r, $slug, 'www.elsewhere.example', $node ) );
		$this->assertCount( $calls, $this->dns->calls(), 'Nothing is written for an external name.' );

		$this->assertSame(
			array( array( 'type' => 'A', 'value' => '198.51.100.7' ), array( 'type' => 'AAAA', 'value' => '2001:db8::7' ) ),
			SiteDns::instructions( $node )
		);
		$this->assertNotContains( 'www.elsewhere.example', array_column( EdgeMap::routes( $r->id(), false ), 'host' ), 'A waiting name is not on the edge.' );

		add_filter( 'beltoft_services_dns_resolve', fn() => array( '203.0.113.50' ), 20 );
		$this->assertSame( 'waiting', SiteDns::check_external( 'www.elsewhere.example', $node ) );
		remove_all_filters( 'beltoft_services_dns_resolve' );

		add_filter( 'beltoft_services_dns_resolve', fn() => array( '198.51.100.7' ) );
		$this->assertSame( 'active', SiteDns::check_external( 'www.elsewhere.example', $node ) );
		$this->assertContains( 'www.elsewhere.example', array_column( EdgeMap::routes( $r->id(), false ), 'host' ) );

		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', fn() => new \WP_Error( 'down', 'down' ) );
		$this->assertNull( SiteDns::check_external( 'www.elsewhere.example', $node ), 'A failed lookup changes nothing.' );
		$this->assertSame( 'active', SiteDomains::find( 'www.elsewhere.example' )['state'] );

		$this->assertNull( SiteDns::check_external( 'w' . $r->id() . '.preview.example.test', $node ), 'Only external names are checked.' );
		$this->assertNull( SiteDns::check_external( 'unknown.example', $node ) );
	}

	public function test_a_domain_belongs_to_one_account(): void {
		list( $a, , $node ) = $this->account();
		list( $b )          = $this->account();

		$this->assertIsArray( SiteDns::add_domain( $a, 'w' . $a->id(), 'www.shared.example', $node ) );
		$this->assertStringContainsString( 'another account', (string) SiteDns::add_domain( $b, 'w' . $b->id(), 'www.shared.example', $node ) );
		$this->assertIsArray( SiteDns::add_domain( $a, 'w' . $a->id(), 'www.shared.example', $node ), 'The same account again is fine.' );
		$this->assertCount( 2, SiteDomains::for_resource( $a->id() ), 'The preview name and the new one.' );
	}

	public function test_removing_a_name_removes_only_what_we_wrote(): void {
		list( $r, $user, $node ) = $this->account();
		$this->domain( $user, 'mine.example' );
		SiteDns::add_domain( $r, 'w' . $r->id(), 'www.mine.example', $node );
		$this->assertSame( array( '198.51.100.7' ), $this->contents( 'mine.example', 'www.mine.example', 'A' ) );

		// The customer changed the record to something else since: it is left alone.
		DnsRecords::replace( 'mine.example', array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 300, 'value' => '203.0.113.77' ) ) );
		$this->assertTrue( SiteDns::remove_domain( $r, 'www.mine.example', $node ) );
		$this->assertSame( array( '203.0.113.77' ), $this->contents( 'mine.example', 'www.mine.example', 'A' ) );
		$this->assertNull( SiteDomains::find( 'www.mine.example' ), 'The row goes.' );

		$this->assertTrue( SiteDns::remove_domain( $r, 'never.added.example', $node ), 'An unknown name is nothing to do.' );
	}

	public function test_delete_removes_the_preview_record_our_records_and_every_row(): void {
		list( $r, $user, $node ) = $this->account();
		$this->domain( $user, 'gone.example' );
		SiteDns::add_domain( $r, 'w' . $r->id(), 'www.gone.example', $node );
		SiteDns::add_domain( $r, 'w' . $r->id(), 'www.external.example', $node );
		$host = 'w' . $r->id() . '.preview.example.test';

		$result = $this->driver->delete( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( array(), $this->contents( 'preview.example.test', $host, 'A' ) );
		$this->assertSame( array(), $this->contents( 'preview.example.test', $host, 'AAAA' ) );
		$this->assertSame( array(), $this->contents( 'gone.example', 'www.gone.example', 'A' ) );
		$this->assertSame( array(), SiteDomains::for_resource( $r->id() ) );
		$this->assertNull( Records::for_resource( $r->id() ) );
		$this->assertSame( array(), Sites::for_resource( $r->id() ) );
	}

	public function test_domains_table_keys_and_validation(): void {
		$this->assertSame( array(), \BeltoftServices\Hosting\Schema::missing_keys() );
		$this->assertTrue( SiteDomains::valid_host( 'www.example.com' ) );
		foreach ( array( 'example', 'Example.com', '-a.example.com', 'a..b.com', 'a_b.example.com', '' ) as $bad ) {
			$this->assertFalse( SiteDomains::valid_host( $bad ), $bad );
		}

		$this->assertTrue( SiteDomains::add( 1, 1, 'a.example.com', 'external' ) );
		$this->assertTrue( SiteDomains::add( 1, 1, 'a.example.com', 'external' ), 'Same resource: kept.' );
		$this->assertFalse( SiteDomains::add( 2, 2, 'a.example.com', 'external' ), 'Taken by another resource.' );
		$this->assertFalse( SiteDomains::add( 1, 1, 'b.example.com', 'weird' ) );
		$this->assertFalse( SiteDomains::set_state( 'a.example.com', 'bogus' ) );
		$this->assertSame( 'waiting', SiteDomains::find( 'a.example.com' )['state'] );
		SiteDomains::remove_all( 1 );
	}
}
