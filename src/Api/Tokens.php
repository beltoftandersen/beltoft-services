<?php
/**
 * Personal API tokens.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Api;

use BeltoftLicenses\Events;
use BeltoftServices\Mcp\Endpoint;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A personal token is a WordPress Application Password (core hashes it, shows it once, records when it was last
 * used and lets the user revoke it) plus a scope kept here per token, because core's item has no field for one.
 * The scope lives in user meta keyed by the password's uuid and is removed when the password is deleted.
 *
 * A request that authenticated with an Application Password is a token request. For it:
 *
 * - without a scope recorded here (a password made in another way), the customer routes are refused;
 * - only the customer routes that are safe to hand to a script are reachable, everything else in WordPress
 *   (core routes, other plugins) is refused, and so is XML-RPC;
 * - the read scope reaches GET routes only; the actions scope also reaches POST, PUT and DELETE, except for actions
 *   that destroy or reveal something (these stay in the dashboard behind its confirmations);
 * - an access token of the OAuth server (Oauth\Grants) is a token request too, with the level the customer chose
 *   (read, actions or full). Full only means something to the MCP tools; on the REST routes it works as actions, so the
 *   action filter below keeps protecting them. Personal tokens are never full;
 * - requests are counted per token (filter beltoft_services_token_rate_limit, default 60 a minute), on top of the
 *   per-user, per-route limits the routes keep;
 * - a change that was accepted is written to the license history ("API: reboot requested").
 *
 * The owner checks of the routes are untouched: the token acts as its user and nothing more.
 */
final class Tokens {

	public const READ    = 'read';
	public const ACTIONS = 'actions';
	public const FULL    = 'full';

	/**
	 * The version of what Full access means: bump it when the set of tools only Full may call changes. A grant made
	 * under an older version works as actions until the customer connects again (see Oauth\Grants).
	 */
	public const FULL_CONSENT = 1;

	/**
	 * User meta: uuid => scope.
	 */
	public const META = 'beltoft_services_token_scopes';

	/**
	 * Tokens one customer may hold.
	 */
	public const MAX = 10;

	/**
	 * Requests per token and minute, unless filtered.
	 */
	public const RATE_LIMIT = 60;

	/**
	 * Customer routes a token may reach (after the namespace).
	 */
	private const ALLOWED = '#^/beltoft-services/v1/(resources(/\d+(/(metrics|db-ca|dns-records(/[A-Za-z]+/[^/]+)?|actions/[a-z0-9_-]+))?)?|usage|domains/check|openapi\.json)$#D';

	/**
	 * Actions a token can never run.
	 */
	private const BLOCKED_ACTION = '#(delete|remove|destroy|restore|rollback|reinstall|transfer|reveal|ssh_key|cancel|password)#';

	/**
	 * Can a token reach this route (after the host, as WP_REST_Request::get_route() gives it)?
	 *
	 * @param string $route Route.
	 */
	public static function reachable( string $route ): bool {
		return 1 === preg_match( self::ALLOWED, $route );
	}

	/**
	 * The password of the current request: array(uuid, user, scope) or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $current = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'determine_current_user', array( __CLASS__, 'reset' ), 1 );
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'authenticated' ), 10, 2 );
		add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'api_only' ), 10, 3 );
		add_action( 'wp_delete_application_password', array( __CLASS__, 'deleted' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'log' ), 10, 3 );
	}

	/**
	 * Remembers the OAuth access token that logged this request in.
	 *
	 * @param array{user: int, scope: string, family: string} $grant Grant from Oauth\Grants::authenticate().
	 */
	public static function use_grant( array $grant ): void {
		self::$current = array(
			'uuid'  => 'oauth_' . $grant['family'],
			'user'  => (int) $grant['user'],
			'scope' => self::level( (string) $grant['scope'] ),
		);
	}

	/**
	 * A level the system knows: read, actions or full. Anything else, empty included, is read (the safe default).
	 *
	 * @param string $scope Stored or requested value.
	 */
	public static function level( string $scope ): string {
		$scope = explode( ':', $scope )[0];

		return in_array( $scope, array( self::ACTIONS, self::FULL ), true ) ? $scope : self::READ;
	}

	/**
	 * What is stored for a level: full carries the version of the consent it was given under ("full:1").
	 *
	 * @param string $scope Level, or an already stored value (kept as it is).
	 * @param bool   $fresh A consent given now: full gets the current version. Otherwise a full without a version is
	 *                      from before versions and stays stale.
	 */
	public static function pack( string $scope, bool $fresh = false ): string {
		$level = self::level( $scope );

		if ( self::FULL !== $level ) {
			return $level;
		}

		return str_contains( $scope, ':' ) ? $scope : self::FULL . ':' . ( $fresh ? self::FULL_CONSENT : 0 );
	}

	/**
	 * Is a stored full from before the last change of what Full means (a plain "full" has no version: stale)?
	 *
	 * @param string $stored Stored value.
	 */
	public static function stale( string $stored ): bool {
		return self::FULL === self::level( $stored ) && (int) ( explode( ':', $stored )[1] ?? 0 ) !== self::FULL_CONSENT;
	}

	/**
	 * The order of the levels: read 1, actions 2, full 3. Empty or unknown is 0 (no access).
	 *
	 * @param string $scope Level.
	 */
	public static function rank( string $scope ): int {
		$at = array_search( $scope, array( self::READ, self::ACTIONS, self::FULL ), true );

		return false === $at ? 0 : (int) $at + 1;
	}

	/**
	 * The levels an OAuth grant can have, level => label.
	 *
	 * @return array<string, string>
	 */
	public static function levels(): array {
		return self::scopes() + array( self::FULL => __( 'Full access', 'beltoft-services' ) );
	}

	/**
	 * Valid scopes, scope => label.
	 *
	 * @return array<string, string>
	 */
	public static function scopes(): array {
		return array(
			self::READ    => __( 'Read only', 'beltoft-services' ),
			self::ACTIONS => __( 'Read and actions', 'beltoft-services' ),
		);
	}

	/**
	 * Creates a token.
	 *
	 * @param int    $user_id User.
	 * @param string $name    A name the customer recognises.
	 * @param string $scope   read or actions.
	 * @return array{password: string, uuid: string}|\WP_Error The password is returned this once.
	 */
	public static function create( int $user_id, string $name, string $scope ) {
		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name || ! isset( self::scopes()[ $scope ] ) ) {
			return new \WP_Error( 'beltoft_services_token_invalid', __( 'Give the token a name and choose what it may do.', 'beltoft-services' ) );
		}

		if ( count( self::all( $user_id ) ) >= self::MAX ) {
			return new \WP_Error( 'beltoft_services_token_max', __( 'You have the most tokens allowed. Revoke one you no longer use first.', 'beltoft-services' ) );
		}

		$made = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );

		if ( is_wp_error( $made ) ) {
			return $made;
		}

		$scopes                     = self::stored( $user_id );
		$scopes[ $made[1]['uuid'] ] = $scope;
		update_user_meta( $user_id, self::META, $scopes );

		return array(
			'password' => \WP_Application_Passwords::chunk_password( $made[0] ),
			'uuid'     => (string) $made[1]['uuid'],
		);
	}

	/**
	 * A customer's tokens (the passwords that carry one of our scopes), never with the secret or its hash.
	 *
	 * @param int $user_id User.
	 * @return array<int, array{uuid: string, name: string, scope: string, created: int, last_used: int}>
	 */
	public static function all( int $user_id ): array {
		$scopes = self::stored( $user_id );
		$out    = array();

		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( ! isset( $scopes[ $item['uuid'] ] ) ) {
				continue;
			}

			$out[] = array(
				'uuid'      => (string) $item['uuid'],
				'name'      => (string) $item['name'],
				'scope'     => (string) $scopes[ $item['uuid'] ],
				'created'   => (int) $item['created'],
				'last_used' => (int) $item['last_used'],
			);
		}

		return $out;
	}

	/**
	 * Revokes a token of this user. Only tokens that carry one of our scopes can be revoked here.
	 *
	 * @param int    $user_id User.
	 * @param string $uuid    Token id.
	 */
	public static function revoke( int $user_id, string $uuid ): bool {
		if ( ! isset( self::stored( $user_id )[ $uuid ] ) ) {
			return false;
		}

		return true === \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}

	/**
	 * The token behind this request, or null for a cookie session or no login.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::$current;
	}

	/**
	 * Forgets the last request's token, before WordPress decides who the user is.
	 *
	 * @param mixed $user_id Passed through.
	 * @return mixed
	 */
	public static function reset( $user_id ) {
		self::$current = null;

		return $user_id;
	}

	/**
	 * Remembers the password that logged this request in.
	 *
	 * @param mixed $user WP_User.
	 * @param mixed $item Password item.
	 */
	public static function authenticated( $user, $item ): void {
		if ( ! $user instanceof \WP_User || ! is_array( $item ) || empty( $item['uuid'] ) ) {
			return;
		}

		$scope         = self::stored( (int) $user->ID )[ $item['uuid'] ] ?? '';
		self::$current = array(
			'uuid'  => (string) $item['uuid'],
			'user'  => (int) $user->ID,
			'scope' => isset( self::scopes()[ $scope ] ) ? $scope : '',
		);
	}

	/**
	 * A token works over the REST API only: a scoped password is refused on XML-RPC.
	 *
	 * @param mixed $error WP_Error to add to.
	 * @param mixed $user  User.
	 * @param mixed $item  Password item.
	 */
	public static function api_only( $error, $user, $item ): void {
		if ( ! $error instanceof \WP_Error || ! $user instanceof \WP_User || ! is_array( $item ) || ! array_key_exists( (string) ( $item['uuid'] ?? '' ), self::stored( (int) $user->ID ) ) ) {
			return;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$error->add( 'beltoft_services_token_rest_only', __( 'This token works for the REST API only.', 'beltoft-services' ) );
		}
	}

	/**
	 * Drops the scope of a deleted password.
	 *
	 * @param mixed $user_id User.
	 * @param mixed $item    Password item.
	 */
	public static function deleted( $user_id, $item ): void {
		$scopes = self::stored( (int) $user_id );

		if ( is_array( $item ) && isset( $scopes[ $item['uuid'] ] ) ) {
			unset( $scopes[ $item['uuid'] ] );
			update_user_meta( (int) $user_id, self::META, $scopes );
		}
	}

	/**
	 * Checks a token request before it is routed: route, scope, action and rate.
	 *
	 * @param mixed            $result  Short-circuit value.
	 * @param mixed            $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed A WP_Error to refuse, else the value unchanged.
	 */
	public static function gate( $result, $server, $request ) {
		unset( $server );

		if ( null === self::$current || null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();

		// The MCP endpoint checks scope per tool and counts calls itself; what it calls inside is checked there.
		if ( '/' . Endpoint::NAMESPACE . '/mcp' === $route || Endpoint::inside() ) {
			return $result;
		}

		$method = strtoupper( $request->get_method() );
		// Full is a level of the MCP tools. On the REST routes it works as actions, so nothing here opens up.
		$scope  = self::FULL === self::$current['scope'] ? self::ACTIONS : (string) self::$current['scope'];
		$read   = in_array( $method, array( 'GET', 'HEAD' ), true );

		if ( '' === $scope || 1 !== preg_match( self::ALLOWED, $route ) ) {
			return self::refuse( 'beltoft_services_token_route', __( 'This token cannot be used for this address.', 'beltoft-services' ), 403 );
		}

		if ( ! $read && self::ACTIONS !== $scope ) {
			return self::refuse( 'beltoft_services_token_scope', __( 'This token can only read. Make a token with actions to change something.', 'beltoft-services' ), 403 );
		}

		if ( preg_match( '#/actions/([a-z0-9_-]+)$#D', $route, $m ) && ( 1 === preg_match( self::BLOCKED_ACTION, $m[1] ) || in_array( $m[1], (array) apply_filters( 'beltoft_services_token_blocked_actions', array() ), true ) ) ) {
			return self::refuse( 'beltoft_services_token_action', __( 'This action is only available in your dashboard.', 'beltoft-services' ), 403 );
		}

		/**
		 * Requests a token may make per minute (0 turns the limit off).
		 *
		 * @param int $max Requests per minute.
		 */
		$max = (int) apply_filters( 'beltoft_services_token_rate_limit', self::RATE_LIMIT );

		if ( RateLimit::exceeded( 'token_' . self::$current['uuid'], 'beltoft-services:token', $max, MINUTE_IN_SECONDS ) ) {
			return self::refuse( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ), 429 );
		}

		return $result;
	}

	/**
	 * Writes an accepted change from a token to the license history.
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function log( $response, $handler, $request ) {
		unset( $handler );

		if ( null === self::$current || Endpoint::inside() || ! $request instanceof \WP_REST_Request || ! $response instanceof \WP_HTTP_Response || $response->get_status() >= 400 ) {
			return $response;
		}

		$route = (string) $request->get_route();

		if ( in_array( strtoupper( $request->get_method() ), array( 'GET', 'HEAD' ), true ) || 1 !== preg_match( '#^/beltoft-services/v1/resources/(\d+)/(actions|dns-records)(?:/([A-Za-z0-9_-]+)(?:/([^/]+))?)?$#D', $route, $m ) ) {
			return $response;
		}

		$resource = Resource::find( (int) $m[1] );
		$license  = $resource ? $resource->license() : array();

		if ( empty( $license['id'] ) ) {
			return $response;
		}

		if ( 'actions' === $m[2] ) {
			/* translators: %s: action name, e.g. "reboot" */
			$text = sprintf( __( 'API: %s requested', 'beltoft-services' ), str_replace( array( '_', '-' ), ' ', (string) ( $m[3] ?? '' ) ) );
		} else {
			$what = trim( strtoupper( (string) ( $m[3] ?? '' ) ) . ' ' . rawurldecode( (string) ( $m[4] ?? '' ) ) );
			/* translators: %s: record type and name */
			$text = sprintf( 'DELETE' === strtoupper( $request->get_method() ) ? __( 'API: DNS record removed (%s)', 'beltoft-services' ) : __( 'API: DNS record set (%s)', 'beltoft-services' ), $what );
		}

		Events::add( (int) $license['id'], 'api', $text );

		return $response;
	}

	/**
	 * A refusal.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function refuse( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Stored scopes of a user.
	 *
	 * @param int $user_id User.
	 * @return array<string, string>
	 */
	private static function stored( int $user_id ): array {
		$scopes = get_user_meta( $user_id, self::META, true );

		return is_array( $scopes ) ? $scopes : array();
	}
}
