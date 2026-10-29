<?php
/** The zone holder's records_off meta decides "paused"; the zone lock covers every writer. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class RecordsHolderTest extends TestCase {
	private FakeDns $dns;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => new \WP_Error( 'dns_missing', 'gone' ) );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( 'beltoft_services_domains_ns_answer' );
		remove_all_filters( 'beltoft_services_zone_lock_wait' );
		parent::tear_down();
	}

	/** An active DNS service holding the zone; the zone exists and is empty. */
	private function holder( string $name, array $meta = array() ): Resource {
		$user    = self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->save();
		$license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$claim   = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, $claim['id'], $name ) );
		$r = Resource::create( $license, new DnsDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		DnsAssignments::bind_order( $name, $user, $order->get_id(), 1 );
		$this->assertTrue( DnsAssignments::activate( $name, $user, (int) $license['id'], $r->id() ) );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'zone_claimed' => true ) ) );
		$this->assertTrue( $this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) ) );
		return Resource::find( $r->id() );
	}

	private function put( string $zone, string $name, string $type, array $contents, bool $disabled = false, int $ttl = 3600 ): void {
		$this->dns->replace_rrsets( $zone, array( array(
			'name' => $name, 'type' => $type, 'ttl' => $ttl, 'changetype' => 'REPLACE',
			'records' => array_map( static fn( $c ) => array( 'content' => $c, 'disabled' => $disabled ), $contents ),
		) ) );
	}

	private function writes(): array {
		return array_values( array_filter( $this->dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] ) );
	}

	private function rrset( string $zone, string $name, string $type ): ?array {
		foreach ( $this->dns->zone( $zone )['rrsets'] as $set ) {
			if ( $set['name'] === $name && $set['type'] === $type ) { return $set; }
		}
		return null;
	}

	private function hold_zone( string $zone ): \mysqli {
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( Locks::zone( $zone ) ) . "', 0)" );
		return $other;
	}

	public function test_a_paused_service_with_an_empty_zone_refuses_change_and_replace(): void {
		$r = $this->holder( 'empty.example' );
		$r->set( array( 'records_off' => true ) );
		$before = count( $this->writes() );

		$change = Records::change( 'empty.example', 'www', 'A', array( array( 'value' => '192.0.2.9' ) ) );
		$this->assertWPError( $change );
		$this->assertSame( 'records_off', $change->get_error_code() );
		$replace = Records::replace( 'empty.example', array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.9' ) ) );
		$this->assertWPError( $replace );
		$this->assertSame( 'records_off', $replace->get_error_code() );
		$this->assertCount( $before, $this->writes(), 'nothing live was written' );

		$r->set( array( 'records_off' => false ) );
		$this->assertTrue( Records::change( 'empty.example', 'www', 'A', array( array( 'value' => '192.0.2.9' ) ) ) );
		$this->assertFalse( $this->rrset( 'empty.example', 'www.empty.example.', 'A' )['records'][0]['disabled'] );
	}

	public function test_a_paused_service_refuses_a_ttl_only_change(): void {
		$r = $this->holder( 'ttl.example' );
		$this->put( 'ttl.example', 'www.ttl.example.', 'A', array( '192.0.2.1' ), true );
		$r->set( array( 'records_off' => true ) );
		$error = Records::change( 'ttl.example', 'www', 'A', array( array( 'value' => '192.0.2.1', 'ttl' => 300 ) ) );
		$this->assertSame( 'records_off', $error->get_error_code() );
		$this->assertSame( 3600, $this->rrset( 'ttl.example', 'www.ttl.example.', 'A' )['ttl'] );
	}

	public function test_an_active_service_whose_records_are_all_disabled_is_not_refused(): void {
		$this->holder( 'adopt.example' );
		$this->put( 'adopt.example', 'www.adopt.example.', 'A', array( '192.0.2.1' ), true );
		$this->assertTrue( Records::change( 'adopt.example', 'www', 'A', array( array( 'value' => '192.0.2.1' ), array( 'value' => '192.0.2.2' ) ) ) );
		$flags = array_column( $this->rrset( 'adopt.example', 'www.adopt.example.', 'A' )['records'], 'disabled', 'content' );
		$this->assertSame( array( '192.0.2.1' => true, '192.0.2.2' => false ), $flags, 'the old flag is kept, the new record is live' );
		$this->assertTrue( Records::replace( 'adopt.example', array( array( 'name' => 'new', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.3' ) ) ) );
	}

	public function test_replace_adding_a_record_to_a_paused_zone_with_no_holder_writes_it_disabled(): void {
		$this->dns->create_zone( 'nohold.example', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->put( 'nohold.example', 'www.nohold.example.', 'A', array( '192.0.2.1' ), true );
		$this->assertTrue( Records::replace( 'nohold.example', array(
			array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.1' ),
			array( 'name' => 'new', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.5' ),
		) ) );
		$this->assertTrue( $this->rrset( 'nohold.example', 'new.nohold.example.', 'A' )['records'][0]['disabled'] );
		$this->assertTrue( $this->rrset( 'nohold.example', 'www.nohold.example.', 'A' )['records'][0]['disabled'] );
	}

	public function test_replace_has_its_own_guard_even_when_called_directly(): void {
		$r = $this->holder( 'direct.example' );
		$this->put( 'direct.example', 'www.direct.example.', 'A', array( '192.0.2.1' ), true );
		$r->set( array( 'records_off' => true ) );
		$this->assertSame( 'records_off', Records::replace( 'direct.example', array() )->get_error_code() );
		$this->assertNotNull( $this->rrset( 'direct.example', 'www.direct.example.', 'A' ) );
	}

	public function test_spelling_differences_in_content_are_not_changes(): void {
		$this->dns->create_zone( 'norm.example', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->put( 'norm.example', 'v6.norm.example.', 'AAAA', array( '2001:db8:0:0:0:0:0:1' ), true );
		$this->put( 'norm.example', 'norm.example.', 'TXT', array( '"v=spf1 " "-all"' ), true );
		$this->put( 'norm.example', 'c.norm.example.', 'CNAME', array( 'Target.Example.Net' ), true );
		$before = count( $this->writes() );
		$rows = array(
			array( 'name' => 'v6', 'type' => 'AAAA', 'ttl' => 3600, 'value' => '2001:db8::1' ),
			array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=spf1 -all' ),
			array( 'name' => 'c', 'type' => 'CNAME', 'ttl' => 3600, 'value' => 'target.example.net' ),
		);
		$this->assertTrue( Records::replace( 'norm.example', $rows ) );
		$this->assertCount( $before, $this->writes(), 'same records, nothing written' );
		$this->assertTrue( $this->rrset( 'norm.example', 'v6.norm.example.', 'AAAA' )['records'][0]['disabled'], 'the flag survives' );

		// A real change in the same set still writes, and the other records keep their flag.
		$rows[1]['value'] = 'v=spf1 ~all';
		$this->assertTrue( Records::replace( 'norm.example', $rows ) );
		$this->assertCount( $before + 1, $this->writes() );
	}

	public function test_zone_create_secure_and_delete_wait_for_a_held_zone_lock(): void {
		$r = $this->holder( 'locked.example', array( 'mode' => 'dns' ) );
		$other = $this->hold_zone( 'locked.example' );
		$driver = new DnsDriver();
		$before = count( $this->dns->calls() );

		$result = $driver->delete( $r );
		$this->assertTrue( $result->is_retry(), 'a held zone makes delete try again later' );
		$this->assertNotNull( $this->dns->zone( 'locked.example' ), 'the zone was not deleted' );
		$this->assertSame( array(), array_filter( array_slice( $this->dns->calls(), $before ), static fn( $c ) => 'delete_zone' === $c[0] ) );

		$this->dns->delete_zone( 'locked.example' );
		$made = count( array_filter( $this->dns->calls(), static fn( $c ) => 'create_zone' === $c[0] ) );
		$this->assertTrue( \BeltoftServices\Domains\Zone::ensure( $r )->is_retry(), 'creating the zone waits for the lock' );
		$this->assertCount( $made, array_filter( $this->dns->calls(), static fn( $c ) => 'create_zone' === $c[0] ) );
		$this->dns->create_zone( 'locked.example', array( 'ns1.example.net', 'ns2.example.net' ) );

		$other->close();
		$this->assertTrue( $driver->delete( Resource::find( $r->id() ) )->is_done() );
		$this->assertNull( $this->dns->zone( 'locked.example' ) );
	}

	public function test_dns_pause_and_resume_hold_the_zone_lock(): void {
		$r = $this->holder( 'pausel.example' );
		$this->put( 'pausel.example', 'www.pausel.example.', 'A', array( '192.0.2.1' ) );
		$driver = new DnsDriver();
		$other = $this->hold_zone( 'pausel.example' );
		$before = count( $this->writes() );

		$this->assertTrue( $driver->pause( $r )->is_retry(), 'pause waits for the lock' );
		$this->assertCount( $before, $this->writes() );
		$other->close();
		$this->assertTrue( $driver->pause( $r )->is_done() );
		$this->assertTrue( $this->rrset( 'pausel.example', 'www.pausel.example.', 'A' )['records'][0]['disabled'] );

		$other = $this->hold_zone( 'pausel.example' );
		$this->assertTrue( $driver->resume( $r )->is_retry(), 'resume waits for the lock' );
		$this->assertTrue( $this->rrset( 'pausel.example', 'www.pausel.example.', 'A' )['records'][0]['disabled'] );
		$other->close();
		$this->assertTrue( $driver->resume( $r )->is_done() );
		$this->assertFalse( $this->rrset( 'pausel.example', 'www.pausel.example.', 'A' )['records'][0]['disabled'] );
	}
}
