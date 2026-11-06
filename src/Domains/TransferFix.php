<?php
/**
 * Transfer failures the customer can fix: a refused transfer code and a domain that is locked at the old registrar.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Such a transfer does not fail: it waits (the order stays, nothing is spent twice), the customer is emailed once for
 * this failure, and the order goes on when the customer enters a new code or clicks Check now.
 */
final class TransferFix {

	public const AUTH   = 'auth_refused';
	public const LOCKED = 'locked';

	/**
	 * How long the customer has to fix it.
	 */
	public const DAYS = 14;

	/**
	 * Seconds between our own tries while the domain is locked.
	 */
	public const EVERY = 6 * HOUR_IN_SECONDS;

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The route that takes a new transfer code.
	 */
	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/transfer-code',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_code' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
			)
		);
	}

	/**
	 * Which fixable reason a registrar answer names: auth_refused, locked or ''.
	 *
	 * @param string $text The error code and description, or the status text, of the answer.
	 */
	public static function classify( string $text ): string {
		$text = strtolower( $text );
		$why  = '';

		if ( 1 === preg_match( '/clienttransferprohibited|transfer[ _-]?prohibited|transfer[ _-]?lock|\block(ed)?\b/', $text ) ) {
			$why = self::LOCKED;
		} elseif ( 1 === preg_match( '/auth(orization|orisation)?[ _-]?(code|info|key)|\bepp\b|\bauth\b|transfer (code|secret)|invalid password|wrong password/', $text ) ) {
			$why = self::AUTH;
		}

		/**
		 * The fixable reason of a transfer failure.
		 *
		 * @param string $why  auth_refused, locked or ''.
		 * @param string $text The registrar's text, lower case.
		 */
		return (string) apply_filters( 'beltoft_services_transfer_fix_reason', $why, $text );
	}

	/**
	 * The fixable reason in a registrar error.
	 *
	 * @param \WP_Error $error Error of the transfer order.
	 */
	public static function from_error( \WP_Error $error ): string {
		$data = $error->get_error_data();

		return self::classify( $error->get_error_code() . ' ' . $error->get_error_message() . ' ' . ( is_array( $data ) ? (string) ( $data['desc'] ?? '' ) : '' ) );
	}

	/**
	 * The fixable reason in the detail of a transfer the registry has failed, or ''. A transfer that is only pending is never failed here.
	 *
	 * @param array<string, mixed> $detail Domain detail.
	 */
	public static function from_detail( array $detail ): string {
		if ( ! in_array( strtoupper( (string) ( $detail['status'] ?? '' ) ), array( 'FAI', 'REJ', 'DEL' ), true ) ) {
			return '';
		}

		$text = '';

		foreach ( array( 'error', 'reason', 'message', 'status_reason', 'status_description', 'transfer_error', 'desc' ) as $key ) {
			if ( isset( $detail[ $key ] ) && is_scalar( $detail[ $key ] ) ) {
				$text .= ' ' . $detail[ $key ];
			}
		}

		return self::classify( $text );
	}

	/**
	 * The reason the order waits for the customer for, or ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function pending( Resource $r ): string {
		$why = (string) $r->get( 'transfer_fix' );

		return in_array( $why, array( self::AUTH, self::LOCKED ), true ) && 'domain' === $r->get( 'driver' ) && ! in_array( (string) $r->get( 'state' ), array( 'failed', 'deleted', 'ready' ), true ) ? $why : '';
	}

	/**
	 * Waits for the customer: the email goes once for this failure, a refused code is dropped, and the order is tried again later.
	 *
	 * @param Resource             $r    Resource.
	 * @param string               $why  auth_refused or locked.
	 * @param array<string, mixed> $meta Meta to save with it.
	 */
	public static function hold( Resource $r, string $why, array $meta = array() ): StepResult {
		$since = (int) ( $r->get( 'transfer_fix_since' ) ?: time() );

		if ( time() - $since > self::DAYS * DAY_IN_SECONDS ) {
			DomainDriver::forget_code( $r );
			Alerts::admin( $r, 'The customer did not fix the transfer within ' . self::DAYS . ' days (' . $why . ').' );

			return StepResult::fail( 'The transfer was not fixed within ' . self::DAYS . ' days.', array( 'transfer_fix' => null ) );
		}

		if ( self::AUTH === $why && null !== $r->get( 'auth' ) ) {
			// The registry refused this code: it is not sent again.
			DomainDriver::forget_code( $r );
		}

		$meta = array_merge( $meta, array( 'transfer_fix' => $why, 'transfer_fix_since' => $since ) );

		if ( $why !== $r->get( 'transfer_fix_sent' ) ) {
			// Saved first: a failed send is not repeated on every try.
			$meta['transfer_fix_sent'] = $why;
			$r->set( array( 'transfer_fix_sent' => $why ) );
			Emails::send( 'beltoft_services_domain_transfer_fix', $r, array( 'transfer_fix_reason' => $why ) );
		}

		return StepResult::wait( self::AUTH === $why ? self::DAYS * DAY_IN_SECONDS : self::EVERY, self::message( $why ), $since + self::DAYS * DAY_IN_SECONDS, $meta );
	}

	/**
	 * What the customer is told on the page and in the check answer.
	 *
	 * @param string $why Reason.
	 */
	public static function message( string $why ): string {
		return self::AUTH === $why
			? __( 'The transfer code was not accepted. Get a new code at your current registrar and enter it here.', 'beltoft-services' )
			: __( 'The domain is locked at your current registrar. Unlock it there, then check again.', 'beltoft-services' );
	}

	/**
	 * The page view of a transfer that waits for the customer, or null.
	 *
	 * @param Resource $r Resource.
	 * @return array{reason: string, registrar: string, message: string}|null
	 */
	public static function view( Resource $r ): ?array {
		$why = self::pending( $r );

		return '' === $why ? null : array(
			'reason'    => $why,
			'registrar' => TransferDns::registrar_name( (string) $r->get( 'domain' ) ),
			'message'   => self::message( $why ),
		);
	}

	/**
	 * POST resources/<id>/transfer-code: the customer enters a new transfer code. It is saved encrypted and never shown again.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest_code( \WP_REST_Request $request ): \WP_REST_Response {
		$r = Routes::guard( $request, false, 5 );

		if ( ! $r instanceof Resource ) {
			$response = $r;
		} elseif ( self::AUTH !== self::pending( $r ) ) {
			$response = rest_convert_error_to_response( new \WP_Error( 'beltoft_services_not_waiting', __( 'This transfer does not need a new code.', 'beltoft-services' ), array( 'status' => 409 ) ) );
		} else {
			$args = (array) ( $request->get_param( 'args' ) ?? array() ) + (array) $request->get_json_params();
			$code = trim( (string) ( $args['auth_code'] ?? $request->get_param( 'auth_code' ) ?? '' ) );

			if ( ! Cart::auth_code_valid( $code ) ) {
				$response = rest_convert_error_to_response( new \WP_Error( 'auth_invalid', __( 'The transfer code must be 6 to 64 characters, letters, digits and common symbols only.', 'beltoft-services' ), array( 'status' => 400 ) ) );
			} else {
				try {
					$saved = $r->set( array( 'auth' => Crypto::encrypt( $code ), 'transfer_fix' => null, 'transfer_fix_sent' => null, 'transfer_fix_since' => null, 'next_attempt_at' => time() ) );
				} catch ( \Throwable $e ) {
					$saved = false;
				}

				if ( ! $saved ) {
					$response = rest_convert_error_to_response( new \WP_Error( 'beltoft_services_save', __( 'The code could not be saved. Try again in a minute.', 'beltoft-services' ), array( 'status' => 503 ) ) );
				} else {
					Jobs::enqueue( $r->id(), 0 );
					$response = new \WP_REST_Response( array( 'status' => 'saved', 'message' => __( 'The new code is saved. We send the transfer again now.', 'beltoft-services' ) ), 200 );
				}
			}
		}

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
