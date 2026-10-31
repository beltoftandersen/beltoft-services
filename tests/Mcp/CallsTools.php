<?php
/**
 * Calls the MCP endpoint as a connected app: an OAuth token or a personal token, JSON-RPC and tool calls.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Oauth\Clients;
use BeltoftServices\Oauth\Grants;
use BeltoftServices\Oauth\Server;

/**
 * For a test case that has set the MCP switches on (see McpTestCase::set_up).
 */
trait CallsTools {

	/**
	 * Turns the connector on and sets up the REST server and Application Passwords the way the MCP tests need them.
	 */
	protected function mcp_set_up(): void {
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );
		$this->settings( array( 'mcp_enabled' => 1, 'mcp_redirect_uris' => '' ) );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		delete_network_option( get_main_network_id(), 'using_application_passwords' );
		as_unschedule_all_actions( \BeltoftServices\Jobs::SWEEP, array(), \BeltoftServices\Jobs::GROUP );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Undoes mcp_set_up().
	 */
	protected function mcp_tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		wp_set_current_user( 0 );
		Tokens::reset( false );
		remove_all_filters( 'beltoft_services_mcp_rate_limit' );
	}

	/**
	 * An OAuth access token for a customer.
	 */
	protected function oauth( int $user, string $scope = Tokens::READ ): string {
		$done   = Clients::register( array( 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ) ) );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		wp_set_current_user( $user );
		$result = Server::authorize(
			array(
				'client_id'             => $done['client_id'],
				'redirect_uri'          => 'https://claude.ai/api/mcp/auth_callback',
				'response_type'         => 'code',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'decision'              => 'approve',
				'_wpnonce'              => wp_create_nonce( Server::NONCE ),
			) + ( Tokens::READ === $scope ? array() : array( 'level' => $scope, 'understand_full' => '1' ) ),
			$user,
			true
		);
		parse_str( (string) wp_parse_url( $result['url'], PHP_URL_QUERY ), $q );
		$tokens = Grants::exchange_code( $q['code'], $done['client_id'], 'https://claude.ai/api/mcp/auth_callback', $verifier );
		$this->assertIsArray( $tokens );
		wp_set_current_user( 0 );

		return $tokens['access_token'];
	}

	/**
	 * A personal token (Application Password) of a customer.
	 *
	 * @return array{0: int, 1: string}
	 */
	protected function personal( int $user, string $scope ): array {
		$made = Tokens::create( $user, 'Script', $scope );
		$this->assertIsArray( $made );

		return array( $user, $made['password'] );
	}

	/**
	 * Sends a JSON-RPC body the way the web server would: authentication first, then dispatch and post-dispatch filters.
	 *
	 * @param array<mixed>|string                  $body Body.
	 * @param string|array{0: int, 1: string}|null $who  A Bearer token, a personal token (user, secret) or nothing.
	 */
	protected function post( $body, $who ): \WP_REST_Response {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		wp_set_current_user( 0 );

		if ( is_string( $who ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $who;
			Tokens::reset( false );
			$error = Server::bearer( null );

			if ( is_wp_error( $error ) ) {
				$response = rest_convert_error_to_response( $error );
				$request  = new \WP_REST_Request( 'POST', '/beltoft-mcp/v1/mcp' );

				return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
			}
		} elseif ( is_array( $who ) ) {
			$_SERVER['PHP_AUTH_USER'] = (string) get_userdata( $who[0] )->user_login;
			$_SERVER['PHP_AUTH_PW']   = $who[1];
			wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
		}

		$request = new \WP_REST_Request( 'POST', '/beltoft-mcp/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( is_string( $body ) ? $body : (string) wp_json_encode( $body ) );

		return apply_filters( 'rest_post_dispatch', rest_get_server()->dispatch( $request ), rest_get_server(), $request );
	}

	/**
	 * @param array<string, mixed>                 $params Params.
	 * @param string|array{0: int, 1: string}|null $who    Who.
	 * @return array<string, mixed> The JSON-RPC reply.
	 */
	protected function rpc( string $method, array $params, $who, int $id = 1 ): array {
		$response = $this->post( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ), $who );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Calls a tool and gives its result.
	 *
	 * @param array<string, mixed>                 $args Arguments.
	 * @param string|array{0: int, 1: string}|null $who  Who.
	 * @return array<string, mixed>
	 */
	protected function tool( string $name, array $args, $who ): array {
		$reply = $this->rpc( 'tools/call', array( 'name' => $name, 'arguments' => $args ), $who );
		$this->assertArrayHasKey( 'result', $reply, wp_json_encode( $reply ) );

		return $reply['result'];
	}

	protected function text( array $result ): string {
		return (string) $result['content'][0]['text'];
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function json( array $result ): array {
		$this->assertFalse( $result['isError'], $this->text( $result ) );

		return json_decode( $this->text( $result ), true );
	}

	/**
	 * Arguments that satisfy a tool's schema, for a service id.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @param int                  $id     Service id.
	 * @return mixed
	 */
	protected function dummy( array $schema, int $id, string $key = '' ) {
		switch ( $schema['type'] ?? '' ) {
			case 'object':
				$out = array();

				foreach ( (array) ( $schema['required'] ?? array() ) as $name ) {
					$out[ $name ] = $this->dummy( (array) ( (array) $schema['properties'] )[ $name ], $id, $name );
				}

				return $out;
			case 'integer':
				return 'id' === $key ? $id : 1;
			case 'boolean':
				return true;
			case 'array':
				return array( $this->dummy( (array) $schema['items'], $id ) );
			case 'string':
				return isset( $schema['enum'] ) ? $schema['enum'][0] : ( 'type' === $key ? 'A' : ( 'proto' === $key ? 'tcp' : ( 'value' === $key ? '192.0.2.1' : 'www' ) ) );
		}

		return null;
	}
}
