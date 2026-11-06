<?php
/**
 * REST routes of a certificate: the public chain, the one-time key, the link to the authority's page, and the refusal of
 * unusable action arguments.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

use BeltoftServices\Api\Routes;
use BeltoftServices\Domains\Clients;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Under beltoft-services/v1/resources/<id>/ . Every route goes through Routes::guard() (logged in with the wp_rest nonce or an
 * API token, a rate limit, the license's owner).
 *
 * - GET  ssl-certificate   the public certificate and chain (part: fullchain, cert or chain). Reachable with a read token.
 * - POST ssl-key           the private key we made, once. Never reachable with an API token, and never by MCP.
 * - POST ssl-pkcs12       an S/MIME key we made, once, as a password protected PKCS#12 file with the certificate. Never reachable with an API token.
 * - POST ssl-panel         a one-time link to the certificate authority's page for this order.
 *
 * The key is in no reply except the one of ssl-key.
 */
final class SslRoutes {

	/** Requests a minute for the key and the panel link. */
	public const SECRET_LIMIT = 5;

	/** Hooks. */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'beltoft_services_action_refusal', array( __CLASS__, 'refusal' ), 10, 4 );
	}

	/** Registers the routes. */
	public static function routes(): void {
		$id = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );

		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/ssl-certificate', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'certificate' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'part' => array( 'type' => 'string', 'enum' => array( 'fullchain', 'cert', 'chain' ), 'default' => 'fullchain', 'description' => 'Which part of the certificate to return.' ) ),
		) );
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/ssl-key', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'key' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id,
		) );
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/ssl-pkcs12', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'pkcs12' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'password' => array( 'type' => 'string', 'description' => 'Password of the file, at least 12 characters. Empty: one is made and returned.' ) ),
		) );
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/ssl-panel', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'panel' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id,
		) );
	}

	/**
	 * The guard of a route, and the check that the resource is a certificate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $ready   Whether the service must accept actions.
	 * @param int              $limit   Requests a minute.
	 * @return Resource|\WP_REST_Response
	 */
	private static function guard( \WP_REST_Request $request, bool $ready, int $limit ) {
		$resource = Routes::guard( $request, $ready, $limit );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( SslDriver::ID !== $resource->get( 'driver' ) ) {
			return self::error( 'not_ssl', __( 'This is not an SSL certificate.', 'beltoft-services' ), 404 );
		}

		return $resource;
	}

	/**
	 * An error reply.
	 *
	 * @param string $code    Code.
	 * @param string $message Message for the customer.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, $message, array( 'status' => $status ) ) );
	}

	/**
	 * The public parts of the current certificate.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function certificate( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, false, 20 );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$pem = trim( (string) $resource->get( 'ssl_cert' ) );

		if ( '' === $pem || $resource->get( 'ssl_cancelled' ) ) {
			return self::error( 'ssl_no_certificate', __( 'There is no certificate yet.', 'beltoft-services' ), 404 );
		}

		$chain = trim( (string) $resource->get( 'ssl_chain' ) );
		$part  = (string) $request['part'];
		$text  = 'cert' === $part ? $pem . "\n" : ( 'chain' === $part ? $chain . "\n" : Csr::fullchain( $pem, $chain ) );

		return new \WP_REST_Response(
			array(
				'name'       => (string) $resource->get( 'ssl_name' ),
				'serial'     => (string) $resource->get( 'ssl_serial' ),
				'valid_from' => (string) $resource->get( 'ssl_valid_from' ),
				'valid_to'   => (string) $resource->get( 'ssl_valid_to' ),
				'part'       => $part,
				'pem'        => $text,
			),
			200
		);
	}

	/**
	 * The private key, once.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function key( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, false, self::SECRET_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		// An S/MIME key is only handed out inside a protected PKCS#12 file.
		if ( Provision::is_smime( $resource ) ) {
			return self::error( 'ssl_key_pkcs12', __( 'The key of an S/MIME certificate is downloaded as a .p12 file.', 'beltoft-services' ), 409 );
		}

		$key = Keys::take( $resource, get_current_user_id() );

		if ( null === $key ) {
			return self::error( 'ssl_key_gone', __( 'The key has already been taken or is no longer available.', 'beltoft-services' ), 409 );
		}

		\BeltoftLicenses\Events::add( (int) ( $resource->license()['id'] ?? 0 ), 'service', 'Customer took the private key of the SSL certificate' );
		$response = new \WP_REST_Response( array( 'key' => $key ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The S/MIME key, the certificate and its chain as a PKCS#12 file, once. The password is the customer's own (at least 12
	 * characters) or, when none is sent, one made now and returned in this reply only. It is not stored and not logged.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function pkcs12( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, false, self::SECRET_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		if ( ! Provision::is_smime( $resource ) ) {
			return self::error( 'ssl_not_smime', __( 'Only the key of an S/MIME certificate is downloaded as a .p12 file.', 'beltoft-services' ), 404 );
		}

		$typed     = is_string( $request['password'] ) ? $request['password'] : '';
		$generated = '' === $typed;
		$password  = $generated ? wp_generate_password( 20, false ) : $typed;

		if ( ! $generated && ( strlen( $password ) < 12 || strlen( $password ) > 128 ) ) {
			return self::error( 'ssl_password', __( 'Choose a password of at least 12 characters, or leave it empty and we make one.', 'beltoft-services' ), 422 );
		}

		$file = Keys::take( $resource, get_current_user_id(), static fn( string $key ): ?string => Keys::pkcs12( $resource, $key, $password ) );

		if ( null === $file ) {
			return self::error( 'ssl_key_gone', __( 'The key has already been taken or is no longer available.', 'beltoft-services' ), 409 );
		}

		\BeltoftLicenses\Events::add( (int) ( $resource->license()['id'] ?? 0 ), 'service', 'Customer took the private key of the S/MIME certificate as a PKCS#12 file' );
		$reply = array( 'pkcs12' => base64_encode( $file ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a binary file in a JSON reply.

		if ( $generated ) {
			$reply['password'] = $password;
		}

		$response = new \WP_REST_Response( $reply, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A one-time link to the certificate authority's page for this order. Made now, not stored.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function panel( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = self::guard( $request, true, self::SECRET_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$id = (string) $resource->get( 'remote_id' );

		if ( '' === $id ) {
			return self::error( 'ssl_no_order', __( 'This certificate has no order yet.', 'beltoft-services' ), 409 );
		}

		$made = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/otp-tokens', array( 'id' => (int) $id ) );
		$url  = is_array( $made ) ? (string) ( $made['uri'] ?? '' ) : '';

		if ( is_wp_error( $made ) || 0 !== strpos( $url, 'https://' ) ) {
			return self::error( 'ssl_panel_failed', __( 'The link could not be made now. Please try again later.', 'beltoft-services' ), 502 );
		}

		$response = new \WP_REST_Response( array( 'url' => esc_url_raw( $url ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Refuses unusable arguments of a certificate action at once, instead of by a background job.
	 *
	 * @param mixed                $refusal  Refusal so far.
	 * @param mixed                $resource Resource.
	 * @param mixed                $action   Action.
	 * @param array<string, mixed> $args     Cleaned arguments.
	 * @return mixed
	 */
	public static function refusal( $refusal, $resource, $action, $args ) {
		if ( null !== $refusal || ! $resource instanceof Resource || SslDriver::ID !== $resource->get( 'driver' ) || ! is_string( $action ) || 0 !== strpos( $action, 'ssl_' ) ) {
			return $refusal;
		}

		$args = is_array( $args ) ? $args : array();

		if ( 'ssl_cancel' === $action && Operations::CONFIRM_CANCEL !== ( $args['confirm'] ?? '' ) ) {
			/* translators: %s: the word to type. */
			return new \WP_Error( 'ssl_confirm', sprintf( __( 'Type %s to cancel the certificate.', 'beltoft-services' ), Operations::CONFIRM_CANCEL ), array( 'status' => 422 ) );
		}

		if ( 'ssl_reissue' === $action && '' !== trim( (string) ( $args['csr'] ?? '' ) ) ) {
			$plan  = (array) $resource->get( 'plan' );
			$level = SslCatalog::level( (int) ( $plan['ssl_product'] ?? 0 ) );
			$read  = Provision::is_smime( $resource )
				? Csr::check_email( (string) $args['csr'], (string) $resource->get( 'ssl_name' ) )
				: Csr::check( (string) $args['csr'], (string) $resource->get( 'ssl_name' ), '' !== $level ? $level : (string) ( $plan['ssl_level'] ?? 'dv' ), array_column( SslNames::entries( $resource ), 'name' ) );

			if ( is_wp_error( $read ) ) {
				return new \WP_Error( $read->get_error_code(), $read->get_error_message(), array( 'status' => 422 ) );
			}
		}

		if ( in_array( $action, array( 'ssl_change_validation', 'ssl_resend_email' ), true ) && Provision::is_smime( $resource ) ) {
			return new \WP_Error( 'ssl_validation', __( 'An S/MIME certificate is validated by the email sent to its mailbox. Use the certificate authority page if the email did not arrive.', 'beltoft-services' ), array( 'status' => 422 ) );
		}

		if ( 'ssl_change_validation' === $action ) {
			$method   = (string) ( $args['method'] ?? '' );
			$wildcard = '*.' === substr( (string) $resource->get( 'ssl_name' ), 0, 2 );

			$names    = array_column( SslNames::entries( $resource ), 'name' );
			$target   = isset( $args['name'] ) ? strtolower( (string) $args['name'] ) : '';

			if ( count( $names ) > 1 && ( 'email' === $method || ( '' !== $target && ! in_array( $target, $names, true ) ) ) ) {
				return new \WP_Error( 'ssl_validation', __( 'Choose a validation method this certificate can use.', 'beltoft-services' ), array( 'status' => 422 ) );
			}

			if ( ! in_array( $method, Validation::METHODS, true ) || ( $wildcard && 'file' === $method ) || ( 'email' === $method && null === SslNames::approver( (string) ( $args['approver'] ?? '' ), (string) $resource->get( 'ssl_domain' ) ) ) ) {
				return new \WP_Error( 'ssl_validation', __( 'Choose a validation method this certificate can use.', 'beltoft-services' ), array( 'status' => 422 ) );
			}
		}

		return $refusal;
	}
}
