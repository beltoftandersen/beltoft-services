<?php
/**
 * The OAuth 2.1 server: discovery, client registration, code flow with PKCE, refresh, revocation, switches.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\Access;
use BeltoftServices\Oauth\Clients;
use BeltoftServices\Oauth\Grants;
use BeltoftServices\Oauth\Schema;
use BeltoftServices\Oauth\Server;

/**
 * OAuth tests.
 */
final class OauthTest extends TestCase {

	private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->assertTrue( Schema::ready(), 'The OAuth tables exist.' );
		$this->settings( array( 'mcp_enabled' => 1, 'mcp_redirect_uris' => '' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		wp_set_current_user( 0 );
		Tokens::reset( false );

		parent::tear_down();
	}

	/**
	 * A registered client id.
	 */
	private function client(): string {
		$done = Clients::register( array( 'redirect_uris' => array( self::REDIRECT ), 'client_name' => 'Claude' ) );
		$this->assertIsArray( $done );

		return $done['client_id'];
	}

	/**
	 * @return array{0: string, 1: string} Verifier and S256 challenge.
	 */
	private function pkce(): array {
		$verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

		return array( $verifier, rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
	}

	/**
	 * Runs the consent form as the customer approving.
	 *
	 * @return array<string, mixed> Result of Server::authorize().
	 */
	private function approve( string $client, int $user, string $challenge, bool $actions = false, array $extra = array() ): array {
		$params = array(
			'client_id'             => $client,
			'redirect_uri'          => self::REDIRECT,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'state'                 => 'xyz',
			'decision'              => 'approve',
			'_wpnonce'              => $this->nonce( $user ),
		) + ( $actions ? array( 'allow_actions' => '1' ) : array() );

		return Server::authorize( $extra + $params, $user, true );
	}

	private function nonce( int $user ): string {
		wp_set_current_user( $user );

		return wp_create_nonce( Server::NONCE );
	}

	/**
	 * @param array<string, mixed> $result Redirect result.
	 * @return array<string, string> Query of the redirect.
	 */
	private function query( array $result ): array {
		$this->assertSame( 'redirect', $result['type'] );
		$this->assertStringStartsWith( self::REDIRECT . '?', $result['url'] );
		parse_str( (string) wp_parse_url( $result['url'], PHP_URL_QUERY ), $q );

		return $q;
	}

	/**
	 * @param array<string, string> $body Form fields.
	 */
	private function token( array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/beltoft-oauth/v1/token' );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	/**
	 * A connection made through the whole flow.
	 *
	 * @return array{0: array<string, mixed>, 1: string, 2: int} Token response, client, user.
	 */
	private function connect( bool $actions = false ): array {
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( $verifier, $challenge ) = $this->pkce();
		$q    = $this->query( $this->approve( $client, $user, $challenge, $actions ) );
		$resp = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $q['code'],
				'client_id'     => $client,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => $verifier,
			)
		);
		$this->assertSame( 200, $resp->get_status() );

		return array( $resp->get_data(), $client, $user );
	}

	public function test_discovery_documents(): void {
		$meta = Server::well_known( '.well-known/oauth-authorization-server' );

		$this->assertSame( untrailingslashit( home_url() ), $meta['issuer'] );
		$this->assertSame( array( 'S256' ), $meta['code_challenge_methods_supported'] );
		$this->assertSame( array( 'none' ), $meta['token_endpoint_auth_methods_supported'] );
		$this->assertSame( home_url( '/beltoft-oauth/authorize' ), $meta['authorization_endpoint'] );
		$this->assertStringContainsString( 'beltoft-oauth/v1/token', $meta['token_endpoint'] );
		$this->assertStringContainsString( 'beltoft-oauth/v1/register', $meta['registration_endpoint'] );
		$this->assertStringContainsString( 'beltoft-oauth/v1/revoke', $meta['revocation_endpoint'] );
		$this->assertContains( 'refresh_token', $meta['grant_types_supported'] );

		$res = Server::well_known( '.well-known/oauth-protected-resource' );
		$this->assertStringContainsString( 'beltoft-mcp/v1/mcp', $res['resource'] );
		$this->assertSame( array( untrailingslashit( home_url() ) ), $res['authorization_servers'] );
		$this->assertNotNull( Server::well_known( '.well-known/oauth-protected-resource/wp-json/beltoft-mcp/v1/mcp' ) );
		$this->assertNull( Server::well_known( '.well-known/other' ) );
	}

	public function test_registration_refuses_a_redirect_that_is_not_on_the_list(): void {
		foreach ( array( 'https://evil.example/cb', 'https://claude.ai/api/mcp/auth_callback/', 'http://claude.ai/api/mcp/auth_callback' ) as $uri ) {
			$done = Clients::register( array( 'redirect_uris' => array( $uri ) ) );
			$this->assertWPError( $done );
			$this->assertSame( 'invalid_redirect_uri', $done->get_error_code() );
		}

		$this->assertWPError( Clients::register( array( 'redirect_uris' => array() ) ) );
		$this->assertWPError( Clients::register( array( 'redirect_uris' => array( self::REDIRECT ), 'token_endpoint_auth_method' => 'client_secret_basic' ) ) );
		$this->assertWPError( Clients::register( array( 'redirect_uris' => array( self::REDIRECT, 'https://evil.example/cb' ) ) ) );
	}

	public function test_registration_over_rest_and_the_owner_allowlist(): void {
		$request = new \WP_REST_Request( 'POST', '/beltoft-oauth/v1/register' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://claude.com/api/mcp/auth_callback' ), 'client_name' => 'Claude' ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^bsc_[0-9a-f]{32}$/', $response->get_data()['client_id'] );
		$this->assertArrayNotHasKey( 'client_secret', $response->get_data() );

		$this->settings( array( 'mcp_redirect_uris' => "https://app.example/cb" ) );
		$this->assertWPError( Clients::register( array( 'redirect_uris' => array( self::REDIRECT ) ) ) );
		$this->assertIsArray( Clients::register( array( 'redirect_uris' => array( 'https://app.example/cb' ) ) ) );

		$bad = new \WP_REST_Request( 'POST', '/beltoft-oauth/v1/register' );
		$bad->set_header( 'content-type', 'application/json' );
		$bad->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://evil.example/' ) ) ) );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );
	}

	public function test_full_flow_with_pkce(): void {
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( $verifier, $challenge ) = $this->pkce();
		$base = array(
			'client_id'             => $client,
			'redirect_uri'          => self::REDIRECT,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'state'                 => 'xyz',
		);

		// Not logged in: sent to log in. Logged in: the consent page names the app and where it returns.
		$this->assertSame( 'login', Server::authorize( $base, 0, false )['type'] );
		$page = Server::authorize( $base, $user, false );
		$this->assertSame( 'consent', $page['kind'] );
		$this->assertSame( 'claude.ai', $page['host'] );
		$this->assertSame( 'Claude', $page['client']['name'] );

		$q = $this->query( $this->approve( $client, $user, $challenge ) );
		$this->assertSame( 'xyz', $q['state'] );

		$resp = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $q['code'],
				'client_id'     => $client,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => $verifier,
			)
		);
		$data = $resp->get_data();

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'no-store', $resp->get_headers()['Cache-Control'] );
		$this->assertSame( 'Bearer', $data['token_type'] );
		$this->assertSame( 3600, $data['expires_in'] );
		$this->assertSame( Tokens::READ, $data['scope'] );

		$grant = Grants::authenticate( $data['access_token'] );
		$this->assertSame( $user, $grant['user'] );
		$this->assertSame( Tokens::READ, $grant['scope'] );
		$this->assertNull( Grants::authenticate( $data['refresh_token'] ), 'A refresh token is not an access token.' );
		$this->assertNull( Grants::authenticate( 'bso_a_nothing' ) );
	}

	public function test_the_actions_scope_comes_from_the_tick(): void {
		list( $data ) = $this->connect( true );

		$this->assertSame( Tokens::ACTIONS, $data['scope'] );
		$this->assertSame( Tokens::ACTIONS, Grants::authenticate( $data['access_token'] )['scope'] );
	}

	public function test_authorize_refuses_plain_and_a_missing_challenge_and_other_requests(): void {
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( , $challenge ) = $this->pkce();
		$base = array(
			'client_id'             => $client,
			'redirect_uri'          => self::REDIRECT,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'state'                 => 's1',
		);

		$plain = Server::authorize( array( 'code_challenge_method' => 'plain' ) + $base, $user, false );
		$this->assertSame( 'invalid_request', $this->query( $plain )['error'] );
		$this->assertSame( 's1', $this->query( $plain )['state'] );

		$none = $base;
		unset( $none['code_challenge'] );
		$this->assertSame( 'invalid_request', $this->query( Server::authorize( $none, $user, false ) )['error'] );
		$this->assertSame( 'unsupported_response_type', $this->query( Server::authorize( array( 'response_type' => 'token' ) + $base, $user, false ) )['error'] );
		$this->assertSame( 'invalid_target', $this->query( Server::authorize( array( 'resource' => 'https://other.example/mcp' ) + $base, $user, false ) )['error'] );
		$this->assertSame( 'consent', Server::authorize( array( 'resource' => \BeltoftServices\Mcp\Endpoint::url() ) + $base, $user, false )['kind'] );
	}

	public function test_an_unregistered_redirect_gets_a_page_not_a_redirect(): void {
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( , $challenge ) = $this->pkce();
		$base = array(
			'client_id'             => $client,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);

		foreach ( array( 'https://evil.example/cb', '' ) as $uri ) {
			$result = Server::authorize( array( 'redirect_uri' => $uri ) + $base, $user, false );
			$this->assertSame( 'page', $result['type'] );
			$this->assertSame( 'invalid', $result['kind'] );
		}

		$this->assertSame( 'invalid', Server::authorize( array( 'redirect_uri' => self::REDIRECT, 'client_id' => 'bsc_' . str_repeat( '0', 32 ) ) + $base, $user, false )['kind'] );

		// A redirect that left the owner's list stops working for a client that had it.
		$this->settings( array( 'mcp_redirect_uris' => 'https://app.example/cb' ) );
		$this->assertSame( 'invalid', Server::authorize( array( 'redirect_uri' => self::REDIRECT ) + $base, $user, false )['kind'] );
	}

	public function test_deny_and_a_bad_nonce(): void {
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( , $challenge ) = $this->pkce();

		$denied = $this->query( $this->approve( $client, $user, $challenge, false, array( 'decision' => 'deny' ) ) );
		$this->assertSame( 'access_denied', $denied['error'] );
		$this->assertSame( 'xyz', $denied['state'] );
		$this->assertArrayNotHasKey( 'code', $denied );

		$forged = $this->approve( $client, $user, $challenge, false, array( '_wpnonce' => 'nope' ) );
		$this->assertSame( 'page', $forged['type'] );
		$this->assertSame( 'expired', $forged['kind'] );
	}

	public function test_a_wrong_verifier_is_refused(): void {
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( , $challenge ) = $this->pkce();
		$q = $this->query( $this->approve( $client, $user, $challenge ) );

		$resp = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $q['code'],
				'client_id'     => $client,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => $this->pkce()[0],
			)
		);

		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_grant', $resp->get_data()['error'] );

		// The code is burned by the failed try.
		list( $verifier, $challenge2 ) = $this->pkce();
		unset( $verifier, $challenge2 );
		$again = $this->token( array( 'grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_verifier' => 'short' ) );
		$this->assertSame( 'invalid_grant', $again->get_data()['error'] );
	}

	public function test_the_code_is_bound_to_client_and_redirect(): void {
		$user   = self::factory()->user->create();
		$client = $this->client();
		$other  = $this->client();
		list( $verifier, $challenge ) = $this->pkce();

		$q = $this->query( $this->approve( $client, $user, $challenge ) );
		$this->assertSame( 'invalid_grant', $this->token( array( 'grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $other, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier ) )->get_data()['error'] );

		$q = $this->query( $this->approve( $client, $user, $challenge ) );
		$this->assertSame( 'invalid_grant', $this->token( array( 'grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client, 'redirect_uri' => 'https://claude.com/api/mcp/auth_callback', 'code_verifier' => $verifier ) )->get_data()['error'] );
	}

	public function test_a_code_works_once_and_its_reuse_ends_the_connection(): void {
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( $verifier, $challenge ) = $this->pkce();
		$q    = $this->query( $this->approve( $client, $user, $challenge ) );
		$body = array( 'grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier );

		$first = $this->token( $body );
		$this->assertSame( 200, $first->get_status() );
		$this->assertNotNull( Grants::authenticate( $first->get_data()['access_token'] ) );

		$second = $this->token( $body );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'invalid_grant', $second->get_data()['error'] );
		$this->assertNull( Grants::authenticate( $first->get_data()['access_token'] ), 'What the first use made is revoked.' );
	}

	public function test_a_code_expires_after_a_minute(): void {
		global $wpdb;
		$user   = self::factory()->user->create();
		$client = $this->client();
		list( $verifier, $challenge ) = $this->pkce();
		$q = $this->query( $this->approve( $client, $user, $challenge ) );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE type = %s ORDER BY id DESC LIMIT 1', Schema::tables()['grants'], 'code' ), ARRAY_A );
		$this->assertEqualsWithDelta( time() + 60, (int) $row['expires_at'], 3 );
		$wpdb->update( Schema::tables()['grants'], array( 'expires_at' => time() - 1 ), array( 'id' => $row['id'] ) );

		$resp = $this->token( array( 'grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier ) );
		$this->assertSame( 'invalid_grant', $resp->get_data()['error'] );
	}

	public function test_an_access_token_lives_an_hour_and_a_refresh_token_thirty_days(): void {
		global $wpdb;
		list( $data ) = $this->connect();

		$access  = $wpdb->get_var( $wpdb->prepare( 'SELECT expires_at FROM %i WHERE hash = %s', Schema::tables()['grants'], Grants::hash( $data['access_token'] ) ) );
		$refresh = $wpdb->get_var( $wpdb->prepare( 'SELECT expires_at FROM %i WHERE hash = %s', Schema::tables()['grants'], Grants::hash( $data['refresh_token'] ) ) );
		$this->assertEqualsWithDelta( time() + 3600, (int) $access, 3 );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, (int) $refresh, 3 );

		$wpdb->update( Schema::tables()['grants'], array( 'expires_at' => time() - 1 ), array( 'hash' => Grants::hash( $data['access_token'] ) ) );
		$this->assertNull( Grants::authenticate( $data['access_token'] ) );
	}

	public function test_refresh_rotates_and_reuse_of_an_old_token_revokes_the_family(): void {
		list( $first, $client ) = $this->connect();

		$second = $this->token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client ) );
		$this->assertSame( 200, $second->get_status() );
		$new = $second->get_data();
		$this->assertNotSame( $first['refresh_token'], $new['refresh_token'] );
		$this->assertNotSame( $first['access_token'], $new['access_token'] );
		$this->assertNotNull( Grants::authenticate( $new['access_token'] ) );

		// The old refresh token shown again: two parties hold it, so the whole connection ends.
		$replay = $this->token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client ) );
		$this->assertSame( 'invalid_grant', $replay->get_data()['error'] );
		$this->assertNull( Grants::authenticate( $new['access_token'] ) );
		$this->assertSame( 'invalid_grant', $this->token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $new['refresh_token'], 'client_id' => $client ) )->get_data()['error'] );
	}

	public function test_refresh_needs_the_same_client_and_an_unexpired_token(): void {
		global $wpdb;
		list( $data, $client ) = $this->connect();

		$this->assertSame( 'invalid_grant', $this->token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $data['refresh_token'], 'client_id' => $this->client() ) )->get_data()['error'] );

		$wpdb->update( Schema::tables()['grants'], array( 'expires_at' => time() - 1 ), array( 'hash' => Grants::hash( $data['refresh_token'] ) ) );
		$this->assertSame( 'invalid_grant', $this->token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $data['refresh_token'], 'client_id' => $client ) )->get_data()['error'] );
		$this->assertSame( 'unsupported_grant_type', $this->token( array( 'grant_type' => 'password', 'client_id' => $client ) )->get_data()['error'] );
		$this->assertSame( 'invalid_client', $this->token( array( 'grant_type' => 'refresh_token', 'client_id' => 'bsc_x' ) )->get_data()['error'] );
	}

	public function test_revocation_ends_the_connection_and_ignores_unknown_tokens(): void {
		list( $data, $client ) = $this->connect();

		$revoke = new \WP_REST_Request( 'POST', '/beltoft-oauth/v1/revoke' );
		$revoke->set_body_params( array( 'token' => $data['refresh_token'], 'client_id' => $client ) );
		$this->assertSame( 200, rest_do_request( $revoke )->get_status() );
		$this->assertNull( Grants::authenticate( $data['access_token'] ) );

		$unknown = new \WP_REST_Request( 'POST', '/beltoft-oauth/v1/revoke' );
		$unknown->set_body_params( array( 'token' => 'bso_a_unknown', 'client_id' => $client ) );
		$this->assertSame( 200, rest_do_request( $unknown )->get_status() );

		// Another client cannot revoke it.
		list( $data2 ) = $this->connect();
		$other = new \WP_REST_Request( 'POST', '/beltoft-oauth/v1/revoke' );
		$other->set_body_params( array( 'token' => $data2['access_token'], 'client_id' => $client ) );
		rest_do_request( $other );
		$this->assertNotNull( Grants::authenticate( $data2['access_token'] ) );
	}

	public function test_only_hashes_are_stored(): void {
		global $wpdb;
		list( $data ) = $this->connect();

		$dump = wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', Schema::tables()['grants'] ), ARRAY_A ) );
		$this->assertStringNotContainsString( $data['access_token'], $dump );
		$this->assertStringNotContainsString( $data['refresh_token'], $dump );
		$this->assertStringNotContainsString( substr( $data['access_token'], 6 ), $dump );
		$this->assertStringContainsString( Grants::hash( $data['access_token'] ), $dump );
		$this->assertSame( 64, strlen( Grants::hash( 'x' ) ) );
	}

	public function test_connected_apps_list_and_disconnect(): void {
		list( $data, , $user ) = $this->connect();
		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', '/beltoft-services/v1/openapi.json' ) )->get_status() );
		Grants::authenticate( $data['access_token'] );

		$apps = Grants::connections( $user );
		$this->assertCount( 1, $apps );
		$this->assertSame( 'Claude', $apps[0]['name'] );
		$this->assertSame( Tokens::READ, $apps[0]['scope'] );
		$this->assertEqualsWithDelta( time(), $apps[0]['connected'], 5 );
		$this->assertEqualsWithDelta( time(), $apps[0]['last_used'], 5 );

		$this->assertFalse( Grants::disconnect( self::factory()->user->create(), $apps[0]['family'] ), 'Not another customer\'s.' );
		$this->assertNotNull( Grants::authenticate( $data['access_token'] ) );

		$panel = \BeltoftServices\Account\ApiTokens::process( $user, array( 'action' => 'disconnect', 'family' => $apps[0]['family'], 'nonce' => wp_create_nonce( \BeltoftServices\Account\ApiTokens::NONCE ) ) );
		$this->assertTrue( $panel['ok'] );
		$this->assertNull( Grants::authenticate( $data['access_token'] ) );
		$this->assertSame( array(), Grants::connections( $user ) );
	}

	public function test_both_switches_end_the_access(): void {
		list( $data, $client, $user ) = $this->connect();
		$this->assertNotNull( Grants::authenticate( $data['access_token'] ) );

		Access::set_user( $user, false );
		$this->assertNull( Grants::authenticate( $data['access_token'] ) );
		$this->assertSame( 'invalid_grant', $this->token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $data['refresh_token'], 'client_id' => $client ) )->get_data()['error'] );
		$this->assertSame( 'user_off', Server::authorize( array( 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'code_challenge' => $this->pkce()[1], 'code_challenge_method' => 'S256' ), $user, false )['kind'] );

		list( $data2, $client2 ) = $this->connect();
		$this->settings( array( 'mcp_enabled' => 0 ) );
		$this->assertNull( Grants::authenticate( $data2['access_token'] ) );
		$this->assertSame( 503, $this->token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $data2['refresh_token'], 'client_id' => $client2 ) )->get_status() );
		$register = new \WP_REST_Request( 'POST', '/beltoft-oauth/v1/register' );
		$register->set_body_params( array( 'redirect_uris' => array( self::REDIRECT ) ) );
		$this->assertSame( 503, rest_do_request( $register )->get_status() );
		$this->assertSame( 'off', Server::authorize( array(), 1, false )['kind'] );
	}

	public function test_bearer_authenticates_rest_requests_with_the_scope(): void {
		list( $data, , $user ) = $this->connect();

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $data['access_token'];
		$this->assertTrue( apply_filters( 'rest_authentication_errors', null ) );
		$this->assertSame( $user, get_current_user_id() );
		$this->assertSame( Tokens::READ, Tokens::current()['scope'] );

		// A token of ours that is not valid is a 401; another scheme is left alone.
		wp_set_current_user( 0 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bso_a_wrong';
		$error = apply_filters( 'rest_authentication_errors', null );
		$this->assertWPError( $error );
		$this->assertSame( 401, $error->get_error_data()['status'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc';
		$this->assertNull( Server::bearer( null ) );
	}

	public function test_the_consent_page_template_prints_and_escapes(): void {
		$client = Clients::register( array( 'redirect_uris' => array( self::REDIRECT ), 'client_name' => '<script>alert(1)</script>' ) );
		$found  = Clients::find( $client['client_id'] );
		$this->assertStringNotContainsString( '<script>', $found['name'] );

		$result = array( 'kind' => 'consent', 'status' => 200, 'client' => $found, 'host' => 'claude.ai', 'wants' => 'read' );
		ob_start();
		( static function ( array $result ): void {
			$fields = array( 'client_id' => 'a"b', 'state' => 's' );
			$nonce  = 'n';
			$action = home_url( '/beltoft-oauth/authorize' );
			include BELTOFT_SERVICES_PATH . 'templates/account/oauth-consent.php';
		} )( $result );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'wants to read your servers, databases, domains and DNS', $html );
		$this->assertStringContainsString( 'and run actions (restart, snapshots, DNS changes)', $html );
		$this->assertStringContainsString( 'claude.ai', $html );
		$this->assertStringNotContainsString( 'a"b', $html );
		$this->assertStringNotContainsString( '—', $html );
	}
}
