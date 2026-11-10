<?php
/**
 * A personal token with Full access: made with an emailed code, listed, enforced like a Full OAuth grant.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Account\ApiTokens as Panel;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Resource;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Full personal token tests.
 */
final class FullPersonalTokenTest extends McpTestCase {

	/**
	 * Captured mails.
	 *
	 * @var array<int, array<string, mixed>>
	 */
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
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_token_rate_limit' );
		parent::tear_down();
	}

	/**
	 * A ready fake-driver server that offers snapshot rollback, and its owner (mail is captured).
	 *
	 * @return array{0: Resource, 1: int}
	 */
	private function server_with_owner(): array {
		$driver                   = $this->fake_driver();
		$driver->customer_actions = array( 'snapshot_rollback' => 'snapshot_rollback', 'reboot' => 'reboot' );
		$row                      = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false ) );
		$r                        = Resource::create( $row, $driver, array(), array( 'state' => 'ready' ) );
		$user                     = (int) $row['user_id'];
		wp_update_user( array( 'ID' => $user, 'user_email' => 'christian.beltoft@gmail.com' ) );

		return array( $r, $user );
	}

	/**
	 * @return array{ok: bool, message: string, password: string}
	 */
	private function create( int $user, string $scope, string $secret ): array {
		return Panel::process( $user, array( 'action' => 'create', 'name' => 'Claude Code', 'scope' => $scope, 'secret' => $secret, 'nonce' => wp_create_nonce( Panel::NONCE ) ) );
	}

	/**
	 * Emails a code for a Full token and returns it from the captured mail.
	 */
	private function emailed_code( int $user ): string {
		\BeltoftServices\StepUp::send( $user, \BeltoftServices\Account\ApiTokens::PURPOSE );
		preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( (string) end( $this->mails )['message'] ), $m );

		return $m[1];
	}

	public function test_full_is_made_with_the_emailed_code_and_stored_and_listed(): void {
		list( , $user ) = $this->server_with_owner();

		$result = $this->create( $user, Tokens::FULL, $this->emailed_code( $user ) );
		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertNotSame( '', $result['password'] );

		$all = Tokens::all( $user );
		$this->assertCount( 1, $all );
		$this->assertSame( Tokens::FULL, $all[0]['scope'] );
		$this->assertSame( 'full:' . Tokens::FULL_CONSENT, array_values( get_user_meta( $user, Tokens::META, true ) )[0] );

		wp_set_current_user( $user );
		ob_start();
		Panel::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Full access', $html );
		$this->assertStringContainsString( 'also purchases, cancellations, password reveals and deletions, each confirmed in the chat first', $html );
		$this->assertStringContainsString( 'name="token_secret"', $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );

		// Revoking works as for any token.
		$this->assertTrue( Tokens::revoke( $user, $all[0]['uuid'] ) );
		$this->assertSame( array(), Tokens::all( $user ) );
	}

	public function test_a_wrong_or_missing_code_refuses_and_creates_nothing(): void {
		list( , $user ) = $this->server_with_owner();
		$this->emailed_code( $user );

		foreach ( array( 'wrong code', '' ) as $secret ) {
			$result = $this->create( $user, Tokens::FULL, $secret );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( '', $result['password'] );
			$this->assertStringContainsString( 'not right or has expired', $result['message'] );
		}

		$this->assertSame( array(), Tokens::all( $user ) );

		// The code is not asked for read and actions.
		$this->assertTrue( $this->create( $user, Tokens::READ, '' )['ok'] );
		$this->assertTrue( $this->create( $user, Tokens::ACTIONS, '' )['ok'] );
	}

	public function test_failed_attempts_are_rate_limited_even_for_the_right_code(): void {
		list( , $user ) = $this->server_with_owner();
		$code           = $this->emailed_code( $user );

		for ( $i = 0; $i < Panel::MAX_FAILS; $i++ ) {
			$this->assertStringContainsString( 'not right', $this->create( $user, Tokens::FULL, 'nope' )['message'] );
		}

		$result = $this->create( $user, Tokens::FULL, $code );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Too many', $result['message'] );
		$this->assertSame( array(), Tokens::all( $user ) );
	}

	public function test_the_full_tool_is_allowed_for_a_full_token_but_still_needs_confirmation(): void {
		list( $r, $user ) = $this->server_with_owner();
		$full             = $this->personal( $user, Tokens::FULL );
		$args             = array( 'id' => $r->id(), 'snapshot' => 'abcd1234' );

		$names = array_column( $this->rpc( 'tools/list', array(), $full )['result']['tools'], 'name' );
		$this->assertContains( 'rollback_snapshot', $names );
		$this->assertContains( 'order_server', $names );

		$first = $this->json( $this->tool( 'rollback_snapshot', $args, $full ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ), 'Nothing ran on the first call.' );

		// A made-up confirm value does nothing.
		$this->assertTrue( $this->tool( 'rollback_snapshot', $args + array( 'confirm' => 'bogus' ), $full )['isError'] );

		$done = $this->tool( 'rollback_snapshot', $args + array( 'confirm' => $first['confirm'] ), $full );
		$this->assertTrue( $this->json( $done )['requested'] );
	}

	public function test_an_actions_token_is_still_refused_the_full_tools(): void {
		list( $r, $user ) = $this->server_with_owner();
		$actions          = $this->personal( $user, Tokens::ACTIONS );

		$names = array_column( $this->rpc( 'tools/list', array(), $actions )['result']['tools'], 'name' );
		$this->assertNotContains( 'rollback_snapshot', $names );
		$this->assertNotContains( 'order_server', $names );
		$this->assertTrue( $this->tool( 'rollback_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ), $actions )['isError'] );
	}

	public function test_the_customers_own_mcp_switch_is_still_required(): void {
		list( , $user ) = $this->server_with_owner();
		$full           = $this->personal( $user, Tokens::FULL );

		\BeltoftServices\Mcp\Access::set_user( $user, false );
		$this->assertSame( 403, $this->post( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), $full )->get_status() );
	}

	public function test_rest_still_refuses_risky_and_dashboard_only_actions_for_a_full_token(): void {
		list( $r, $user ) = $this->server_with_owner();
		$full             = $this->personal( $user, Tokens::FULL )[1];

		$call = function ( string $method, string $route, array $params = array() ) use ( $user, $full ): \WP_REST_Response {
			wp_set_current_user( 0 );
			$_SERVER['PHP_AUTH_USER'] = (string) get_userdata( $user )->user_login;
			$_SERVER['PHP_AUTH_PW']   = $full;
			wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
			$request = new \WP_REST_Request( $method, '/beltoft-services/v1' . $route );
			foreach ( $params as $k => $v ) {
				$request->set_param( $k, $v );
			}

			return rest_get_server()->dispatch( $request );
		};

		// Full works as actions on REST: an everyday action is accepted.
		$this->assertSame( 202, $call( 'POST', '/resources/' . $r->id() . '/actions/reboot' )->get_status() );

		foreach ( array( 'unlock', 'reinstall', 'snapshot_rollback' ) as $action ) {
			$response = $call( 'POST', '/resources/' . $r->id() . '/actions/' . $action );
			$this->assertSame( 403, $response->get_status(), $action );
			$this->assertSame( 'beltoft_services_token_action', $response->get_data()['code'], $action );
		}
	}

	public function test_the_oauth_full_grant_is_unchanged(): void {
		list( $r, $user ) = $this->server_with_owner();
		$token            = $this->oauth( $user, Tokens::FULL );

		$first = $this->json( $this->tool( 'rollback_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ), $token ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertTrue( $this->tool( 'rollback_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ), $this->oauth( $user, Tokens::ACTIONS ) )['isError'] );
	}
}
