<?php
/** DNS assignment (one holder per domain) tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Tests\TestCase;

class DnsAssignmentsTest extends TestCase {
	private function set( string $domain, array $data ): void {
		global $wpdb;
		$wpdb->update( DnsSchema::tables()['assignments'], $data, array( 'domain' => $domain ) );
	}

	private function past(): string {
		return gmdate( 'Y-m-d H:i:s', time() - 60 );
	}

	public function test_one_winner_the_second_user_gets_domain_taken(): void {
		$this->assertTrue( DnsAssignments::reserve( 1, 10, 'example.com' ) );
		$second = DnsAssignments::reserve( 2, 20, 'Example.com' );
		$this->assertWPError( $second );
		$this->assertSame( 'domain_taken', $second->get_error_code() );
		$this->assertSame( 'This domain cannot be added here. Contact support.', $second->get_error_message() );
		$this->assertSame( 1, (int) DnsAssignments::get( 'example.com' )['user_id'] );
	}

	public function test_duplicate_key_insert_is_a_refusal_not_an_error(): void {
		global $wpdb;
		$table = DnsSchema::tables()['assignments'];
		$fired = false;
		// The competing row appears after reserve() read "no row" and just before its INSERT.
		$race = function ( $query ) use ( &$race, &$fired, $table, $wpdb ) {
			if ( 0 === strpos( $query, 'INSERT INTO `' . $table . '`' ) ) {
				remove_filter( 'query', $race );
				$fired = true;
				$now   = gmdate( 'Y-m-d H:i:s' );
				$wpdb->insert( $table, array( 'domain' => 'race.example', 'user_id' => 9, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
			}
			return $query;
		};
		add_filter( 'query', $race );

		ob_start();
		$result = DnsAssignments::reserve( 2, 20, 'race.example' );
		$output = ob_get_clean();

		$this->assertTrue( $fired, 'the competing insert ran before the INSERT' );
		$this->assertWPError( $result );
		$this->assertSame( 'domain_taken', $result->get_error_code() );
		$this->assertSame( '', $output, 'no database error was printed' );
		$this->assertSame( 9, (int) DnsAssignments::get( 'race.example' )['user_id'] );
	}

	public function test_other_insert_failure_is_db_error_not_domain_taken(): void {
		$table = DnsSchema::tables()['assignments'];
		$break = static function ( $query ) use ( $table ) {
			return 0 === strpos( $query, 'INSERT INTO `' . $table . '`' ) ? str_replace( $table, $table . '_missing', $query ) : $query;
		};
		add_filter( 'query', $break );
		ob_start();
		$result = DnsAssignments::reserve( 2, 20, 'broken.example' );
		$output = ob_get_clean();
		remove_filter( 'query', $break );

		$this->assertWPError( $result );
		$this->assertSame( 'db_error', $result->get_error_code() );
		$this->assertSame( '', $output );
		$this->assertNull( DnsAssignments::get( 'broken.example' ) );
	}

	public function test_expired_reservation_can_be_taken_over(): void {
		DnsAssignments::reserve( 1, 10, 'example.com' );
		DnsAssignments::bind_order( 'example.com', 1, 77, 78 );
		$this->set( 'example.com', array( 'reserved_until' => $this->past() ) );
		$this->assertTrue( DnsAssignments::reserve( 2, 20, 'example.com' ) );
		$row = DnsAssignments::get( 'example.com' );
		$this->assertSame( 2, (int) $row['user_id'] );
		$this->assertSame( 20, (int) $row['claim_id'] );
		$this->assertNull( $row['order_id'] );
	}

	public function test_same_user_reserve_refreshes(): void {
		DnsAssignments::reserve( 1, 10, 'example.com', 60 );
		$this->assertTrue( DnsAssignments::reserve( 1, 11, 'example.com', 3600 ) );
		$row = DnsAssignments::get( 'example.com' );
		$this->assertSame( 11, (int) $row['claim_id'] );
		$this->assertEqualsWithDelta( time() + 3600, strtotime( $row['reserved_until'] . ' UTC' ), 5 );
	}

	public function test_same_user_with_active_or_release_pending_is_already_yours(): void {
		DnsAssignments::reserve( 1, 10, 'example.com' );
		DnsAssignments::activate( 'example.com', 1, 5, 6 );
		$this->assertSame( 'already_yours', DnsAssignments::reserve( 1, 10, 'example.com' )->get_error_code() );
		DnsAssignments::release_pending( 'example.com' );
		$this->assertSame( 'already_yours', DnsAssignments::reserve( 1, 10, 'example.com' )->get_error_code() );
		$this->assertSame( 'domain_taken', DnsAssignments::reserve( 2, 10, 'example.com' )->get_error_code() );
	}

	public function test_expire_reservations_only_removes_expired_reserved(): void {
		DnsAssignments::reserve( 1, 1, 'gone.example' );
		DnsAssignments::reserve( 1, 1, 'fresh.example' );
		DnsAssignments::reserve( 1, 1, 'active.example' );
		DnsAssignments::activate( 'active.example', 1, 1, 1 );
		DnsAssignments::reserve( 1, 1, 'rp.example' );
		DnsAssignments::activate( 'rp.example', 1, 1, 2 );
		DnsAssignments::release_pending( 'rp.example' );
		foreach ( array( 'gone.example', 'active.example', 'rp.example' ) as $d ) {
			$this->set( $d, array( 'reserved_until' => $this->past() ) );
		}
		$this->assertSame( 1, DnsAssignments::expire_reservations() );
		$this->assertNull( DnsAssignments::get( 'gone.example' ) );
		$this->assertNotNull( DnsAssignments::get( 'fresh.example' ) );
		$this->assertSame( 'active', DnsAssignments::get( 'active.example' )['status'] );
		$this->assertSame( 'release_pending', DnsAssignments::get( 'rp.example' )['status'] );
		$this->assertSame( 2, DnsAssignments::open_count() );
		$this->assertCount( 1, DnsAssignments::for_status( 'release_pending' ) );
	}

	public function test_release_for_order_and_release(): void {
		DnsAssignments::reserve( 1, 1, 'a.example' );
		DnsAssignments::reserve( 1, 1, 'b.example' );
		DnsAssignments::reserve( 1, 1, 'c.example' );
		$this->assertTrue( DnsAssignments::bind_order( 'a.example', 1, 50, 51 ) );
		$this->assertTrue( DnsAssignments::bind_order( 'b.example', 1, 50, 52 ) );
		$this->assertFalse( DnsAssignments::bind_order( 'c.example', 2, 50, 53 ) );
		$this->assertSame( 2, DnsAssignments::release_for_order( 50 ) );
		$this->assertNull( DnsAssignments::get( 'a.example' ) );
		$this->assertNotNull( DnsAssignments::get( 'c.example' ) );
		$this->assertTrue( DnsAssignments::release( 'c.example' ) );
		$this->assertFalse( DnsAssignments::release( 'c.example' ) );
	}

	public function test_release_for_order_leaves_active_rows(): void {
		DnsAssignments::reserve( 1, 1, 'a.example' );
		DnsAssignments::bind_order( 'a.example', 1, 50, 51 );
		DnsAssignments::activate( 'a.example', 1, 3, 4 );
		$this->assertSame( 0, DnsAssignments::release_for_order( 50 ) );
		$this->assertSame( 'active', DnsAssignments::get( 'a.example' )['status'] );
	}

	public function test_activate_is_idempotent_and_user_bound(): void {
		DnsAssignments::reserve( 1, 1, 'a.example' );
		$this->assertFalse( DnsAssignments::activate( 'a.example', 2, 3, 4 ) );
		$this->assertTrue( DnsAssignments::activate( 'a.example', 1, 3, 4 ) );
		$this->assertTrue( DnsAssignments::activate( 'a.example', 1, 3, 4 ) );
		$this->assertFalse( DnsAssignments::activate( 'a.example', 1, 3, 9 ) );
		$row = DnsAssignments::get( 'a.example' );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( 4, (int) $row['resource_id'] );
		$this->assertNull( $row['reserved_until'] );
	}

	public function test_a_newer_claim_does_not_repoint_a_row_bound_to_an_order(): void {
		$this->assertTrue( DnsAssignments::reserve( 1, 10, 'bound.example' ) );
		$this->assertTrue( DnsAssignments::reserve( 1, 11, 'bound.example' ), 'An unbound reservation refreshes, even with the same values.' );
		$this->assertTrue( DnsAssignments::bind_order( 'bound.example', 1, 500, 5 ) );
		$again = DnsAssignments::reserve( 1, 12, 'bound.example' );
		$this->assertWPError( $again );
		$this->assertSame( 'order_open', $again->get_error_code() );
		$this->assertSame( 'Finish or cancel your open order for this domain first.', $again->get_error_message() );
		$row = DnsAssignments::get( 'bound.example' );
		$this->assertSame( 11, (int) $row['claim_id'] );
		$this->assertSame( 500, (int) $row['order_id'] );
	}

	public function test_assign_reserved_refuses_a_live_row_and_replaces_only_what_is_free(): void {
		$this->assertTrue( DnsAssignments::assign_reserved( 'fresh.example', 1 ), 'No row.' );

		DnsAssignments::reserve( 1, 10, 'active.example' );
		DnsAssignments::activate( 'active.example', 1, 7, 8 );
		$this->assertWPError( DnsAssignments::assign_reserved( 'active.example', 2 ), 'Another user, active.' );
		$this->assertWPError( DnsAssignments::assign_reserved( 'active.example', 1 ), 'Same user, active: its resource may be gone but the name may still point at us.' );
		$this->assertSame( 'active', DnsAssignments::get( 'active.example' )['status'] );

		DnsAssignments::reserve( 1, 10, 'pending.example' );
		DnsAssignments::activate( 'pending.example', 1, 7, 8 );
		$this->set( 'pending.example', array( 'status' => 'release_pending' ) );
		$this->assertWPError( DnsAssignments::assign_reserved( 'pending.example', 2 ) );
		$this->assertWPError( DnsAssignments::assign_reserved( 'pending.example', 1 ) );
		$this->assertSame( 'release_pending', DnsAssignments::get( 'pending.example' )['status'] );

		DnsAssignments::reserve( 1, 10, 'live.example' );
		$this->assertWPError( DnsAssignments::assign_reserved( 'live.example', 2 ), 'Another user, reservation still open.' );

		$this->set( 'live.example', array( 'reserved_until' => $this->past() ) );
		$this->assertTrue( DnsAssignments::assign_reserved( 'live.example', 2 ), 'An expired reservation is free.' );
		$this->assertSame( 2, (int) DnsAssignments::get( 'live.example' )['user_id'] );

		$this->assertTrue( DnsAssignments::assign_reserved( 'live.example', 2 ), 'The same user may extend their own reservation.' );
	}
}
