<?php
/**
 * Personal API tokens: creation, authentication, scope, revoking, rate limit, history.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Events;
use BeltoftServices\Account\ApiTokens as Panel;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * Tokens tests.
 */
final class ApiTokensTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		delete_network_option( get_main_network_id(), 'using_application_passwords' );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		wp_set_current_user( 0 );
		remove_all_filters( 'beltoft_services_token_rate_limit' );

		parent::tear_down();
	}

	/**
	 * A ready resource and its owner.
	 *
	 * @return array{0: Resource, 1: int}
	 */
	private function resource(): array {
		$driver                   = $this->fake_driver();
		$driver->customer_actions = array( 'reboot' => 'Reboot', 'reinstall' => 'Reinstall' );
		$row                      = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false ) );
		$resource                 = Resource::create( $row, $driver, array(), array( 'state' => 'ready' ) );

		return array( $resource, (int) $row['user_id'] );
	}

	/**
	 * Dispatches a request logged in the way a script is: Basic authentication, no cookie.
	 *
	 * @param string $method   Method.
	 * @param string $route    Route below the namespace, or a full route when it starts with /wp/.
	 * @param int    $user_id  The token's user.
	 * @param string $password The token.
	 */
	private function call( string $method, string $route, int $user_id, string $password ): \WP_REST_Response {
		wp_set_current_user( 0 );
		$_SERVER['PHP_AUTH_USER'] = (string) get_userdata( $user_id )->user_login;
		$_SERVER['PHP_AUTH_PW']   = $password;
		// What WordPress does at the start of a request.
		wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );

		$path = 0 === strpos( $route, '/wp/' ) ? $route : '/beltoft-services/v1' . $route;

		return rest_get_server()->dispatch( new \WP_REST_Request( $method, $path ) );
	}

	private function token( int $user, string $scope ): string {
		$made = Tokens::create( $user, 'Script', $scope );
		$this->assertIsArray( $made );

		return $made['password'];
	}

	public function test_a_token_logs_in_and_reads_what_its_owner_may_read(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::READ );

		$response = $this->call( 'GET', '/resources/' . $resource->id(), $user, $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ready', $response->get_data()['state'] );
	}

	public function test_owner_checks_are_unchanged_for_tokens(): void {
		list( $resource )  = $this->resource();
		$other             = self::factory()->user->create();
		$token             = $this->token( $other, Tokens::ACTIONS );

		$this->assertSame( 403, $this->call( 'GET', '/resources/' . $resource->id(), $other, $token )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/resources/' . $resource->id() . '/actions/reboot', $other, $token )->get_status() );
	}

	public function test_a_wrong_token_is_not_logged_in(): void {
		list( $resource, $user ) = $this->resource();
		$this->token( $user, Tokens::ACTIONS );

		$this->assertSame( 401, $this->call( 'GET', '/resources/' . $resource->id(), $user, 'abcd efgh ijkl mnop qrst uvwx' )->get_status() );
	}

	public function test_read_scope_refuses_actions_and_changes(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::READ );

		$response = $this->call( 'POST', '/resources/' . $resource->id() . '/actions/reboot', $user, $token );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'beltoft_services_token_scope', $response->get_data()['code'] );
		$this->assertSame( 403, $this->call( 'PUT', '/resources/' . $resource->id() . '/dns-records/A/www', $user, $token )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/resources/' . $resource->id() . '/dns-records/A/www', $user, $token )->get_status() );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );
	}

	public function test_actions_scope_queues_an_action_and_writes_the_history(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::ACTIONS );

		$response = $this->call( 'POST', '/resources/' . $resource->id() . '/actions/reboot', $user, $token );

		$this->assertSame( 202, $response->get_status() );
		$messages = array_column( Events::for_license( (int) $resource->license()['id'] ), 'message' );
		$this->assertContains( 'API: reboot requested', $messages );
	}

	public function test_a_refused_action_leaves_no_history_entry(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::ACTIONS );

		$this->assertSame( 403, $this->call( 'POST', '/resources/' . $resource->id() . '/actions/nothing', $user, $token )->get_status() );
		$this->assertNotContains( 'API: nothing requested', array_column( Events::for_license( (int) $resource->license()['id'] ), 'message' ) );
	}

	public function test_destructive_actions_stay_in_the_dashboard(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::ACTIONS );

		$response = $this->call( 'POST', '/resources/' . $resource->id() . '/actions/reinstall', $user, $token );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'beltoft_services_token_action', $response->get_data()['code'] );
	}

	public function test_password_resets_stay_in_the_dashboard(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::ACTIONS );

		foreach ( array( 'reset_password', 'db_reset_password' ) as $action ) {
			$response = $this->call( 'POST', '/resources/' . $resource->id() . '/actions/' . $action, $user, $token );

			$this->assertSame( 403, $response->get_status(), $action );
			$this->assertSame( 'beltoft_services_token_action', $response->get_data()['code'], $action );
		}
	}

	public function test_a_token_reaches_the_customer_routes_only(): void {
		list( , $user ) = $this->resource();
		$token          = $this->token( $user, Tokens::ACTIONS );

		$this->assertSame( 403, $this->call( 'GET', '/wp/v2/users/me', $user, $token )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/wp/v2/users/me/application-passwords', $user, $token )->get_status() );
	}

	public function test_reveal_routes_are_not_reachable_with_a_token(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::ACTIONS );

		foreach ( array( 'reveal', 'db-reveal', 'app-reveal', 'console', 'domain-authcode' ) as $route ) {
			$this->assertSame( 403, $this->call( 'POST', '/resources/' . $resource->id() . '/' . $route, $user, $token )->get_status(), $route );
		}
	}

	public function test_a_password_made_outside_this_feature_has_no_access(): void {
		list( $resource, $user ) = $this->resource();
		$made                    = \WP_Application_Passwords::create_new_application_password( $user, array( 'name' => 'Other' ) );

		$this->assertSame( 403, $this->call( 'GET', '/resources/' . $resource->id(), $user, $made[0] )->get_status() );
		$this->assertSame( array(), Tokens::all( $user ), 'Not listed as one of ours either.' );
	}

	public function test_a_revoked_token_stops_working_and_its_scope_is_dropped(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::ACTIONS );
		$uuid                    = Tokens::all( $user )[0]['uuid'];

		$this->assertSame( 200, $this->call( 'GET', '/resources/' . $resource->id(), $user, $token )->get_status() );
		$this->assertTrue( Tokens::revoke( $user, $uuid ) );
		$this->assertSame( 401, $this->call( 'GET', '/resources/' . $resource->id(), $user, $token )->get_status() );
		$this->assertSame( array(), Tokens::all( $user ) );
		$this->assertArrayNotHasKey( $uuid, (array) get_user_meta( $user, Tokens::META, true ) );
	}

	public function test_nobody_can_revoke_someone_elses_token(): void {
		list( , $user ) = $this->resource();
		$this->token( $user, Tokens::READ );
		$other = self::factory()->user->create();

		$this->assertFalse( Tokens::revoke( $other, Tokens::all( $user )[0]['uuid'] ) );
		$this->assertCount( 1, Tokens::all( $user ) );
	}

	public function test_requests_are_limited_per_token(): void {
		list( $resource, $user ) = $this->resource();
		$first                   = $this->token( $user, Tokens::READ );
		$second                  = $this->token( $user, Tokens::READ );
		add_filter( 'beltoft_services_token_rate_limit', static fn(): int => 2 );

		$this->assertSame( 200, $this->call( 'GET', '/resources/' . $resource->id(), $user, $first )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/resources/' . $resource->id(), $user, $first )->get_status() );
		$limited = $this->call( 'GET', '/resources/' . $resource->id(), $user, $first );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/resources/' . $resource->id(), $user, $second )->get_status(), 'Another token has its own count.' );
	}

	public function test_last_used_is_recorded_and_the_secret_is_never_listed(): void {
		list( $resource, $user ) = $this->resource();
		$token                   = $this->token( $user, Tokens::READ );

		$this->assertSame( 0, Tokens::all( $user )[0]['last_used'] );
		$this->call( 'GET', '/resources/' . $resource->id(), $user, $token );
		$this->assertGreaterThan( 0, Tokens::all( $user )[0]['last_used'] );
		$this->assertStringNotContainsString( str_replace( ' ', '', $token ), (string) wp_json_encode( Tokens::all( $user ) ) );
	}

	public function test_a_token_needs_a_name_a_scope_and_is_capped(): void {
		list( , $user ) = $this->resource();

		$this->assertWPError( Tokens::create( $user, '  ', Tokens::READ ) );
		$this->assertWPError( Tokens::create( $user, 'x', 'admin' ) );

		for ( $i = 0; $i < Tokens::MAX; $i++ ) {
			$this->assertIsArray( Tokens::create( $user, 'T' . $i, Tokens::READ ) );
		}

		$this->assertWPError( Tokens::create( $user, 'one more', Tokens::READ ) );
	}

	public function test_the_secret_is_shown_once_by_the_panel_and_never_again(): void {
		list( , $user ) = $this->resource();
		wp_set_current_user( $user );
		$nonce = wp_create_nonce( Panel::NONCE );

		$made = Panel::process( $user, array( 'action' => 'create', 'name' => 'CI', 'scope' => Tokens::READ, 'nonce' => $nonce ) );

		$this->assertTrue( $made['ok'] );
		$this->assertNotSame( '', $made['password'] );

		$secret = str_replace( ' ', '', $made['password'] );
		ob_start();
		Panel::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'CI', $html, 'Listed by name.' );
		$this->assertStringNotContainsString( $secret, str_replace( ' ', '', $html ), 'A later page does not show it.' );
		$this->assertStringContainsString( 'beltoft-services/v1/', $html, 'The docs.' );
		$this->assertStringContainsString( 'curl -u', $html );
	}

	public function test_the_section_goes_under_the_account_form_unless_the_theme_has_a_security_tab(): void {
		list( , $user ) = $this->resource();
		wp_set_current_user( $user );

		ob_start();
		Panel::render_under_form();
		$this->assertStringContainsString( 'id="api-tokens"', (string) ob_get_clean() );

		add_filter( 'beltoft_services_api_tokens_on_security_tab', '__return_true' );
		ob_start();
		Panel::render_under_form();
		$this->assertSame( '', (string) ob_get_clean(), 'The Security tab prints it.' );

		ob_start();
		do_action( 'beltoft_account_security_sections' );
		$html = (string) ob_get_clean();
		remove_filter( 'beltoft_services_api_tokens_on_security_tab', '__return_true' );

		$this->assertStringContainsString( 'id="api-tokens"', $html );
	}

	public function test_a_new_secret_has_a_copy_button(): void {
		list( , $user ) = $this->resource();
		wp_set_current_user( $user );
		$_POST = array( Panel::FIELD => 'create', 'token_name' => 'CI', 'token_scope' => Tokens::READ, '_wpnonce' => wp_create_nonce( Panel::NONCE ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Panel::handle();
		$_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		Panel::render();

		$this->assertStringContainsString( 'data-api-copy="', (string) ob_get_clean() );
	}

	public function test_the_panel_refuses_without_a_valid_nonce(): void {
		list( , $user ) = $this->resource();

		$result = Panel::process( $user, array( 'action' => 'create', 'name' => 'CI', 'scope' => Tokens::READ, 'nonce' => 'bad' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), Tokens::all( $user ) );
	}

	public function test_the_panel_revokes(): void {
		list( , $user ) = $this->resource();
		$this->token( $user, Tokens::READ );
		$nonce = wp_create_nonce( Panel::NONCE );

		$result = Panel::process( $user, array( 'action' => 'revoke', 'uuid' => Tokens::all( $user )[0]['uuid'], 'nonce' => $nonce ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), Tokens::all( $user ) );
	}

	public function test_a_cookie_session_is_not_a_token_request(): void {
		list( $resource, $user ) = $this->resource();
		wp_set_current_user( $user );
		Tokens::reset( 0 );

		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $resource->id() . '/actions/reboot' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNotContains( 'API: reboot requested', array_column( Events::for_license( (int) $resource->license()['id'] ), 'message' ), 'The dashboard is not the API.' );
	}
}
