<?php
/**
 * A mailbox password the customer types: on a new mailbox and on a new password. Nothing is shown afterwards.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Mail\FakeMailcowClient;
use BeltoftServices\Mail\MailAccess;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailOwnPasswordTest extends MailTestCase {

	private const BASE = '/beltoft-services/v1/resources/';
	private const PW   = 'Horse-battery-77';

	/** @var object The client that remembers the passwords it was given. */
	private $spy;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		add_filter( 'beltoft_services_step_up_verify', static fn( $ok, $user, $secret, $kind, $purpose ) => 'mail_password' === $purpose ? ( '123456' === $secret ) : $ok, 10, 5 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_step_up_verify' );
		parent::tear_down();
	}

	/** A ready domain whose mail client records the passwords it receives. */
	private function domain( string $name = 'own.example' ): array {
		$user = self::factory()->user->create();
		$r    = $this->ready_mail( $name, array(), $user );
		$spy  = new class() extends FakeMailcowClient {
			public array $passwords = array();
			public function add_mailbox( array $attr ) {
				$this->passwords[] = array( 'add', (string) $attr['password'], (string) $attr['password2'] );
				return parent::add_mailbox( $attr );
			}
			public function edit_mailbox( string $email, array $attr ) {
				if ( isset( $attr['password'] ) ) {
					$this->passwords[] = array( 'edit', (string) $attr['password'], (string) $attr['password2'] );
				}
				return parent::edit_mailbox( $email, $attr );
			}
		};
		$spy->add_domain( array( 'domain' => $name, 'mailboxes' => '5', 'maxquota' => '10240' ) );
		remove_all_filters( 'beltoft_services_mail_client' );
		add_filter( 'beltoft_services_mail_client', static fn() => $spy );
		$this->spy = $spy;

		return array( $r, $user );
	}

	private function call( string $path, int $user, array $params ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', self::BASE . $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_get_server()->dispatch( $request );
	}

	private function create( Resource $r, int $user, array $body ): \WP_REST_Response {
		return $this->call( $r->id() . '/mail/mailboxes', $user, $body );
	}

	private function change( Resource $r, int $user, string $email, array $body ): \WP_REST_Response {
		return $this->call( $r->id() . '/mail/mailboxes/' . rawurlencode( $email ) . '/password', $user, $body + array( 'step_code' => '123456' ) );
	}

	/** Everything a queued job or a stored result could hold: Action Scheduler args and the resource meta that is not the encrypted store. */
	private function leaks( Resource $r, string $needle ): array {
		global $wpdb;
		$found = array();

		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE args LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' ) ) > 0 ) {
			$found[] = 'actionscheduler';
		}

		$fresh = Resource::find( $r->id() );

		foreach ( array( Jobs::RESULT, \BeltoftServices\Progress::MARKER ) as $key ) {
			if ( str_contains( (string) wp_json_encode( $fresh->get( $key ) ), $needle ) ) {
				$found[] = $key;
			}
		}

		return $found;
	}

	public function test_create_with_a_typed_password_sets_exactly_it_and_offers_no_reveal(): void {
		list( $r, $user ) = $this->domain();

		$made = $this->create( $r, $user, array( 'local' => 'anna', 'password' => self::PW, 'password_again' => self::PW ) );
		$this->assertSame( 202, $made->get_status() );
		$this->assertFalse( $made->get_data()['reveal'] );
		$this->assertStringNotContainsString( self::PW, (string) wp_json_encode( $made->get_data() ) );
		$this->assertSame( array(), $this->leaks( $r, self::PW ), 'Not in a queued job or the marker.' );
		$this->assertTrue( MailAccess::has( Resource::find( $r->id() ), 'anna@own.example' ), 'Kept encrypted until the job has run.' );

		$this->run_jobs();

		$this->assertSame( array( array( 'add', self::PW, self::PW ) ), $this->spy->passwords );
		$fresh = Resource::find( $r->id() );
		$this->assertFalse( MailAccess::has( $fresh, 'anna@own.example' ), 'The kept copy is wiped.' );
		$this->assertFalse( MailAccess::revealable( $fresh, 'anna@own.example' ) );
		$this->assertNull( MailAccess::take( $fresh, 'anna@own.example', (int) $fresh->license()['user_id'] ) );
		$this->assertStringNotContainsString( self::PW, (string) wp_json_encode( $fresh->get( MailAccess::META ) ) );
		$this->assertSame( array(), $this->leaks( $r, self::PW ), 'Not in the result.' );
		$result = (array) $fresh->get( Jobs::RESULT );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'The mailbox is ready. Sign in with the password you chose.', $result['text'] );

		$box = array_values( array_filter( Mailboxes::all( $fresh ), static fn( $b ) => 'anna@own.example' === $b['email'] ) );
		$this->assertFalse( $box[0]['reveal'] );
	}

	public function test_a_replay_of_create_after_the_wipe_is_done_and_sets_nothing_again(): void {
		list( $r, $user ) = $this->domain( 'replay2.example' );
		$this->create( $r, $user, array( 'local' => 'bob', 'password' => self::PW ) );
		$this->run_jobs();
		$this->assertCount( 1, $this->spy->passwords );

		$again = Mailboxes::run_create( Resource::find( $r->id() ), array( 'email' => 'bob@replay2.example', 'name' => 'bob', 'quota' => 100, 'own' => true ) );
		$this->assertTrue( $again->is_done(), $again->error() );
		$this->assertSame( 'The mailbox is ready. Sign in with the password you chose.', $again->meta()[ Jobs::TEXT ] );
		$this->assertCount( 1, $this->spy->passwords, 'Nothing was added again.' );
	}

	public function test_a_lost_answer_before_the_wipe_sets_the_same_password_again(): void {
		list( $r, $user ) = $this->domain( 'lost.example' );
		$plan = Mailboxes::plan_create( $r, 'cy' );
		$this->assertTrue( Mailboxes::reserve( $r, $plan['email'], self::PW ) );
		$plan['own'] = true;

		// The first run set it on the server and died before the wipe.
		MailAccess::mark_sent( Resource::find( $r->id() ), $plan['email'] );
		$this->spy->add_mailbox( array( 'local_part' => 'cy', 'domain' => 'lost.example', 'quota' => '100', 'password' => self::PW, 'password2' => self::PW ) );
		$replay = Mailboxes::run_create( Resource::find( $r->id() ), $plan );
		$this->assertTrue( $replay->is_done(), $replay->error() );
		$this->assertFalse( MailAccess::has( Resource::find( $r->id() ), $plan['email'] ) );
	}

	public function test_a_typed_password_that_the_job_could_not_finish_is_forgotten(): void {
		list( $r, $user ) = $this->domain( 'undo.example' );
		$plan = Mailboxes::plan_create( $r, 'dee' );
		$plan['own'] = true;
		Mailboxes::reserve( $r, $plan['email'], self::PW );
		do_action( 'beltoft_services_action_failed', Resource::find( $r->id() ), Mailboxes::CREATE, $plan, 'failed' );
		$this->assertFalse( MailAccess::has( Resource::find( $r->id() ), $plan['email'] ) );
	}

	public function test_a_new_password_typed_needs_the_code_sets_exactly_it_and_wipes_the_copy(): void {
		list( $r, $user ) = $this->domain( 'chg.example' );
		$this->create( $r, $user, array( 'local' => 'eve' ) );
		$this->run_jobs();
		$this->spy->passwords = array();

		$no_code = $this->call( $r->id() . '/mail/mailboxes/eve%40chg.example/password', $user, array( 'password' => self::PW ) );
		$this->assertSame( 403, $no_code->get_status() );
		$wrong = $this->call( $r->id() . '/mail/mailboxes/eve%40chg.example/password', $user, array( 'password' => self::PW, 'step_code' => '000000' ) );
		$this->assertSame( 403, $wrong->get_status() );
		$this->assertSame( array(), $this->spy->passwords );
		$this->assertSame( array(), $this->leaks( $r, self::PW ) );

		$done = $this->change( $r, $user, 'eve@chg.example', array( 'password' => self::PW, 'password_again' => self::PW ) );
		$this->assertSame( 202, $done->get_status() );
		$this->assertFalse( $done->get_data()['reveal'] );
		$this->assertSame( array(), $this->leaks( $r, self::PW ) );
		$this->run_jobs();

		$this->assertSame( array( array( 'edit', self::PW, self::PW ) ), $this->spy->passwords );
		$fresh = Resource::find( $r->id() );
		$this->assertFalse( MailAccess::has( $fresh, 'eve@chg.example' ) );
		$this->assertSame( array(), $this->leaks( $r, self::PW ) );
		$result = (array) $fresh->get( Jobs::RESULT );
		$this->assertSame( 'The new password is set. The old one no longer works.', $result['text'] );

	}

	public function test_the_emailed_code_is_used_up_by_a_typed_change_and_kept_for_a_generated_one(): void {
		remove_all_filters( 'beltoft_services_step_up_verify' );
		$user = self::factory()->user->create( array( 'user_email' => 'christian.beltoft+mop' . wp_generate_password( 8, false ) . '@gmail.com' ) );
		$r    = $this->ready_mail( 'code.example', array(), $user );
		$this->domain_client( 'code.example' );
		$this->create( $r, $user, array( 'local' => 'jo' ) );
		$this->run_jobs();

		$code = function () use ( $user ): string {
			$this->mails = array();
			$this->assertTrue( \BeltoftServices\StepUp::send( $user, 'mail_password' ) );
			$this->assertSame( 1, preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( (string) end( $this->mails )['message'] ), $m ) );

			return $m[1];
		};

		// A generated password keeps the code: the reveal that follows uses it up.
		$one = $code();
		$this->assertSame( 202, $this->call( $r->id() . '/mail/mailboxes/jo%40code.example/password', $user, array( 'step_code' => $one ) )->get_status() );
		$this->run_jobs();
		$this->assertSame( 202, $this->call( $r->id() . '/mail/mailboxes/jo%40code.example/password', $user, array( 'step_code' => $one ) )->get_status(), 'Not used up yet.' );
		$this->run_jobs();

		// A typed password shows nothing later, so the code is used up with the change.
		$two = $code();
		$this->assertSame( 202, $this->change( $r, $user, 'jo@code.example', array( 'step_code' => $two, 'password' => self::PW ) )->get_status() );
		$this->run_jobs();
		$this->assertSame( 403, $this->change( $r, $user, 'jo@code.example', array( 'step_code' => $two, 'password' => 'Other-pass-4321' ) )->get_status() );
	}

	private function domain_client( string $name ): void {
		$spy = new FakeMailcowClient();
		$spy->add_domain( array( 'domain' => $name, 'mailboxes' => '5', 'maxquota' => '10240' ) );
		remove_all_filters( 'beltoft_services_mail_client' );
		add_filter( 'beltoft_services_mail_client', static fn() => $spy );
	}

	public function test_a_replay_of_the_password_job_after_the_wipe_is_done(): void {
		list( $r, $user ) = $this->domain( 'chg2.example' );
		$this->create( $r, $user, array( 'local' => 'fay' ) );
		$this->run_jobs();
		$this->change( $r, $user, 'fay@chg2.example', array( 'password' => self::PW ) );
		$this->run_jobs();
		$count = count( $this->spy->passwords );

		$replay = Mailboxes::run_password( Resource::find( $r->id() ), array( 'email' => 'fay@chg2.example', 'own' => true ) );
		$this->assertTrue( $replay->is_done(), $replay->error() );
		$this->assertCount( $count, $this->spy->passwords );

		// A replay before the wipe (the job died after the set): the same password is set again.
		Mailboxes::reserve( Resource::find( $r->id() ), 'fay@chg2.example', self::PW );
		$before = Mailboxes::run_password( Resource::find( $r->id() ), array( 'email' => 'fay@chg2.example', 'own' => true ) );
		$this->assertTrue( $before->is_done() );
		$this->assertSame( array( 'edit', self::PW, self::PW ), end( $this->spy->passwords ) );
	}

	public function test_without_a_password_nothing_changes_random_and_shown_once(): void {
		list( $r, $user ) = $this->domain( 'old.example' );
		$made = $this->create( $r, $user, array( 'local' => 'gus' ) );
		$this->assertTrue( $made->get_data()['reveal'] );
		$this->run_jobs();
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( MailAccess::revealable( $fresh, 'gus@old.example' ) );
		$this->assertSame( 24, strlen( $this->spy->passwords[0][1] ) );
		$this->assertStringContainsString( 'Show the password once', (string) ( (array) $fresh->get( Jobs::RESULT ) )['text'] );

		$this->spy->passwords = array();
		$reset = $this->change( $r, $user, 'gus@old.example', array() );
		$this->assertTrue( $reset->get_data()['reveal'] );
		$this->run_jobs();
		$this->assertTrue( MailAccess::revealable( Resource::find( $r->id() ), 'gus@old.example' ) );
		$this->assertSame( 24, strlen( $this->spy->passwords[0][1] ) );
	}

	public function test_a_weak_or_mismatched_password_is_refused_and_nothing_is_queued_or_kept(): void {
		list( $r, $user ) = $this->domain( 'weak.example' );

		foreach ( array(
			array( 'password' => 'short1' ),
			array( 'password' => 'nodigitsinthispassword' ),
			array( 'password' => 'hank-is-here-1234' ),
			array( 'password' => self::PW, 'password_again' => self::PW . 'x' ),
					) as $body ) {
			$bad = $this->create( $r, $user, $body + array( 'local' => 'hank' ) );
			$this->assertSame( 422, $bad->get_status(), (string) wp_json_encode( $bad->get_data() ) );
			$this->assertStringNotContainsString( self::PW, (string) wp_json_encode( $bad->get_data() ) );
		}

		$this->assertFalse( MailAccess::has( Resource::find( $r->id() ), 'hank@weak.example' ) );
		$this->assertCount( 0, $this->mailcow_calls_for( 'hank@weak.example' ) );

		// The same rules on a new password: refused after the code, nothing kept.
		$this->create( $r, $user, array( 'local' => 'ivana' ) );
		$this->run_jobs();
		$bad = $this->change( $r, $user, 'ivana@weak.example', array( 'password' => 'ivana-pass-12345' ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertNull( Resource::find( $r->id() )->get( \BeltoftServices\Progress::MARKER ) );
	}

	public function test_the_system_mailbox_of_a_hosting_site_keeps_its_refusal(): void {
		list( $r, $user ) = $this->domain( 'sys.example' );
		$this->assertTrue( $r->set( array( \BeltoftServices\Mail\MailMeta::SYSTEM => 'system@sys.example' ) ) );
		$this->assertWPError( Mailboxes::plan_password( Resource::find( $r->id() ), 'system@sys.example' ) );

		$refused = $this->change( Resource::find( $r->id() ), $user, 'system@sys.example', array( 'password' => self::PW ) );
		$this->assertSame( 422, $refused->get_status() );
		$this->assertSame( array(), $this->spy->passwords );
	}

	public function test_the_page_has_the_password_fields_and_the_new_password_form_in_its_own_disclosure(): void {
		$root = dirname( __DIR__, 2 ) . '/';
		$tpl  = (string) file_get_contents( $root . 'templates/account/mail.php' );
		$js   = (string) file_get_contents( $root . 'assets/mail.js' );

		$this->assertSame( 4, substr_count( $tpl, 'type="password"' ), 'Password and repeat, on the new mailbox and on a row.' );
		$this->assertSame( 4, substr_count( $tpl, 'autocomplete="new-password"' ) );
		$this->assertMatchesRegularExpression( '/<details class="bs-confirm beltoft-services-mail__newpw">/', $tpl );
		$this->assertStringNotContainsString( 'newpw beltoft-services-vm__more', $tpl );
		$this->assertStringContainsString( 'password_again', $js );
		$this->assertStringContainsString( 'passwordProblem', $js );
	}

	/** @return array<int, mixed> The calls of the spy that name an address. */
	private function mailcow_calls_for( string $email ): array {
		return array_values( array_filter( $this->spy->calls_of( 'add_mailbox' ), static fn( $c ) => str_contains( (string) wp_json_encode( $c ), $email ) ) );
	}
}
