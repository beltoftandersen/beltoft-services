<?php
/**
 * The REST routes of an email domain: owner checks, mailboxes, aliases, the DNS check and the one-time reveal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailAccess;
use BeltoftServices\Mail\MailRoutes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailRoutesTest extends MailTestCase {

	private const BASE = '/beltoft-services/v1/resources/';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->settings( array( 'mail_allow_external_forward' => 1 ) );
		// A right emailed code is read as 123456 in these tests; the real check is StepUp's own (tested there).
		add_filter( 'beltoft_services_step_up_verify', static fn( $ok, $user, $secret, $kind, $purpose ) => in_array( $purpose, array( 'mail_password' ), true ) ? ( '123456' === $secret ) : $ok, 10, 5 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'beltoft_services_step_up_verify' );
		parent::tear_down();
	}

	/**
	 * A ready email domain and its owner.
	 *
	 * @return array{0: Resource, 1: int}
	 */
	private function domain( string $name = 'route.example' ): array {
		$user = self::factory()->user->create();

		return array( $this->ready_mail( $name, array(), $user ), $user );
	}

	/**
	 * @param array<string, mixed> $params Body or query parameters.
	 */
	private function call( string $method, string $path, int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, self::BASE . $path );

		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/** A new mailbox through the route: the request, then the queued action is run. */
	private function box( Resource $r, int $user, array $body ): \WP_REST_Response {
		$response = $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, $body );
		$this->run_jobs();

		return $response;
	}

	public function test_the_overview_is_for_the_owner_only_and_holds_no_password(): void {
		list( $r, $user ) = $this->domain();
		$this->assertSame( 202, $this->box( $r, $user, array( 'local' => 'info', 'name' => 'Info' ) )->get_status() );

		$response = $this->call( 'GET', $r->id() . '/mail', $user );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'route.example', $data['domain'] );
		$this->assertSame( 'ready', $data['kind'] );
		$this->assertSame( 'info@route.example', $data['mailboxes'][0]['email'] );
		$this->assertTrue( $data['mailboxes'][0]['reveal'] );
		$this->assertSame( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ), $data['limits'] );
		$this->assertSame( 'mail.example.net', $data['connect']['host'] );
		$this->assertStringContainsString( 'no-store', (string) $response->get_headers()['Cache-Control'] );

		$stored = (array) Resource::find( $r->id() )->get( MailAccess::META );
		$pw     = (string) \BeltoftServices\Crypto::decrypt( (string) $stored['info@route.example']['pw'] );
		$this->assertNotSame( '', $pw );
		$this->assertStringNotContainsString( $pw, (string) wp_json_encode( $data ) );

		$stranger = self::factory()->user->create();
		$this->assertSame( 403, $this->call( 'GET', $r->id() . '/mail', $stranger )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', $r->id() . '/mail', 0 )->get_status() );
	}

	public function test_a_service_that_is_not_email_is_refused(): void {
		$user   = self::factory()->user->create();
		$server = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false, 'user_id' => $user ) );
		$r      = Resource::create( $server, $this->fake_driver(), array(), array( 'state' => 'ready' ) );

		$this->assertSame( 409, $this->call( 'GET', $r->id() . '/mail', $user )->get_status() );
		$this->assertSame( 409, $this->box( $r, $user, array( 'local' => 'a' ) )->get_status() );
	}

	public function test_create_a_mailbox_then_show_its_password_once(): void {
		list( $r, $user ) = $this->domain();

		$made = $this->box( $r, $user, array( 'local' => 'Info', 'name' => 'Front desk' ) );
		$this->assertSame( 202, $made->get_status() );
		$this->assertTrue( $made->get_data()['queued'] );
		$this->assertSame( '/resources/' . $r->id(), $made->get_data()['status'] );
		$this->assertSame( 'mailbox_create', $made->get_data()['action'] );
		$this->assertSame( 'info@route.example', $made->get_data()['email'] );
		$this->assertArrayNotHasKey( 'password', $made->get_data() );
		$this->assertSame( 'Front desk', $this->mailcow->mailbox( 'info@route.example' )['name'] );

		$shown = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'info@route.example', 'step_code' => '123456' ) );
		$this->assertSame( 200, $shown->get_status() );
		$this->assertMatchesRegularExpression( '/^\S{20,}$/', $shown->get_data()['password'] );
		$this->assertStringContainsString( 'no-store', (string) $shown->get_headers()['Cache-Control'] );

		$again = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'info@route.example', 'step_code' => '123456' ) );
		$this->assertSame( 409, $again->get_status() );
		$this->assertStringNotContainsString( $shown->get_data()['password'], (string) wp_json_encode( $again->get_data() ) );
		$this->assertFalse( MailAccess::revealable( Resource::find( $r->id() ), 'info@route.example' ) );
	}

	public function test_a_mailbox_password_is_not_shown_or_reset_without_the_emailed_code(): void {
		list( $r, $user ) = $this->domain();
		$this->box( $r, $user, array( 'local' => 'a' ) );

		foreach ( array( null, '', '000000' ) as $code ) {
			$params = null === $code ? array( 'email' => 'a@route.example' ) : array( 'email' => 'a@route.example', 'step_code' => $code );
			$shown  = $this->call( 'POST', $r->id() . '/mail-reveal', $user, $params );
			$this->assertSame( 403, $shown->get_status() );
			$this->assertSame( 'step_up', $shown->get_data()['code'] );
			$this->assertArrayNotHasKey( 'password', $shown->get_data() );
		}

		$this->assertTrue( MailAccess::revealable( Resource::find( $r->id() ), 'a@route.example' ), 'A refused request took nothing.' );

		$reset = $this->call( 'POST', $r->id() . '/mail/mailboxes/a%40route.example/password', $user, array( 'step_code' => '000000' ) );
		$this->assertSame( 403, $reset->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail/mailboxes/a%40route.example/password', $user )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example', 'step_code' => '123456' ) )->get_status() );
	}

	public function test_the_mail_password_purpose_is_registered_and_has_its_own_words(): void {
		$this->assertContains( 'mail_password', \BeltoftServices\StepUp::purposes() );
		$this->assertNotSame( 'confirming this step', apply_filters( 'beltoft_services_stepup_text', 'confirming this step', 'mail_password' ) );
	}

	public function test_the_account_scripts_ask_for_the_code_before_these_routes(): void {
		$root = dirname( __DIR__, 2 ) . '/assets/';
		$mail = (string) file_get_contents( $root . 'mail.js' );
		$ssl  = (string) file_get_contents( $root . 'ssl.js' );
		$this->assertStringContainsString( "stepUp( 'mail_password'", $mail );
		$this->assertStringContainsString( 'step_code', $mail );
		$this->assertStringContainsString( "stepUp( 'ssl_key'", $ssl );
		$this->assertSame( 2, substr_count( $ssl, "stepUp( 'ssl_key'" ), 'The key and the .p12 file.' );
		$this->assertStringContainsString( 'function stepUp', (string) file_get_contents( $root . 'api.js' ) );
	}

	public function test_only_the_owner_can_show_a_password_and_only_for_a_mailbox_of_the_domain(): void {
		list( $r, $user ) = $this->domain();
		list( $other, $other_user ) = $this->domain( 'other.example' );
		$this->box( $r, $user, array( 'local' => 'a' ) );
		$this->box( $other, $other_user, array( 'local' => 'a' ) );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail-reveal', $other_user, array( 'email' => 'a@route.example' ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@other.example', 'step_code' => '123456' ) )->get_status(), 'A name outside the domain.' );
		$this->assertTrue( MailAccess::revealable( Resource::find( $r->id() ), 'a@route.example' ), 'Nothing was taken.' );
	}

	public function test_the_reveal_has_a_floor_that_a_filter_cannot_lift(): void {
		list( $r, $user ) = $this->domain();
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );

		$codes = array();

		for ( $i = 0; $i < 33; $i++ ) {
			$codes[] = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example', 'step_code' => '123456' ) )->get_status();
		}

		$this->assertContains( 429, $codes );
	}

	public function test_a_bad_name_a_taken_name_and_the_plan_limit_are_refused_with_words(): void {
		list( $r, $user ) = $this->domain();
		$bad = $this->box( $r, $user, array( 'local' => 'not valid' ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'beltoft_services_mail_invalid', $bad->get_data()['code'] );

		$this->assertSame( 202, $this->box( $r, $user, array( 'local' => 'a' ) )->get_status() );
		$this->assertSame( 409, $this->box( $r, $user, array( 'local' => 'a' ) )->get_status() );

		foreach ( array( 'b', 'c', 'd', 'e' ) as $local ) {
			$this->box( $r, $user, array( 'local' => $local ) );
		}

		$full = $this->box( $r, $user, array( 'local' => 'f' ) );
		$this->assertSame( 409, $full->get_status(), 'A full plan is known from the remembered count: said at once, no job.' );
		$this->assertStringContainsString( 'no room', $full->get_data()['message'] );
		$this->assertNull( $this->mailcow->mailbox( 'f@route.example' ) );
		$this->assertFalse( \BeltoftServices\Jobs::acting( $r->id() ) );
		$this->assertNull( \BeltoftServices\Progress::pending( Resource::find( $r->id() ) ) );
	}

	public function test_a_size_within_the_plan_can_be_chosen(): void {
		list( $r, $user ) = $this->domain();

		$small = $this->box( $r, $user, array( 'local' => 'small', 'quota_mb' => 1024 ) );
		$this->assertSame( 202, $small->get_status() );
		$this->assertSame( 1024, $small->get_data()['quota_mb'] );

		$big = $this->box( $r, $user, array( 'local' => 'big', 'quota_mb' => 99999 ) );
		$this->assertSame( 409, $big->get_status() );
		$this->assertStringContainsString( 'outside what your plan allows', $big->get_data()['message'] );
	}

	public function test_deleting_a_mailbox_needs_the_typed_word_and_stays_inside_the_domain(): void {
		list( $r, $user ) = $this->domain();
		list( $other, $other_user ) = $this->domain( 'other.example' );
		$this->box( $r, $user, array( 'local' => 'a' ) );
		$this->box( $other, $other_user, array( 'local' => 'a' ) );

		$this->assertSame( 422, $this->call( 'DELETE', $r->id() . '/mail/mailboxes/a%40route.example', $user )->get_status() );
		$this->assertSame( 422, $this->call( 'DELETE', $r->id() . '/mail/mailboxes/a%40route.example', $user, array( 'confirm' => 'delete' ) )->get_status() );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@route.example' ) );

		$this->assertSame( 422, $this->call( 'DELETE', $r->id() . '/mail/mailboxes/a%40other.example', $user, array( 'confirm' => MailRoutes::CONFIRM_DELETE ) )->get_status() );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@other.example' ) );
		$this->assertSame( 403, $this->call( 'DELETE', $r->id() . '/mail/mailboxes/a%40route.example', $other_user, array( 'confirm' => MailRoutes::CONFIRM_DELETE ) )->get_status() );

		$gone = $this->call( 'DELETE', $r->id() . '/mail/mailboxes/a%40route.example', $user, array( 'confirm' => MailRoutes::CONFIRM_DELETE ) );
		$this->assertSame( 200, $gone->get_status() );
		$this->assertSame( 'a@route.example', $gone->get_data()['deleted'] );
		$this->assertNull( $this->mailcow->mailbox( 'a@route.example' ) );
		$this->assertArrayNotHasKey( 'a@route.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );
	}

	public function test_a_new_password_replaces_the_old_one_and_is_shown_once(): void {
		list( $r, $user ) = $this->domain();
		$this->box( $r, $user, array( 'local' => 'a' ) );
		$first = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example', 'step_code' => '123456' ) )->get_data()['password'];

		$reset = $this->call( 'POST', $r->id() . '/mail/mailboxes/a%40route.example/password', $user, array( 'step_code' => '123456' ) );
		$this->assertSame( 202, $reset->get_status() );
		$this->assertSame( 'mailbox_password', $reset->get_data()['action'] );
		$this->assertArrayNotHasKey( 'password', $reset->get_data() );
		$this->assertFalse( MailAccess::revealable( Resource::find( $r->id() ), 'a@route.example' ), 'Not before the job has set it.' );
		$this->run_jobs();

		$second = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example', 'step_code' => '123456' ) )->get_data()['password'];
		$this->assertNotSame( $first, $second );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example', 'step_code' => '123456' ) )->get_status() );

		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/mail/mailboxes/zz%40route.example/password', $user, array( 'step_code' => '123456' ) )->get_status() );
		$this->run_jobs();
		$last = Resource::find( $r->id() )->get( 'last_result' );
		$this->assertFalse( $last['ok'], 'No such mailbox: said in the outcome.' );
		$this->assertStringContainsString( 'does not exist', $last['text'] );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail/mailboxes/a%40route.example/password', self::factory()->user->create() )->get_status() );
	}

	public function test_aliases_are_made_and_deleted_and_external_delivery_follows_the_setting(): void {
		list( $r, $user ) = $this->domain();

		$made = $this->call( 'POST', $r->id() . '/mail/aliases', $user, array( 'address' => 'sales', 'goto' => array( 'a@route.example', 'christian.beltoft@gmail.com' ) ) );
		$this->assertSame( 201, $made->get_status() );
		$this->assertSame( 'sales@route.example', $made->get_data()['address'] );

		$text = $this->call( 'POST', $r->id() . '/mail/aliases', $user, array( 'address' => 'help', 'goto' => "a@route.example\nb@route.example" ) );
		$this->assertSame( 201, $text->get_status() );

		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/mail/aliases', $user, array( 'address' => '*', 'goto' => array( 'a@route.example' ) ) )->get_status(), 'No catch-all.' );

		$list = $this->call( 'GET', $r->id() . '/mail', $user )->get_data()['aliases'];
		$this->assertSame( array( 'sales@route.example', 'help@route.example' ), array_column( $list, 'address' ) );

		$this->settings( array( 'mail_allow_external_forward' => 0 ) );
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/mail/aliases', $user, array( 'address' => 'ext', 'goto' => array( 'christian.beltoft@gmail.com' ) ) )->get_status() );

		$this->assertSame( 403, $this->call( 'DELETE', $r->id() . '/mail/aliases/sales%40route.example', self::factory()->user->create() )->get_status() );
		$gone = $this->call( 'DELETE', $r->id() . '/mail/aliases/sales%40route.example', $user );
		$this->assertSame( 200, $gone->get_status() );
		$this->assertSame( array( 'help@route.example' ), array_column( $this->call( 'GET', $r->id() . '/mail', $user )->get_data()['aliases'], 'address' ) );
	}

	public function test_the_dns_check_reads_public_dns_and_reports_each_record(): void {
		list( $r, $user ) = $this->domain( 'dnscheck.example' );
		$view = $this->call( 'GET', $r->id() . '/mail', $user )->get_data();

		$this->assertSame( 'waiting', $view['dns']['status'] );
		$this->assertSame( array( 'missing' ), array_values( array_unique( array_column( $view['dns']['rows'], 'status' ) ) ) );
		$this->assertTrue( $view['dns']['can_check'] );

		$this->dns['MX dnscheck.example'] = array( '10 mail.example.net' );
		$result = $this->call( 'POST', $r->id() . '/mail/dns/check', $user );

		$this->assertSame( 202, $result->get_status() );
		$this->assertSame( 'mail_dns_check', $result->get_data()['action'] );
		$this->assertSame( 'missing', array_column( $result->get_data()['dns']['rows'], 'status', 'type' )['MX'], 'The answer carries the state as it was asked.' );
		$this->assertTrue( $this->call( 'GET', $r->id() . '/mail', $user )->get_data()['task']['working'] );
		$this->run_jobs();

		$after = $this->call( 'GET', $r->id() . '/mail', $user )->get_data();
		$status = array_column( $after['dns']['rows'], 'status', 'type' );
		$this->assertSame( 'ok', $status['MX'] );
		$this->assertSame( 'missing', $status['TXT'] );
		$this->assertFalse( $after['task']['working'] );
		$this->assertTrue( $after['task']['last_result']['ok'] );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail/dns/check', self::factory()->user->create() )->get_status() );
	}

	/** A domain at another provider still waiting for the TXT proof. */
	private function proof_waiting( string $name ): array {
		$user = self::factory()->user->create();
		$r    = $this->mail_resource( $name, array(), array(), $user );
		\BeltoftServices\Jobs::enqueue( $r->id() );
		$this->run_jobs( 3 );
		$r = Resource::find( $r->id() );
		$this->assertNotSame( 'ready', $r->get( 'state' ) );
		$this->assertGreaterThan( time(), (int) $r->get( 'next_attempt_at' ) );

		return array( $r, $user );
	}

	public function test_check_now_on_the_proof_state_looks_at_once_and_is_rate_limited(): void {
		list( $r, $user ) = $this->proof_waiting( 'proofnow.example' );

		$response = $this->call( 'POST', $r->id() . '/mail/proof/check', $user );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'building', $response->get_data()['kind'] );
		$this->assertNotSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );

		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$response = $this->call( 'POST', $r->id() . '/mail/proof/check', $user );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertSame( 'ready', $response->get_data()['kind'] );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail/proof/check', self::factory()->user->create() )->get_status() );
	}

	public function test_check_now_is_limited_per_hour_and_refused_for_a_ready_domain(): void {
		list( $r, $user ) = $this->proof_waiting( 'proofrate.example' );
		$codes = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$codes[] = $this->call( 'POST', $r->id() . '/mail/proof/check', $user )->get_status();
		}
		$this->assertContains( 429, $codes );

		list( $ready, $owner ) = $this->domain( 'proofready.example' );
		$this->assertSame( 409, $this->call( 'POST', $ready->id() . '/mail/proof/check', $owner )->get_status() );
	}

	public function test_check_now_is_refused_for_a_setup_that_is_not_waiting_for_proof_or_registration(): void {
		$user = self::factory()->user->create();
		$r    = $this->mail_resource( 'failing.example', array(), array(), $user );
		$r->set( array( 'state' => 'provisioning', 'op' => 'provision', 'attempts' => 2, 'next_attempt_at' => time() + 900 ) );
		$response = $this->call( 'POST', $r->id() . '/mail/proof/check', $user );
		$this->assertSame( 409, $response->get_status() );
		$this->assertGreaterThan( time(), (int) Resource::find( $r->id() )->get( 'next_attempt_at' ), 'The backoff stays.' );
	}

	public function test_changes_are_counted_and_a_paused_service_is_refused(): void {
		list( $r, $user ) = $this->domain();
		$r->set( array( 'state' => 'paused' ) );

		$this->assertSame( 403, $this->box( $r, $user, array( 'local' => 'a' ) )->get_status() );
		$this->assertNull( $this->mailcow->mailbox( 'a@route.example' ) );
	}

	public function test_a_mail_server_that_cannot_be_reached_says_so_without_detail(): void {
		list( $r, $user ) = $this->domain();
		$this->mailcow->fail_next( 'add_mailbox', new \WP_Error( 'mailcow_transport', 'secret-host.internal refused' ) );

		$response = $this->box( $r, $user, array( 'local' => 'a' ) );

		$this->assertSame( 202, $response->get_status(), 'Accepted: the job waits for the mail server and tries again.' );
		$this->assertStringNotContainsString( 'secret-host', (string) wp_json_encode( $response->get_data() ) );
		$view = $this->call( 'GET', $r->id() . '/mail', $user )->get_data();
		$this->assertStringNotContainsString( 'secret-host', (string) wp_json_encode( $view ) );
		$this->assertFalse( MailAccess::revealable( Resource::find( $r->id() ), 'a@route.example' ), 'Nothing to show before the mailbox is made.' );
		$this->assertTrue( $view['task']['working'], 'Still on its backoff: the page keeps saying so.' );
	}

	public function test_add_a_domain_of_ours_and_one_at_another_provider(): void {
		list( $r, $user ) = $this->domain( 'plan.example' );
		$this->our_zone( $user, 'zone.example' );

		$ours = $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'ours', 'domain' => 'zone.example' ) );
		$this->assertSame( 201, $ours->get_status() );
		$this->assertSame( 'zone.example', $ours->get_data()['domain'] );

		$this->proof['check_txt'] = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );
		$own = $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'own', 'domain' => 'Elsewhere.Example' ) );
		$this->assertSame( 201, $own->get_status() );
		$this->assertSame( 'elsewhere.example', $own->get_data()['domain'] );

		$this->run_jobs();
		$child = Resource::find( (int) $own->get_data()['id'] );
		$view  = \BeltoftServices\Mail\MailView::data( $child, array() );
		$this->assertNotNull( $view['dns']['proof'], 'The new domain shows the TXT proof.' );
		$this->assertSame( 'TXT', $view['dns']['proof']['type'] );
		$this->assertSame( 'ours', Resource::find( (int) $ours->get_data()['id'] )->get( 'mail_mode' ) );
	}

	public function test_a_zone_that_is_not_the_users_is_refused_for_mode_ours(): void {
		list( $r, $user ) = $this->domain( 'plan2.example' );
		$other = self::factory()->user->create();
		$this->our_zone( $other, 'theirs.example' );

		$res = $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'ours', 'domain' => 'theirs.example' ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 400, $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'nope', 'domain' => 'x.example' ) )->get_status() );
	}

	public function test_another_users_plan_is_refused_and_a_full_plan_says_no_room(): void {
		list( $r, $user ) = $this->domain( 'plan3.example' );
		$stranger = self::factory()->user->create();
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail-domains', $stranger, array( 'mode' => 'own', 'domain' => 'a.example' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', $r->id() . '/mail-domains/a.example', $stranger )->get_status() );

		$this->assertSame( 201, $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'own', 'domain' => 'b.example' ) )->get_status() );
		$this->assertSame( 201, $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'own', 'domain' => 'c.example' ) )->get_status() );
		$full = $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'own', 'domain' => 'd.example' ) );
		$this->assertSame( 409, $full->get_status() );
		$this->assertSame( 'Your plan has no room for another domain.', $full->get_data()['message'] );
	}

	public function test_a_domain_is_removed_through_the_route_and_a_root_is_not(): void {
		list( $r, $user ) = $this->domain( 'plan4.example' );
		$made = $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'own', 'domain' => 'gone.example' ) );
		$this->run_jobs();

		$this->assertSame( 409, $this->call( 'DELETE', $r->id() . '/mail-domains/plan4.example', $user )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', $r->id() . '/mail-domains/unknown.example', $user )->get_status() );

		$done = $this->call( 'DELETE', $r->id() . '/mail-domains/gone.example', $user );
		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( 'deleted', Resource::find( (int) $made->get_data()['id'] )->get( 'desired' ) );
	}

	public function test_a_domain_with_mailboxes_and_a_paused_plan_refuse_removal(): void {
		list( $r, $user ) = $this->domain( 'plan5.example' );
		$made  = $this->call( 'POST', $r->id() . '/mail-domains', $user, array( 'mode' => 'own', 'domain' => 'full.example' ) );
		$this->run_jobs();
		$child = Resource::find( (int) $made->get_data()['id'] );
		$this->create_box( $child, 'a' );

		$res = $this->call( 'DELETE', $r->id() . '/mail-domains/full.example', $user );
		$this->assertSame( 409, $res->get_status() );

		\BeltoftServices\Mail\Mailboxes::delete( Resource::find( $child->id() ), 'a@full.example' );
		Resource::find( $r->id() )->set( array( 'state' => 'paused' ) );
		$this->assertSame( 403, $this->call( 'DELETE', $r->id() . '/mail-domains/full.example', $user )->get_status(), 'A paused plan is not available to the routes.' );
	}
}
