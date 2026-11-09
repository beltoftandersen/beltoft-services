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

	public function test_reserved_names_are_never_a_website_and_the_list_is_filterable(): void {
		foreach ( array( 'www', 'mail', 'smtp', 'imap', 'webmail', 'autoconfig', 'autodiscover', 'ns1', 'ns2', 'admin', 'api', '_acme-challenge' ) as $name ) {
			$this->assertFalse( Sites::valid_slug( $name ), $name );
		}

		$this->assertTrue( Sites::valid_slug( 'blog' ) );
		add_filter( 'beltoft_services_hosting_reserved_slugs', static fn( $names ) => array_merge( $names, array( 'blog' ) ) );
		$this->assertFalse( Sites::valid_slug( 'blog' ) );
		remove_all_filters( 'beltoft_services_hosting_reserved_slugs' );
	}

	public function test_a_preview_name_never_overwrites_a_record_it_did_not_make(): void {
		list( , , $node ) = $this->account();
		$zone = 'preview.example.test';

		// A TXT set at the name, and an address that is not our edge's, are somebody else's.
		$this->dns->replace_rrsets( $zone, array( array( 'name' => 'taken.' . $zone, 'type' => 'TXT', 'ttl' => 60, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '"v=spf1 -all"', 'disabled' => false ) ) ) ) );
		$this->assertIsString( SiteDns::publish_preview( 'taken', $node ) );
		$this->assertSame( array(), $this->contents( $zone, 'taken.' . $zone, 'A' ), 'No address was added.' );

		$this->dns->replace_rrsets( $zone, array( array( 'name' => 'other.' . $zone, 'type' => 'A', 'ttl' => 60, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '192.0.2.99', 'disabled' => false ) ) ) ) );
		$this->assertIsString( SiteDns::publish_preview( 'other', $node ) );
		$this->assertSame( array( '192.0.2.99' ), $this->contents( $zone, 'other.' . $zone, 'A' ), 'The foreign address stays.' );

		// Our own record is published again without complaint.
		$this->assertTrue( SiteDns::publish_preview( 'mine', $node ) );
		$this->assertTrue( SiteDns::publish_preview( 'mine', $node ) );
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

	/**
	 * Answers the public lookups of the external domain checks.
	 *
	 * @param string[] $a   Addresses.
	 * @param string[] $txt TXT strings.
	 */
	private function lookups( array $a, array $txt ): void {
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $name, $type ) => 'TXT' === $type ? $txt : $a, 10, 3 );
	}

	public function test_an_external_domain_is_active_only_with_the_ownership_proof_and_our_address(): void {
		list( $r, , $node ) = $this->account();
		$slug  = 'w' . $r->id();
		$host  = 'www.elsewhere.example';
		$token = SiteDns::proof_token( $r->id(), $host );

		$calls = count( $this->dns->calls() );
		$this->assertSame( array( 'kind' => 'external', 'state' => 'waiting' ), SiteDns::add_domain( $r, $slug, $host, $node ) );
		$this->assertCount( $calls, $this->dns->calls(), 'Nothing is written for an external name.' );

		$this->assertSame(
			array( array( 'type' => 'A', 'value' => '198.51.100.7' ), array( 'type' => 'AAAA', 'value' => '2001:db8::7' ) ),
			SiteDns::instructions( $node )
		);
		$with = SiteDns::instructions( $node, $host, $r->id() );
		$this->assertSame( array( 'type' => 'TXT', 'name' => '_beltoft-verify.' . $host, 'value' => $token ), end( $with ) );
		$this->assertNotSame( $token, SiteDns::proof_token( $r->id() + 1, $host ), 'The token belongs to the account.' );
		$this->assertNotContains( $host, array_column( EdgeMap::routes( $r->id(), false ), 'host' ), 'A waiting name is not on the edge.' );

		$this->lookups( array( '203.0.113.50' ), array( $token ) );
		$this->assertSame( 'waiting', SiteDns::check_external( $host, $node ), 'The proof without our address.' );

		$this->lookups( array( '198.51.100.7' ), array() );
		$this->assertSame( 'waiting', SiteDns::check_external( $host, $node ), 'Pointing at us proves nothing: anybody can point a name they do not own.' );
		$this->lookups( array( '198.51.100.7' ), array( 'someone-elses-token' ) );
		$this->assertSame( 'waiting', SiteDns::check_external( $host, $node ) );

		$this->lookups( array( '198.51.100.7' ), array( '"' . $token . '"' ) );
		$this->assertSame( 'active', SiteDns::check_external( $host, $node ) );
		$this->assertContains( $host, array_column( EdgeMap::routes( $r->id(), false ), 'host' ) );

		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', fn() => new \WP_Error( 'down', 'down' ) );
		$this->assertNull( SiteDns::check_external( $host, $node ), 'A failed lookup changes nothing.' );
		$this->assertSame( 'active', SiteDomains::find( $host )['state'] );
		remove_all_filters( 'beltoft_services_dns_resolve' );

		$this->assertNull( SiteDns::check_external( 'w' . $r->id() . '.preview.example.test', $node ), 'Only external names are checked.' );
		$this->assertNull( SiteDns::check_external( 'unknown.example', $node ) );
	}

	public function test_subdomains_of_a_held_or_verified_domain_are_unlimited_and_need_no_proof_of_their_own(): void {
		list( $r, $user, $node ) = $this->account();
		$slug = 'w' . $r->id();

		// A domain in the customer's zone: any number of subdomains, all active at once.
		$this->domain( $user, 'shop.example' );
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->assertSame( array( 'kind' => 'ours', 'state' => 'active' ), SiteDns::add_domain( $r, $slug, 'sub' . $i . '.shop.example', $node ) );
		}

		// An outside domain: the domain itself needs the proof, a subdomain of it does not once it is active.
		$apex  = 'outside.example';
		$under = 'blog.outside.example';
		SiteDns::add_domain( $r, $slug, $apex, $node );
		SiteDns::add_domain( $r, $slug, $under, $node );
		$this->lookups( array( '198.51.100.7' ), array() );
		$this->assertSame( 'waiting', SiteDns::check_external( $under, $node ), 'Not verified yet, so the subdomain gets no free pass.' );
		$this->assertFalse( SiteDns::under_verified_domain( $r->id(), $under ) );

		$this->lookups( array( '198.51.100.7' ), array( SiteDns::proof_token( $r->id(), $apex ) ) );
		$this->assertSame( 'active', SiteDns::check_external( $apex, $node ) );
		$this->lookups( array( '198.51.100.7' ), array() );
		$this->assertTrue( SiteDns::under_verified_domain( $r->id(), $under ) );
		$this->assertSame( 'active', SiteDns::check_external( $under, $node ), 'A subdomain of a verified domain only has to point at us.' );
		$this->lookups( array( '203.0.113.50' ), array() );
		$this->assertSame( 'waiting', SiteDns::check_external( $under, $node ), 'It still has to point at us.' );

		// Someone else's domain: no free pass, and a name another account holds is refused.
		list( $other ) = $this->account();
		$this->assertFalse( SiteDns::under_verified_domain( $other->id(), 'www.outside.example' ) );
		$this->assertStringContainsString( 'another account', (string) SiteDns::add_domain( $other, 'w' . $other->id(), $under, $node ) );
		$this->lookups( array( '198.51.100.7' ), array() );
		SiteDns::add_domain( $other, 'w' . $other->id(), 'www.outside.example', $node );
		$this->assertSame( 'waiting', SiteDns::check_external( 'www.outside.example', $node ), 'The other account never proved outside.example.' );
		remove_all_filters( 'beltoft_services_dns_resolve' );
	}

	public function test_the_sweep_activates_and_deactivates_external_names_and_sends_the_routes(): void {
		list( $r, , $node ) = $this->account();
		$r->set( array( 'state' => 'ready', 'desired' => 'ready', 'steps' => array( 'ready' ) ) );
		$host = 'www.sweep.example';
		SiteDns::add_domain( Resource::find( $r->id() ), 'w' . $r->id(), $host, $node );

		$this->lookups( array( '198.51.100.7' ), array( SiteDns::proof_token( $r->id(), $host ) ) );
		$this->assertSame( array( $host => 'active' ), \BeltoftServices\Hosting\DomainSweep::run() );
		$edge = array_filter( $this->agent->calls_of( 'edge.apply' ), static fn( $c ) => in_array( 'www.sweep.example', array_column( (array) ( $c['args']['routes'] ?? array() ), 'host' ), true ) );
		$this->assertNotEmpty( $edge, 'The edge was told.' );
		$this->assertSame( array(), \BeltoftServices\Hosting\DomainSweep::run(), 'Nothing changed.' );

		$this->lookups( array( '198.51.100.7' ), array() );
		$this->assertSame( array( $host => 'waiting' ), \BeltoftServices\Hosting\DomainSweep::run(), 'The proof was removed.' );
		remove_all_filters( 'beltoft_services_dns_resolve' );
	}

	public function test_the_operators_own_domains_cannot_be_added(): void {
		list( $r, , $node ) = $this->account();
		$this->settings( array( 'hosting_denied_domains' => "beltofttest.example\nshop.example" ) );

		foreach ( array( 'beltofttest.example', 'www.beltofttest.example', 'a.b.shop.example' ) as $host ) {
			$this->assertIsString( SiteDns::add_domain( $r, 'w' . $r->id(), $host, $node ), $host );
			$this->assertNull( SiteDomains::find( $host ) );
		}

		$this->assertIsArray( SiteDns::add_domain( $r, 'w' . $r->id(), 'www.notmine.example', $node ), 'Another domain is fine.' );
		$this->assertIsArray( SiteDns::add_domain( $r, 'w' . $r->id(), 'xshop.example', $node ), 'Only the name and what is under it.' );
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
