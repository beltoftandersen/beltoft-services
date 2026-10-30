<?php
/**
 * REST routes of a managed database: the one-time password reveal and the CA certificate download.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * POST resources/<id>/db-reveal gives the password once (7 days to take it); GET resources/<id>/db-ca gives the
 * CA certificate to the owner. Both go through Routes::guard() like every customer route; replies are never cached.
 */
final class DbRoutes {

	public const RATE_LIMIT = 5;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve' ), 10, 4 );
	}

	public static function routes(): void {
		$args = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );

		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/db-reveal', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reveal' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $args,
		) );
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/db-ca', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'ca' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $args,
		) );
	}

	/**
	 * The link of the "Download CA certificate" button (a REST nonce, because the browser follows a plain link).
	 */
	public static function ca_url( int $resource_id ): string {
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( Routes::NAMESPACE . '/resources/' . $resource_id . '/db-ca' ) );
	}

	/**
	 * The password, once.
	 */
	public static function take( Resource $r, int $user_id ): ?string {
		$answer = null;

		$r->locked( static function () use ( $r, $user_id, &$answer ) {
			$license = $r->license();

			if ( $user_id <= 0 || (int) ( $license['user_id'] ?? 0 ) !== $user_id || DbDriver::ID !== $r->get( 'driver' ) ) {
				return false;
			}

			$stored = $r->get( DbJob::REVEAL );

			if ( ! is_string( $stored ) || '' === $stored ) {
				return false;
			}

			$wipe = array( DbJob::REVEAL => '', DbJob::UNTIL => 0 );

			if ( (int) $r->get( DbJob::UNTIL ) < time() ) {
				return $r->set( $wipe );
			}

			$plain = Crypto::decrypt( $stored );

			if ( null === $plain || 1 !== preg_match( '/^[A-Za-z0-9]{32}$/D', $plain ) || ! $r->set( $wipe ) ) {
				return false;
			}

			$answer = $plain;

			return true;
		} );

		return $answer;
	}

	/**
	 * Is there a password the customer can still take?
	 */
	public static function revealable( Resource $r ): bool {
		return is_string( $r->get( DbJob::REVEAL ) ) && '' !== $r->get( DbJob::REVEAL ) && (int) $r->get( DbJob::UNTIL ) >= time();
	}

	private static function quiet( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	public static function reveal( \WP_REST_Request $request ): \WP_REST_Response {
		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:db-reveal-floor', RevealRoute::FLOOR_LIMIT, MINUTE_IN_SECONDS ) ) {
			return self::quiet( rest_convert_error_to_response( new \WP_Error( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ), array( 'status' => 429 ) ) ) );
		}

		$resource = Routes::guard( $request, true, self::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return self::quiet( $resource );
		}

		$password = self::take( $resource, get_current_user_id() );

		return self::quiet( null === $password
			? rest_convert_error_to_response( new \WP_Error( 'beltoft_services_no_password', __( 'The password has already been shown or is no longer available.', 'beltoft-services' ), array( 'status' => 409 ) ) )
			: new \WP_REST_Response( array( 'password' => $password ), 200 ) );
	}

	public static function ca( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, false, self::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return self::quiet( $resource );
		}

		$pem = (string) $resource->get( 'db_ca_pem' );

		if ( DbDriver::ID !== $resource->get( 'driver' ) || ! DbGuest::pem( $pem ) ) {
			return self::quiet( rest_convert_error_to_response( new \WP_Error( 'beltoft_services_no_ca', __( 'The certificate is not available.', 'beltoft-services' ), array( 'status' => 404 ) ) ) );
		}

		$response = new \WP_REST_Response( $pem, 200 );
		$response->header( 'Content-Type', 'application/x-pem-file' );
		$response->header( 'Content-Disposition', 'attachment; filename="ca.crt"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return self::quiet( $response );
	}

	/**
	 * Sends the certificate as it is, not as JSON.
	 *
	 * @param mixed $served  Whether the request was served.
	 * @param mixed $result  Result.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public static function serve( $served, $result, $request = null ) {
		if ( ! $request instanceof \WP_REST_Request || 1 !== preg_match( '#/resources/\d+/db-ca$#D', $request->get_route() ) || ! $result instanceof \WP_REST_Response || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return $served;
		}

		foreach ( $result->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A validated PEM certificate, sent as a file.

		return true;
	}
}
