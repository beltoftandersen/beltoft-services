<?php
/**
 * Customer REST routes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Api;

use BeltoftServices\Account\Panel;
use BeltoftServices\Drivers\Opens;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes under beltoft-services/v1 for the My Account panel, with WordPress cookie
 * authentication and the standard wp_rest nonce:
 *
 * - GET  resources/<id>                  → {state, desired, label, message}
 * - POST resources/<id>/actions/<action> → 202, queues the job beltoft_services_act
 * - GET  resources/<id>/open             → 302 to the service, signed at click time
 *
 * Logged-in users only (401 otherwise), each route rate limited per user (429): 20 requests a
 * minute for the status route, 10 for actions and Open. A user who
 * does not own the license (its user_id; a guest license matched by email is not enough),
 * a license that is not active, or a resource that is paused or on its way out gets 403
 * and nothing is queued. Actions and Open need a ready resource. No route calls the service:
 * work is queued as jobs. Replies never carry last_error or other driver detail.
 */
final class Routes {

	public const NAMESPACE = 'beltoft-services/v1';

	/**
	 * Requests per user, route and window for actions and Open, unless filtered.
	 */
	public const RATE_LIMIT = 10;

	/**
	 * Requests per user, route and window for the status route, unless filtered: room for
	 * the panel's polling (every 10 s) plus a reload or a second tab.
	 */
	public const STATUS_RATE_LIMIT = 20;

	/**
	 * Rate limit window in seconds.
	 */
	public const WINDOW = 60;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public static function routes(): void {
		$id = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);

		register_rest_route(
			self::NAMESPACE,
			'/resources/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'id' => $id ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/resources/(?P<id>\d+)/actions/(?P<action>[a-z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'action' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'id'   => $id,
					'args' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/resources/(?P<id>\d+)/open',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'open' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'id' => $id ),
			)
		);
	}

	/**
	 * The link for an Open button: the open route with a REST nonce (cookie authentication
	 * needs one, also on a plain link).
	 *
	 * @param int $activation_id Activation id.
	 */
	public static function open_url( int $activation_id ): string {
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( self::NAMESPACE . '/resources/' . $activation_id . '/open' ) );
	}

	/**
	 * GET resources/<id>. A ready resource also gets a usage refresh queued (at most every few
	 * minutes), which runs as a job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function status( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, false, self::STATUS_RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$state = (string) $resource->get( 'state' );

		if ( $resource->accepts_actions() ) {
			Jobs::queue_usage_refresh( $resource->id() );
		}

		return new \WP_REST_Response(
			array(
				'state'   => $state,
				'desired' => (string) $resource->get( 'desired' ),
				'label'   => Panel::label( $state, $resource ),
				'message' => Panel::message( $state, $resource ),
			),
			200
		);
	}

	/**
	 * POST resources/<id>/actions/<action>: queues the action when the driver offers it now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function action( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, true, self::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$action  = (string) $request['action'];
		$driver  = Registry::get( $resource->get( 'driver' ) );
		$offered = $driver ? $driver->customer_actions( $resource ) : array();

		if ( ! array_key_exists( $action, $offered ) ) {
			return self::error( 'beltoft_services_unknown_action', __( 'This action is not available.', 'beltoft-services' ), 403 );
		}

		$args = self::clean_args( $request['args'] );

		/**
		 * A driver's refusal of an action that is offered but whose arguments are not acceptable
		 * (a missing confirmation, an invalid key), answered at once instead of by a background job.
		 *
		 * @param \WP_Error|null       $refusal  Null to accept; an error whose message is for the customer.
		 * @param Resource             $resource Resource.
		 * @param string               $action   Action name.
		 * @param array<string, string> $args     Cleaned arguments.
		 */
		$refusal = apply_filters( 'beltoft_services_action_refusal', null, $resource, $action, $args );

		if ( $refusal instanceof \WP_Error ) {
			$data   = $refusal->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 422;

			return self::error( $refusal->get_error_code(), $refusal->get_error_message(), $status );
		}

		Jobs::queue_act( $resource->id(), $action, $args );

		return new \WP_REST_Response(
			array(
				'queued'  => true,
				'message' => __( 'Your request is on its way. This can take a minute.', 'beltoft-services' ),
			),
			202
		);
	}

	/**
	 * GET resources/<id>/open: sends the customer to the service with a token made now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function open( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, true, self::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$driver = Registry::get( $resource->get( 'driver' ) );
		$url    = $driver instanceof Opens ? $driver->open_url( $resource ) : null;

		if ( null === $url || '' === $url ) {
			return self::error( 'beltoft_services_no_open', __( 'This service cannot be opened from here.', 'beltoft-services' ), 404 );
		}

		$location = self::safe_redirect( $url, (string) $driver->open_host( $resource ) );

		if ( '' === $location ) {
			return self::error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), 403 );
		}

		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $location );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The URL when wp_validate_redirect() accepts it with only the driver's configured host
	 * added to the allowed redirect hosts (for this check only), otherwise ''.
	 *
	 * @param string $url  URL from the driver.
	 * @param string $host The driver's configured host.
	 */
	private static function safe_redirect( string $url, string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( '' === $host || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'https', 'http' ), true ) ) {
			return '';
		}

		$allow = static function ( $hosts ) use ( $host ) {
			$hosts   = is_array( $hosts ) ? $hosts : array();
			$hosts[] = $host;
			return $hosts;
		};

		add_filter( 'allowed_redirect_hosts', $allow );

		try {
			$location = wp_validate_redirect( wp_sanitize_redirect( $url ), '' );
		} finally {
			remove_filter( 'allowed_redirect_hosts', $allow );
		}

		return is_string( $location ) && '' !== $location && strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) ) === $host ? $location : '';
	}

	/**
	 * The checks every route makes, in order: rate limit, then ownership and state. Public for
	 * routes of drivers built on this plugin, so they get the identical checks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $ready   Whether the resource must accept actions.
	 * @param int              $limit   Default requests per minute for this route.
	 * @return Resource|\WP_REST_Response The resource, or the refusal.
	 */
	public static function guard( \WP_REST_Request $request, bool $ready, int $limit ) {
		$user_id = get_current_user_id();

		if ( self::exceeded( $user_id, (string) $request->get_route(), $limit ) ) {
			$response = self::error( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ), 429 );
			$response->header( 'Retry-After', (string) ( self::WINDOW - ( time() % self::WINDOW ) ) );

			return $response;
		}

		$resource = Resource::find( (int) $request['id'] );
		$license  = $resource ? $resource->license() : array();
		$owned    = $user_id > 0 && ! empty( $license['user_id'] ) && (int) $license['user_id'] === $user_id;
		$allowed  = $owned && ( $ready ? $resource->accepts_actions() : $resource->shows_status() );

		// The same answer whether the resource is someone else's, gone or paused.
		if ( ! $allowed ) {
			return self::error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), 403 );
		}

		return $resource;
	}

	/**
	 * Counts this request. True when it is over the limit (filter
	 * beltoft_services_rest_rate_limit; 0 turns it off). The counting is RateLimit::exceeded().
	 *
	 * @param int    $user_id User id.
	 * @param string $route   Request route (with the resource id).
	 * @param int    $limit   Default limit of this route.
	 */
	private static function exceeded( int $user_id, string $route, int $limit ): bool {
		/**
		 * Requests per minute a user may make to one customer route of one resource.
		 *
		 * @param int    $max   Requests: default 20 for the status route, 10 for actions and Open.
		 * @param string $route Request route.
		 */
		$max = (int) apply_filters( 'beltoft_services_rest_rate_limit', $limit, $route );

		return RateLimit::exceeded( $user_id, $route, $max, self::WINDOW );
	}

	/**
	 * Action arguments: at most 20 entries with sanitized keys and scalar, sanitized values.
	 *
	 * @param mixed $args Raw arguments.
	 * @return array<string, string>
	 */
	private static function clean_args( $args ): array {
		$clean = array();

		foreach ( is_array( $args ) ? array_slice( $args, 0, 20, true ) : array() as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' !== $key && is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * An error reply.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message for the customer.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, $message, array( 'status' => $status ) ) );
	}
}
