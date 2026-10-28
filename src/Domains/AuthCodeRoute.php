<?php
/** Owner-only retrieval of a registrar transfer code. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class AuthCodeRoute {
	public static function register(): void { add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }
	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/domain-authcode', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'get' ),
			'permission_callback' => 'is_user_logged_in',
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
		) );
	}
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:authcode', 5, MINUTE_IN_SECONDS ) ) {
			$response = rest_convert_error_to_response( new \WP_Error( 'rate_limited', 'Try again in a minute.', array( 'status' => 429 ) ) );
		} else {
			$r = Routes::guard( $request, true, 5 );
			if ( ! $r instanceof Resource ) { $response = $r; }
			elseif ( 'domain' !== $r->get( 'driver' ) || ! ctype_digit( (string) $r->get( 'remote_id' ) ) ) {
				$response = rest_convert_error_to_response( new \WP_Error( 'unavailable', 'This code is unavailable.', array( 'status' => 403 ) ) );
			} else {
				// Asking for the code means a transfer may be starting: the re-lock clock restarts.
				if ( ! $r->get( 'is_locked' ) ) { $r->set( array( 'unlocked_at' => time() ) ); }
				$reply = Clients::registrar()->request( 'GET', '/domains/' . $r->get( 'remote_id' ) . '/authcode' );
				$code = is_wp_error( $reply ) ? '' : (string) ( $reply['auth_code'] ?? '' );
				if ( '' === $code ) {
					$response = rest_convert_error_to_response( new \WP_Error( 'unavailable', 'The transfer code is not available yet.', array( 'status' => 503 ) ) );
				} else {
					// The customer is told it was shown, never the code.
					Security::notify( $r, 'code_viewed' );
					$response = new \WP_REST_Response( array( 'auth_code' => $code ), 200 );
				}
			}
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
