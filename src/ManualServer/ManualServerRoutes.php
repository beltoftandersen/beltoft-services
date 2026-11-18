<?php
/**
 * The root password of a manual server: shown only behind the emailed code.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\Resource;
use BeltoftServices\StepUp;

defined( 'ABSPATH' ) || exit;

/**
 * POST resources/<id>/manual-password: the password, only for the owner and only with the code emailed to the account (one code,
 * used up by the call), 5 requests a minute, never cached. The password is encrypted at rest (Crypto) and is in no email, log, REST
 * status or page. A token for the API never reaches this route (Tokens::reachable lists what a token may call).
 */
final class ManualServerRoutes {
	public const PURPOSE = 'manual_password';
	public const LIMIT   = 5;

	/** Hooks. */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'beltoft_services_stepup_purposes', array( __CLASS__, 'stepup_purposes' ) );
		add_filter( 'beltoft_services_stepup_text', array( __CLASS__, 'stepup_text' ), 10, 2 );
	}

	/**
	 * @param mixed $purposes Purposes so far.
	 * @return array<int, string>
	 */
	public static function stepup_purposes( $purposes ): array {
		return array_merge( (array) $purposes, array( self::PURPOSE ) );
	}

	/**
	 * @param mixed $text    Text so far.
	 * @param mixed $purpose Purpose.
	 */
	public static function stepup_text( $text, $purpose ): string {
		return self::PURPOSE === $purpose ? __( 'showing the root password of your dedicated server', 'beltoft-services' ) : (string) $text;
	}

	/** Registers the route. */
	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/manual-password', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'password' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
		) );
	}

	/**
	 * The password, once the code is right.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function password( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, false, self::LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( ManualServerDriver::ID !== $resource->get( 'driver' ) ) {
			return self::error( 'not_manual', __( 'This is not a dedicated server.', 'beltoft-services' ), 404 );
		}

		if ( null === ManualServerDelivery::root_password( $resource ) ) {
			return self::error( 'manual_no_password', __( 'There is no root password to show. Log in as the login note says.', 'beltoft-services' ), 409 );
		}

		$refused = StepUp::demand( $request, self::PURPOSE );

		if ( ! $refused && ! StepUp::consume( get_current_user_id(), self::PURPOSE ) ) {
			$refused = new \WP_Error( 'step_up', StepUp::refusal( get_current_user_id(), self::PURPOSE ), array( 'status' => 403 ) );
		}

		if ( $refused ) {
			$response = rest_convert_error_to_response( $refused );
			$response->header( 'Cache-Control', 'no-store' );

			return $response;
		}

		$response = new \WP_REST_Response( array( 'password' => ManualServerDelivery::root_password( $resource ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  Status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, $message, array( 'status' => $status ) ) );
	}
}
