<?php
/** Encrypted one-time app credential reveal. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class AppReveal {
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/app-reveal', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'reveal' ),
			'permission_callback' => 'is_user_logged_in',
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
		) );
	}

	public static function take( Resource $r, int $user_id ): ?array {
		$answer = null;
		$r->locked( static function () use ( $r, $user_id, &$answer ) {
			$license = $r->license();
			if ( $user_id <= 0 || (int) ( $license['user_id'] ?? 0 ) !== $user_id ) {
				return false;
			}
			$stored = $r->get( 'app_reveal' );
			if ( ! is_string( $stored ) || '' === $stored ) {
				return false;
			}
			$wipe = array( 'app_reveal' => '', 'app_reveal_until' => 0 );
			if ( (int) $r->get( 'app_reveal_until' ) < time() ) {
				return $r->set( $wipe );
			}
			$plain = Crypto::decrypt( $stored );
			$data = null === $plain ? null : json_decode( $plain, true );
			if ( ! is_array( $data ) || ! $r->set( $wipe ) ) {
				return false;
			}
			$answer = $data;
			return true;
		} );
		return $answer;
	}

	public static function reveal( \WP_REST_Request $request ): \WP_REST_Response {
		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:app-reveal-floor', RevealRoute::FLOOR_LIMIT, MINUTE_IN_SECONDS ) ) {
			$response = rest_convert_error_to_response( new \WP_Error( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ), array( 'status' => 429 ) ) );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$resource = Routes::guard( $request, true, RevealRoute::RATE_LIMIT );
		if ( ! $resource instanceof Resource ) {
			$resource->header( 'Cache-Control', 'no-store' );
			return $resource;
		}
		$data = self::take( $resource, get_current_user_id() );
		$response = null === $data ? rest_convert_error_to_response( new \WP_Error( 'beltoft_services_no_app_login', __( 'The app login has already been shown or is no longer available.', 'beltoft-services' ), array( 'status' => 409 ) ) ) : new \WP_REST_Response( array( 'credentials' => $data ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
