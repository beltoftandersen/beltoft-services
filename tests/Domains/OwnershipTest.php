<?php
/**
 * The one ownership check: token, scope, sources, the DNS check and the claim job's use of it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsClaimJobs;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Ownership;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class OwnershipTest extends TestCase {

	/** @var FakeDns */
	private $dns;

	/** @var array<string, array<string, mixed>> */
	private array $proof = array();

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 1 ) );
		$this->dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', fn() => $this->dns );
		$this->proof = array();
		add_filter( 'beltoft_services_dns_proof_result', fn( $pre, $call ) => $this->proof[ $call ] ?? $pre, 10, 2 );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( Ownership::FILTER );
		remove_all_filters( 'beltoft_services_dns_resolve' );
		parent::tear_down();
	}

	private function delegation(): array {
		return array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.host.example' ), 'ips' => array(), 'ds' => null, 'ours' => false );
	}

	/** A ready domain of the customer on our name servers. */
	private function zone( int $user, string $name ): void {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->assertTrue( $this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) ) );
	}

	public function test_the_token_is_stable_and_differs_per_customer_and_per_domain(): void {
		$a = Ownership::token( 5, 'example.com' );

		$this->assertStringStartsWith( 'beltoft-owner=', $a );
		$this->assertSame( 14 + 40, strlen( $a ) );
		$this->assertSame( $a, Ownership::token( 5, 'example.com' ), 'Made again, not stored: always the same.' );
		$this->assertSame( $a, Ownership::token( 5, 'www.example.com' ), 'One value for the registrable domain.' );
		$this->assertSame( $a, Ownership::token( 5, 'Shop.Example.COM.' ) );
		$this->assertNotSame( $a, Ownership::token( 6, 'example.com' ), 'Another customer has another value.' );
		$this->assertNotSame( $a, Ownership::token( 5, 'example.org' ) );
		$this->assertNotSame( Ownership::token( 5, 'example.co.uk' ), Ownership::token( 5, 'other.co.uk' ), 'A public suffix is not the domain.' );
		$this->assertSame( '', Ownership::token( 0, 'example.com' ) );
		$this->assertSame( '', Ownership::token( 5, 'co.uk' ), 'Nobody owns a public suffix.' );
		$this->assertSame( '', Ownership::token( 5, 'localhost' ) );
		$this->assertSame( '_beltoft-verify.shop.example.com', Ownership::record_name( 'Shop.example.com.' ) );
		$this->assertSame( array( 'name' => '_beltoft-verify.example.com', 'type' => 'TXT', 'value' => $a ), Ownership::record( 5, 'example.com' ) );
	}

	public function test_scope_a_domain_covers_its_names_and_a_subdomain_only_itself_and_below(): void {
		$this->assertTrue( Ownership::covers( 'example.com', 'example.com' ) );
		$this->assertTrue( Ownership::covers( 'example.com', 'www.example.com' ) );
		$this->assertTrue( Ownership::covers( 'example.com', 'a.b.example.com' ) );
		$this->assertTrue( Ownership::covers( 'shop.example.com', 'shop.example.com' ) );
		$this->assertTrue( Ownership::covers( 'shop.example.com', 'x.shop.example.com' ) );
		$this->assertFalse( Ownership::covers( 'shop.example.com', 'example.com' ), 'A subdomain proves nothing above it.' );
		$this->assertFalse( Ownership::covers( 'shop.example.com', 'www.example.com' ), 'Nor its sibling.' );
		$this->assertFalse( Ownership::covers( 'example.com', 'notexample.com' ) );
		$this->assertFalse( Ownership::covers( 'example.com', 'example.com.evil.org' ) );
		$this->assertFalse( Ownership::covers( 'co.uk', 'example.co.uk' ), 'A public suffix proves nothing.' );
		$this->assertFalse( Ownership::covers( 'com', 'example.com' ) );
		$this->assertSame( array( 'a.b.example.com', 'b.example.com', 'example.com' ), Ownership::chain( 'a.b.example.com' ) );
		$this->assertSame( array(), Ownership::chain( 'co.uk' ) );
	}

	public function test_a_zone_of_the_customer_proves_the_domain_and_the_names_below_it(): void {
		$this->zone( 11, 'zoned.example' );

		$this->assertSame( 'zone', Ownership::source( 11, 'zoned.example' ) );
		$this->assertTrue( Ownership::proven( 11, 'www.zoned.example' ) );
		$this->assertTrue( Ownership::proven( 11, 'a.b.zoned.example' ) );
		$this->assertFalse( Ownership::proven( 12, 'zoned.example' ), 'Another customer.' );
		$this->assertFalse( Ownership::proven( 11, 'other.example' ) );
		$this->assertFalse( Ownership::proven( 11, 'example' ) );
		$this->assertFalse( Ownership::proven( 0, 'zoned.example' ) );
		$this->assertSame( '', Ownership::source( 11, 'zoned.example', array( 'zone' ) ), 'A caller may leave a source out.' );
	}

	public function test_a_zone_of_a_subdomain_covers_only_that_subdomain(): void {
		$this->zone( 11, 'shop.zoned.example' );

		$this->assertTrue( Ownership::proven( 11, 'shop.zoned.example' ) );
		$this->assertTrue( Ownership::proven( 11, 'a.shop.zoned.example' ) );
		$this->assertFalse( Ownership::proven( 11, 'zoned.example' ) );
		$this->assertFalse( Ownership::proven( 11, 'www.zoned.example' ) );
	}

	public function test_a_verified_claim_proves_the_domain_but_not_one_verified_on_another_proof_and_not_for_another_customer(): void {
		$mine = DnsClaims::create( 21, 'claimed.example' );
		DnsClaims::set_status( $mine['id'], 'verified', array( 'last_result' => array( 'status' => 'found', 'via' => 'dns' ) ) );

		$this->assertSame( 'claim', Ownership::source( 21, 'www.claimed.example' ) );
		$this->assertFalse( Ownership::proven( 22, 'claimed.example' ), 'Another customer.' );

		$derived = DnsClaims::create( 23, 'borrowed.example' );
		DnsClaims::set_status( $derived['id'], 'verified', array( 'last_result' => array( 'status' => 'found', 'via' => 'derived' ) ) );
		$this->assertFalse( Ownership::proven( 23, 'borrowed.example' ), 'A claim that took its proof from another service is not passed on.' );

		$old = DnsClaims::create( 24, 'oldclaim.example' );
		DnsClaims::set_status( $old['id'], 'verified' );
		$this->assertTrue( Ownership::proven( 24, 'oldclaim.example' ), 'Claims verified before the mark was added were verified by the record.' );

		$pending = DnsClaims::create( 25, 'pending.example' );
		$this->assertFalse( Ownership::proven( 25, 'pending.example' ) );
		DnsClaims::set_status( $pending['id'], 'verified' );
		global $wpdb;
		$wpdb->update( \BeltoftServices\Domains\DnsSchema::tables()['claims'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $pending['id'] ) );
		$this->assertFalse( Ownership::proven( 25, 'pending.example' ), 'A lapsed claim proves nothing.' );
	}

	public function test_the_filter_answers_only_with_true_and_is_asked_for_the_customer_and_name(): void {
		$asked = array();
		add_filter(
			Ownership::FILTER,
			static function ( $proven, $name, $user ) use ( &$asked ) {
				$asked[] = array( $name, $user );
				return 'sub.feature.example' === $name && 31 === $user ? true : $proven;
			},
			10,
			3
		);

		$this->assertTrue( Ownership::proven( 31, 'sub.feature.example' ) );
		$this->assertFalse( Ownership::proven( 32, 'sub.feature.example' ) );
		$this->assertContains( array( 'sub.feature.example', 31 ), $asked );

		remove_all_filters( Ownership::FILTER );
		add_filter( Ownership::FILTER, static fn() => 'yes' );
		$this->assertFalse( Ownership::proven( 31, 'sub.feature.example' ), 'Only a real true counts.' );
		$this->assertFalse( Ownership::proven( 31, 'co.uk' ), 'No filter is asked for a public suffix.' );
	}

	public function test_the_check_looks_for_the_central_value_and_the_value_a_feature_showed_before(): void {
		$this->proof['delegation'] = $this->delegation();
		$seen                      = array();
		add_filter(
			'beltoft_services_dns_proof_result',
			static function ( $pre, $call, $args ) use ( &$seen ) {
				if ( 'check_txt' === $call ) {
					$seen = $args;
					return array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
				}
				return $pre;
			},
			20,
			3
		);

		$result = Ownership::check( 41, 'www.checked.example', array( 'old-hosting-value', '' ) );
		$this->assertSame( 'found', $result['status'] );
		$this->assertSame( 'www.checked.example', $seen[0], 'The record is looked for at the name asked about.' );
		$this->assertSame( array( Ownership::token( 41, 'checked.example' ), 'old-hosting-value' ), $seen[1] );
	}

	public function test_a_missing_record_and_an_unsupported_name(): void {
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );

		$this->assertSame( 'missing', Ownership::check( 41, 'checked.example' )['status'] );
		$this->assertSame( 'support', Ownership::check( 41, 'co.uk' )['status'] );
		$this->assertSame( 'support', Ownership::check( 0, 'checked.example' )['status'] );
		$this->assertSame( 'support', Ownership::check( 41, 'not a name' )['status'] );
	}

	public function test_the_resolver_is_only_the_fallback_when_the_name_servers_cannot_be_asked(): void {
		$token = Ownership::token( 41, 'fallback.example' );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $name, $type ) => 'TXT' === $type && '_beltoft-verify.fallback.example' === $name ? array( '"' . $token . '"' ) : array(), 10, 3 );

		$this->proof['delegation'] = array( 'status' => 'unknown', 'reason' => 'parent_unreachable', 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => false );
		$this->assertSame( 'unknown', Ownership::check( 41, 'fallback.example' )['status'], 'No fallback unless the caller allows it.' );
		$this->assertSame( 'found', Ownership::check( 41, 'fallback.example', array(), true )['status'] );
		$this->assertSame( 'missing', Ownership::check( 42, 'fallback.example', array(), true )['status'], 'The value belongs to another customer.' );

		// Delegated to our own servers, or not delegated: the resolver is not asked.
		foreach ( array( 'delegated_to_us', 'not_delegated' ) as $reason ) {
			$this->proof['delegation'] = array( 'status' => 'support', 'reason' => $reason, 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => true );
			$this->assertSame( 'support', Ownership::check( 41, 'fallback.example', array(), true )['status'], $reason );
		}

		// The name servers answered: missing is final, the resolver (a cache) cannot overrule it.
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );
		$this->assertSame( 'missing', Ownership::check( 41, 'fallback.example', array(), true )['status'] );
	}

	public function test_the_claim_job_verifies_a_claim_on_a_domain_proven_elsewhere_and_marks_it(): void {
		$other = DnsClaims::create( 51, 'proven.example' );
		DnsClaims::set_status( $other['id'], 'checking' );
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );

		// Nothing proven yet: the record is needed.
		DnsClaimJobs::check( $other['id'] );
		$this->assertSame( 'pending', DnsClaims::get( $other['id'] )['status'] );

		// Another service holds a proof for the same customer: no new record.
		add_filter( Ownership::FILTER, static fn( $p, $name, $user ) => 51 === $user && 'proven.example' === $name ? true : $p, 10, 3 );
		DnsClaims::set_status( $other['id'], 'checking' );
		DnsClaimJobs::check( $other['id'] );
		$claim = DnsClaims::get( $other['id'] );
		$this->assertSame( 'verified', $claim['status'] );
		$this->assertSame( 'derived', $claim['last_result']['via'] );

		// It is not passed on: the same claim looked at again does not renew itself.
		remove_all_filters( Ownership::FILTER );
		$this->assertFalse( Ownership::proven( 51, 'proven.example' ), 'A derived claim proves nothing for others.' );
		DnsClaimJobs::check( $other['id'] );
		$this->assertSame( 'pending', DnsClaims::get( $other['id'] )['status'], 'The other proof is gone, so the claim is asked for the record again.' );

		// Another customer's proof does not help.
		add_filter( Ownership::FILTER, static fn( $p, $name, $user ) => 99 === $user ? true : $p, 10, 3 );
		DnsClaims::set_status( $other['id'], 'checking' );
		DnsClaimJobs::check( $other['id'] );
		$this->assertSame( 'pending', DnsClaims::get( $other['id'] )['status'] );
	}

	public function test_the_claim_job_accepts_the_central_value_and_the_value_the_claim_showed_before(): void {
		$claim = DnsClaims::create( 61, 'valued.example' );
		DnsClaims::set_status( $claim['id'], 'checking' );
		$this->proof['delegation'] = $this->delegation();
		$seen                      = array();
		add_filter(
			'beltoft_services_dns_proof_result',
			static function ( $pre, $call, $args ) use ( &$seen ) {
				if ( 'check_txt' === $call ) {
					$seen = (array) $args[1];
					return array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
				}
				return $pre;
			},
			20,
			3
		);

		DnsClaimJobs::check( $claim['id'] );
		$this->assertSame( array( Ownership::token( 61, 'valued.example' ), DnsClaims::legacy_value( $claim ) ), $seen );
		$this->assertSame( 'dns', DnsClaims::get( $claim['id'] )['last_result']['via'] );
		$this->assertSame( 'claim', Ownership::source( 61, 'valued.example' ), 'A claim verified by the record proves the domain.' );
	}
}
