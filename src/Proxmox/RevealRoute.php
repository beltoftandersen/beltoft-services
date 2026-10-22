<?php
/**
 * REST route that shows a VM's generated password once.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * POST beltoft-services/v1/resources/<id>/reveal → {password}. The same checks as every
 * customer route (Routes::guard(): logged in with the wp_rest nonce, rate limit, the
 * license's owner, a ready resource), 5 requests a minute. The reply is never cached and
 * the password is never logged.
 */
final class RevealRoute {

	/**
	 * Requests per user and window.
	 */
	public const RATE_LIMIT = 5;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/reveal',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reveal' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Shows the password once.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function reveal( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, true, self::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			$resource->header( 'Cache-Control', 'no-store' );

			return $resource;
		}

		$password = Access::take_reveal( $resource, get_current_user_id() );

		if ( null === $password ) {
			$response = rest_convert_error_to_response( new \WP_Error( 'beltoft_services_no_password', __( 'The password has already been shown or is no longer available.', 'beltoft-services' ), array( 'status' => 410 ) ) );
		} else {
			$response = new \WP_REST_Response( array( 'password' => $password ), 200 );
		}

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
