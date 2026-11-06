<?php
/** Where the DNS of a transferred domain lives. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Crypto;
use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\TransferDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class TransferDnsTest extends TestCase {
	/** The name servers the domain delegates to now, and whether the resolver reports ours. */
	private array $current = array( 'ns1.old-host.example', 'ns2.old-host.example' );
	private bool $moved = false;
	private int $scans = 0;
	private int $lookups = 0;
	private array $found_rows = array();

	protected function setUp(): void {
		parent::setUp();
		$this->current = array( 'ns1.old-host.example', 'ns2.old-host.example' );
		$this->moved = false;
		$this->scans = 0;
		$this->lookups = 0;
		$this->found_rows = array(
			array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ),
			array( 'name' => '@', 'type' => 'MX', 'ttl' => 300, 'value' => 'mail.old-host.example', 'priority' => 10 ),
			array( 'name' => 'www', 'type' => 'CNAME', 'ttl' => 300, 'value' => 'example-site.test' ),
		);
		// Nothing reaches the network: the resolver, the parent lookup, the scan and the registrar lookup are all answered here.
		add_filter( 'beltoft_services_domains_ns_answer', fn() => $this->moved ? array( 'ns1.example.net', 'ns2.example.net' ) : $this->current );
		add_filter( 'beltoft_services_transfer_registrar', static fn() => false );
		add_filter(
			'beltoft_services_dns_proof_result',
			function ( $pre, $call, $args ) {
				if ( 'delegation' === $call ) {
					++$this->lookups;
					$ns = $this->moved ? array( 'ns1.example.net', 'ns2.example.net' ) : $this->current;
					return array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => $ns, 'ips' => array(), 'ds' => false, 'ours' => $this->moved );
				}
				if ( 'scan' === $call ) {
					++$this->scans;
					return array( 'records' => $this->found_rows, 'server' => 'ns1.old-host.example', 'queries' => 3 );
				}
				return $pre;
			},
			10,
			3
		);
		update_option( FakeClient::OPTION, array() );
	}

	private function prices(): void {
		$table = get_option( Prices::OPTION, array() );
		$table = is_array( $table ) ? $table : array();
		foreach ( array( 'com', 'dk', 'nl' ) as $tld ) {
			$table[ $tld ] = ( $table[ $tld ] ?? array() ) + array( 'create' => 10.0, 'renew' => 10.0, 'transfer' => 9.0, 'restricted' => false, 'price' => 16 );
		}
		update_option( Prices::OPTION, $table );
	}

	private function resource( string $choice, ?string $domain = null, float $total = 50.0 ): Resource {
		$this->prices();
		$domain = $domain ?? 'move-' . strtolower( wp_generate_password( 8, false, false ) ) . '.com';
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'admin_email' => 'ops@example.com' ) );
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 't', 32 ) ) );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Domain', 'quantity' => 1, 'subtotal' => $total, 'total' => $total ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$meta = array( 'domain' => $domain, 'mode' => 'transfer', 'order_item_id' => (int) $line->get_id(), 'auth' => Crypto::encrypt( 'transfer-secret' ) );
		if ( '' !== $choice ) { $meta['transfer_dns'] = $choice; }
		$r = Resource::create( $license, new DomainDriver(), array(), $meta );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	/** A registrar that accepts the transfer at once; the replies of a transfer that is done in one run. */
	private function registrar( string $status = 'ACT' ): FakeClient {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains/transfer', array( 'id' => 123, 'status' => $status, 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => $status, 'expiration_date' => '2027-10-26 00:00:00' ) );
		return $fake;
	}

	private function finish_replies( FakeClient $fake ): void {
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2028-10-26 00:00:00' ) );
		for ( $i = 0; $i < 4; ++$i ) { $fake->reply( 'PUT', '/domains/123', array( 'success' => true ) ); }
	}

	private function wire( FakeClient $fake ): FakeDns {
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		return $dns;
	}

	private function posts( FakeClient $fake ): array {
		return array_values( array_filter( $fake->calls(), static fn( $c ) => 'POST' === $c['method'] && '/domains/transfer' === $c['path'] ) );
	}

	private function types( array $zone ): array {
		$out = array();
		foreach ( (array) ( $zone['rrsets'] ?? array() ) as $set ) { $out[] = $set['type'] . ':' . $set['name']; }
		return $out;
	}

	public function test_first_copies_the_dns_then_waits_and_sends_nothing_until_the_domain_uses_our_name_servers(): void {
		$fake = $this->registrar();
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry(), $result->error() );
		$this->assertGreaterThan( 0, $result->wait_until(), 'A bounded wait.' );
		$this->assertSame( 300, $result->delay() );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'dns_wait_since' ) );
		$this->assertSame( 0, count( $this->posts( $fake ) ), 'Nothing is sent to the registrar.' );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( 'ns1.old-host.example', 'ns2.old-host.example' ), $fresh->get( 'transfer_ns' ) );
		$this->assertTrue( (bool) $fresh->get( 'zone_claimed' ), 'The zone is ours for this domain.' );
		$this->assertContains( 'dns_copy', (array) $fresh->get( 'steps' ) );
		$this->assertNotContains( 'ns_ours', (array) $fresh->get( 'steps' ) );
		$types = $this->types( (array) $dns->zone( $name ) );
		$this->assertContains( 'A:' . $name . '.', $types );
		$this->assertContains( 'MX:' . $name . '.', $types );
		$this->assertContains( 'CNAME:www.' . $name . '.', $types );
		$this->assertSame( '', (string) $fresh->get( 'import_error' ) );
		// Still waiting on the next look.
		$this->assertTrue( ( new DomainDriver() )->provision( Resource::find( $r->id() ) )->is_retry() );
		$this->assertSame( 0, count( $this->posts( $fake ) ) );
		$this->assertSame( 1, $this->scans, 'The records are read once.' );
	}

	public function test_first_sends_the_transfer_with_our_name_servers_once_the_domain_is_delegated_to_us(): void {
		$fake = $this->registrar();
		$this->finish_replies( $fake );
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->moved = true;
		$result = ( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$post = $this->posts( $fake );
		$this->assertCount( 1, $post );
		$this->assertSame( array( array( 'name' => 'ns1.example.net' ), array( 'name' => 'ns2.example.net' ) ), $post[0]['body']['name_servers'] );
		$fresh = Resource::find( $r->id() );
		$this->assertEmpty( $fresh->get( 'dns_external' ) );
		$this->assertContains( 'ns_ours', (array) $fresh->get( 'steps' ) );
		$this->assertNotNull( $dns->zone( $name ), 'The zone stays.' );
		$this->assertNotEmpty( array_filter( $dns->calls(), static fn( $c ) => 'secure' === $c[0] ), 'DNSSEC is enabled once the domain is ours.' );
	}

	public function test_first_waits_until_our_zone_answers_on_every_name_server(): void {
		$fake = $this->registrar();
		$this->wire( $fake );
		$r = $this->resource( 'first' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->moved = true;
		add_filter( 'beltoft_services_dns_query', static fn() => new \WP_Error( 'dns_missing', 'not yet' ) );
		$result = ( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 0, count( $this->posts( $fake ) ), 'The zone does not answer yet.' );
	}

	public function test_first_gives_up_after_fourteen_days_forgets_the_code_and_removes_the_zone_it_made(): void {
		$fake = $this->registrar();
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->assertNotNull( $dns->zone( $name ) );
		$this->assertTrue( Resource::find( $r->id() )->set( array( 'dns_wait_since' => time() - 15 * DAY_IN_SECONDS ) ) );
		$result = ( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( '14 days', $result->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'auth' ), 'The code is forgotten.' );
		$this->assertNull( $dns->zone( $name ), 'Our zone is removed.' );
		$this->assertFalse( (bool) $fresh->get( 'zone_claimed' ) );
		$this->assertSame( 0, count( $this->posts( $fake ) ) );
	}

	public function test_a_failure_keeps_the_zone_when_the_domain_already_points_at_it(): void {
		$fake = $this->registrar();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first', null, 1.0 );
		$name = (string) $r->get( 'domain' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->moved = true;
		// Paid below the transfer cost: the money guard refuses after the wait, and the zone must stay up.
		$result = ( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_failed(), $result->error() );
		$this->assertSame( 0, count( $this->posts( $fake ) ), 'The money guard still applies.' );
		$this->assertNotNull( $dns->zone( $name ), 'A zone the domain points at is never removed.' );
	}

	public function test_a_zone_we_did_not_make_is_never_removed_on_failure(): void {
		$fake = $this->registrar();
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first', null, 1.0 );
		$name = (string) $r->get( 'domain' );
		// A zone of the same customer's DNS service is not ours to remove: a transfer of it is the unchanged path.
		$user = (int) $r->license()['user_id'];
		$claim = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, (int) $claim['id'], $name ) );
		$dns_license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user ) );
		$old = Resource::create( $dns_license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertTrue( DnsAssignments::activate( $name, $user, (int) $dns_license['id'], $old->id() ) );
		$dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed(), 'Paid below the cost.' );
		$this->assertNotNull( $dns->zone( $name ), 'The DNS service zone stays.' );
	}

	public function test_after_sends_the_current_name_servers_at_once_and_switches_to_ours_when_the_transfer_is_done(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains/transfer', array( 'id' => 123, 'status' => 'REQ', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'REQ' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$this->finish_replies( $fake );
		$dns = $this->wire( $fake );
		$r = $this->resource( 'after' );
		$name = (string) $r->get( 'domain' );
		$first = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $first->is_retry(), 'The registry is still processing: ' . $first->error() );
		$post = $this->posts( $fake );
		$this->assertCount( 1, $post );
		$this->assertSame( array( array( 'name' => 'ns1.old-host.example' ), array( 'name' => 'ns2.old-host.example' ) ), $post[0]['body']['name_servers'], 'The current name servers.' );
		$this->assertContains( 'A:' . $name . '.', $this->types( (array) $dns->zone( $name ) ), 'The records were copied before the transfer.' );
		$this->assertSame( array(), array_filter( $fake->calls(), static fn( $c ) => 'PUT' === $c['method'] && isset( $c['body']['name_servers'] ) ), 'Not switched yet.' );
		$done = ( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $done->is_done(), $done->error() );
		$puts = array_values( array_filter( $fake->calls(), static fn( $c ) => 'PUT' === $c['method'] && isset( $c['body']['name_servers'] ) ) );
		$this->assertCount( 1, $puts );
		$this->assertSame( array( array( 'name' => 'ns1.example.net' ), array( 'name' => 'ns2.example.net' ) ), $puts[0]['body']['name_servers'] );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ours', $fresh->get( 'ns_mode' ) );
		$this->assertEmpty( $fresh->get( 'dns_external' ) );
		$this->assertContains( 'dns_switch', (array) $fresh->get( 'steps' ) );
		$this->assertCount( 1, $this->posts( $fake ), 'The transfer is sent once.' );
	}

	public function test_keep_is_the_old_behaviour_and_makes_no_zone(): void {
		foreach ( array( 'keep', '' ) as $choice ) {
			$fake = $this->registrar();
			$this->finish_replies( $fake );
			$dns = $this->wire( $fake );
			$r = $this->resource( $choice );
			$name = (string) $r->get( 'domain' );
			$result = ( new DomainDriver() )->provision( $r );
			$this->assertTrue( $result->is_done(), $result->error() );
			$post = $this->posts( $fake );
			$this->assertSame( array( array( 'name' => 'ns1.old-host.example' ), array( 'name' => 'ns2.old-host.example' ) ), $post[0]['body']['name_servers'] );
			$this->assertNull( $dns->zone( $name ) );
			$this->assertSame( array(), $dns->calls() );
			$this->assertTrue( (bool) Resource::find( $r->id() )->get( 'dns_external' ) );
		}
		$this->assertSame( 0, $this->scans, 'No records are read.' );
	}

	public function test_a_domain_our_dns_service_serves_has_no_choice_and_is_unchanged(): void {
		$fake = $this->registrar();
		$this->finish_replies( $fake );
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$user = (int) $r->license()['user_id'];
		$claim = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, (int) $claim['id'], $name ) );
		$dns_license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user ) );
		$old = Resource::create( $dns_license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertTrue( DnsAssignments::activate( $name, $user, (int) $dns_license['id'], $old->id() ) );
		$dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->assertSame( 'served', TransferDns::plan( $r ) );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 0, $this->lookups, 'No lookup of the current name servers.' );
		$this->assertSame( 0, $this->scans );
		$post = $this->posts( $fake );
		$this->assertSame( array( array( 'name' => 'ns1.example.net' ), array( 'name' => 'ns2.example.net' ) ), $post[0]['body']['name_servers'] );
	}

	public function test_a_crash_resume_does_not_read_or_copy_twice(): void {
		$fake = $this->registrar();
		$this->wire( $fake );
		$r = $this->resource( 'first' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$lookups = $this->lookups;
		$scans = $this->scans;
		$this->assertTrue( ( new DomainDriver() )->provision( Resource::find( $r->id() ) )->is_retry() );
		$this->assertSame( $scans, $this->scans );
		// The only lookups of the second run are the wait's own delegation checks.
		$this->assertLessThanOrEqual( $lookups + 1, $this->lookups );
	}

	public function test_records_that_cannot_be_added_are_left_out_and_said(): void {
		$fake = $this->registrar();
		$dns = $this->wire( $fake );
		$this->found_rows[] = array( 'name' => 'bad', 'type' => 'MX', 'ttl' => 300, 'value' => 'not a host name!', 'priority' => 10 );
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$fresh = Resource::find( $r->id() );
		$this->assertNotSame( '', (string) $fresh->get( 'import_error' ) );
		$this->assertContains( 'A:' . $name . '.', $this->types( (array) $dns->zone( $name ) ) );
		$this->assertNotContains( 'MX:bad.' . $name . '.', $this->types( (array) $dns->zone( $name ) ) );
	}

	public function test_nothing_read_from_the_current_servers_is_said_and_the_zone_is_empty(): void {
		$fake = $this->registrar();
		$this->wire( $fake );
		$this->found_rows = array();
		$r = $this->resource( 'first' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->assertStringContainsString( 'no records', (string) Resource::find( $r->id() )->get( 'import_error' ) );
	}

	public function test_a_zone_of_another_customer_waits_for_staff(): void {
		$fake = $this->registrar();
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$other = self::factory()->user->create();
		$claim = DnsClaims::create( $other, $name );
		$this->assertTrue( DnsAssignments::reserve( $other, (int) $claim['id'], $name ) );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertNull( $dns->zone( $name ), 'No zone is made over another customer\'s name.' );
		$this->assertSame( 0, count( $this->posts( $fake ) ) );
	}

	public function test_a_cancelled_transfer_keeps_a_zone_that_the_name_servers_point_at(): void {
		$fake = $this->registrar();
		$dns = $this->wire( $fake );
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->moved = true;
		$this->assertTrue( ( new DomainDriver() )->delete( Resource::find( $r->id() ) )->is_done() );
		$this->assertNotNull( $dns->zone( $name ) );
		$this->moved = false;
		$this->assertTrue( ( new DomainDriver() )->delete( Resource::find( $r->id() ) )->is_done() );
		$this->assertNull( $dns->zone( $name ), 'Not delegated to us: the zone made for the transfer goes.' );
	}

	public function test_the_heuristic_flags_a_registrars_own_name_servers(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->assertTrue( TransferDns::at_risk( array( 'ns1.domaincontrol.com', 'ns2.domaincontrol.com' ), 'example.com' ) );
		$this->assertTrue( TransferDns::at_risk( array( 'ns01.one.com' ), 'example.dk' ) );
		$this->assertFalse( TransferDns::at_risk( array( 'ada.ns.cloudflare.com', 'bob.ns.cloudflare.com' ), 'example.com' ) );
		$this->assertFalse( TransferDns::at_risk( array( 'ns1.some-web-host.example', 'ns2.some-web-host.example' ), 'example.com' ) );
		$this->assertFalse( TransferDns::at_risk( array(), 'example.com' ) );
	}

	public function test_the_heuristic_matches_the_registrar_that_rdap_names(): void {
		add_filter( 'beltoft_services_transfer_registrar', static fn() => array( 'name' => 'Acme Registrar Ltd', 'url' => 'https://www.acmeregistrar.com/about' ), 20 );
		$this->assertTrue( TransferDns::at_risk( array( 'dns1.acmeregistrar.com' ), 'example.com' ) );
		$this->assertFalse( TransferDns::at_risk( array( 'dns1.other-host.com' ), 'example.com' ) );
	}

	public function test_the_list_and_the_verdict_are_filterable(): void {
		add_filter( 'beltoft_services_transfer_dns_registrar_domains', static fn( $list ) => array_merge( $list, array( 'custom-registrar.com' ) ) );
		$this->assertTrue( TransferDns::at_risk( array( 'ns1.custom-registrar.com' ), 'example.com' ) );
		add_filter( 'beltoft_services_transfer_dns_at_risk', static fn() => false );
		$this->assertFalse( TransferDns::at_risk( array( 'ns1.domaincontrol.com' ), 'example.com' ) );
	}

	public function test_rdap_registrar_is_read_from_the_entity(): void {
		$found = TransferDns::registrar_from_rdap( array( 'entities' => array(
			array( 'roles' => array( 'registrant' ), 'vcardArray' => array( 'vcard', array( array( 'fn', array(), 'text', 'Someone' ) ) ) ),
			array( 'roles' => array( 'registrar' ), 'vcardArray' => array( 'vcard', array( array( 'fn', array(), 'text', 'GoDaddy.com, LLC' ), array( 'url', array(), 'uri', 'https://www.godaddy.com' ) ) ) ),
		) ) );
		$this->assertSame( array( 'name' => 'GoDaddy.com, LLC', 'url' => 'https://www.godaddy.com' ), $found );
		$this->assertNull( TransferDns::registrar_from_rdap( array() ) );
	}

	public function test_the_recommendation_follows_the_current_name_servers(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->current = array( 'ns1.domaincontrol.com', 'ns2.domaincontrol.com' );
		$risk = TransferDns::recommend( 'example.com' );
		$this->assertSame( 'first', $risk['choice'] );
		$this->assertTrue( $risk['at_risk'] );
		$this->assertSame( array( 'ns1.domaincontrol.com', 'ns2.domaincontrol.com' ), $risk['hosts'] );
		$this->current = array( 'ada.ns.cloudflare.com', 'bob.ns.cloudflare.com' );
		$safe = TransferDns::recommend( 'example.com' );
		$this->assertSame( 'keep', $safe['choice'] );
		$this->assertFalse( $safe['at_risk'] );
		// Nothing can be read: the safe choice.
		remove_all_filters( 'beltoft_services_dns_proof_result' );
		add_filter( 'beltoft_services_dns_proof_result', static fn( $pre, $call ) => 'delegation' === $call ? array( 'status' => 'unknown', 'reason' => 'parent_unreachable', 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => false ) : $pre, 10, 2 );
		$unknown = TransferDns::recommend( 'example.com' );
		$this->assertSame( 'first', $unknown['choice'] );
		$this->assertNull( $unknown['at_risk'] );
	}

	public function test_no_choice_is_shown_when_our_dns_serves_the_domain_for_the_customer(): void {
		$r = $this->resource( 'first' );
		$name = (string) $r->get( 'domain' );
		$user = (int) $r->license()['user_id'];
		$claim = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, (int) $claim['id'], $name ) );
		$dns_license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user ) );
		$old = Resource::create( $dns_license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertTrue( DnsAssignments::activate( $name, $user, (int) $dns_license['id'], $old->id() ) );
		$this->assertTrue( TransferDns::recommend( $name, $user )['served'] );
		$this->assertFalse( TransferDns::recommend( $name, $user + 1 )['served'] );
	}

	public function test_the_choice_travels_with_the_cart_line_and_to_the_resource(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'x', 32 ) ) );
		foreach ( array( 'first', 'after', 'keep' ) as $choice ) {
			$data = Cart::line_data( 'test.com', 'transfer', 'auth-secret', $choice );
			$this->assertSame( $choice, $data['beltoft_services_domain']['dns'] );
		}
		$this->assertArrayNotHasKey( 'dns', Cart::line_data( 'test.com', 'transfer', 'auth-secret' )['beltoft_services_domain'], 'No choice, an older line.' );
		$bad = Cart::line_data( 'test.com', 'transfer', 'auth-secret', 'sideways' );
		$this->assertWPError( $bad );
		$this->assertSame( 'bad_dns_choice', $bad->get_error_code() );
		$this->assertArrayNotHasKey( 'dns', Cart::line_data( 'test.com', 'register', '', 'first' )['beltoft_services_domain'], 'Only a transfer has a choice.' );
	}

	public function test_the_choice_reaches_the_resource_from_the_order_line(): void {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-choice' ) );
		$user = self::factory()->user->create();
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Domain', 'quantity' => 1, 'total' => 20 ) );
		$line->add_meta_data( '_beltoft_services_domain', array( 'domain' => 'carried.com', 'mode' => 'transfer', 'dns' => 'after' ), true );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$meta = \BeltoftServices\Domains\Meta::initial_meta( array(), $license, new DomainDriver() );
		$this->assertSame( 'after', $meta['transfer_dns'] );
		$this->assertSame( 'transfer', $meta['mode'] );
	}

	public function test_check_now_is_rate_limited_and_brings_the_order_forward_when_the_domain_moved(): void {
		$fake = $this->registrar();
		$this->wire( $fake );
		$r = $this->resource( 'first' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'other', TransferDns::check_now( $r ) );
		$limited = TransferDns::check_now( Resource::find( $r->id() ) );
		$this->assertWPError( $limited );
		$this->assertSame( 'rate_limited', $limited->get_error_code() );
		$this->moved = true;
		$this->assertTrue( Resource::find( $r->id() )->set( array( 'transfer_dns_checked' => time() - 120 ) ) );
		$this->assertSame( 'ours', TransferDns::check_now( Resource::find( $r->id() ) ) );
		$this->assertLessThanOrEqual( time(), (int) Resource::find( $r->id() )->get( 'next_attempt_at' ) );
	}

	public function test_the_view_says_when_the_zone_is_not_in_use_yet(): void {
		$fake = $this->registrar();
		$this->wire( $fake );
		$r = $this->resource( 'first' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$view = TransferDns::view( Resource::find( $r->id() ) );
		$this->assertTrue( $view['made'] );
		$this->assertFalse( $view['in_use'] );
		$this->assertTrue( $view['waiting'] );
		$this->assertSame( array( 'ns1.old-host.example', 'ns2.old-host.example' ), $view['current'] );
		$this->assertNull( TransferDns::view( $this->resource( 'keep' ) ) );
	}

	public function test_the_search_form_offers_the_three_choices_and_the_dnssec_help(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.js' );
		$this->assertStringContainsString( 'Turn off DNSSEC at your current registrar first.', $js );
		$this->assertStringContainsString( 'Move my DNS to Beltoft first', $js );
		$this->assertStringContainsString( 'Move my DNS to Beltoft when the transfer is done', $js );
		$this->assertStringContainsString( 'Keep my DNS where it is', $js );
		$this->assertStringNotContainsString( "\u{2014}", $js );
		$this->assertStringNotContainsString( "\u{00B7}", $js );
	}
}
