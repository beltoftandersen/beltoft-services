<?php
/** Safe DNS writes: one RRset at a time, the disabled flag kept, one writer per zone. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Locks;
use BeltoftServices\Tests\TestCase;

class RecordsSafeWritesTest extends TestCase {
	private FakeDns $dns;

	public function set_up(): void {
		parent::set_up();
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		$this->dns->create_zone( 'example.com', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->dns->replace_rrsets( 'example.com', array(
			$this->set( 'www.example.com.', 'A', array( '192.0.2.1' ) ),
			$this->set( 'example.com.', 'TXT', array( '"v=spf1 -all"' ) ),
			$this->set( 'example.com.', 'NS', array( 'ns1.example.net.' ) ),
			$this->set( '_tls.example.com.', 'TLSA', array( '3 1 1 abcd' ) ),
		) );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( 'beltoft_services_zone_lock_wait' );
		parent::tear_down();
	}

	private function set( string $name, string $type, array $contents, bool $disabled = false, int $ttl = 3600 ): array {
		return array(
			'name'       => $name,
			'type'       => $type,
			'ttl'        => $ttl,
			'changetype' => 'REPLACE',
			'records'    => array_map( static fn( $c ) => array( 'content' => $c, 'disabled' => $disabled ), $contents ),
		);
	}

	/** The writes made since the zone was set up, as lists of sets. */
	private function writes(): array {
		$calls = array_values( array_filter( $this->dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] ) );
		return array_map( static fn( $c ) => $c[2], array_slice( $calls, 1 ) );
	}

	private function rrset( string $name, string $type ): ?array {
		foreach ( $this->dns->zone( 'example.com' )['rrsets'] as $set ) {
			if ( $set['name'] === $name && $set['type'] === $type ) {
				return $set;
			}
		}
		return null;
	}

	private function rows_for_editor(): array {
		return array(
			array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.1' ),
			array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=spf1 -all' ),
		);
	}

	/** Holds the zone lock on a second connection. Close the returned connection to release it. */
	private function hold_zone( string $zone ): \mysqli {
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( Locks::zone( $zone ) ) . "', 0)" );
		return $other;
	}

	public function test_a_save_keeps_a_paused_zones_records_switched_off_and_writes_only_the_edited_set(): void {
		$this->assertTrue( Records::set_disabled( 'example.com', true ) );
		$this->assertTrue( $this->rrset( 'www.example.com.', 'A' )['records'][0]['disabled'] );
		$before_writes = count( $this->writes() );

		$rows           = $this->rows_for_editor();
		$rows[1]['value'] = 'v=spf1 include:example.net -all';
		$this->assertTrue( Records::replace( 'example.com', $rows ) );

		$writes = array_slice( $this->writes(), $before_writes );
		$this->assertCount( 1, $writes, 'one write' );
		$this->assertCount( 1, $writes[0], 'with one RRset' );
		$this->assertSame( 'TXT', $writes[0][0]['type'] );
		$this->assertTrue( $this->rrset( '_tls.example.com.', 'TLSA' ) !== null );
		foreach ( array( array( 'www.example.com.', 'A' ), array( 'example.com.', 'TXT' ) ) as $key ) {
			$this->assertTrue( $this->rrset( $key[0], $key[1] )['records'][0]['disabled'], implode( ' ', $key ) . ' stays off' );
		}
		$this->assertStringContainsString( 'include:example.net', $this->rrset( 'example.com.', 'TXT' )['records'][0]['content'] );
	}

	public function test_an_unchanged_list_writes_nothing_and_a_removed_set_is_one_delete(): void {
		$this->assertTrue( Records::replace( 'example.com', $this->rows_for_editor() ) );
		$this->assertSame( array(), $this->writes(), 'nothing differs, nothing is written' );

		$this->assertTrue( Records::replace( 'example.com', array( $this->rows_for_editor()[1] ) ) );
		$writes = $this->writes();
		$this->assertCount( 1, $writes );
		$this->assertSame( array( array( 'name' => 'www.example.com.', 'type' => 'A', 'changetype' => 'DELETE' ) ), $writes[0] );
		$this->assertNull( $this->rrset( 'www.example.com.', 'A' ) );
		$this->assertNotNull( $this->rrset( '_tls.example.com.', 'TLSA' ), 'a type the editor does not know is never touched' );
		$this->assertNotNull( $this->rrset( 'example.com.', 'NS' ) );
	}

	public function test_change_writes_one_set_and_leaves_the_others_as_they_were(): void {
		$others = array( $this->rrset( 'example.com.', 'TXT' ), $this->rrset( '_tls.example.com.', 'TLSA' ) );
		$this->assertTrue( Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.9', 'ttl' => 300 ) ) ) );

		$writes = $this->writes();
		$this->assertCount( 1, $writes );
		$this->assertCount( 1, $writes[0] );
		$this->assertSame( 'www.example.com.', $writes[0][0]['name'] );
		$this->assertSame( 'REPLACE', $writes[0][0]['changetype'] );
		$this->assertSame( 300, $this->rrset( 'www.example.com.', 'A' )['ttl'] );
		$this->assertSame( $others, array( $this->rrset( 'example.com.', 'TXT' ), $this->rrset( '_tls.example.com.', 'TLSA' ) ) );
	}

	public function test_change_refuses_a_paused_zone_and_works_again_after_resume(): void {
		Records::set_disabled( 'example.com', true );
		$before = count( $this->writes() );
		$error  = Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.9' ) ) );
		$this->assertWPError( $error );
		$this->assertSame( 'records_off', $error->get_error_code() );
		$this->assertCount( $before, $this->writes() );

		Records::set_disabled( 'example.com', false );
		$this->assertTrue( Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.9' ) ) ) );
		$this->assertFalse( $this->rrset( 'www.example.com.', 'A' )['records'][0]['disabled'] );
	}

	public function test_a_record_kept_in_a_changed_set_keeps_its_disabled_flag(): void {
		$this->dns->replace_rrsets( 'example.com', array( array( 'name' => 'www.example.com.', 'type' => 'A', 'ttl' => 3600, 'changetype' => 'REPLACE', 'records' => array(
			array( 'content' => '192.0.2.1', 'disabled' => true ),
			array( 'content' => '192.0.2.2', 'disabled' => false ),
		) ) ) );
		$this->assertTrue( Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.1' ), array( 'value' => '192.0.2.2' ), array( 'value' => '192.0.2.3' ) ) ) );
		$flags = array_column( $this->rrset( 'www.example.com.', 'A' )['records'], 'disabled', 'content' );
		$this->assertSame( array( '192.0.2.1' => true, '192.0.2.2' => false, '192.0.2.3' => false ), $flags );
	}

	public function test_deleting_a_set_is_one_delete_and_a_missing_set_writes_nothing(): void {
		$this->assertTrue( Records::delete_set( 'example.com', 'www', 'A' ) );
		$this->assertSame( array( array( array( 'name' => 'www.example.com.', 'type' => 'A', 'changetype' => 'DELETE' ) ) ), $this->writes() );
		$this->assertNull( $this->rrset( 'www.example.com.', 'A' ) );
		$this->assertNotNull( $this->rrset( 'example.com.', 'TXT' ) );
		$this->assertTrue( Records::delete_set( 'example.com', 'nothing', 'A' ) );
		$this->assertCount( 1, $this->writes() );
	}

	public function test_a_stale_view_is_refused_without_a_write(): void {
		$seen  = array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.1' ) );
		$error = Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.5' ) ), array( array( 'ttl' => 3600, 'value' => '198.51.100.7' ) ) );
		$this->assertSame( 'stale', $error->get_error_code() );
		$this->assertSame( array(), $this->writes() );
		$this->assertTrue( Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.5' ) ), $seen ) );
	}

	public function test_a_cname_conflicts_with_what_the_live_zone_has_at_the_name(): void {
		$this->assertSame( 'cname_conflict', Records::change( 'example.com', 'www', 'CNAME', array( array( 'value' => 'target.example.net' ) ) )->get_error_code() );
		$this->assertTrue( Records::delete_set( 'example.com', 'www', 'A' ) );
		$this->assertTrue( Records::change( 'example.com', 'www', 'CNAME', array( array( 'value' => 'target.example.net' ) ) ) );
		$this->assertSame( 'cname_conflict', Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.1' ) ) )->get_error_code() );
		$this->assertSame( 'cname_apex', Records::change( 'example.com', '@', 'CNAME', array( array( 'value' => 'target.example.net' ) ) )->get_error_code() );
	}

	public function test_the_200_record_limit_counts_the_whole_zone(): void {
		$sets = array();
		for ( $i = 0; $i < 199; $i++ ) {
			$sets[] = $this->set( 'h' . $i . '.example.com.', 'A', array( '192.0.2.1' ) );
		}
		$this->dns->replace_rrsets( 'example.com', $sets );
		$this->assertSame( 'too_many_records', Records::change( 'example.com', 'new', 'A', array( array( 'value' => '192.0.2.8' ), array( 'value' => '192.0.2.9' ) ) )->get_error_code() );
	}

	public function test_a_busy_zone_is_refused_for_every_writer_and_nothing_is_written(): void {
		$other = $this->hold_zone( 'example.com' );
		foreach ( array(
			fn() => Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.9' ) ) ),
			fn() => Records::delete_set( 'example.com', 'www', 'A' ),
			fn() => Records::replace( 'example.com', array() ),
			fn() => Records::set_disabled( 'example.com', true ),
		) as $call ) {
			$result = $call();
			$this->assertWPError( $result );
			$this->assertSame( 'zone_busy', $result->get_error_code() );
		}
		$this->assertSame( array(), $this->writes() );
		$other->close();
		$this->assertTrue( Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.9' ) ) ), 'free again once the holder is gone' );
	}

	public function test_the_lock_is_released_when_a_write_throws(): void {
		try {
			Records::locked( 'example.com', static function () {
				throw new \LogicException( 'boom' );
			} );
			$this->fail( 'the exception must reach the caller' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertTrue( Records::set_disabled( 'example.com', false ) );
	}

	public function test_the_zone_lock_name_is_short_case_insensitive_and_scoped(): void {
		$name = Locks::zone( 'Example.com' );
		$this->assertSame( $name, Locks::zone( 'example.com.' ) );
		$this->assertLessThanOrEqual( 64, strlen( $name ) );
		$this->assertNotSame( $name, Locks::zone( 'example.org' ) );
		$this->assertNotSame( Locks::domain( 'example.com' ), $name );
	}

	public function test_wildcard_names_are_allowed_for_five_types_only(): void {
		foreach ( array(
			array( '*', 'A', '192.0.2.1' ),
			array( '*.shop', 'A', '192.0.2.1' ),
			array( '*', 'AAAA', '2001:db8::1' ),
			array( '*', 'CNAME', 'target.example.net' ),
			array( '*.x', 'TXT', 'hello' ),
			array( '*', 'MX', 'mail.example.net', 10 ),
		) as $case ) {
			$set = Records::validate( 'example.com', array( array( 'name' => $case[0], 'type' => $case[1], 'value' => $case[2], 'priority' => $case[3] ?? null ) ) );
			$this->assertIsArray( $set, $case[0] . ' ' . $case[1] );
			$this->assertStringStartsWith( '*.', $set[0]['name'] );
		}
		$this->assertSame( '*.example.com.', Records::validate( 'example.com', array( array( 'name' => '*', 'type' => 'A', 'value' => '192.0.2.1' ) ) )[0]['name'] );
		foreach ( array(
			array( '*', 'SRV' ), array( '*', 'CAA' ), array( 'a.*', 'A' ), array( 'w*', 'A' ), array( '**', 'A' ), array( '*.*', 'A' ), array( '*', 'NS' ), array( '*', 'TLSA' ), array( '*', 'PTR' ),
		) as $case ) {
			$this->assertWPError( Records::validate( 'example.com', array( array( 'name' => $case[0], 'type' => $case[1], 'value' => '192.0.2.1', 'priority' => 1, 'weight' => 1, 'port' => 1, 'flag' => 0, 'tag' => 'issue' ) ) ), $case[0] . ' ' . $case[1] );
		}
		$this->assertWPError( Records::validate( 'example.com', array( array( 'name' => '*', 'type' => 'CNAME', 'value' => 'a.example.net' ), array( 'name' => '*', 'type' => 'A', 'value' => '192.0.2.1' ) ) ) );
		$this->assertTrue( Records::change( 'example.com', '*', 'A', array( array( 'value' => '192.0.2.4' ) ) ) );
		$this->assertNotNull( $this->rrset( '*.example.com.', 'A' ) );
	}

	public function test_editor_rows_carry_the_disabled_flag(): void {
		Records::set_disabled( 'example.com', true );
		$rows = Records::to_rows( 'example.com', $this->dns->zone( 'example.com' )['rrsets'] );
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertTrue( $row['disabled'] );
		}
	}
}
