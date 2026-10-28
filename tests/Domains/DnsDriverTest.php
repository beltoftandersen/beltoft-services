<?php
/** DNS hosting ownership and lifecycle tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\Zone;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DnsDriverTest extends TestCase {
	/**
	 * A resource. A DNS one gets a consumed claim and an assignment bound to its order, the way checkout leaves them.
	 *
	 * @param array<string, mixed> $opts draft (records of the claim), claim (false for none), assign (false for no row), assign_user, order_offset.
	 */
	private function resource( string $name, int $user, string $driver = 'dns', array $opts = array() ): Resource {
		$product = $this->service_product( $driver );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$meta = array( 'domain' => $name, 'mode' => $driver );
		if ( 'dns' === $driver && false !== ( $opts['claim'] ?? true ) ) {
			$claim = DnsClaims::create( $user, $name );
			DnsClaims::set_status( $claim['id'], 'consumed', isset( $opts['draft'] ) ? array( 'draft_records' => $opts['draft'] ) : array() );
			$meta['claim_id'] = $claim['id'];
			if ( false !== ( $opts['assign'] ?? true ) ) {
				$holder = (int) ( $opts['assign_user'] ?? $user );
				$this->assertTrue( DnsAssignments::reserve( $holder, $claim['id'], $name ) );
				DnsAssignments::bind_order( $name, $holder, $order->get_id() + (int) ( $opts['order_offset'] ?? 0 ), 1 );
			}
		}
		$r = Resource::create( $license, 'dns' === $driver ? new DnsDriver() : new DomainDriver(), array(), $meta );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	private function fakes(): FakeDns {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'elsewhere.example.net' ) );
		return $dns;
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
		// The name lock refuses a second customer's reservation, so their order has no assignment.
		$second = $this->resource( 'conflict-example.net', self::factory()->user->create(), 'dns', array( 'assign' => false ) );
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

	public function test_no_claim_fails_without_a_zone_and_alerts_once(): void {
		$dns = $this->fakes();
		$mails = array();
		add_filter( 'pre_wp_mail', static function ( $pre, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$r = $this->resource( 'noclaim-example.net', self::factory()->user->create(), 'dns', array( 'claim' => false ) );
		$result = ( new DnsDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'DNS hosting needs a verified domain.', $result->error() );
		$this->assertNull( $dns->zone( 'noclaim-example.net' ) );
		$this->assertSame( array(), $dns->calls() );
		$this->assertCount( 1, $mails );
	}

	public function test_another_users_assignment_fails(): void {
		$dns = $this->fakes();
		$other = self::factory()->user->create();
		$r = $this->resource( 'theirs-example.net', self::factory()->user->create(), 'dns', array( 'assign_user' => $other ) );
		$this->assertSame( 'DNS hosting needs a verified domain.', ( new DnsDriver() )->provision( $r )->error() );
		$this->assertNull( $dns->zone( 'theirs-example.net' ) );
	}

	public function test_reservation_for_another_order_fails(): void {
		$dns = $this->fakes();
		$r = $this->resource( 'otherorder-example.net', self::factory()->user->create(), 'dns', array( 'order_offset' => 1000 ) );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_failed() );
		$this->assertNull( $dns->zone( 'otherorder-example.net' ) );
	}

	public function test_claim_of_another_user_fails(): void {
		$dns = $this->fakes();
		$user = self::factory()->user->create();
		$r = $this->resource( 'foreignclaim-example.net', $user );
		$claim = DnsClaims::create( self::factory()->user->create(), 'foreignclaim-example.net' );
		$r->set( array( 'claim_id' => $claim['id'] ) );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_failed() );
		$this->assertNull( $dns->zone( 'foreignclaim-example.net' ) );
	}

	public function test_success_activates_the_assignment_and_repeats_safely(): void {
		$dns = $this->fakes();
		$user = self::factory()->user->create();
		$r = $this->resource( 'active-example.net', $user );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$row = DnsAssignments::get( 'active-example.net' );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( $r->id(), (int) $row['resource_id'] );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$this->assertCount( 1, array_filter( $dns->calls(), static fn( $call ) => 'create_zone' === $call[0] ) );
	}

	public function test_import_applies_draft_records_once(): void {
		$dns = $this->fakes();
		$draft = array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) );
		$r = $this->resource( 'import-example.net', self::factory()->user->create(), 'dns', array( 'draft' => $draft ) );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$this->assertCount( 1, array_filter( $dns->calls(), static fn( $call ) => 'replace_rrsets' === $call[0] ) );
		$this->assertSame( '192.0.2.10', $dns->zone( 'import-example.net' )['rrsets'][0]['records'][0]['content'] );
		$this->assertContains( 'import', Resource::find( $r->id() )->get( 'steps' ) );
	}

	public function test_invalid_draft_continues_with_an_import_error(): void {
		$dns = $this->fakes();
		$draft = array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => 'not-an-ip' ) );
		$r = $this->resource( 'badimport-example.net', self::factory()->user->create(), 'dns', array( 'draft' => $draft ) );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$this->assertNotEmpty( Resource::find( $r->id() )->get( 'import_error' ) );
		$this->assertSame( array(), array_filter( $dns->calls(), static fn( $call ) => 'replace_rrsets' === $call[0] ) );
	}

	public function test_pause_switches_records_off_and_resume_on(): void {
		$dns = $this->fakes();
		$draft = array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ), array( 'name' => '@', 'type' => 'MX', 'ttl' => 300, 'value' => 'mail.example.org', 'priority' => 10 ) );
		$r = $this->resource( 'pause-example.net', self::factory()->user->create(), 'dns', array( 'draft' => $draft ) );
		$driver = new DnsDriver();
		$this->assertTrue( $driver->provision( $r )->is_done() );
		$off = $driver->pause( $r );
		$this->assertTrue( $off->is_done() );
		$this->assertTrue( $off->meta()['records_off'] );
		foreach ( $dns->zone( 'pause-example.net' )['rrsets'] as $set ) { foreach ( $set['records'] as $record ) { $this->assertTrue( $record['disabled'] ); } }
		$on = $driver->resume( $r );
		$this->assertFalse( $on->meta()['records_off'] );
		foreach ( $dns->zone( 'pause-example.net' )['rrsets'] as $set ) { foreach ( $set['records'] as $record ) { $this->assertFalse( $record['disabled'] ); } }
	}

	public function test_pause_changes_nothing_when_the_same_users_domain_owns_the_zone(): void {
		$dns = $this->fakes();
		$user = self::factory()->user->create();
		$hosting = $this->resource( 'owned-example.net', $user, 'dns', array( 'draft' => array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$driver = new DnsDriver();
		$this->assertTrue( $driver->provision( $hosting )->is_done() );
		$this->resource( 'owned-example.net', $user, 'domain' );
		$before = count( $dns->calls() );
		$result = $driver->pause( $hosting );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( array(), $result->meta() );
		$this->assertCount( $before, $dns->calls() );
	}

	public function test_record_edits_are_refused_while_off(): void {
		$this->fakes();
		$r = $this->resource( 'edit-example.net', self::factory()->user->create() );
		$r->set( array( 'state' => 'ready', 'records_off' => true ) );
		$refusal = \BeltoftServices\Domains\Actions::refusal( null, $r, 'set_records', array( 'records' => '[]' ) );
		$this->assertWPError( $refusal );
		$this->assertSame( 'DNS hosting is paused.', $refusal->get_error_message() );
		$r->set( array( 'records_off' => false ) );
		$this->assertNull( \BeltoftServices\Domains\Actions::refusal( null, $r, 'set_records', array( 'records' => '[]' ) ) );
	}

	public function test_delete_removes_the_zone_and_marks_the_name_release_pending(): void {
		$dns = $this->fakes();
		$op = new FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => new \WP_Error( 'dns_missing', 'gone' ) );
		$r = $this->resource( 'gone-example.net', self::factory()->user->create() );
		$driver = new DnsDriver();
		$this->assertTrue( $driver->provision( $r )->is_done() );
		$r = Resource::find( $r->id() );
		$this->assertTrue( $driver->delete( $r )->is_done() );
		$this->assertNull( $dns->zone( 'gone-example.net' ) );
		$this->assertSame( 'release_pending', DnsAssignments::get( 'gone-example.net' )['status'] );
		$this->assertSame( array(), $op->calls(), 'no registrar call' );
	}

	public function test_delete_releases_nothing_while_the_domain_resource_keeps_the_zone(): void {
		$dns = $this->fakes();
		$user = self::factory()->user->create();
		$hosting = $this->resource( 'keep-example.net', $user );
		$this->assertTrue( ( new DnsDriver() )->provision( $hosting )->is_done() );
		$this->resource( 'keep-example.net', $user, 'domain' );
		$this->assertTrue( ( new DnsDriver() )->delete( Resource::find( $hosting->id() ) )->is_done() );
		$this->assertNotNull( $dns->zone( 'keep-example.net' ) );
		$this->assertSame( 'active', DnsAssignments::get( 'keep-example.net' )['status'] );
	}

	public function test_an_existing_zone_of_the_verified_customer_is_adopted(): void {
		$dns = $this->fakes();
		$r = $this->resource( 'prezone-example.net', self::factory()->user->create() );
		$dns->create_zone( 'prezone-example.net', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$this->assertCount( 1, array_filter( $dns->calls(), static fn( $call ) => 'create_zone' === $call[0] ) );
	}
}
