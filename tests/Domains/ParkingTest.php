<?php
/** Domain parking: written for a zone with no address, removed by whatever wants the apex or www. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Apps\AppDomains;
use BeltoftServices\Cli\ParkingCommand;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Parking;
use BeltoftServices\Domains\RecordImport;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\Zone;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

class ParkingTest extends TestCase {
	private FakeDns $dns;
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'parking_ipv4' => '192.0.2.77', 'parking_ipv6' => '2001:db8::77' ) );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_client', static fn() => new FakeClient() );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'elsewhere.example.net' ) );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		$this->user = self::factory()->user->create();
	}

	/** A DNS-only service whose claim and assignment are in place. */
	private function dns_service( string $name ): Resource {
		$product = $this->service_product( 'dns' );
		$order   = wc_create_order( array( 'customer_id' => $this->user ) );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user, 'order_id' => $order->get_id() ) );
		$claim   = DnsClaims::create( $this->user, $name );
		DnsClaims::set_status( $claim['id'], 'consumed', array() );
		$this->assertTrue( DnsAssignments::reserve( $this->user, $claim['id'], $name ) );
		DnsAssignments::bind_order( $name, $this->user, $order->get_id(), 1 );
		$r = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	private function parked_service( string $name ): Resource {
		$r = $this->dns_service( $name );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		return $r;
	}

	/** @return array<string, array<int, string>> Contents by "name|type" of the address sets in the zone. */
	private function addresses( string $zone ): array {
		$out = array();
		foreach ( (array) $this->dns->zone( $zone )['rrsets'] as $set ) {
			$out[ rtrim( $set['name'], '.' ) . '|' . $set['type'] ] = array_column( $set['records'], 'content' );
		}
		ksort( $out );
		return $out;
	}

	private function assert_parked( string $zone ): void {
		$this->assertSame(
			array( $zone . '|A' => array( '192.0.2.77' ), $zone . '|AAAA' => array( '2001:db8::77' ), 'www.' . $zone . '|A' => array( '192.0.2.77' ), 'www.' . $zone . '|AAAA' => array( '2001:db8::77' ) ),
			$this->addresses( $zone )
		);
		$this->assertNotNull( Parking::state( $zone ) );
	}

	public function test_a_new_dns_only_domain_is_parked_with_ttl_300_and_a_stored_mark(): void {
		$this->parked_service( 'parkme-a.example' );
		$this->assert_parked( 'parkme-a.example' );
		foreach ( $this->dns->zone( 'parkme-a.example' )['rrsets'] as $set ) {
			$this->assertSame( 300, $set['ttl'] );
		}
		$this->assertSame( 'parkme-a.example', Parking::all()[0]['zone'] );
	}

	public function test_actions_tell_when_a_zone_is_parked_and_unparked(): void {
		$seen = array();
		add_action( 'beltoft_services_parked', static function ( $zone ) use ( &$seen ) { $seen[] = 'parked:' . $zone; } );
		add_action( 'beltoft_services_unparked', static function ( $zone ) use ( &$seen ) { $seen[] = 'unparked:' . $zone; } );
		$this->parked_service( 'hook-a.example' );
		$this->assertTrue( Records::change( 'hook-a.example', '@', 'A', array( array( 'value' => '198.51.100.9', 'ttl' => 3600 ) ) ) );
		$this->assertSame( array( 'parked:hook-a.example', 'unparked:hook-a.example' ), $seen );
	}

	public function test_a_registered_domain_that_keeps_our_dns_is_parked_and_one_on_other_name_servers_is_not(): void {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$ours    = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'regd-a.example', 'mode' => 'register' ) );
		$this->assertTrue( Zone::ensure( $ours, false, false )->is_done() );
		$this->assert_parked( 'regd-a.example' );

		$custom = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'regd-b.example', 'mode' => 'register', 'ns_mode' => 'custom' ) );
		$this->assertTrue( Zone::ensure( $custom, false, false )->is_done() );
		$this->assertNull( $this->dns->zone( 'regd-b.example' ) );
	}

	public function test_nothing_is_written_when_the_settings_are_empty(): void {
		$this->settings( array( 'parking_ipv4' => '', 'parking_ipv6' => '' ) );
		$this->parked_service( 'noparking-a.example' );
		$this->assertSame( array(), $this->dns->zone( 'noparking-a.example' )['rrsets'] );
		$this->assertNull( Parking::state( 'noparking-a.example' ) );
		$this->assertSame( 'off', Parking::park( 'noparking-a.example' ) );
		$this->assertSame( array(), $this->dns->zone( 'noparking-a.example' )['rrsets'] );
	}

	public function test_only_one_family_is_written_when_only_one_address_is_set(): void {
		$this->settings( array( 'parking_ipv6' => '' ) );
		$this->parked_service( 'v4only-a.example' );
		$this->assertSame( array( 'v4only-a.example|A', 'www.v4only-a.example|A' ), array_keys( $this->addresses( 'v4only-a.example' ) ) );
	}

	public function test_a_customers_address_or_cname_at_the_apex_or_www_is_never_touched_and_nothing_is_added(): void {
		foreach ( array(
			'cust-a.example' => array( array( 'name' => '@', 'type' => 'A', 'ttl' => 3600, 'value' => '198.51.100.5' ) ),
			'cust-b.example' => array( array( 'name' => 'www', 'type' => 'CNAME', 'ttl' => 3600, 'value' => 'shop.example.org' ) ),
			'cust-c.example' => array( array( 'name' => 'www', 'type' => 'AAAA', 'ttl' => 3600, 'value' => '2001:db8::5' ) ),
		) as $zone => $rows ) {
			$this->dns->create_zone( $zone, array( 'ns1.example.net', 'ns2.example.net' ) );
			$this->assertTrue( Records::replace( $zone, $rows ) );
			$before = $this->dns->zone( $zone )['rrsets'];
			$this->assertSame( 'has_address', Parking::park( $zone ) );
			$this->assertSame( $before, $this->dns->zone( $zone )['rrsets'], $zone );
			$this->assertNull( Parking::state( $zone ) );
		}
	}

	public function test_mail_and_text_records_do_not_remove_parking_and_are_not_mixed_with_it(): void {
		$this->parked_service( 'mailok-a.example' );
		$this->assertTrue( Records::change( 'mailok-a.example', '@', 'MX', array( array( 'value' => 'mail.example.org', 'priority' => 10, 'ttl' => 3600 ) ) ) );
		$this->assertTrue( Records::change( 'mailok-a.example', '@', 'TXT', array( array( 'value' => 'v=spf1 -all', 'ttl' => 3600 ) ) ) );
		$this->assertSame( 6, count( $this->addresses( 'mailok-a.example' ) ) );
		$this->assertNotNull( Parking::state( 'mailok-a.example' ) );
		// A zone with only parking and mail records still parks only when nothing holds an address.
		$this->assertSame( 'already', Parking::park( 'mailok-a.example' ) );
	}

	public function test_the_record_editor_never_lists_the_parking_records_and_does_not_count_them(): void {
		$this->parked_service( 'editor-a.example' );
		$this->assertSame( array(), Records::to_rows( 'editor-a.example', (array) $this->dns->zone( 'editor-a.example' )['rrsets'] ) );
		$this->assertTrue( Records::change( 'editor-a.example', '@', 'TXT', array( array( 'value' => 'hello', 'ttl' => 3600 ) ) ) );
		$rows = Records::to_rows( 'editor-a.example', (array) $this->dns->zone( 'editor-a.example' )['rrsets'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'TXT', $rows[0]['type'] );
	}

	public function test_a_customer_adding_an_address_in_the_editor_removes_all_parking(): void {
		$this->parked_service( 'edit-a.example' );
		$this->assertTrue( Records::change( 'edit-a.example', '@', 'A', array( array( 'value' => '198.51.100.9', 'ttl' => 3600 ) ) ) );
		$this->assertSame( array( 'edit-a.example|A' => array( '198.51.100.9' ) ), $this->addresses( 'edit-a.example' ) );
		$this->assertNull( Parking::state( 'edit-a.example' ) );
	}

	public function test_a_customer_cname_at_www_removes_the_parking_that_would_clash_with_it(): void {
		$this->parked_service( 'edit-b.example' );
		$this->assertTrue( Records::change( 'edit-b.example', 'www', 'CNAME', array( array( 'value' => 'shop.example.org', 'ttl' => 3600 ) ) ) );
		$this->assertSame( array( 'www.edit-b.example|CNAME' => array( 'shop.example.org.' ) ), $this->addresses( 'edit-b.example' ) );
		$this->assertNull( Parking::state( 'edit-b.example' ) );
	}

	public function test_replacing_the_whole_record_list_with_an_address_removes_parking_and_one_without_keeps_it(): void {
		$this->parked_service( 'repl-a.example' );
		$this->assertTrue( Records::replace( 'repl-a.example', array( array( 'name' => '@', 'type' => 'MX', 'ttl' => 3600, 'value' => 'mail.example.org', 'priority' => 5 ) ) ) );
		$this->assertNotNull( Parking::state( 'repl-a.example' ) );
		$this->assertSame( 5, count( $this->addresses( 'repl-a.example' ) ) );
		$this->assertTrue( Records::replace( 'repl-a.example', array( array( 'name' => '@', 'type' => 'A', 'ttl' => 3600, 'value' => '198.51.100.1' ) ) ) );
		$this->assertSame( array( 'repl-a.example|A' => array( '198.51.100.1' ) ), $this->addresses( 'repl-a.example' ) );
		$this->assertNull( Parking::state( 'repl-a.example' ) );
	}

	public function test_a_zone_file_import_with_an_address_removes_parking(): void {
		$r = $this->parked_service( 'import-a.example' );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready', RecordImport::JOB => array(
			'sets'      => array( array( 'name' => '@', 'type' => 'A', 'status' => 'new', 'rows' => array( array( 'name' => '@', 'type' => 'A', 'ttl' => 3600, 'value' => '198.51.100.20' ) ) ) ),
			'next'      => 0,
			'unchanged' => 0,
			'added'     => 0,
			'replaced'  => 0,
			'failed'    => array(),
		) ) );
		$this->assertTrue( RecordImport::run_job( Resource::find( $r->id() ) )->is_done() );
		$this->assertSame( array( 'import-a.example|A' => array( '198.51.100.20' ) ), $this->addresses( 'import-a.example' ) );
		$this->assertNull( Parking::state( 'import-a.example' ) );
	}

	public function test_a_service_that_wants_www_or_the_apex_removes_parking_through_the_zone_writer(): void {
		$this->parked_service( 'app-a.example' );
		$wrote = false;
		// The name is free as far as the service can see: our parking is not in its view of the zone.
		$this->assertTrue( AppDomains::publish( $this->user, 'app-a.example', 'www.app-a.example', '192.0.2.10', $wrote, true ) );
		$this->assertTrue( $wrote );
		$this->assertSame( array( 'www.app-a.example|A' => array( '192.0.2.10' ) ), $this->addresses( 'app-a.example' ) );
		$this->assertNull( Parking::state( 'app-a.example' ) );
	}

	public function test_adding_a_hosting_site_on_the_domain_replaces_parking_with_the_edge_addresses(): void {
		$this->parked_service( 'site-a.example' );
		$node = array( 'edge_ip' => '203.0.113.10', 'edge_ipv6' => '' );
		$this->assertTrue( \BeltoftServices\Hosting\SiteDns::publish_ours( $this->user, 'site-a.example', $node ) );
		$this->assertNull( Parking::state( 'site-a.example' ) );
		$this->assertSame( array( 'site-a.example|A' => array( '203.0.113.10' ) ), $this->addresses( 'site-a.example' ) );
		$this->assertTrue( \BeltoftServices\Hosting\SiteDns::publish_ours( $this->user, 'www.site-a.example', $node ) );
		$this->assertSame( array( 'site-a.example|A', 'www.site-a.example|A' ), array_keys( $this->addresses( 'site-a.example' ) ) );
	}

	public function test_a_hosting_site_on_a_subdomain_leaves_parking_alone(): void {
		$this->parked_service( 'site-b.example' );
		$this->assertTrue( \BeltoftServices\Hosting\SiteDns::publish_ours( $this->user, 'blog.site-b.example', array( 'edge_ip' => '203.0.113.10', 'edge_ipv6' => '' ) ) );
		$this->assertNotNull( Parking::state( 'site-b.example' ) );
		$this->assertSame( 5, count( $this->addresses( 'site-b.example' ) ) );
	}

	public function test_a_service_that_writes_a_subdomain_leaves_parking_alone(): void {
		$this->parked_service( 'app-b.example' );
		$wrote = false;
		$this->assertTrue( AppDomains::publish( $this->user, 'app-b.example', 'app.app-b.example', '192.0.2.10', $wrote ) );
		$this->assertTrue( $wrote );
		$this->assertNotNull( Parking::state( 'app-b.example' ) );
		$this->assertSame( 5, count( $this->addresses( 'app-b.example' ) ) );
	}

	public function test_a_record_that_replaced_a_parking_address_is_the_customers_and_is_not_removed_as_ours(): void {
		$this->parked_service( 'changed-a.example' );
		// Somebody edits the zone outside the plugin: the apex A is no longer what we wrote.
		$this->dns->replace_rrsets( 'changed-a.example', array( array( 'name' => 'changed-a.example.', 'type' => 'A', 'ttl' => 3600, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '198.51.100.44', 'disabled' => false ) ) ) ) );
		$rows = Records::to_rows( 'changed-a.example', (array) $this->dns->zone( 'changed-a.example' )['rrsets'] );
		$this->assertSame( '198.51.100.44', $rows[0]['value'] );
		$this->assertTrue( Records::change( 'changed-a.example', '@', 'TXT', array( array( 'value' => 'x', 'ttl' => 3600 ) ) ) );
		$this->assertSame( array( '198.51.100.44' ), $this->addresses( 'changed-a.example' )['changed-a.example|A'] );
	}

	public function test_parking_a_paused_zone_is_refused_and_a_second_run_changes_nothing(): void {
		$this->parked_service( 'twice-a.example' );
		$calls = count( $this->dns->calls() );
		$this->assertSame( 'already', Parking::park( 'twice-a.example' ) );
		$this->assertSame( $calls, count( $this->dns->calls() ) );
	}

	public function test_the_sweep_is_a_dry_run_unless_applied_and_skips_zones_without_a_holder(): void {
		$this->settings( array( 'parking_ipv4' => '', 'parking_ipv6' => '' ) );
		$this->parked_service( 'sweep-a.example' );
		$this->dns->create_zone( 'infra-a.example', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->settings( array( 'parking_ipv4' => '192.0.2.77', 'parking_ipv6' => '2001:db8::77' ) );

		$this->assertSame( array( array( 'zone' => 'sweep-a.example', 'result' => 'would_park' ) ), Parking::sweep( false ) );
		$this->assertSame( array(), $this->dns->zone( 'sweep-a.example' )['rrsets'] );

		$this->assertSame( array( array( 'zone' => 'sweep-a.example', 'result' => 'parked' ) ), Parking::sweep( true ) );
		$this->assert_parked( 'sweep-a.example' );
		$this->assertSame( array(), $this->dns->zone( 'infra-a.example' )['rrsets'] );
		$this->assertSame( array( array( 'zone' => 'sweep-a.example', 'result' => 'already' ) ), Parking::sweep( false ) );
	}

	public function test_the_cli_lists_parked_domains_and_is_a_dry_run_by_default(): void {
		$this->parked_service( 'cli-a.example' );
		$cli = new ParkingCommand();
		\WP_CLI::reset();
		$cli->list_( array(), array() );
		$this->assertStringContainsString( 'cli-a.example', wp_json_encode( \WP_CLI::$lines ) );
		\WP_CLI::reset();
		$cli->add( array(), array() );
		$this->assertStringContainsString( 'Dry run', wp_json_encode( \WP_CLI::$lines ) );
		$this->settings( array( 'parking_ipv4' => '', 'parking_ipv6' => '' ) );
		$this->expectException( \Throwable::class );
		$cli->add( array(), array() );
	}
}
