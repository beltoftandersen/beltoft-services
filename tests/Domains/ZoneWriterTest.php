<?php
/** The one zone writer: lock, read, paused check and a write of only the RRsets the policy names. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Domains\ZoneWriter;
use BeltoftServices\Locks;
use BeltoftServices\Tests\Mail\MailTestCase;

class ZoneWriterTest extends MailTestCase {
	private int $user;

	public function set_up(): void {
		parent::set_up();
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		$this->user = self::factory()->user->create();
		$this->our_zone(
			$this->user,
			'writer.example',
			array(
				array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.1' ),
				array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=spf1 -all' ),
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_zone_lock_wait' );
		remove_all_filters( 'beltoft_services_domains_dns' );
		parent::tear_down();
	}

	private function row(): array {
		return array( 'name' => 'new', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.9' );
	}

	private function replace_policy(): callable {
		return static function ( array $zone_data, array $sets ) {
			$set                = $sets[0];
			$set['changetype']  = 'REPLACE';
			return array( $set );
		};
	}

	private function reads(): array {
		return array_filter( Clients::dns()->calls(), static fn( $c ) => 'zone' === $c[0] );
	}

	private function writes(): array {
		return array_values( array_filter( Clients::dns()->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] ) );
	}

	public function test_a_zone_that_is_not_the_customers_is_refused_without_a_read(): void {
		$before = count( Clients::dns()->calls() );
		$r      = ZoneWriter::write( $this->user + 1000, 'writer.example', array( $this->row() ), $this->replace_policy() );
		$this->assertSame( array( 'status' => 'refused', 'reason' => 'not_ours', 'wrote' => false ), $r );
		$this->assertCount( $before, Clients::dns()->calls() );
	}

	public function test_an_invalid_row_is_refused_before_the_lock(): void {
		$r = ZoneWriter::write( $this->user, 'writer.example', array( array( 'name' => 'x', 'type' => 'A', 'ttl' => 300, 'value' => 'not-an-ip' ) ), $this->replace_policy() );
		$this->assertSame( 'invalid', $r['reason'] );
		$this->assertSame( 'refused', $r['status'] );
	}

	public function test_a_paused_zone_is_refused_unless_the_caller_skips_the_check(): void {
		$this->assertTrue( Records::set_disabled( 'writer.example', true ) );
		$r = ZoneWriter::write( $this->user, 'writer.example', array( $this->row() ), $this->replace_policy() );
		$this->assertSame( 'paused', $r['reason'] );
		$writes = count( $this->writes() );
		ZoneWriter::write( $this->user, 'writer.example', array( $this->row() ), $this->replace_policy() );
		$this->assertCount( $writes, $this->writes(), 'a paused zone is not written' );
		$r = ZoneWriter::write( $this->user, 'writer.example', array( $this->row() ), $this->replace_policy(), false );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertTrue( $r['wrote'] );
	}

	public function test_the_policy_decides_and_only_its_sets_reach_the_zone(): void {
		$before = Clients::dns()->zone( 'writer.example' )['rrsets'];
		$r      = ZoneWriter::write( $this->user, 'writer.example', array( $this->row() ), $this->replace_policy() );
		$this->assertSame( array( 'status' => 'ok', 'reason' => '', 'wrote' => true ), $r );
		$last = array_slice( $this->writes(), -1 )[0];
		$this->assertCount( 1, $last[2] );
		$this->assertSame( 'REPLACE', $last[2][0]['changetype'] );
		$after = Clients::dns()->zone( 'writer.example' )['rrsets'];
		$this->assertCount( count( $before ) + 1, $after );
		foreach ( $before as $set ) {
			$this->assertContains( $set, $after, 'a record outside the written set is unchanged' );
		}
	}

	public function test_true_from_the_policy_writes_nothing_and_is_ok(): void {
		$writes = count( $this->writes() );
		$r      = ZoneWriter::write( $this->user, 'writer.example', array( $this->row() ), static fn() => true );
		$this->assertSame( array( 'status' => 'ok', 'reason' => '', 'wrote' => false ), $r );
		$this->assertCount( $writes, $this->writes() );
	}

	public function test_a_string_from_the_policy_is_the_refusal(): void {
		$r = ZoneWriter::write( $this->user, 'writer.example', array( $this->row() ), static fn() => 'name_taken' );
		$this->assertSame( array( 'status' => 'refused', 'reason' => 'name_taken', 'wrote' => false ), $r );
	}

	public function test_a_missing_zone_is_refused_as_no_zone(): void {
		$r = ZoneWriter::write( null, 'gone.example', array( $this->row() ), $this->replace_policy() );
		$this->assertSame( 'no_zone', $r['reason'] );
	}

	public function test_a_busy_lock_returns_the_lock_error_unchanged(): void {
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( Locks::zone( 'writer.example' ) ) . "', 0)" );
		$writes = count( $this->writes() );
		$r      = ZoneWriter::write( $this->user, 'writer.example', array( $this->row() ), $this->replace_policy() );
		$this->assertWPError( $r );
		$this->assertSame( 'zone_busy', $r->get_error_code() );
		$this->assertCount( $writes, $this->writes() );
		$other->close();
	}

	public function test_remove_deletes_only_what_the_policy_names(): void {
		$r = ZoneWriter::remove(
			null,
			'writer.example',
			static fn( array $zone ) => array( array( 'name' => 'www.writer.example.', 'type' => 'A', 'changetype' => 'DELETE', 'records' => array() ) )
		);
		$this->assertTrue( $r );
		$names = array_map( static fn( $s ) => $s['name'] . '|' . $s['type'], Clients::dns()->zone( 'writer.example' )['rrsets'] );
		$this->assertNotContains( 'www.writer.example.|A', $names );
		$this->assertContains( 'writer.example.|TXT', $names );
		$this->assertTrue( ZoneWriter::remove( null, 'writer.example', static fn() => array() ) );
		$this->assertTrue( ZoneWriter::remove( null, 'gone.example', static fn() => array( array( 'x' ) ) ), 'a missing zone is nothing to remove' );
	}

	public function test_remove_for_a_user_who_does_not_own_the_zone_is_an_error_and_writes_nothing(): void {
		$before = count( Clients::dns()->calls() );
		$r      = ZoneWriter::remove( $this->user + 1000, 'writer.example', static fn() => array( array( 'name' => 'www.writer.example.', 'type' => 'A', 'changetype' => 'DELETE' ) ) );
		$this->assertWPError( $r );
		$this->assertSame( 'not_ours', $r->get_error_code() );
		$this->assertCount( $before, Clients::dns()->calls() );
	}

	public function test_sets_at_normalises_trailing_dots_case_and_the_apex(): void {
		$rrsets = array(
			array( 'name' => 'Www.Z.example.', 'type' => 'a' ),
			array( 'name' => '@', 'type' => 'TXT' ),
			array( 'name' => '', 'type' => 'MX' ),
			array( 'name' => 'z.example.', 'type' => 'SOA' ),
			array( 'name' => 'z.example.', 'type' => 'NS' ),
			array( 'name' => 'other.z.example.', 'type' => 'A' ),
		);
		$this->assertSame( array( 'A' ), array_keys( Records::sets_at( 'z.example', 'www.z.example', $rrsets ) ) );
		$this->assertSame( array( 'TXT', 'MX' ), array_keys( Records::sets_at( 'z.example', 'z.example', $rrsets ) ) );
		$this->assertSame( 'z.example', Records::fqdn_of_set( 'z.example', '' ) );
		$this->assertSame( 'www.z.example', Records::fqdn_of_set( 'z.example', 'www' ) );
		$this->assertSame( 'www.z.example', Records::fqdn_of_set( 'z.example', 'WWW.z.example.' ) );
	}

	public function test_zone_of_picks_the_longest_matching_zone(): void {
		$this->our_zone( $this->user, 'a.example' );
		$this->our_zone( $this->user, 'b.a.example' );
		$this->assertSame( 'b.a.example', ZoneAccess::zone_of( $this->user, 'x.b.a.example' ) );
		$this->assertSame( 'a.example', ZoneAccess::zone_of( $this->user, 'x.a.example' ) );
		$this->assertSame( 'a.example', ZoneAccess::zone_of( $this->user, 'a.example' ) );
		$this->assertNull( ZoneAccess::zone_of( $this->user, 'other.example' ) );
		$this->assertNull( ZoneAccess::zone_of( $this->user, 'nota.example' ) );
	}
}
