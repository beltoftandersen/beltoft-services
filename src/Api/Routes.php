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
 * Logged-in users only (401 otherwise), each route rate limited per user (429). A user who
 * does not own the license (its user_id; a guest license matched by email is not enough),
 * a license that is not active, or a resource that is paused or on its way out gets 403
 * and nothing is queued. Actions and Open need a ready resource. No route calls the service:
 * work is queued as jobs. Replies never carry last_error or other driver detail.
 */
final class Routes {

	public const NAMESPACE = 'beltoft-services/v1';

	/**
	 * Requests per user, route and window, unless filtered.
	 */
	public const RATE_LIMIT = 10;

	/**
	 * Rate limit window in seconds.
	 */
	public const WINDOW = 60;

	/**
	 * Object cache group of the rate limit counters.
	 */
	private const GROUP = 'beltoft_services_rl';

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
		$resource = self::guard( $request, false );

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
				'label'   => Panel::label( $state ),
				'message' => Panel::message( $state ),
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
		$resource = self::guard( $request, true );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$action  = (string) $request['action'];
		$driver  = Registry::get( $resource->get( 'driver' ) );
		$offered = $driver ? $driver->customer_actions( $resource ) : array();

		if ( ! array_key_exists( $action, $offered ) ) {
			return self::error( 'beltoft_services_unknown_action', __( 'This action is not available.', 'beltoft-services' ), 403 );
		}

		Jobs::queue_act( $resource->id(), $action, self::clean_args( $request['args'] ) );

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
		$resource = self::guard( $request, true );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$driver = Registry::get( $resource->get( 'driver' ) );
		$url    = $driver instanceof Opens ? $driver->open_url( $resource ) : null;

		if ( null === $url || '' === $url ) {
			return self::error( 'beltoft_services_no_open', __( 'This service cannot be opened from here.', 'beltoft-services' ), 404 );
		}

		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', esc_url_raw( $url ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The checks every route makes, in order: rate limit, then ownership and state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $ready   Whether the resource must accept actions.
	 * @return Resource|\WP_REST_Response The resource, or the refusal.
	 */
	private static function guard( \WP_REST_Request $request, bool $ready ) {
		$user_id = get_current_user_id();

		if ( self::exceeded( $user_id, (string) $request->get_route() ) ) {
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
	 * beltoft_services_rest_rate_limit; 0 turns it off). Counters live in the persistent object
	 * cache when there is one, otherwise in transients; if counting fails the request is let
	 * through.
	 *
	 * @param int    $user_id User id.
	 * @param string $route   Request route (with the resource id).
	 */
	private static function exceeded( int $user_id, string $route ): bool {
		/**
		 * Requests per minute a user may make to one customer route of one resource.
		 *
		 * @param int    $max   Requests, default 10.
		 * @param string $route Request route.
		 */
		$max = (int) apply_filters( 'beltoft_services_rest_rate_limit', self::RATE_LIMIT, $route );

		if ( $max <= 0 ) {
			return false;
		}

		$key = 'rl_' . md5( $user_id . '|' . $route . '|' . (int) floor( time() / self::WINDOW ) );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::GROUP, self::WINDOW );
			$count = wp_cache_incr( $key, 1, self::GROUP );
		} else {
			$count = (int) get_transient( 'beltoft_services_' . $key ) + 1;
			set_transient( 'beltoft_services_' . $key, $count, self::WINDOW );
		}

		return false !== $count && (int) $count > $max;
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
