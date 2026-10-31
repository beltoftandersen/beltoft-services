<?php
/**
 * Mailbox operations and the one-time password reveal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailAccess;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailboxesTest extends MailTestCase {

	private function user_of( Resource $r ): int {
		return (int) $r->license()['user_id'];
	}

	public function test_create_makes_the_mailbox_with_plan_quota_and_the_stricter_send_limit(): void {
		$r   = $this->ready_mail( 'm.example' );
		$res = Mailboxes::create( $r, 'Anna', 'Anna A' );

		$this->assertSame( array( 'email' => 'anna@m.example', 'quota_mb' => 10240 ), $res );
		$box = $this->mailcow->mailbox( 'anna@m.example' );
		$this->assertSame( 10240 * 1048576, $box['quota'] );
		$this->assertSame( 'Anna A', $box['name'] );
		// 30 per minute is 43200 a day, 500 per day is stricter: mailcow keeps one value and one frame.
		$this->assertSame( array( 'value' => 500, 'frame' => 'd' ), $box['rl'] );
		$this->assertSame( '1', $box['active'] );
	}

	public function test_the_password_is_stored_encrypted_returned_to_nobody_and_revealed_once(): void {
		$r    = $this->ready_mail( 'pw.example' );
		$res  = Mailboxes::create( $r, 'anna' );
		$user = $this->user_of( $r );

		$this->assertStringNotContainsString( 'password', implode( ',', array_keys( $res ) ) );
		$this->assertSame( array(), array_filter( $this->mailcow->calls(), static fn( $c ) => str_contains( (string) wp_json_encode( $c ), 'password' ) ), 'The mail server log has no password.' );

		$fresh  = Resource::find( $r->id() );
		$stored = $fresh->get( MailAccess::META )['anna@pw.example'];
		$this->assertStringStartsWith( 'bs1:', $stored['pw'] );
		$this->assertFalse( $stored['pending'] );
		$this->assertTrue( MailAccess::revealable( $fresh, 'anna@pw.example' ) );

		$this->assertNull( MailAccess::take( $fresh, 'anna@pw.example', 0 ), 'Not a user.' );
		$this->assertNull( MailAccess::take( $fresh, 'anna@pw.example', self::factory()->user->create() ), 'Not the owner.' );
		$plain = MailAccess::take( Resource::find( $r->id() ), 'ANNA@pw.example', $user );
		$this->assertSame( 24, strlen( (string) $plain ) );
		$this->assertNull( MailAccess::take( Resource::find( $r->id() ), 'anna@pw.example', $user ), 'Once.' );
		$this->assertFalse( MailAccess::revealable( Resource::find( $r->id() ), 'anna@pw.example' ) );
		$this->assertStringNotContainsString( (string) $plain, (string) wp_json_encode( Resource::find( $r->id() )->get( MailAccess::META ) ) );
		$this->assertSame( array(), $this->mails, 'Nothing was emailed.' );
	}

	public function test_the_password_that_reached_the_server_is_the_one_that_is_revealed(): void {
		$r    = $this->ready_mail( 'same.example' );
		$spy  = new class() extends \BeltoftServices\Mail\FakeMailcowClient {
			public string $password = '';
			public function add_mailbox( array $attr ) {
				$this->password = (string) $attr['password'];
				return parent::add_mailbox( $attr );
			}
		};
		$spy->add_domain( array( 'domain' => 'same.example', 'mailboxes' => '5', 'maxquota' => '10240' ) );
		remove_all_filters( 'beltoft_services_mail_client' );
		add_filter( 'beltoft_services_mail_client', static fn() => $spy );

		$this->assertIsArray( Mailboxes::create( $r, 'bob' ) );
		$seen = MailAccess::take( Resource::find( $r->id() ), 'bob@same.example', $this->user_of( $r ) );
		$this->assertSame( $spy->password, $seen );
	}

	public function test_a_pending_or_expired_password_cannot_be_revealed(): void {
		$r = $this->ready_mail( 'exp.example' );
		$r->locked( static function () use ( $r ): bool {
			MailAccess::begin( $r, 'a@exp.example' );
			return true;
		} );
		$fresh = Resource::find( $r->id() );
		$this->assertFalse( MailAccess::revealable( $fresh, 'a@exp.example' ), 'Pending.' );
		$this->assertNull( MailAccess::take( $fresh, 'a@exp.example', $this->user_of( $r ) ) );

		MailAccess::finish( $fresh, 'a@exp.example' );
		$all                                = Resource::find( $r->id() )->get( MailAccess::META );
		$all['a@exp.example']['until'] = time() - 5;
		$r->set( array( MailAccess::META => $all ) );
		$this->assertNull( MailAccess::take( Resource::find( $r->id() ), 'a@exp.example', $this->user_of( $r ) ), 'Expired.' );
		$this->assertArrayNotHasKey( 'a@exp.example', Resource::find( $r->id() )->get( MailAccess::META ), 'And wiped.' );
	}

	public function test_no_key_means_no_mailbox_and_nothing_is_sent(): void {
		$r = $this->ready_mail( 'nokey.example' );
		remove_all_filters( 'beltoft_services_key' );
		$res = Mailboxes::create( $r, 'a' );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'unavailable', $res->get_error_code() );
		$this->assertSame( array(), $this->mailcow->calls_of( 'add_mailbox' ) );
	}

	public function test_the_plan_limit_names_and_state_are_enforced(): void {
		$r = $this->ready_mail( 'lim.example', array( 'mailboxes' => 2 ) );
		$this->assertIsArray( Mailboxes::create( $r, 'a' ) );
		$this->assertIsArray( Mailboxes::create( $r, 'b' ) );
		$this->assertSame( 'limit', Mailboxes::create( $r, 'c' )->get_error_code() );
		$this->assertSame( array(), array_filter( $this->mailcow->calls_of( 'add_mailbox' ), static fn( $c ) => 'c@lim.example' === $c[1] ), 'Refused before the call.' );

		foreach ( array( '', '.a', 'a b', 'a@b', 'a..b', '-a', str_repeat( 'a', 70 ), '*', 'ü' ) as $bad ) {
			$this->assertSame( 'invalid', Mailboxes::create( $r, $bad )->get_error_code(), $bad );
		}
		$this->assertSame( 'invalid', Mailboxes::create( $r, array( 'x' ) )->get_error_code() );

		$dup = $this->ready_mail( 'dup2.example' );
		Mailboxes::create( $dup, 'x' );
		$dup->set( array( 'mail_limits' => array( 'mailboxes' => 9, 'quota_mb' => 10240, 'aliases' => 5 ) ) );
		$again = Mailboxes::create( Resource::find( $dup->id() ), 'x' );
		$this->assertSame( 'exists', $again->get_error_code() );

		$r->set( array( 'state' => 'paused' ) );
		$this->assertSame( 'not_ready', Mailboxes::create( Resource::find( $r->id() ), 'z' )->get_error_code() );
	}

	public function test_a_failed_send_limit_removes_the_mailbox_again(): void {
		$r = $this->ready_mail( 'rl.example' );
		$this->mailcow->fail_next( 'set_mailbox_rate_limit', new \WP_Error( 'mailcow_transport', 'x' ) );
		$res = Mailboxes::create( $r, 'a' );

		$this->assertSame( 'unavailable', $res->get_error_code() );
		$this->assertNull( $this->mailcow->mailbox( 'a@rl.example' ) );
		$this->assertArrayNotHasKey( 'a@rl.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );
	}

	public function test_a_refused_mailbox_leaves_no_password_behind(): void {
		$r = $this->ready_mail( 'fail.example' );
		$this->mailcow->fail_next( 'add_mailbox', new \WP_Error( 'mailcow_error', 'refused', array( 'key' => 'x' ) ) );
		$this->assertSame( 'failed', Mailboxes::create( $r, 'a' )->get_error_code() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( MailAccess::META ) );
	}

	public function test_send_limits_follow_the_settings(): void {
		$this->settings( array( 'mail_rl_day' => 0, 'mail_rl_minute' => 30 ) );
		$r = $this->ready_mail( 'rl2.example' );
		Mailboxes::create( $r, 'a' );
		$this->assertSame( array( 'value' => 30, 'frame' => 'm' ), $this->mailcow->mailbox( 'a@rl2.example' )['rl'] );

		$this->settings( array( 'mail_rl_day' => 0, 'mail_rl_minute' => 0 ) );
		Mailboxes::create( $r, 'b' );
		$this->assertFalse( $this->mailcow->mailbox( 'b@rl2.example' )['rl'] );

		$this->settings( array( 'mail_rl_day' => 100000, 'mail_rl_minute' => 30 ) );
		Mailboxes::create( $r, 'c' );
		$this->assertSame( 'm', $this->mailcow->mailbox( 'c@rl2.example' )['rl']['frame'], 'The minute limit is the stricter one.' );
	}

	public function test_delete_forgets_the_password_and_stays_inside_the_domain(): void {
		$r = $this->ready_mail( 'del.example' );
		Mailboxes::create( $r, 'a' );
		$other = $this->ready_mail( 'other.example' );
		Mailboxes::create( $other, 'a' );

		$this->assertSame( 'invalid', Mailboxes::delete( $r, 'a@other.example' )->get_error_code() );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@other.example' ) );

		$this->assertTrue( Mailboxes::delete( Resource::find( $r->id() ), 'A@del.example' ) );
		$this->assertNull( $this->mailcow->mailbox( 'a@del.example' ) );
		$this->assertArrayNotHasKey( 'a@del.example', Resource::find( $r->id() )->get( MailAccess::META ) );
		$this->assertTrue( Mailboxes::delete( Resource::find( $r->id() ), 'a@del.example' ), 'Gone already is fine.' );
	}

	public function test_reset_password_sets_a_new_one_for_one_time_reveal(): void {
		$r    = $this->ready_mail( 'reset.example' );
		$user = $this->user_of( $r );
		Mailboxes::create( $r, 'a' );
		$first = MailAccess::take( Resource::find( $r->id() ), 'a@reset.example', $user );

		$this->assertTrue( Mailboxes::reset_password( Resource::find( $r->id() ), 'a@reset.example' ) );
		$second = MailAccess::take( Resource::find( $r->id() ), 'a@reset.example', $user );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 'changed', $this->mailcow->mailbox( 'a@reset.example' )['password'] );

		$this->assertSame( 'not_found', Mailboxes::reset_password( Resource::find( $r->id() ), 'none@reset.example' )->get_error_code() );

		$this->mailcow->fail_next( 'edit_mailbox', new \WP_Error( 'mailcow_transport', 'x' ) );
		$this->assertSame( 'unavailable', Mailboxes::reset_password( Resource::find( $r->id() ), 'a@reset.example' )->get_error_code() );
		$this->assertFalse( MailAccess::revealable( Resource::find( $r->id() ), 'a@reset.example' ), 'A password that was not set cannot be revealed.' );
	}

	public function test_quota_is_bounded_by_the_plan(): void {
		$r = $this->ready_mail( 'q.example' );
		Mailboxes::create( $r, 'a' );
		$this->assertTrue( Mailboxes::set_quota( $r, 'a@q.example', 2048 ) );
		$this->assertSame( 2048 * 1048576, $this->mailcow->mailbox( 'a@q.example' )['quota'] );
		$this->assertSame( 'limit', Mailboxes::set_quota( $r, 'a@q.example', 10241 )->get_error_code() );
		$this->assertSame( 'limit', Mailboxes::set_quota( $r, 'a@q.example', 0 )->get_error_code() );
		$this->assertSame( 'invalid', Mailboxes::set_quota( $r, 'a@elsewhere.example', 100 )->get_error_code() );
	}

	public function test_the_list_shows_usage_and_whether_a_password_waits(): void {
		$r = $this->ready_mail( 'list.example' );
		Mailboxes::create( $r, 'a' );
		$this->mailcow->set_usage( 'a@list.example', 5 * 1048576 );

		$rows = Mailboxes::all( Resource::find( $r->id() ) );
		$this->assertSame( array( array( 'email' => 'a@list.example', 'name' => 'a', 'quota_mb' => 10240, 'used_mb' => 5, 'active' => true, 'reveal' => true ) ), $rows );
	}

	public function test_a_mailbox_can_be_switched_off_for_abuse(): void {
		$r = $this->ready_mail( 'abuse.example' );
		Mailboxes::create( $r, 'a' );
		$this->assertTrue( Mailboxes::set_active( $r, 'a@abuse.example', false ) );
		$this->assertSame( '0', $this->mailcow->mailbox( 'a@abuse.example' )['active'] );
		$this->assertTrue( Mailboxes::set_active( $r, 'a@abuse.example', true ) );
	}

	public function test_a_deleted_service_keeps_no_password(): void {
		$r = $this->ready_mail( 'gone.example' );
		Mailboxes::create( $r, 'a' );
		MailAccess::on_state_changed( Resource::find( $r->id() ), 'ready', 'deleted' );
		$this->assertSame( array(), Resource::find( $r->id() )->get( MailAccess::META ) );
	}
}
