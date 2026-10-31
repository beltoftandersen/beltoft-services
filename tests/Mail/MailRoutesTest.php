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
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
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

	public function test_the_overview_is_for_the_owner_only_and_holds_no_password(): void {
		list( $r, $user ) = $this->domain();
		$this->assertSame( 201, $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'info', 'name' => 'Info' ) )->get_status() );

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
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) )->get_status() );
	}

	public function test_create_a_mailbox_then_show_its_password_once(): void {
		list( $r, $user ) = $this->domain();

		$made = $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'Info', 'name' => 'Front desk' ) );
		$this->assertSame( 201, $made->get_status() );
		$this->assertSame( 'info@route.example', $made->get_data()['email'] );
		$this->assertArrayNotHasKey( 'password', $made->get_data() );
		$this->assertSame( 'Front desk', $this->mailcow->mailbox( 'info@route.example' )['name'] );

		$shown = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'info@route.example' ) );
		$this->assertSame( 200, $shown->get_status() );
		$this->assertMatchesRegularExpression( '/^\S{20,}$/', $shown->get_data()['password'] );
		$this->assertStringContainsString( 'no-store', (string) $shown->get_headers()['Cache-Control'] );

		$again = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'info@route.example' ) );
		$this->assertSame( 409, $again->get_status() );
		$this->assertStringNotContainsString( $shown->get_data()['password'], (string) wp_json_encode( $again->get_data() ) );
		$this->assertFalse( MailAccess::revealable( Resource::find( $r->id() ), 'info@route.example' ) );
	}

	public function test_only_the_owner_can_show_a_password_and_only_for_a_mailbox_of_the_domain(): void {
		list( $r, $user ) = $this->domain();
		list( $other, $other_user ) = $this->domain( 'other.example' );
		$this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) );
		$this->call( 'POST', $other->id() . '/mail/mailboxes', $other_user, array( 'local' => 'a' ) );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail-reveal', $other_user, array( 'email' => 'a@route.example' ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@other.example' ) )->get_status(), 'A name outside the domain.' );
		$this->assertTrue( MailAccess::revealable( Resource::find( $r->id() ), 'a@route.example' ), 'Nothing was taken.' );
	}

	public function test_the_reveal_has_a_floor_that_a_filter_cannot_lift(): void {
		list( $r, $user ) = $this->domain();
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );

		$codes = array();

		for ( $i = 0; $i < 33; $i++ ) {
			$codes[] = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example' ) )->get_status();
		}

		$this->assertContains( 429, $codes );
	}

	public function test_a_bad_name_a_taken_name_and_the_plan_limit_are_refused_with_words(): void {
		list( $r, $user ) = $this->domain();
		$bad = $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'not valid' ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'beltoft_services_mail_invalid', $bad->get_data()['code'] );

		$this->assertSame( 201, $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) )->get_status() );

		foreach ( array( 'b', 'c', 'd', 'e' ) as $local ) {
			$this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => $local ) );
		}

		$full = $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'f' ) );
		$this->assertSame( 409, $full->get_status() );
		$this->assertStringContainsString( 'no room', $full->get_data()['message'] );
	}

	public function test_a_size_within_the_plan_can_be_chosen(): void {
		list( $r, $user ) = $this->domain();

		$small = $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'small', 'quota_mb' => 1024 ) );
		$this->assertSame( 201, $small->get_status() );
		$this->assertSame( 1024, $small->get_data()['quota_mb'] );

		$big = $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'big', 'quota_mb' => 99999 ) );
		$this->assertSame( 409, $big->get_status() );
		$this->assertStringContainsString( 'outside what your plan allows', $big->get_data()['message'] );
	}

	public function test_deleting_a_mailbox_needs_the_typed_word_and_stays_inside_the_domain(): void {
		list( $r, $user ) = $this->domain();
		list( $other, $other_user ) = $this->domain( 'other.example' );
		$this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) );
		$this->call( 'POST', $other->id() . '/mail/mailboxes', $other_user, array( 'local' => 'a' ) );

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
		$this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) );
		$first = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example' ) )->get_data()['password'];

		$reset = $this->call( 'POST', $r->id() . '/mail/mailboxes/a%40route.example/password', $user );
		$this->assertSame( 200, $reset->get_status() );
		$this->assertArrayNotHasKey( 'password', $reset->get_data() );

		$second = $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example' ) )->get_data()['password'];
		$this->assertNotSame( $first, $second );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail-reveal', $user, array( 'email' => 'a@route.example' ) )->get_status() );

		$this->assertSame( 404, $this->call( 'POST', $r->id() . '/mail/mailboxes/zz%40route.example/password', $user )->get_status() );
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

		$this->assertSame( 200, $result->get_status() );
		$status = array_column( $result->get_data()['dns']['rows'], 'status', 'type' );
		$this->assertSame( 'ok', $status['MX'] );
		$this->assertSame( 'missing', $status['TXT'] );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail/dns/check', self::factory()->user->create() )->get_status() );
	}

	public function test_changes_are_counted_and_a_paused_service_is_refused(): void {
		list( $r, $user ) = $this->domain();
		$r->set( array( 'state' => 'paused' ) );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) )->get_status() );
		$this->assertNull( $this->mailcow->mailbox( 'a@route.example' ) );
	}

	public function test_a_mail_server_that_cannot_be_reached_says_so_without_detail(): void {
		list( $r, $user ) = $this->domain();
		$this->mailcow->fail_next( 'add_mailbox', new \WP_Error( 'mailcow_transport', 'secret-host.internal refused' ) );

		$response = $this->call( 'POST', $r->id() . '/mail/mailboxes', $user, array( 'local' => 'a' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertStringNotContainsString( 'secret-host', (string) wp_json_encode( $response->get_data() ) );
		$this->assertArrayNotHasKey( 'a@route.example', (array) Resource::find( $r->id() )->get( MailAccess::META ) );
	}
}
