<?php
/**
 * The MCP endpoint ("Use with Claude (MCP)"): Streamable HTTP, plain JSON replies, JSON-RPC 2.0.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Api\Tokens;
use BeltoftServices\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/beltoft-mcp/v1/mcp. Methods: initialize, notifications/initialized, ping, tools/list, tools/call.
 * Protocol versions 2025-06-18 and 2025-03-26 (a request for another version gets the newest we have, as the
 * specification says). No server push, so no SSE: GET answers 405.
 *
 * Who may call: a request with an OAuth access token of this site (Oauth\Server::bearer) or a personal token (M1), in
 * both cases with the customer's own "Allow Claude (MCP) access" switch on. A cookie session is not enough. Scope is
 * checked per tool; ownership by the same code as the REST routes (see Tools).
 */
final class Endpoint {

	public const NAMESPACE = 'beltoft-mcp/v1';

	/**
	 * Protocol versions, newest first.
	 */
	public const VERSIONS = array( '2025-06-18', '2025-03-26' );

	/**
	 * Calls per token and minute, unless filtered.
	 */
	public const RATE_LIMIT = 60;

	/**
	 * Depth of nested internal REST calls of the running tool.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The route.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/mcp',
			array(
				'methods'             => \WP_REST_Server::ALLMETHODS,
				'callback'            => array( __CLASS__, 'handle' ),
				// Authentication is checked in handle(): an unauthenticated call must get the 401 with the
				// WWW-Authenticate challenge that tells the client where to log in, not a generic REST error.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The address a client adds as the connector.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . '/mcp' );
	}

	/**
	 * Is a tool running its own REST calls (they are not counted or logged a second time)?
	 */
	public static function inside(): bool {
		return self::$depth > 0;
	}

	/**
	 * Runs a callback as an internal call.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function nested( callable $callback ) {
		++self::$depth;

		try {
			return $callback();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Handles one request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Access::enabled() ) {
			return self::http( array( 'error' => 'not_found' ), 404 );
		}

		$token = Tokens::current();

		if ( null === $token || '' === (string) $token['scope'] || get_current_user_id() !== (int) $token['user'] ) {
			return self::http( array( 'error' => 'unauthorized' ), 401 );
		}

		if ( ! Access::user_allowed( (int) $token['user'] ) ) {
			return self::http( array( 'error' => 'access_off', 'message' => __( 'Connecting apps is turned off for this account.', 'beltoft-services' ) ), 403 );
		}

		if ( 'POST' !== $request->get_method() ) {
			$response = self::http( array( 'error' => 'method_not_allowed' ), 405 );
			$response->header( 'Allow', 'POST' );

			return $response;
		}

		/**
		 * Calls a token may make per minute to the MCP endpoint (0 turns the limit off).
		 *
		 * @param int $max Calls per minute.
		 */
		$max = (int) apply_filters( 'beltoft_services_mcp_rate_limit', self::RATE_LIMIT );

		if ( RateLimit::exceeded( 'mcp_' . $token['uuid'], 'beltoft-services:mcp', $max, MINUTE_IN_SECONDS ) ) {
			$response = self::http( array( 'error' => 'rate_limited', 'message' => __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ) ), 429 );
			$response->header( 'Retry-After', '60' );

			return $response;
		}

		$body = json_decode( (string) $request->get_body(), true );

		if ( ! is_array( $body ) ) {
			return self::http( self::error( null, -32700, 'Parse error' ), 400 );
		}

		// A list is a batch (2025-03-26); an object is one message.
		$batch   = array_is_list( $body ) && array() !== $body;
		$replies = array();

		foreach ( $batch ? $body : array( $body ) as $message ) {
			$reply = self::message( is_array( $message ) ? $message : array(), $token );

			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}

		if ( ! $replies ) {
			return self::http( null, 202 );
		}

		return self::http( $batch ? $replies : $replies[0], 200 );
	}

	/**
	 * Answers one JSON-RPC message; null for a notification.
	 *
	 * @param array<string, mixed> $message Message.
	 * @param array<string, mixed> $token   Token of the request.
	 * @return array<string, mixed>|null
	 */
	private static function message( array $message, array $token ): ?array {
		$id     = $message['id'] ?? null;
		$has_id = array_key_exists( 'id', $message );
		$method = $message['method'] ?? null;

		if ( ( $has_id && ! is_int( $id ) && ! is_string( $id ) ) || '2.0' !== ( $message['jsonrpc'] ?? '' ) || ! is_string( $method ) ) {
			// A response from the client, or something that is not a request.
			return isset( $message['result'] ) || isset( $message['error'] ) ? null : self::error( $has_id ? $id : null, -32600, 'Invalid Request' );
		}

		$params = $message['params'] ?? array();

		if ( ! is_array( $params ) ) {
			return $has_id ? self::error( $id, -32602, 'Invalid params' ) : null;
		}

		if ( ! $has_id ) {
			// Notifications (initialized, cancelled) need no reply.
			return null;
		}

		switch ( $method ) {
			case 'initialize':
				$asked = (string) ( $params['protocolVersion'] ?? '' );

				return self::result(
					$id,
					array(
						'protocolVersion' => in_array( $asked, self::VERSIONS, true ) ? $asked : self::VERSIONS[0],
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array( 'name' => 'beltoft-services', 'version' => defined( 'BELTOFT_SERVICES_VERSION' ) ? (string) BELTOFT_SERVICES_VERSION : '1' ),
						'instructions'    => __( 'Manage the services of one customer: servers, databases, domains and DNS. Start with list_services. Ask the customer before you change anything. Passwords and other secrets are never available here.', 'beltoft-services' ),
					)
				);

			case 'ping':
				return self::result( $id, new \stdClass() );

			case 'tools/list':
				return self::result( $id, array( 'tools' => Tools::listing( (string) $token['scope'] ) ) );

			case 'tools/call':
				$name = $params['name'] ?? null;
				$args = $params['arguments'] ?? array();

				if ( ! is_string( $name ) || ! is_array( $args ) || ( $args && array_is_list( $args ) ) ) {
					return self::error( $id, -32602, 'Invalid params' );
				}

				if ( ! Tools::exists( $name ) ) {
					return self::error( $id, -32602, 'Unknown tool' );
				}

				return self::result( $id, Tools::call( $name, $args, $token ) );
		}

		return self::error( $id, -32601, 'Method not found' );
	}

	/**
	 * A JSON-RPC result.
	 *
	 * @param int|string $id     Id.
	 * @param mixed      $result Result.
	 * @return array<string, mixed>
	 */
	private static function result( $id, $result ): array {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	/**
	 * A JSON-RPC error.
	 *
	 * @param int|string|null $id      Id.
	 * @param int             $code    Code.
	 * @param string          $message Message.
	 * @return array<string, mixed>
	 */
	private static function error( $id, int $code, string $message ): array {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}

	/**
	 * A reply that is never cached.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status Status.
	 */
	private static function http( $data, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
