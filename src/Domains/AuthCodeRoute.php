<?php
/** Owner-only retrieval of a registrar transfer code, after a second check (StepUp: a code emailed to the account address). @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;
use BeltoftServices\StepUp;

defined( 'ABSPATH' ) || exit;

final class AuthCodeRoute {
	/**
	 * The step-up purpose of showing a domain transfer code.
	 */
	public const PURPOSE = 'transfer_code';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'beltoft_services_stepup_purposes', array( __CLASS__, 'stepup_purposes' ) );
		add_filter( 'beltoft_services_stepup_text', array( __CLASS__, 'stepup_text' ), 10, 2 );
	}

	/**
	 * Registers the purpose of the code step before a transfer code is shown.
	 *
	 * @param array<int, string> $purposes Purposes so far.
	 * @return array<int, string>
	 */
	public static function stepup_purposes( $purposes ): array {
		return array_merge( (array) $purposes, array( self::PURPOSE ) );
	}

	/**
	 * What the code email says this purpose is for.
	 *
	 * @param string $text    Text so far.
	 * @param string $purpose The purpose.
	 */
	public static function stepup_text( $text, $purpose ): string {
		return self::PURPOSE === $purpose ? __( 'showing a domain transfer code', 'beltoft-services' ) : (string) $text;
	}
	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/domain-authcode', array(
			// POST: the code travels in the body, never in a URL or a log line.
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'get' ),
			'permission_callback' => 'is_user_logged_in',
			'args' => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
				'secret' => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:authcode', 5, MINUTE_IN_SECONDS ) ) {
			$response = rest_convert_error_to_response( new \WP_Error( 'rate_limited', __( 'Try again in a minute.', 'beltoft-services' ), array( 'status' => 429 ) ) );
		} else {
			$r = Routes::guard( $request, true, 5 );
			if ( ! $r instanceof Resource ) { $response = $r; }
			elseif ( ! StepUp::verify( get_current_user_id(), (string) $request->get_param( 'secret' ), self::PURPOSE ) ) {
				$response = rest_convert_error_to_response( new \WP_Error( 'step_up', __( 'That code is not right or has expired. Send a new code.', 'beltoft-services' ), array( 'status' => 403 ) ) );
			}
			elseif ( 'domain' !== $r->get( 'driver' ) || ! ctype_digit( (string) $r->get( 'remote_id' ) ) ) {
				$response = rest_convert_error_to_response( new \WP_Error( 'unavailable', __( 'This code is unavailable.', 'beltoft-services' ), array( 'status' => 403 ) ) );
			} else {
				// Asking for the code means a transfer may be starting: the re-lock clock restarts.
				if ( ! $r->get( 'is_locked' ) ) { $r->set( array( 'unlocked_at' => time() ) ); }
				$reply = Clients::registrar()->request( 'GET', '/domains/' . $r->get( 'remote_id' ) . '/authcode' );
				$code = is_wp_error( $reply ) ? '' : (string) ( $reply['auth_code'] ?? '' );
				if ( '' === $code ) {
					$response = rest_convert_error_to_response( new \WP_Error( 'unavailable', __( 'The transfer code is not available yet.', 'beltoft-services' ), array( 'status' => 503 ) ) );
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
