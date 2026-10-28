<?php
/** DNS ownership claim tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Tests\TestCase;

class DnsClaimsTest extends TestCase {
	private function age( int $id, string $column, int $seconds_ago ): void {
		global $wpdb;
		$wpdb->update( DnsSchema::tables()['claims'], array( $column => gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ) ), array( 'id' => $id ) );
	}

	public function test_token_format(): void {
		$a = DnsClaims::create( 1, 'example.com' );
		$b = DnsClaims::create( 2, 'example.com' );
		$this->assertSame( 1, preg_match( '/^[A-Za-z0-9_-]{43}$/', $a['token'] ) );
		$this->assertNotSame( $a['token'], $b['token'] );
		$this->assertSame( 'beltoft-verify=v1:' . $a['token'], DnsClaims::txt_value( $a ) );
		$this->assertSame( '_beltoft-verify.example.com', DnsClaims::txt_name( $a ) );
		$this->assertSame( 'pending', $a['status'] );
		$this->assertSame( array(), $a['parent_ns'] );
		$this->assertNull( $a['ds_present'] );
	}

	public function test_two_users_can_hold_a_pending_claim_for_one_domain(): void {
		$a = DnsClaims::create( 1, 'example.com' );
		$b = DnsClaims::create( 2, 'example.com' );
		$this->assertIsArray( $a );
		$this->assertIsArray( $b );
		$this->assertSame( 'pending', DnsClaims::get( $a['id'] )['status'] );
	}

	public function test_rotation_cancels_the_older_claim_of_the_same_user(): void {
		$old = DnsClaims::create( 1, 'example.com' );
		$new = DnsClaims::create( 1, 'example.com' );
		$this->assertSame( 'cancelled', DnsClaims::get( $old['id'] )['status'] );
		$this->assertSame( 'pending', DnsClaims::get( $new['id'] )['status'] );
	}

	public function test_cap_returns_claims_limit_but_rotation_is_allowed(): void {
		$this->settings( array( 'dns_claims_max_per_user' => 2 ) );
		DnsClaims::create( 1, 'a.example' );
		DnsClaims::create( 1, 'b.example' );
		$third = DnsClaims::create( 1, 'c.example' );
		$this->assertWPError( $third );
		$this->assertSame( 'claims_limit', $third->get_error_code() );
		$this->assertIsArray( DnsClaims::create( 1, 'b.example' ) );
		$this->assertIsArray( DnsClaims::create( 2, 'c.example' ) );
	}

	public function test_live_dns_resources_count_toward_the_cap(): void {
		$this->settings( array( 'dns_claims_max_per_user' => 1 ) );
		$product = $this->service_product( 'dns' );
		$user    = self::factory()->user->create();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$this->assertInstanceOf( \BeltoftServices\Resource::class, \BeltoftServices\Resource::create( $license, new \BeltoftServices\Domains\DnsDriver(), array(), array( 'domain' => 'held.example' ) ) );
		$result = DnsClaims::create( $user, 'new.example' );
		$this->assertWPError( $result );
		$this->assertSame( 'claims_limit', $result->get_error_code() );
	}

	public function test_set_status_stores_json_and_verified_extends_expiry(): void {
		$c = DnsClaims::create( 1, 'example.com' );
		$this->assertTrue( DnsClaims::set_status( $c['id'], 'verified', array( 'parent_ns' => array( 'ns1.x.net' ), 'ds_present' => 0, 'last_result' => array( 'ok' => true ), 'draft_records' => array( array( 'type' => 'A' ) ), 'verified_at' => gmdate( 'Y-m-d H:i:s' ), 'check_failures' => 2 ) ) );
		$got = DnsClaims::get( $c['id'] );
		$this->assertSame( array( 'ns1.x.net' ), $got['parent_ns'] );
		$this->assertSame( 0, $got['ds_present'] );
		$this->assertSame( array( 'ok' => true ), $got['last_result'] );
		$this->assertSame( 'A', $got['draft_records'][0]['type'] );
		$this->assertSame( 2, $got['check_failures'] );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, strtotime( $got['expires_at'] . ' UTC' ), 5 );
	}

	public function test_usable(): void {
		$c = DnsClaims::create( 1, 'example.com' );
		$this->assertFalse( DnsClaims::usable( DnsClaims::get( $c['id'] ), 1 ) );
		DnsClaims::set_status( $c['id'], 'verified' );
		$this->assertTrue( DnsClaims::usable( DnsClaims::get( $c['id'] ), 1 ) );
		$this->assertFalse( DnsClaims::usable( DnsClaims::get( $c['id'] ), 2 ) );
		$this->age( $c['id'], 'expires_at', 60 );
		$this->assertFalse( DnsClaims::usable( DnsClaims::get( $c['id'] ), 1 ) );
	}

	public function test_expire_old_and_purge(): void {
		$open = DnsClaims::create( 1, 'open.example' );
		$late = DnsClaims::create( 1, 'late.example' );
		$ver  = DnsClaims::create( 1, 'ver.example' );
		DnsClaims::set_status( $ver['id'], 'verified' );
		$this->age( $late['id'], 'expires_at', 60 );
		$this->age( $ver['id'], 'expires_at', 60 );
		$this->assertSame( 2, DnsClaims::expire_old() );
		$this->assertSame( 'pending', DnsClaims::get( $open['id'] )['status'] );
		$this->assertSame( 'expired', DnsClaims::get( $late['id'] )['status'] );

		$this->assertSame( 0, DnsClaims::purge() );
		$this->age( $late['id'], 'expires_at', 91 * DAY_IN_SECONDS );
		$this->age( $ver['id'], 'expires_at', 10 * DAY_IN_SECONDS );
		$this->assertSame( 1, DnsClaims::purge() );
		$this->assertNull( DnsClaims::get( $late['id'] ) );
		$this->assertNotNull( DnsClaims::get( $ver['id'] ) );
		$this->assertNotNull( DnsClaims::get( $open['id'] ) );
	}

	public function test_for_user_newest_first_hides_old_finished_rows(): void {
		$a = DnsClaims::create( 1, 'a.example' );
		$b = DnsClaims::create( 1, 'b.example' );
		$c = DnsClaims::create( 1, 'c.example' );
		DnsClaims::create( 2, 'other.example' );
		DnsClaims::set_status( $c['id'], 'cancelled' );
		$this->age( $c['id'], 'expires_at', 8 * DAY_IN_SECONDS );
		$ids = array_column( DnsClaims::for_user( 1 ), 'id' );
		$this->assertSame( array( $b['id'], $a['id'] ), $ids );
	}
}
