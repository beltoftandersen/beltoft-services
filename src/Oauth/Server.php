<?php
/**
 * The OAuth 2.1 authorization server of "Use with Claude (MCP)".
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Oauth;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\Access;
use BeltoftServices\Mcp\Endpoint;
use BeltoftServices\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Own implementation (no third-party OAuth code), limited to what a remote MCP connector needs:
 *
 * - discovery: /.well-known/oauth-authorization-server (RFC 8414) and /.well-known/oauth-protected-resource (RFC 9728),
 *   answered from the parse_request hook, so no rewrite rule has to be flushed;
 * - Dynamic Client Registration (RFC 7591) at beltoft-oauth/v1/register, public clients on an allowlist of redirect addresses;
 * - the authorization page at /beltoft-oauth/authorize (the customer logs in through the normal WordPress login, so 2FA
 *   applies, then ticks what the app may do): authorization code with PKCE S256 only;
 * - the token endpoint (authorization_code and refresh_token) and revocation (RFC 7009);
 * - Bearer authentication of REST requests with an access token of ours.
 */
final class Server {

	public const NAMESPACE = 'beltoft-oauth/v1';

	/**
	 * Path of the authorization page below the home address.
	 */
	public const AUTHORIZE_PATH = 'beltoft-oauth/authorize';

	/**
	 * Nonce action of the consent form.
	 */
	public const NONCE = 'beltoft_oauth_consent';

	/**
	 * Longest value of an authorization parameter, in bytes.
	 */
	public const MAX_PARAM = 512;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'discovery' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'authorize_page' ), 1 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'bearer' ), 5 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'challenge' ), 10, 3 );
		// A new password, or a deleted account, ends every connected app.
		add_action( 'after_password_reset', array( Grants::class, 'password_changed' ) );
		add_action( 'wp_set_password', array( Grants::class, 'password_changed' ), 10, 2 );
		add_action( 'profile_update', array( Grants::class, 'profile_updated' ), 10, 2 );
		add_action( 'delete_user', array( Grants::class, 'revoke_user' ) );
	}

	/**
	 * The issuer: the home address without a trailing slash.
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * Address of the authorization page.
	 */
	public static function authorize_url(): string {
		return home_url( '/' . self::AUTHORIZE_PATH );
	}

	/**
	 * RFC 8414 metadata.
	 *
	 * @return array<string, mixed>
	 */
	public static function metadata(): array {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => rest_url( self::NAMESPACE . '/token' ),
			'registration_endpoint'                 => rest_url( self::NAMESPACE . '/register' ),
			'revocation_endpoint'                   => rest_url( self::NAMESPACE . '/revoke' ),
			'authorization_response_iss_parameter_supported' => true,
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( Tokens::READ, Tokens::ACTIONS, Tokens::FULL ),
		);
	}

	/**
	 * RFC 9728 metadata of the MCP endpoint.
	 *
	 * @return array<string, mixed>
	 */
	public static function resource_metadata(): array {
		return array(
			'resource'                 => Endpoint::url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( Tokens::READ, Tokens::ACTIONS, Tokens::FULL ),
			'resource_name'            => (string) apply_filters( 'beltoft_services_mcp_resource_name', get_bloginfo( 'name' ) ),
		);
	}

	/**
	 * The metadata document of a path below the site root, or null when the path is not a discovery address.
	 *
	 * @param string $path Request path, no leading slash.
	 * @return array<string, mixed>|null
	 */
	public static function well_known( string $path ): ?array {
		if ( 1 === preg_match( '#^\.well-known/oauth-authorization-server(/.*)?$#D', $path ) ) {
			return self::metadata();
		}

		if ( 1 === preg_match( '#^\.well-known/oauth-protected-resource(/.*)?$#D', $path ) ) {
			return self::resource_metadata();
		}

		return null;
	}

	/**
	 * Listener of parse_request: answers the two discovery addresses.
	 *
	 * @param \WP $wp WordPress.
	 */
	public static function discovery( $wp ): void {
		$path = $wp instanceof \WP ? ltrim( (string) $wp->request, '/' ) : '';

		if ( 0 !== strpos( $path, '.well-known/oauth-' ) || ! Access::enabled() ) {
			return;
		}

		$doc = self::well_known( $path );

		if ( null === $doc ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $doc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
		exit;
	}

	/**
	 * The REST routes: register, token, revoke.
	 */
	public static function routes(): void {
		foreach ( array( 'register' => 'register_client', 'token' => 'token', 'revoke' => 'revoke' ) as $name => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/' . $name,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $callback ),
					// Public by design: a client has no login yet. Every route limits requests per visitor and checks its input.
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * POST register: Dynamic Client Registration.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function register_client( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Access::enabled() ) {
			return self::oauth_error( 'temporarily_unavailable', 'This feature is turned off.', 503 );
		}

		if ( RateLimit::exceeded( RateLimit::visitor(), 'beltoft-oauth:register', (int) apply_filters( 'beltoft_services_oauth_register_limit', 60 ), HOUR_IN_SECONDS ) ) {
			return self::oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
		}

		$meta = $request->get_json_params();
		$done = Clients::register( is_array( $meta ) ? $meta : $request->get_body_params() );

		if ( is_wp_error( $done ) ) {
			return self::oauth_error( $done->get_error_code(), $done->get_error_message(), 400 );
		}

		return self::reply( $done, 201 );
	}

	/**
	 * POST token.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Access::enabled() ) {
			return self::oauth_error( 'temporarily_unavailable', 'This feature is turned off.', 503 );
		}

		if ( RateLimit::exceeded( RateLimit::visitor(), 'beltoft-oauth:token', (int) apply_filters( 'beltoft_services_oauth_token_limit', 600 ) ) ) {
			return self::oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
		}

		if ( self::secret_in_query( $request ) ) {
			return self::oauth_error( 'invalid_request', 'Send secrets in the request body, not the address.', 400 );
		}

		$client = Clients::find( self::param( $request, 'client_id' ) );

		if ( null === $client ) {
			return self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}

		// Claude's requests all come from a few addresses, so each app is counted as well.
		if ( RateLimit::exceeded( 'c_' . $client['client_id'], 'beltoft-oauth:token-client', (int) apply_filters( 'beltoft_services_oauth_client_token_limit', 60 ) ) ) {
			return self::oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
		}

		$type = self::param( $request, 'grant_type' );

		if ( 'authorization_code' === $type ) {
			$result = Grants::exchange_code( self::secret( $request, 'code' ), $client['client_id'], self::param( $request, 'redirect_uri' ), self::secret( $request, 'code_verifier' ) );
		} elseif ( 'refresh_token' === $type ) {
			$result = Grants::refresh( self::secret( $request, 'refresh_token' ), $client['client_id'] );
		} else {
			return self::oauth_error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.', 400 );
		}

		return is_wp_error( $result ) ? self::oauth_error( $result->get_error_code(), $result->get_error_message(), 400 ) : self::reply( $result, 200 );
	}

	/**
	 * POST revoke (RFC 7009): always 200, also for a token that is unknown.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		if ( RateLimit::exceeded( RateLimit::visitor(), 'beltoft-oauth:revoke', 60 ) ) {
			return self::oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
		}

		if ( self::secret_in_query( $request ) ) {
			return self::oauth_error( 'invalid_request', 'Send secrets in the request body, not the address.', 400 );
		}

		$client = Clients::find( self::param( $request, 'client_id' ) );

		if ( null === $client ) {
			return self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}

		Grants::revoke_token( self::secret( $request, 'token' ), $client['client_id'] );

		return self::reply( new \stdClass(), 200 );
	}

	/**
	 * Listener of rest_authentication_errors: a request with one of our access tokens is that customer, limited to the
	 * token's scope. A token we issued that is not valid is a 401; any other Authorization header is left alone.
	 *
	 * @param mixed $result Result so far.
	 * @return mixed
	 */
	public static function bearer( $result ) {
		$token = self::bearer_token();

		if ( null === $token || 0 !== strpos( $token, 'bso_a_' ) || null !== $result ) {
			return $result;
		}

		$grant = Access::enabled() ? Grants::authenticate( $token ) : null;

		if ( null === $grant ) {
			return new \WP_Error( 'beltoft_oauth_invalid_token', __( 'The access token is not valid.', 'beltoft-services' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( $grant['user'] );
		Tokens::use_grant( $grant );

		return true;
	}

	/**
	 * Listener of rest_post_dispatch: a 401 from the MCP endpoint says where the protected resource metadata is.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   Server.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function challenge( $response, $server, $request ) {
		unset( $server );

		if ( $response instanceof \WP_HTTP_Response && 401 === $response->get_status() && $request instanceof \WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/' . Endpoint::NAMESPACE . '/' ) ) {
			$value = 'Bearer resource_metadata="' . esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ) . '"';

			if ( null !== self::bearer_token() ) {
				$value .= ', error="invalid_token"';
			}

			$response->header( 'WWW-Authenticate', $value );
		}

		return $response;
	}

	/**
	 * The Bearer token of this request, or null.
	 */
	public static function bearer_token(): ?string {
		$header = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
				break;
			}
		}

		return 1 === preg_match( '/^Bearer\s+(\S+)$/iD', $header, $m ) ? $m[1] : null;
	}

	/**
	 * Listener of template_redirect: the authorization page.
	 */
	public static function authorize_page(): void {
		global $wp;

		if ( ! is_object( $wp ) || trim( (string) $wp->request, '/' ) !== self::AUTHORIZE_PATH ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- The nonce is checked in authorize() for the POST.
		$post   = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
		$source = $post ? $_POST : $_GET;
		// phpcs:enable WordPress.Security.NonceVerification
		$params = array();

		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource', 'decision', 'allow_actions', 'level', 'understand_full', '_wpnonce' ) as $key ) {
			if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) {
				// Not run through a text sanitizer: state and the redirect address must come back exactly as sent.
				// They are capped, refused when they hold control characters, and escaped where they are printed.
				$value = wp_unslash( (string) $source[ $key ] );

				if ( strlen( $value ) > self::MAX_PARAM || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
					$params['_invalid'] = '1';
					continue;
				}

				$params[ $key ] = $value;
			}
		}

		$result = self::authorize( $params, get_current_user_id(), $post );

		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );

		if ( 'redirect' === $result['type'] ) {
			// An external address on purpose: it is the client's registered redirect address, checked by authorize().
			wp_redirect( $result['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		if ( 'login' === $result['type'] ) {
			wp_safe_redirect( wp_login_url( add_query_arg( rawurlencode_deep( self::query( $params ) ), self::authorize_url() ) ) );
			exit;
		}

		status_header( (int) $result['status'] );
		$wp_query = $GLOBALS['wp_query'] ?? null;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}

		\BeltoftServices\Account\Panel::style();
		add_filter( 'pre_get_document_title', static fn() => __( 'Connect an app', 'beltoft-services' ) );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		get_header();
		self::print_page( $result, $params );
		get_footer();
		exit;
	}

	/**
	 * Decides what the authorization request gets.
	 *
	 * @param array<string, string> $params  Request parameters.
	 * @param int                   $user_id Logged in customer, or 0.
	 * @param bool                  $post    Whether the consent form was posted.
	 * @return array<string, mixed> type redirect (url), login, or page (status, kind, client, ...).
	 */
	public static function authorize( array $params, int $user_id, bool $post ): array {
		$fail = static fn( string $kind, int $status = 400 ): array => array(
			'type'   => 'page',
			'status' => $status,
			'kind'   => $kind,
		);

		if ( ! Access::enabled() ) {
			return $fail( 'off', 404 );
		}

		if ( ! empty( $params['_invalid'] ) ) {
			return $fail( 'invalid' );
		}

		$client   = Clients::find( (string) ( $params['client_id'] ?? '' ) );
		$redirect = (string) ( $params['redirect_uri'] ?? '' );

		// Nothing is sent to an address that is not registered: the customer gets a page, not a redirect.
		if ( null === $client || ! Clients::allows_redirect( $client, $redirect ) ) {
			return $fail( 'invalid' );
		}

		$state = (string) ( $params['state'] ?? '' );
		$back  = static function ( string $error, string $text ) use ( $redirect, $state ): array {
			$args = array( 'error' => $error, 'error_description' => $text, 'iss' => self::issuer() );

			if ( '' !== $state ) {
				$args['state'] = $state;
			}

			return array( 'type' => 'redirect', 'url' => add_query_arg( rawurlencode_deep( $args ), $redirect ) );
		};

		if ( 'code' !== ( $params['response_type'] ?? '' ) ) {
			return $back( 'unsupported_response_type', 'Only response_type code is supported.' );
		}

		if ( 'S256' !== ( $params['code_challenge_method'] ?? '' ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/D', (string) ( $params['code_challenge'] ?? '' ) ) ) {
			return $back( 'invalid_request', 'PKCE with S256 is required.' );
		}

		if ( ! empty( $params['resource'] ) && untrailingslashit( (string) $params['resource'] ) !== untrailingslashit( Endpoint::url() ) ) {
			return $back( 'invalid_target', 'Unknown resource.' );
		}

		if ( $user_id <= 0 ) {
			return array( 'type' => 'login' );
		}

		if ( ! Access::user_allowed( $user_id ) ) {
			return $fail( 'user_off', 403 );
		}

		if ( ! $post ) {
			return array(
				'type'    => 'page',
				'status'  => 200,
				'kind'    => 'consent',
				'client'  => $client,
				'host'    => (string) wp_parse_url( $redirect, PHP_URL_HOST ),
				// The choice is never set from the request: the customer chooses. This only says what the app asked for.
				'asks'         => self::asked( (string) ( $params['scope'] ?? '' ) ),
				'asks_actions' => Tokens::rank( self::asked( (string) ( $params['scope'] ?? '' ) ) ) >= Tokens::rank( Tokens::ACTIONS ),
				// Verified: the redirect is on the owner's list and the app calls itself exactly Claude.
				'verified' => 'Claude' === $client['name'] && in_array( $redirect, Access::redirect_allowlist(), true ),
				'account'  => self::account( $user_id ),
			);
		}

		if ( ! wp_verify_nonce( (string) ( $params['_wpnonce'] ?? '' ), self::NONCE ) ) {
			return $fail( 'expired' );
		}

		if ( 'approve' !== ( $params['decision'] ?? '' ) ) {
			return $back( 'access_denied', 'The customer did not allow access.' );
		}

		// The page posts level. A page that was open before the update posts allow_actions=1, which is actions.
		$scope = Tokens::level( (string) ( $params['level'] ?? ( ! empty( $params['allow_actions'] ) ? Tokens::ACTIONS : '' ) ) );

		// Full needs its own tick, checked here and not only in the page: without it the page is shown again.
		if ( Tokens::FULL === $scope && empty( $params['understand_full'] ) ) {
			return array(
				'type'         => 'page',
				'status'       => 200,
				'kind'         => 'consent',
				'client'       => $client,
				'host'         => (string) wp_parse_url( $redirect, PHP_URL_HOST ),
				'asks'         => self::asked( (string) ( $params['scope'] ?? '' ) ),
				'asks_actions' => true,
				'notice'       => 'full_tick',
				'verified'     => 'Claude' === $client['name'] && in_array( $redirect, Access::redirect_allowlist(), true ),
				'account'      => self::account( $user_id ),
			);
		}

		$code  = Grants::issue_code( $client['client_id'], $user_id, $scope, $redirect, (string) $params['code_challenge'] );
		$args  = array( 'code' => $code, 'iss' => self::issuer() );

		if ( '' !== $state ) {
			$args['state'] = $state;
		}

		return array( 'type' => 'redirect', 'url' => add_query_arg( rawurlencode_deep( $args ), $redirect ) );
	}

	/**
	 * The highest level a scope parameter asks for.
	 *
	 * @param string $scope Space separated scope parameter.
	 */
	private static function asked( string $scope ): string {
		$words = explode( ' ', ' ' . $scope . ' ' );

		return in_array( Tokens::FULL, $words, true ) ? Tokens::FULL : ( in_array( Tokens::ACTIONS, $words, true ) ? Tokens::ACTIONS : Tokens::READ );
	}

	/**
	 * The account the customer is signed in as, for the consent page.
	 *
	 * @param int $user_id Customer.
	 * @return array{name: string, email: string}
	 */
	private static function account( int $user_id ): array {
		$user = get_userdata( $user_id );

		return array(
			'name'  => $user ? (string) $user->display_name : '',
			'email' => $user ? (string) $user->user_email : '',
		);
	}

	/**
	 * The parameters that go back into the login return address and the consent form.
	 *
	 * @param array<string, string> $params Request parameters.
	 * @return array<string, string>
	 */
	private static function query( array $params ): array {
		return array_intersect_key( $params, array_flip( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' ) ) );
	}

	/**
	 * Prints the page inside the theme.
	 *
	 * @param array<string, mixed>  $result Result of authorize().
	 * @param array<string, string> $params Request parameters.
	 */
	private static function print_page( array $result, array $params ): void {
		$args = array(
			'result' => $result,
			'fields' => self::query( $params ),
			'nonce'  => wp_create_nonce( self::NONCE ),
			'action' => self::authorize_url(),
		);

		if ( function_exists( 'wc_get_template' ) ) {
			wc_get_template( 'oauth-consent.php', $args, 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

			return;
		}

		include BELTOFT_SERVICES_PATH . 'templates/account/oauth-consent.php';
	}

	/**
	 * A request value, trimmed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Name.
	 */
	private static function param( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * A secret of the request body (a code, a token, a verifier). The address is never read for these: it ends up in logs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Name.
	 */
	private static function secret( \WP_REST_Request $request, string $key ): string {
		$body  = array_merge( (array) $request->get_body_params(), is_array( $request->get_json_params() ) ? $request->get_json_params() : array() );
		$value = $body[ $key ] ?? '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Did the request put a secret in its address?
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function secret_in_query( \WP_REST_Request $request ): bool {
		return (bool) array_intersect( array( 'code', 'refresh_token', 'token', 'code_verifier' ), array_keys( (array) $request->get_query_params() ) );
	}

	/**
	 * A reply that must not be cached.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status Status.
	 */
	private static function reply( $data, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * An OAuth error reply.
	 *
	 * @param string $code   Error code.
	 * @param string $text   Description.
	 * @param int    $status Status.
	 */
	private static function oauth_error( string $code, string $text, int $status ): \WP_REST_Response {
		return self::reply( array( 'error' => $code, 'error_description' => $text ), $status );
	}
}
