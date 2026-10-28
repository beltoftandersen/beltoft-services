<?php
/** Proof resolver tests; every answer comes from the wire filter, nothing touches the network. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAddress;
use BeltoftServices\Domains\DnsWire;
use BeltoftServices\Domains\ProofResolver;
use BeltoftServices\Domains\Records;
use BeltoftServices\Tests\Support\DnsPackets;
use BeltoftServices\Tests\TestCase;

class ProofResolverTest extends TestCase {
	private const P1 = '192.5.6.30';
	private const P2 = '192.33.14.30';
	private const N1 = '93.184.216.10';
	private const N2 = '93.184.216.20';

	/** @var array<string, array|\WP_Error> Answers by "ip|name|type"; "ip|*|*" is the default for that server. */
	private array $net = array();
	/** @var array<int, string> */
	private array $asked = array();
	/** @var array<string, string[]> */
	private array $hosts = array();
	/** @var array<string, string[]> */
	private array $zones = array();

	protected function setUp(): void {
		parent::setUp();
		DnsAddress::flush();
		$this->net   = array();
		$this->asked = array();
		$this->hosts = array( 'a.parent.test' => array( self::P1 ), 'b.parent.test' => array( self::P2 ) );
		$this->zones = array( 'com' => array( 'a.parent.test', 'b.parent.test' ), 'uk' => array( 'a.parent.test', 'b.parent.test' ) );
		add_filter( 'beltoft_services_dns_resolve', fn( $pre, $host ) => $this->hosts[ $host ] ?? array(), 10, 2 );
		add_filter( 'beltoft_services_dns_zone_ns', fn( $pre, $zone ) => $this->zones[ $zone ] ?? array(), 10, 2 );
		add_filter(
			'beltoft_services_dns_wire',
			function ( $pre, $ip, $name, $type ) {
				$this->asked[] = "$ip|$name|$type";
				$answer        = $this->net[ "$ip|$name|$type" ] ?? $this->net[ "$ip|*|*" ] ?? new \WP_Error( 'dns_timeout', 'no answer' );
				return is_wp_error( $answer ) ? $answer : DnsPackets::response( $name, $type, $answer );
			},
			10,
			4
		);
	}

	protected function tearDown(): void {
		foreach ( array( 'beltoft_services_dns_resolve', 'beltoft_services_dns_zone_ns', 'beltoft_services_dns_wire', 'beltoft_services_dns_own_ips', 'beltoft_services_dns_budget' ) as $hook ) { remove_all_filters( $hook ); }
		DnsAddress::flush();
		parent::tearDown();
	}

	private function ns_rrs( string $domain, array $names ): array {
		return array_map( static fn( $n ) => array( 'name' => $domain, 'type' => DnsWire::NS, 'ttl' => 172800, 'data' => $n ), $names );
	}

	private function glue( array $map ): array {
		$rrs = array();
		foreach ( $map as $name => $ip ) { $rrs[] = array( 'name' => $name, 'type' => str_contains( $ip, ':' ) ? DnsWire::AAAA : DnsWire::A, 'ttl' => 172800, 'data' => $ip ); }
		return $rrs;
	}

	/** Both parent servers send the same referral. */
	private function refer( string $domain, array $names, array $glue = array() ): void {
		foreach ( array( self::P1, self::P2 ) as $ip ) {
			$this->net[ "$ip|$domain|2" ] = array( 'authority' => $this->ns_rrs( $domain, $names ), 'additional' => $this->glue( $glue ) );
			$this->net[ "$ip|$domain|43" ] = array( 'aa' => true );
		}
	}

	private function delegation_for( array $ips ): array {
		$names = array_keys( $ips );
		sort( $names );
		return array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => $names, 'ips' => $ips, 'ds' => false, 'ours' => false );
	}

	private function txt( string $name, array $strings ): array {
		return array( 'aa' => true, 'answer' => array( array( 'name' => $name, 'type' => DnsWire::TXT, 'data' => $strings ) ) );
	}

	// delegation().

	public function test_in_bailiwick_names_use_glue_and_ds_is_read(): void {
		$this->refer( 'example.com', array( 'NS2.example.com', 'ns1.example.com' ), array( 'ns1.example.com' => self::N1, 'ns2.example.com' => '2606:4700::6810' ) );
		$ds = array( 'aa' => true, 'answer' => array( array( 'name' => 'example.com', 'type' => DnsWire::DS, 'data' => array( 'key_tag' => 1, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'ab' ) ) ) );
		$this->net[ self::P1 . '|example.com|43' ] = $ds;
		$result = ProofResolver::delegation( 'Example.COM' );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( array( 'ns1.example.com', 'ns2.example.com' ), $result['ns'] );
		$this->assertSame( array( 'ns1.example.com' => array( self::N1 ), 'ns2.example.com' => array( '2606:4700::6810' ) ), $result['ips'] );
		$this->assertTrue( $result['ds'] );
		$this->assertFalse( $result['ours'] );
	}

	public function test_out_of_bailiwick_names_are_resolved_not_taken_from_glue(): void {
		$this->hosts['ns1.dnshost.net'] = array( self::N1, '10.9.9.9' );
		$this->refer( 'example.com', array( 'ns1.dnshost.net' ), array( 'ns1.dnshost.net' => '8.8.8.8' ) );
		$result = ProofResolver::delegation( 'example.com' );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( array( 'ns1.dnshost.net' => array( self::N1 ) ), $result['ips'] );
		$this->assertFalse( $result['ds'] );
	}

	public function test_private_glue_is_refused_and_the_name_has_no_address(): void {
		$this->refer( 'example.com', array( 'ns1.example.com', 'ns2.example.com' ), array( 'ns1.example.com' => '10.0.0.5', 'ns2.example.com' => '127.0.0.1' ) );
		$result = ProofResolver::delegation( 'example.com' );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( array( 'ns1.example.com' => array(), 'ns2.example.com' => array() ), $result['ips'] );
	}

	public function test_names_that_are_not_registrable_apexes_are_unsupported(): void {
		foreach ( array( 'www.example.com', 'com', 'co.uk', 'example.notarealtld', 'not a domain', '' ) as $name ) {
			$result = ProofResolver::delegation( $name );
			$this->assertSame( array( 'support', 'unsupported_name' ), array( $result['status'], $result['reason'] ), $name );
		}
		$this->assertSame( array(), $this->asked );
	}

	public function test_a_second_level_suffix_walks_up_to_the_parent_with_servers(): void {
		$this->refer( 'example.co.uk', array( 'ns1.dnshost.net' ) );
		$this->hosts['ns1.dnshost.net'] = array( self::N1 );
		$result = ProofResolver::delegation( 'example.co.uk' );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( array( 'ns1.dnshost.net' ), $result['ns'] );
	}

	public function test_no_parent_servers_is_unknown(): void {
		$this->zones = array();
		$this->assertSame( array( 'unknown', 'no_parent_server' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
	}

	public function test_parents_that_disagree_are_unknown(): void {
		$this->refer( 'example.com', array( 'ns1.dnshost.net' ) );
		$this->net[ self::P2 . '|example.com|2' ] = array( 'authority' => $this->ns_rrs( 'example.com', array( 'ns9.evil.net' ) ) );
		$this->assertSame( array( 'unknown', 'parent_disagrees' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
	}

	public function test_nxdomain_is_not_delegated(): void {
		foreach ( array( self::P1, self::P2 ) as $ip ) { $this->net[ "$ip|example.com|2" ] = array( 'rcode' => 3, 'aa' => true ); }
		$this->assertSame( array( 'support', 'not_delegated' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
	}

	public function test_two_parents_must_both_answer_but_a_single_parent_may_stand_alone(): void {
		$this->refer( 'example.com', array( 'ns1.dnshost.net' ) );
		$this->hosts['ns1.dnshost.net'] = array( self::N1 );
		unset( $this->net[ self::P1 . '|example.com|2' ] );
		$this->assertSame( array( 'unknown', 'parent_unreachable' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
		unset( $this->net[ self::P2 . '|example.com|2' ] );
		$this->assertSame( array( 'unknown', 'parent_unreachable' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
		// A parent zone with one server (with a public address) is enough on its own.
		$this->refer( 'example.com', array( 'ns1.dnshost.net' ) );
		$this->zones['com'] = array( 'a.parent.test' );
		$this->assertSame( 'ok', ProofResolver::delegation( 'example.com' )['status'] );
		unset( $this->net[ self::P1 . '|example.com|2' ] );
		$this->assertSame( array( 'unknown', 'parent_unreachable' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
	}

	public function test_our_address_is_matched_in_any_ipv6_spelling_and_beyond_the_eighth_address(): void {
		add_filter( 'beltoft_services_dns_own_ips', static fn() => array( '2606:4700:0000:0000:0000:0000:0000:6810' ) );
		$this->refer( 'example.com', array( 'ns1.example.com' ), array( 'ns1.example.com' => '2606:4700::6810' ) );
		$this->assertSame( array( 'support', 'delegated_to_us' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
		// The reverse: ours canonical, the resolved address in upper case and uncompressed.
		remove_all_filters( 'beltoft_services_dns_own_ips' );
		add_filter( 'beltoft_services_dns_own_ips', static fn() => array( '2606:4700::6810' ) );
		$this->hosts['ns.other.net'] = array( '2606:4700:0:0:0:0:0:6810'  );
		$this->refer( 'example.com', array( 'ns.other.net' ) );
		$this->assertSame( array( 'support', 'delegated_to_us' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
		// Nine decoys before ours do not hide it, and the stored list stays capped.
		$this->hosts['ns.other.net'] = array_merge( array_map( static fn( $i ) => "8.8.8.$i", range( 1, 9 ) ), array( '2606:4700::6810' ) );
		$this->assertSame( array( 'support', 'delegated_to_us' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
		$this->assertCount( 8, ProofResolver::delegation( 'example.com' )['ips']['ns.other.net'] );
		// The same through glue.
		$this->net[ self::P1 . '|example.com|2' ]['additional'] = array_merge( $this->glue( array( 'ns1.example.com' => '8.8.8.1' ) ), $this->glue( array( 'ns1.example.com' => '2606:4700::6810' ) ) );
		$this->net[ self::P1 . '|example.com|2' ]['authority'] = $this->ns_rrs( 'example.com', array( 'ns1.example.com' ) );
		$this->net[ self::P2 . '|example.com|2' ] = $this->net[ self::P1 . '|example.com|2' ];
		$this->assertSame( array( 'support', 'delegated_to_us' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
	}

	public function test_a_parent_that_is_authoritative_answers_in_the_answer_section(): void {
		foreach ( array( self::P1, self::P2 ) as $ip ) { $this->net[ "$ip|example.com|2" ] = array( 'aa' => true, 'answer' => $this->ns_rrs( 'example.com', array( 'ns1.dnshost.net' ) ) ); }
		$this->hosts['ns1.dnshost.net'] = array( self::N1 );
		$this->assertSame( array( 'ns1.dnshost.net' ), ProofResolver::delegation( 'example.com' )['ns'] );
	}

	public function test_more_than_ten_name_servers_is_unsupported(): void {
		$this->refer( 'example.com', array_map( static fn( $i ) => "ns$i.dnshost.net", range( 1, 11 ) ) );
		$this->assertSame( array( 'support', 'too_many_ns' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
	}

	public function test_a_name_server_of_ours_by_name_or_by_address_goes_to_support(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.ours.test,ns2.ours.test' ) );
		$this->hosts['ns1.ours.test'] = array( '8.8.4.4' );
		$this->refer( 'example.com', array( 'ns1.dnshost.net', 'ns2.ours.test' ) );
		$this->hosts['ns1.dnshost.net'] = array( self::N1 );
		$result = ProofResolver::delegation( 'example.com' );
		$this->assertSame( array( 'support', 'delegated_to_us' ), $this->pair( $result ) );
		$this->assertTrue( $result['ours'] );

		// Not by name: an unrelated name that resolves to one of our addresses.
		$this->refer( 'example.org', array( 'ns.innocent.net' ) );
		foreach ( array( self::P1, self::P2 ) as $ip ) { $this->net[ "$ip|example.org|2" ] = array( 'authority' => $this->ns_rrs( 'example.org', array( 'ns.innocent.net' ) ) ); }
		$this->zones['org'] = array( 'a.parent.test' );
		$this->hosts['ns.innocent.net'] = array( '8.8.4.4' );
		DnsAddress::flush();
		$by_ip = ProofResolver::delegation( 'example.org' );
		$this->assertSame( array( 'support', 'delegated_to_us' ), $this->pair( $by_ip ) );
		$this->assertTrue( $by_ip['ours'] );

		// Or an address the site lists itself.
		add_filter( 'beltoft_services_dns_own_ips', static fn() => array( self::N1 ) );
		$this->settings( array( 'domain_nameservers' => '' ) );
		DnsAddress::flush();
		$this->assertSame( array( 'support', 'delegated_to_us' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
	}

	public function test_ds_absent_and_ds_unknown(): void {
		$this->refer( 'example.com', array( 'ns1.dnshost.net' ) );
		$this->hosts['ns1.dnshost.net'] = array( self::N1 );
		$this->assertFalse( ProofResolver::delegation( 'example.com' )['ds'] );
		unset( $this->net[ self::P1 . '|example.com|43' ], $this->net[ self::P2 . '|example.com|43' ] );
		$this->assertNull( ProofResolver::delegation( 'example.com' )['ds'] );
	}

	public function test_a_spent_budget_stops_with_unknown(): void {
		$this->refer( 'example.com', array( 'ns1.dnshost.net' ) );
		add_filter( 'beltoft_services_dns_budget', static fn() => 0.0 );
		$this->assertSame( array( 'unknown', 'budget' ), $this->pair( ProofResolver::delegation( 'example.com' ) ) );
		$this->assertSame( array(), $this->asked );
		$check = ProofResolver::check_txt( 'example.com', 'v', $this->delegation_for( array( 'ns1.example.com' => array( self::N1 ) ) ) );
		$this->assertSame( array( 'unknown', 'budget' ), array( $check['status'], $check['reason'] ) );
		$this->assertSame( array( 'ns1.example.com' => 'timeout' ), $check['servers'] );
	}

	private function pair( array $result ): array { return array( $result['status'], $result['reason'] ); }

	// check_txt().

	public function test_txt_found_on_every_server_and_split_strings_are_joined(): void {
		$name = '_beltoft-verify.example.com';
		$this->net[ self::N1 . "|$name|16" ] = $this->txt( $name, array( 'proof-1' ) );
		$this->net[ self::N2 . "|$name|16" ] = $this->txt( $name, array( 'proof', '-1' ) );
		$result = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ), 'ns2.x.net' => array( self::N2 ) ) ) );
		$this->assertSame( 'found', $result['status'] );
		$this->assertSame( array( 'ns1.x.net' => 'found', 'ns2.x.net' => 'found' ), $result['servers'] );
		$this->assertSame( array( self::N1 . "|$name|16", self::N2 . "|$name|16" ), $this->asked );
	}

	public function test_txt_found_on_one_and_missing_on_another_is_missing(): void {
		$name = '_beltoft-verify.example.com';
		$this->net[ self::N1 . "|$name|16" ] = $this->txt( $name, array( 'proof-1' ) );
		$this->net[ self::N2 . "|$name|16" ] = array( 'aa' => true );
		$result = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ), 'ns2.x.net' => array( self::N2 ) ) ) );
		$this->assertSame( array( 'missing', 'not_found' ), array( $result['status'], $result['reason'] ) );
		$this->assertSame( 'missing', $result['servers']['ns2.x.net'] );
	}

	public function test_txt_with_another_value_is_wrong_and_nxdomain_is_missing(): void {
		$name = '_beltoft-verify.example.com';
		$this->net[ self::N1 . "|$name|16" ] = $this->txt( $name, array( 'proof-2' ) );
		$this->net[ self::N2 . "|$name|16" ] = array( 'aa' => true, 'rcode' => 3 );
		$result = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ), 'ns2.x.net' => array( self::N2 ) ) ) );
		$this->assertSame( 'missing', $result['status'] );
		$this->assertSame( array( 'ns1.x.net' => 'wrong', 'ns2.x.net' => 'missing' ), $result['servers'] );
		$only = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ) ) ) );
		$this->assertSame( array( 'missing', 'wrong_value' ), array( $only['status'], $only['reason'] ) );
	}

	public function test_a_cname_at_the_name_is_reported(): void {
		$name = '_beltoft-verify.example.com';
		$this->net[ self::N1 . "|$name|16" ] = $this->txt( $name, array( 'proof-1' ) );
		$this->net[ self::N2 . "|$name|16" ] = array( 'aa' => true, 'answer' => array( array( 'name' => $name, 'type' => DnsWire::CNAME, 'data' => 'elsewhere.example.net' ) ) );
		$result = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ), 'ns2.x.net' => array( self::N2 ) ) ) );
		$this->assertSame( array( 'missing', 'cname' ), array( $result['status'], $result['reason'] ) );
		$this->assertSame( 'cname', $result['servers']['ns2.x.net'] );
	}

	public function test_a_server_that_times_out_makes_it_unknown(): void {
		$name = '_beltoft-verify.example.com';
		$this->net[ self::N1 . "|$name|16" ] = $this->txt( $name, array( 'proof-1' ) );
		$result = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ), 'ns2.x.net' => array( self::N2 ) ) ) );
		$this->assertSame( array( 'unknown', 'incomplete' ), array( $result['status'], $result['reason'] ) );
		$this->assertSame( array( 'ns1.x.net' => 'found', 'ns2.x.net' => 'timeout' ), $result['servers'] );
	}

	public function test_a_name_without_address_and_a_non_authoritative_server_are_not_found(): void {
		$name = '_beltoft-verify.example.com';
		$this->net[ self::N1 . "|$name|16" ] = $this->txt( $name, array( 'proof-1' ) );
		$this->net[ self::N2 . "|$name|16" ] = array( 'aa' => false, 'answer' => array( array( 'name' => $name, 'type' => DnsWire::TXT, 'data' => array( 'proof-1' ) ) ) );
		$result = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ), 'ns2.x.net' => array( self::N2 ), 'ns3.x.net' => array() ) ) );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( array( 'ns1.x.net' => 'found', 'ns2.x.net' => 'error', 'ns3.x.net' => 'no_address' ), $result['servers'] );
	}

	public function test_the_next_address_is_tried_after_a_timeout(): void {
		$name = '_beltoft-verify.example.com';
		$this->net[ self::N2 . "|$name|16" ] = $this->txt( $name, array( 'proof-1' ) );
		$result = ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1, self::N2 ) ) ) );
		$this->assertSame( 'found', $result['status'] );
		$this->assertSame( array( self::N1 . "|$name|16", self::N2 . "|$name|16" ), $this->asked );
	}

	public function test_only_a_txt_question_is_ever_asked(): void {
		ProofResolver::check_txt( 'example.com', 'proof-1', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ) ) ) );
		$this->assertSame( array( self::N1 . '|_beltoft-verify.example.com|16' ), $this->asked );
	}

	public function test_a_delegation_that_is_not_ok_is_passed_through(): void {
		$result = ProofResolver::check_txt( 'example.com', 'v', array( 'status' => 'support', 'reason' => 'delegated_to_us' ) );
		$this->assertSame( array( 'support', 'delegated_to_us', array() ), array( $result['status'], $result['reason'], $result['servers'] ) );
		$this->assertSame( array(), $this->asked );
	}

	public function test_no_name_servers_is_unknown(): void {
		$this->assertSame( 'unknown', ProofResolver::check_txt( 'example.com', 'v', $this->delegation_for( array() ) )['status'] );
	}

	// scan().

	private function scan_fixture(): void {
		$answers = array(
			'example.com|1' => array( array( 'example.com', DnsWire::A, '93.184.216.34' ), array( 'example.com', DnsWire::A, '93.184.216.35' ) ),
			'example.com|28' => array( array( 'example.com', DnsWire::AAAA, '2606:2800:220:1::1' ) ),
			'example.com|15' => array( array( 'example.com', DnsWire::MX, array( 'priority' => 10, 'target' => 'mail.example.com' ) ) ),
			'example.com|16' => array( array( 'example.com', DnsWire::TXT, array( 'v=spf1 include:_spf.example.net ', '~all' ) ), array( 'example.com', DnsWire::TXT, array( 'beltoft-verify=_beltoft-verify abc' ) ), array( 'example.com', DnsWire::TXT, array( str_repeat( 'x', 255 ), 'y' ) ) ),
			'example.com|257' => array( array( 'example.com', DnsWire::CAA, array( 'flag' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org' ) ) ),
			'www.example.com|5' => array(),
			'www.example.com|1' => array( array( 'www.example.com', DnsWire::CNAME, 'example.com' ) ),
			'mail.example.com|1' => array( array( 'mail.example.com', DnsWire::A, '93.184.216.40' ) ),
			'_dmarc.example.com|16' => array( array( '_dmarc.example.com', DnsWire::TXT, array( 'v=DMARC1; p=none' ) ) ),
			'k1._domainkey.example.com|16' => array( array( 'k1._domainkey.example.com', DnsWire::CNAME, 'k1.dkim.example.net' ) ),
			'_sip._tls.example.com|33' => array( array( '_sip._tls.example.com', DnsWire::SRV, array( 'priority' => 1, 'weight' => 5, 'port' => 443, 'target' => 'sip.example.net' ) ) ),
		);
		$this->net[ self::N1 . '|*|*' ] = array( 'aa' => true );
		foreach ( $answers as $key => $rrs ) {
			list( $name, $type ) = explode( '|', $key );
			$this->net[ self::N1 . "|$name|$type" ] = array( 'aa' => true, 'compress' => true, 'answer' => array_map( static fn( $r ) => array( 'name' => $r[0], 'type' => $r[1], 'ttl' => 30, 'data' => $r[2] ), $rrs ) );
		}
	}

	public function test_scan_returns_importable_rows(): void {
		$this->scan_fixture();
		$delegation = $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ) ) );
		$result     = ProofResolver::scan( 'example.com', $delegation );
		$this->assertSame( 'ns1.x.net', $result['server'] );
		$this->assertSame( count( $this->asked ), $result['queries'] );
		$this->assertLessThanOrEqual( ProofResolver::MAX_SCAN_QUERIES, $result['queries'] );
		$rows = $result['records'];
		$find = static fn( $name, $type ) => array_values( array_filter( $rows, static fn( $r ) => $r['name'] === $name && $r['type'] === $type ) );
		$this->assertCount( 2, $find( '@', 'A' ) );
		$this->assertSame( 60, $find( '@', 'A' )[0]['ttl'], 'TTL is clamped to 60' );
		$this->assertSame( array( 'name' => '@', 'type' => 'MX', 'ttl' => 60, 'value' => 'mail.example.com', 'priority' => 10 ), $find( '@', 'MX' )[0] );
		$this->assertSame( array( 'name' => '@', 'type' => 'CAA', 'ttl' => 60, 'value' => 'letsencrypt.org', 'flag' => 0, 'tag' => 'issue' ), $find( '@', 'CAA' )[0] );
		$txt = $find( '@', 'TXT' );
		$this->assertCount( 1, $txt, 'the verify record and an over-long string are left out' );
		$this->assertSame( 'v=spf1 include:_spf.example.net ~all', $txt[0]['value'] );
		$this->assertSame( 'example.com', $find( 'www', 'CNAME' )[0]['value'] );
		$this->assertSame( array(), $find( 'www', 'AAAA' ) );
		$this->assertNotContains( self::N1 . '|www.example.com|28', $this->asked, 'a CNAME stops further types for the name' );
		$this->assertSame( 'v=DMARC1; p=none', $find( '_dmarc', 'TXT' )[0]['value'] );
		$this->assertSame( 'k1.dkim.example.net', $find( 'k1._domainkey', 'CNAME' )[0]['value'] );
		$this->assertSame( array( 'name' => '_sip._tls', 'type' => 'SRV', 'ttl' => 60, 'value' => 'sip.example.net', 'priority' => 1, 'weight' => 5, 'port' => 443 ), $find( '_sip._tls', 'SRV' )[0] );
		$this->assertNotContains( self::N1 . '|_beltoft-verify.example.com|16', $this->asked );
		$this->assertNotWPError( Records::validate( 'example.com', $rows ) );
	}

	public function test_scan_reads_one_responsive_server_and_dedupes(): void {
		$this->scan_fixture();
		$this->net[ self::N1 . '|example.com|1' ]['answer'][] = $this->net[ self::N1 . '|example.com|1' ]['answer'][0];
		$delegation = $this->delegation_for( array( 'ns1.x.net' => array( self::N2 ), 'ns2.x.net' => array( self::N1 ) ) );
		$result     = ProofResolver::scan( 'example.com', $delegation );
		$this->assertSame( 'ns2.x.net', $result['server'] );
		$this->assertCount( 2, array_filter( $result['records'], static fn( $r ) => '@' === $r['name'] && 'A' === $r['type'] ) );
		foreach ( $this->asked as $key ) { $this->assertStringNotContainsString( self::N2 . '|example.com|28', $key ); }
	}

	public function test_scan_with_no_answering_server_and_the_query_cap(): void {
		$empty = ProofResolver::scan( 'example.com', $this->delegation_for( array( 'ns1.x.net' => array( self::N1 ) ) ) );
		$this->assertSame( array( 'records' => array(), 'server' => '', 'queries' => 1 ), $empty );
		$this->asked = array();
		$ips = array_map( static fn( $i ) => '93.184.217.' . $i, range(1, 70) );
		$many = ProofResolver::scan( 'example.com', $this->delegation_for( array( 'ns1.x.net' => $ips ) ) );
		$this->assertSame( ProofResolver::MAX_SCAN_QUERIES, $many['queries'] );
		$this->assertSame( ProofResolver::MAX_SCAN_QUERIES, count( $this->asked ) );
		$this->assertSame( array(), ProofResolver::scan( 'example.com', array( 'status' => 'support', 'reason' => 'x' ) )['records'] );
	}
}
