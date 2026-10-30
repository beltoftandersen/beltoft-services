<?php
/** Security emails and the automatic re-lock. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Actions;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Security;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class SecurityTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_relock_days' => 7 ) );
	}

	private function domain( array $meta = array() ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-security-test' ) );
		$user    = self::factory()->user->create( array( 'first_name' => 'Dana', 'user_pass' => 'right-password' ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'state' => 'ready', 'domain' => 'secure-example.net', 'mode' => 'register', 'remote_id' => '321', 'is_locked' => true, 'auth' => 'AUTH-CODE-NEVER-IN-MAIL' ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		return Resource::find( $r->id() );
	}

	private function registrar(): FakeClient {
		$op = new FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		return $op;
	}

	private function assertOneNotice( string $needle ): void {
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'secure-example.net', $this->mails[0]['subject'] );
		$this->assertStringContainsString( $needle, $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'AUTH-CODE-NEVER-IN-MAIL', $this->mails[0]['subject'] . $this->mails[0]['message'] );
	}

	public function test_unlocking_emails_the_customer_once_and_starts_the_clock(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'PUT', '/domains/321', array() );
		$result = Actions::run( $r, 'unlock', array() );
		$this->assertTrue( $result->is_done() );
		$this->assertFalse( $result->meta()['is_locked'] );
		$this->assertEqualsWithDelta( time(), $result->meta()['unlocked_at'], 5 );
		$this->assertOneNotice( 'was unlocked' );
	}

	public function test_a_failed_unlock_sends_nothing_and_locking_again_sends_nothing(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'PUT', '/domains/321', new \WP_Error( 'transport', 'Down.' ) );
		$this->assertFalse( Actions::run( $r, 'unlock', array() )->is_done() );
		$op->reply( 'PUT', '/domains/321', array() );
		$result = Actions::run( $r, 'lock', array() );
		$this->assertTrue( $result->is_done() );
		$this->assertNull( $result->meta()['unlocked_at'] );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_name_server_change_emails_the_customer(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'PUT', '/domains/321', array() );
		$this->assertTrue( Actions::run( $r, 'set_nameservers', array( 'mode' => 'custom', 'hosts' => 'ns1.custom.net,ns2.custom.net' ) )->is_done() );
		$this->assertOneNotice( 'name servers of your domain' );
	}

	public function test_a_failed_name_server_change_sends_nothing(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'PUT', '/domains/321', new \WP_Error( 'transport', 'Down.' ) );
		$this->assertFalse( Actions::run( $r, 'set_nameservers', array( 'mode' => 'custom', 'hosts' => 'ns1.custom.net,ns2.custom.net' ) )->is_done() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_showing_the_transfer_code_emails_the_customer_without_the_code(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321/authcode', array( 'auth_code' => 'REGISTRAR-SECRET-CODE' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$response = $this->code_request( $r );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'REGISTRAR-SECRET-CODE', $response->get_data()['auth_code'] );
		$this->assertOneNotice( 'transfer code for your domain' );
		$this->assertStringNotContainsString( 'REGISTRAR-SECRET-CODE', $this->mails[0]['message'] );
	}

	public function test_a_missing_transfer_code_sends_nothing(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321/authcode', new \WP_Error( 'http_404', 'No.' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$response = $this->code_request( $r );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_domain_left_unlocked_is_locked_again_after_the_set_days_and_the_customer_is_told_once(): void {
		$r  = $this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 8 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321', array( 'status' => 'ACT', 'is_locked' => false ) );
		$op->reply( 'PUT', '/domains/321', array() );
		Security::relock();
		$r = Resource::find( $r->id() );
		$this->assertTrue( $r->get( 'is_locked' ) );
		$this->assertNull( $r->get( 'unlocked_at' ) );
		$this->assertSame( array( true ), array( $op->calls()[1]['body']['is_locked'] ) );
		$this->assertOneNotice( 'locked again' );
		Security::relock();
		$this->assertCount( 1, $this->mails, 'A locked domain is not touched again.' );
		$this->assertCount( 2, $op->calls() );
	}

	public function test_a_domain_unlocked_for_less_than_the_days_is_left_alone(): void {
		$this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 3 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		Security::relock();
		$this->assertSame( array(), $op->calls() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_days_are_a_setting_and_zero_turns_the_relock_off(): void {
		$this->settings( array( 'domain_relock_days' => 0 ) );
		$this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 30 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		Security::relock();
		$this->assertSame( array(), $op->calls() );
		$this->settings( array( 'domain_relock_days' => 2 ) );
		$r = $this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 3 * DAY_IN_SECONDS ) );
		// The first domain, unlocked for 30 days, is locked in the same run.
		foreach ( array( 1, 2 ) as $unused ) {
			$op->reply( 'GET', '/domains/321', array( 'status' => 'ACT', 'is_locked' => false ) );
			$op->reply( 'PUT', '/domains/321', array() );
		}
		Security::relock();
		$this->assertTrue( Resource::find( $r->id() )->get( 'is_locked' ) );
	}

	public function test_a_domain_with_an_outgoing_transfer_under_way_is_not_locked(): void {
		$r  = $this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 20 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321', array( 'status' => 'TRF', 'is_locked' => false ) );
		Security::relock();
		$this->assertCount( 1, $op->calls(), 'Only the status was read.' );
		$this->assertFalse( Resource::find( $r->id() )->get( 'is_locked' ) );
		$this->assertSame( array(), $this->mails );
		$op->reply( 'GET', '/domains/321', array( 'status' => 'ACT', 'is_locked' => false ) );
		add_filter( 'beltoft_services_domain_outgoing_transfer_pending', '__return_true' );
		Security::relock();
		$this->assertCount( 2, $op->calls() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_failed_relock_sends_nothing_and_keeps_the_clock(): void {
		$r  = $this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 9 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321', array( 'status' => 'ACT', 'is_locked' => false ) );
		$op->reply( 'PUT', '/domains/321', new \WP_Error( 'transport', 'Down.' ) );
		Security::relock();
		$r = Resource::find( $r->id() );
		$this->assertFalse( $r->get( 'is_locked' ) );
		$this->assertGreaterThan( 0, (int) $r->get( 'unlocked_at' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_an_unlocked_domain_without_a_start_gets_one_and_a_registrar_that_is_locked_fixes_the_record(): void {
		$r  = $this->domain( array( 'is_locked' => false ) );
		$op = $this->registrar();
		Security::relock();
		$this->assertEqualsWithDelta( time(), (int) Resource::find( $r->id() )->get( 'unlocked_at' ), 5 );
		$this->assertSame( array(), $op->calls() );
		$r->set( array( 'unlocked_at' => time() - 9 * DAY_IN_SECONDS ) );
		$op->reply( 'GET', '/domains/321', array( 'status' => 'ACT', 'is_locked' => true ) );
		Security::relock();
		$this->assertTrue( Resource::find( $r->id() )->get( 'is_locked' ) );
		$this->assertCount( 1, $op->calls(), 'No lock call was needed.' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_security_email_is_registered_and_never_carries_the_code(): void {
		$this->assertContains( 'beltoft_services_domain_security', Emails::IDS );
		$this->assertInstanceOf( \BeltoftServices\Emails\DomainSecurity::class, Emails::get( 'beltoft_services_domain_security' ) );
	}

	public function test_the_relock_is_off_by_default(): void {
		delete_option( \BeltoftServices\Settings::OPTION );
		$this->assertSame( 0, Security::days() );
		$this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 60 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		Security::relock();
		$this->assertSame( array(), $op->calls() );
	}

	public function test_asking_for_the_transfer_code_restarts_the_relock_clock(): void {
		$r  = $this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 20 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321/authcode', array( 'auth_code' => 'REGISTRAR-SECRET-CODE' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->code_request( $r );
		$this->assertEqualsWithDelta( time(), (int) Resource::find( $r->id() )->get( 'unlocked_at' ), 5 );
		$this->mails = array();
		Security::relock();
		$this->assertCount( 1, $op->calls(), 'Only the code was read: the domain is not locked mid-transfer.' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_failed_code_request_also_restarts_the_clock(): void {
		$r  = $this->domain( array( 'is_locked' => false, 'unlocked_at' => time() - 20 * DAY_IN_SECONDS ) );
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321/authcode', new \WP_Error( 'http_404', 'No.' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->code_request( $r );
		$this->assertEqualsWithDelta( time(), (int) Resource::find( $r->id() )->get( 'unlocked_at' ), 5 );
	}

	/** The transfer code route as the page calls it: a POST with the password or code in the body. */
	private function code_request( Resource $r, string $secret = 'right-password' ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/domain-authcode' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'secret' => $secret ) ) );
		return rest_do_request( $request );
	}

	public function test_the_transfer_code_needs_the_account_password(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321/authcode', array( 'auth_code' => 'REGISTRAR-SECRET-CODE' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );

		$wrong = $this->code_request( $r, 'wrong-password' );
		$this->assertSame( 403, $wrong->get_status() );
		$this->assertStringNotContainsString( 'REGISTRAR-SECRET-CODE', (string) wp_json_encode( $wrong->get_data() ) );
		$this->assertSame( array(), $op->calls(), 'The registrar is not asked.' );
		$this->assertSame( array(), $this->mails );

		$get = rest_do_request( new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/domain-authcode' ) );
		$this->assertNotSame( 200, $get->get_status(), 'The old GET without a secret is gone.' );

		$this->assertSame( 200, $this->code_request( $r )->get_status() );
	}

	public function test_a_site_can_ask_for_an_app_code_instead_and_without_a_check_for_it_nothing_is_shown(): void {
		$r  = $this->domain();
		$op = $this->registrar();
		$op->reply( 'GET', '/domains/321/authcode', array( 'auth_code' => 'REGISTRAR-SECRET-CODE' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$ask = static fn() => array( 'kind' => 'code', 'label' => 'Code from your app' );
		add_filter( 'beltoft_services_step_up_ask', $ask );

		$this->assertSame( 'one-time-code', \BeltoftServices\StepUp::ask( (int) $r->license()['user_id'] )['autocomplete'] );
		$this->assertSame( 403, $this->code_request( $r )->get_status(), 'Asking for a code, the password is not accepted.' );

		$check = static fn( $ok, $user, $secret ) => '123456' === $secret;
		add_filter( 'beltoft_services_step_up_verify', $check, 10, 3 );
		$this->assertSame( 403, $this->code_request( $r, '000000' )->get_status() );
		$this->assertSame( 200, $this->code_request( $r, '123456' )->get_status() );
		remove_filter( 'beltoft_services_step_up_verify', $check, 10 );
		remove_filter( 'beltoft_services_step_up_ask', $ask );
	}
}
