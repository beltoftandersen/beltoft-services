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
		$res = $this->create_box( $r, 'Anna', 'Anna A' );

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
		$res  = $this->create_box( $r, 'anna' );
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

		$this->assertIsArray( $this->create_box( $r, 'bob' ) );
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
		$res = $this->create_box( $r, 'a' );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'unavailable', $res->get_error_code() );
		$this->assertSame( array(), $this->mailcow->calls_of( 'add_mailbox' ) );
	}

	public function test_the_plan_limit_names_and_state_are_enforced(): void {
		$r = $this->ready_mail( 'lim.example', array( 'mailboxes' => 2 ) );
		$this->assertIsArray( $this->create_box( $r, 'a' ) );
		$this->assertIsArray( $this->create_box( $r, 'b' ) );
		$this->assertSame( 'Your plan has no room for more mailboxes.', $this->create_box( $r, 'c' )->get_error_message() );
		$this->assertSame( array(), array_filter( $this->mailcow->calls_of( 'add_mailbox' ), static fn( $c ) => 'c@lim.example' === $c[1] ), 'Refused before the call.' );

		foreach ( array( '', '.a', 'a b', 'a@b', 'a..b', '-a', str_repeat( 'a', 70 ), '*', 'ü' ) as $bad ) {
			$this->assertSame( 'invalid', $this->create_box( $r, $bad )->get_error_code(), $bad );
		}
		$this->assertSame( 'invalid', $this->create_box( $r, array( 'x' ) )->get_error_code() );

		$dup = $this->ready_mail( 'dup2.example' );
		$this->create_box( $dup, 'x' );
		$dup->set( array( 'mail_limits' => array( 'mailboxes' => 9, 'quota_mb' => 10240, 'aliases' => 5 ) ) );
		$again = $this->create_box( Resource::find( $dup->id() ), 'x' );
		$this->assertSame( 'exists', $again->get_error_code() );

		$r->set( array( 'state' => 'paused' ) );
		$this->assertSame( 'not_ready', $this->create_box( Resource::find( $r->id() ), 'z' )->get_error_code() );
	}

	public function test_the_state_and_the_count_are_read_again_under_the_lock(): void {
		$r = $this->ready_mail( 'race.example', array( 'mailboxes' => 2 ) );
		$stale = Resource::find( $r->id() );
		$this->mailcow->add_mailbox( array( 'local_part' => 'other', 'domain' => 'race.example', 'quota' => '100' ) );
		$this->assertIsArray( $this->create_box( $stale, 'a' ), 'One place was left.' );
		$this->assertSame( 'Your plan has no room for more mailboxes.', $this->create_box( $stale, 'b' )->get_error_message(), 'The count is read at the add, not before.' );

		// A request that checked the state earlier holds a copy that still says ready.
		$other = $this->ready_mail( 'race2.example', array( 'mailboxes' => 5 ) );
		$copy  = Resource::find( $other->id() );
		$other->set( array( 'state' => 'paused' ) );
		$this->assertSame( 'not_ready', $this->create_box( $copy, 'x' )->get_error_code() );
		$this->assertSame( array(), array_filter( $this->mailcow->calls_of( 'add_mailbox' ), static fn( $c ) => 'x@race2.example' === $c[1] ) );

		// A job that was queued while the service was ready and runs after it was paused reads the state again: nothing is made.
		$plan = \BeltoftServices\Mail\Mailboxes::plan_create( Resource::find( $copy->id() ) ?? $copy, 'y' );
		$this->assertWPError( $plan );
		$ready = $this->ready_mail( 'race3.example' );
		$plan  = \BeltoftServices\Mail\Mailboxes::plan_create( $ready, 'y' );
		\BeltoftServices\Mail\Mailboxes::reserve( $ready, $plan['email'] );
		$ready->set( array( 'state' => 'paused' ) );
		$result = \BeltoftServices\Mail\Mailboxes::run_create( Resource::find( $ready->id() ), $plan );
		$this->assertTrue( $result->is_failed() );
		$this->assertNull( $this->mailcow->mailbox( 'y@race3.example' ) );
	}

	public function test_a_send_limit_that_fails_for_a_moment_is_tried_again_and_the_mailbox_is_finished(): void {
		$r = $this->ready_mail( 'rl.example' );
		$this->mailcow->fail_next( 'set_mailbox_rate_limit', new \WP_Error( 'mailcow_transport', 'x' ) );
		$res = $this->create_box( $r, 'a' );

		$this->assertWPError( $res, 'No outcome yet: the job waits for its retry.' );
		$fresh = Resource::find( $r->id() );
		$this->assertFalse( MailAccess::revealable( $fresh, 'a@rl.example' ), 'Not finished: no password is shown.' );
		$this->assertSame( 'pending', MailAccess::state( $fresh, 'a@rl.example' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'act_attempts' ) );

		// The retry runs the job again: the mailbox is there and was asked for, so it is taken as made and finished.
		\BeltoftServices\Jobs::act( $r->id(), \BeltoftServices\Mail\Mailboxes::CREATE, array( 'email' => 'a@rl.example', 'name' => 'a', 'quota' => 10240 ) );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( MailAccess::revealable( $fresh, 'a@rl.example' ) );
		$this->assertSame( array( 'value' => 500, 'frame' => 'd' ), $this->mailcow->mailbox( 'a@rl.example' )['rl'] );
		$this->assertCount( 1, $this->mailcow->calls_of( 'add_mailbox' ), 'Never added twice.' );
		$this->assertTrue( $fresh->get( 'last_result' )['ok'] );
	}

	public function test_a_send_limit_the_mail_server_refuses_removes_the_mailbox_again_and_checks_the_removal(): void {
		$r = $this->ready_mail( 'rl3.example' );
		$this->mailcow->fail_next( 'set_mailbox_rate_limit', new \WP_Error( 'mailcow_error', 'refused', array( 'key' => 'x' ) ) );
		$res = $this->create_box( $r, 'a' );

		$this->assertWPError( $res );
		$this->assertNull( $this->mailcow->mailbox( 'a@rl3.example' ) );
		$this->assertArrayNotHasKey( 'a@rl3.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );

		// The removal itself fails: staff are told, and the password is still forgotten.
		$this->mails = array();
		$this->mailcow->fail_next( 'set_mailbox_rate_limit', new \WP_Error( 'mailcow_error', 'refused', array( 'key' => 'x' ) ) );
		$this->mailcow->fail_next( 'delete_mailbox', new \WP_Error( 'mailcow_transport', 'down' ) );
		$this->assertWPError( $this->create_box( Resource::find( $r->id() ), 'b' ) );
		$this->assertNotNull( $this->mailcow->mailbox( 'b@rl3.example' ), 'It could not be removed.' );
		$this->assertArrayNotHasKey( 'b@rl3.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => str_contains( (string) wp_json_encode( $m ), 'was not removed again' ) ), 'Staff were told.' );
	}

	public function test_a_mailbox_that_exists_and_was_asked_for_by_an_earlier_run_is_taken_as_made_not_in_use(): void {
		$r    = $this->ready_mail( 'replay.example' );
		$plan = \BeltoftServices\Mail\Mailboxes::plan_create( $r, 'a' );
		$this->assertTrue( \BeltoftServices\Mail\Mailboxes::reserve( $r, $plan['email'] ) );
		// An earlier run asked the mail server and applied it, then died before it stored anything.
		$this->assertTrue( MailAccess::mark_sent( Resource::find( $r->id() ), $plan['email'] ) );
		$this->mailcow->add_mailbox( array( 'local_part' => 'a', 'domain' => 'replay.example', 'quota' => '100', 'password' => 'x' ) );
		$added = count( $this->mailcow->calls_of( 'add_mailbox' ) );

		$result = \BeltoftServices\Mail\Mailboxes::run_create( Resource::find( $r->id() ), $plan );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertTrue( MailAccess::revealable( Resource::find( $r->id() ), $plan['email'] ) );
		$this->assertCount( $added, $this->mailcow->calls_of( 'add_mailbox' ), 'Not added again.' );
		$this->assertSame( array( 'value' => 500, 'frame' => 'd' ), $this->mailcow->mailbox( 'a@replay.example' )['rl'], 'The steps after the add were done.' );

		// Replayed once more after it all finished: done, nothing touched.
		$again = \BeltoftServices\Mail\Mailboxes::run_create( Resource::find( $r->id() ), $plan );
		$this->assertTrue( $again->is_done() );
		$this->assertCount( $added, $this->mailcow->calls_of( 'add_mailbox' ) );
	}

	public function test_a_mailbox_that_was_there_before_is_refused_and_never_touched_or_given_a_password(): void {
		$r = $this->ready_mail( 'before.example' );
		$this->mailcow->add_mailbox( array( 'local_part' => 'old', 'domain' => 'before.example', 'quota' => '100', 'password' => 'theirs' ) );
		$this->mails = array();
		$res = $this->create_box( $r, 'old' );

		$this->assertWPError( $res );
		$this->assertSame( 'That address is already in use.', $res->get_error_message() );
		$this->assertSame( array(), $this->mailcow->calls_of( 'delete_mailbox' ) );
		$this->assertSame( array(), $this->mailcow->calls_of( 'edit_mailbox' ) );
		$this->assertArrayNotHasKey( 'old@before.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );
		$this->assertSame( array(), array_filter( $this->mails, static fn( $m ) => str_contains( (string) wp_json_encode( $m ), 'old@before.example' ) ), 'A refusal the customer reads is not an alert for staff.' );
	}

	public function test_what_the_request_can_know_is_said_at_once_and_queues_nothing(): void {
		$r = $this->ready_mail( 'known.example', array( 'mailboxes' => 2 ) );
		$r->set( array( \BeltoftServices\Mail\MailMeta::SYSTEM => 'wordpress@known.example' ) );
		$r = Resource::find( $r->id() );

		// Format, size and the hidden system address: no job.
		$this->assertSame( 'invalid', \BeltoftServices\Mail\Mailboxes::plan_create( $r, 'not valid' )->get_error_code() );
		$this->assertSame( 'limit', \BeltoftServices\Mail\Mailboxes::plan_create( $r, 'a', '', 99999 )->get_error_code() );
		$this->assertSame( 'exists', \BeltoftServices\Mail\Mailboxes::plan_create( $r, 'wordpress' )->get_error_code() );

		// A full plan, from the remembered count.
		$this->assertIsArray( $this->create_box( $r, 'a' ) );
		$this->assertIsArray( $this->create_box( $r, 'b' ) );
		$full = \BeltoftServices\Mail\Mailboxes::plan_create( Resource::find( $r->id() ), 'c' );
		$this->assertSame( 'limit', $full->get_error_code() );
		$this->assertSame( 'Your plan has no room for more mailboxes.', $full->get_error_message() );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );

		// Deleting one frees the place at once (the count is remembered).
		$this->assertTrue( \BeltoftServices\Mail\Mailboxes::delete( Resource::find( $r->id() ), 'a@known.example' ) );
		$this->assertIsArray( \BeltoftServices\Mail\Mailboxes::plan_create( Resource::find( $r->id() ), 'c' ) );
	}

	public function test_an_expected_refusal_does_not_mail_staff_and_a_real_failure_does(): void {
		$r = $this->ready_mail( 'alerts.example' );
		$this->mails = array();
		$this->mailcow->add_mailbox( array( 'local_part' => 'old', 'domain' => 'alerts.example', 'quota' => '100', 'password' => 'theirs' ) );
		$this->assertWPError( $this->create_box( $r, 'old' ) );
		$this->assertWPError( $this->reset_box( $r, 'none@alerts.example' ) );
		$this->assertSame( array(), $this->mails, 'Taken name and missing mailbox: expected, no mail.' );

		$this->mailcow->fail_next( 'add_mailbox', new \WP_Error( 'mailcow_error', 'refused', array( 'key' => 'x' ) ) );
		$this->assertWPError( $this->create_box( $r, 'broken' ) );
		$this->assertNotEmpty( $this->mails, 'A mail server that refuses something unexpected: staff are told.' );
	}

	public function test_the_password_is_never_in_a_job_argument(): void {
		$r    = $this->ready_mail( 'args.example' );
		$plan = \BeltoftServices\Mail\Mailboxes::plan_create( $r, 'a' );
		$this->assertTrue( \BeltoftServices\Jobs::start_action( $r, \BeltoftServices\Mail\Mailboxes::CREATE, 'Creating', $plan, static fn() => \BeltoftServices\Mail\Mailboxes::reserve( $r, $plan['email'] ) ) );
		$pw   = MailAccess::pending_password( Resource::find( $r->id() ), 'a@args.example' );
		$jobs = as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) );
		$this->assertNotEmpty( $jobs );
		$this->assertSame( 24, strlen( (string) $pw ) );
		$this->assertStringNotContainsString( (string) $pw, (string) wp_json_encode( array_map( static fn( $a ) => $a->get_args(), $jobs ) ) );
		$this->assertSame( array( 'email', 'name', 'quota' ), array_keys( $plan ) );
	}

	public function test_a_second_change_is_refused_while_one_is_pending_and_keeps_the_stored_password(): void {
		$r    = $this->ready_mail( 'busy.example' );
		$plan = \BeltoftServices\Mail\Mailboxes::plan_create( $r, 'a' );
		$this->assertTrue( \BeltoftServices\Jobs::start_action( $r, \BeltoftServices\Mail\Mailboxes::CREATE, 'Creating', $plan, static fn() => \BeltoftServices\Mail\Mailboxes::reserve( $r, $plan['email'] ) ) );
		$before = MailAccess::pending_password( Resource::find( $r->id() ), 'a@busy.example' );

		$second = \BeltoftServices\Jobs::start_action( Resource::find( $r->id() ), \BeltoftServices\Mail\Mailboxes::PASSWORD, 'New password', array( 'email' => 'a@busy.example' ), static fn() => \BeltoftServices\Mail\Mailboxes::reserve( $r, 'a@busy.example' ) );
		$this->assertWPError( $second );
		$this->assertSame( 'busy', $second->get_error_code() );
		$this->assertSame( $before, MailAccess::pending_password( Resource::find( $r->id() ), 'a@busy.example' ), 'The refusal did not touch the password.' );
	}

	public function test_a_mailbox_job_that_ends_for_good_takes_back_what_it_made(): void {
		$r    = $this->ready_mail( 'back.example' );
		$plan = \BeltoftServices\Mail\Mailboxes::plan_create( $r, 'a' );
		\BeltoftServices\Mail\Mailboxes::reserve( $r, $plan['email'] );
		MailAccess::mark_sent( Resource::find( $r->id() ), $plan['email'] );
		$this->mailcow->add_mailbox( array( 'local_part' => 'a', 'domain' => 'back.example', 'quota' => '100' ) );

		// The retries ran out (action_failed, why failed): the mailbox it asked for is removed and the password forgotten.
		do_action( 'beltoft_services_action_failed', Resource::find( $r->id() ), \BeltoftServices\Mail\Mailboxes::CREATE, $plan, 'failed' );
		$this->assertNull( $this->mailcow->mailbox( 'a@back.example' ) );
		$this->assertArrayNotHasKey( 'a@back.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );

		// A mailbox that was there before is never removed by this hook.
		$this->mailcow->add_mailbox( array( 'local_part' => 'b', 'domain' => 'back.example', 'quota' => '100' ) );
		\BeltoftServices\Mail\Mailboxes::reserve( Resource::find( $r->id() ), 'b@back.example' );
		do_action( 'beltoft_services_action_failed', Resource::find( $r->id() ), \BeltoftServices\Mail\Mailboxes::CREATE, array( 'email' => 'b@back.example' ), 'failed' );
		$this->assertNotNull( $this->mailcow->mailbox( 'b@back.example' ) );
	}

	public function test_a_refused_mailbox_leaves_no_password_behind(): void {
		$r = $this->ready_mail( 'fail.example' );
		$this->mailcow->fail_next( 'add_mailbox', new \WP_Error( 'mailcow_error', 'refused', array( 'key' => 'x' ) ) );
		$this->assertSame( 'job', $this->create_box( $r, 'a' )->get_error_code() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( MailAccess::META ) );
	}

	public function test_the_mailbox_limit_is_the_daily_setting_only(): void {
		$r = $this->ready_mail( 'rl2.example' );
		$this->create_box( $r, 'a' );
		$this->assertSame( array( 'value' => 500, 'frame' => 'd' ), $this->mailcow->mailbox( 'a@rl2.example' )['rl'] );

		$this->settings( array( 'mail_rl_day' => 0, 'mail_rl_minute' => 30 ) );
		$this->create_box( $r, 'b' );
		$this->assertFalse( $this->mailcow->mailbox( 'b@rl2.example' )['rl'], 'The minute limit belongs to the domain.' );

		$this->settings( array( 'mail_rl_day' => 100, 'mail_rl_minute' => 0 ) );
		$this->create_box( $r, 'c' );
		$this->assertSame( array( 'value' => 100, 'frame' => 'd' ), $this->mailcow->mailbox( 'c@rl2.example' )['rl'] );
	}

	public function test_delete_forgets_the_password_and_stays_inside_the_domain(): void {
		$r = $this->ready_mail( 'del.example' );
		$this->create_box( $r, 'a' );
		$other = $this->ready_mail( 'other.example' );
		$this->create_box( $other, 'a' );

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
		$this->create_box( $r, 'a' );
		$first = MailAccess::take( Resource::find( $r->id() ), 'a@reset.example', $user );

		$this->assertTrue( $this->reset_box( Resource::find( $r->id() ), 'a@reset.example' ) );
		$second = MailAccess::take( Resource::find( $r->id() ), 'a@reset.example', $user );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 'changed', $this->mailcow->mailbox( 'a@reset.example' )['password'] );

		$res = $this->reset_box( Resource::find( $r->id() ), 'none@reset.example' );
		$this->assertSame( 'That mailbox does not exist.', $res->get_error_message() );
		$this->assertArrayNotHasKey( 'none@reset.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );

		$this->mailcow->fail_next( 'edit_mailbox', new \WP_Error( 'mailcow_transport', 'x' ) );
		$this->assertWPError( $this->reset_box( Resource::find( $r->id() ), 'a@reset.example' ), 'The mail server could not be reached: the job waits for its retry.' );
		$this->assertFalse( MailAccess::revealable( Resource::find( $r->id() ), 'a@reset.example' ), 'A password that was not set cannot be revealed.' );

		// The retry sets the password that was kept, so the answer that never came cannot leave it unknown.
		\BeltoftServices\Jobs::act( $r->id(), \BeltoftServices\Mail\Mailboxes::PASSWORD, array( 'email' => 'a@reset.example' ) );
		$this->assertTrue( MailAccess::revealable( Resource::find( $r->id() ), 'a@reset.example' ) );
		$this->assertTrue( Resource::find( $r->id() )->get( 'last_result' )['ok'] );
	}

	public function test_the_list_shows_usage_and_whether_a_password_waits(): void {
		$r = $this->ready_mail( 'list.example' );
		$this->create_box( $r, 'a' );
		$this->mailcow->set_usage( 'a@list.example', 5 * 1048576 );

		$rows = Mailboxes::all( Resource::find( $r->id() ) );
		$this->assertSame( array( array( 'email' => 'a@list.example', 'name' => 'a', 'quota_mb' => 10240, 'used_mb' => 5, 'active' => true, 'reveal' => true ) ), $rows );
	}

	public function test_a_deleted_service_keeps_no_password(): void {
		$r = $this->ready_mail( 'gone.example' );
		$this->create_box( $r, 'a' );
		MailAccess::on_state_changed( Resource::find( $r->id() ), 'ready', 'deleted' );
		$this->assertSame( array(), Resource::find( $r->id() )->get( MailAccess::META ) );
	}

	public function test_a_mailbox_can_be_smaller_than_the_plan_but_never_larger(): void {
		$r = $this->ready_mail( 'size.example' );

		$this->assertSame( 2048, $this->create_box( $r, 'small', '', 2048 )['quota_mb'] );
		$this->assertSame( 2048 * 1048576, $this->mailcow->mailbox( 'small@size.example' )['quota'] );
		$this->assertSame( 10240, $this->create_box( $r, 'full' )['quota_mb'] );
		$this->assertSame( 'limit', $this->create_box( $r, 'big', '', 10241 )->get_error_code() );
		$this->assertSame( 'limit', $this->create_box( $r, 'neg', '', -1 )->get_error_code() );
		$this->assertNull( $this->mailcow->mailbox( 'big@size.example' ) );
		$this->assertArrayNotHasKey( 'big@size.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );
	}

	public function test_a_domain_being_removed_takes_no_new_mailbox_or_alias(): void {
		$root  = $this->ready_mail( 'rm-root.example' );
		$child = \BeltoftServices\Mail\MailDomains::add( $root, 'rm-kid.example' );
		$this->run_jobs();
		$child = \BeltoftServices\Resource::find( $child->id() );
		$this->assertTrue( \BeltoftServices\Mail\MailDomains::remove( $child ) );
		$gone = \BeltoftServices\Resource::find( $child->id() );
		$this->assertSame( 'deleted', $gone->get( 'desired' ) );
		$this->assertWPError( $this->create_box( $gone, 'late' ) );
		$this->assertWPError( \BeltoftServices\Mail\Aliases::create( $gone, 'late', array( 'x@rm-root.example' ) ) );
	}
}
