<?php
/**
 * A small Mollie API client for saved cards (mandates and first payments).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Card;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the API key the Mollie Payments for WooCommerce plugin is set up with (test or live, by its own switch);
 * the key is read when a call is made, never stored or logged. Every call goes through wp_remote_request, so a
 * test answers it with pre_http_request. The filter beltoft_services_mollie_key lets a site supply the key.
 */
final class Mollie {

	/**
	 * API base address.
	 */
	public const API = 'https://api.mollie.com/v2';

	/**
	 * Option prefix of the Mollie plugin's settings.
	 */
	private const PREFIX = 'mollie-payments-for-woocommerce_';

	/**
	 * The API key in use, or ''.
	 */
	public static function key(): string {
		$test = 'yes' === trim( (string) get_option( self::PREFIX . 'test_mode_enabled' ) );
		$key  = trim( (string) get_option( self::PREFIX . ( $test ? 'test_api_key' : 'live_api_key' ) ) );

		/**
		 * Filters the Mollie API key used for saved cards.
		 *
		 * @param string $key The Mollie plugin's key for its current mode.
		 */
		return (string) apply_filters( 'beltoft_services_mollie_key', $key );
	}

	/**
	 * Whether a key is set.
	 */
	public static function configured(): bool {
		return '' !== self::key();
	}

	/**
	 * One API call.
	 *
	 * @param string                    $method GET, POST or DELETE.
	 * @param string                    $path   Path under /v2.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<string, mixed>|\WP_Error Decoded answer ([] for 204), or an error with a message free of the key.
	 */
	public static function call( string $method, string $path, ?array $body = null ) {
		$key = self::key();

		if ( '' === $key ) {
			return new \WP_Error( 'beltoft_services_mollie_key', 'No Mollie API key is set.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'beltoft_services_mollie_http', 'Mollie could not be reached.' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		$detail = is_array( $data ) && isset( $data['detail'] ) ? (string) $data['detail'] : '';

		return new \WP_Error( 'beltoft_services_mollie_' . $code, 'Mollie answered ' . $code . ( '' !== $detail ? ': ' . $detail : '' ) );
	}

	/**
	 * A mandate of a customer.
	 *
	 * @param string $customer Customer id (cst_...).
	 * @param string $mandate  Mandate id (mdt_...).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function mandate( string $customer, string $mandate ) {
		return self::call( 'GET', '/customers/' . rawurlencode( $customer ) . '/mandates/' . rawurlencode( $mandate ) );
	}

	/**
	 * Revokes a mandate.
	 *
	 * @param string $customer Customer id.
	 * @param string $mandate  Mandate id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function revoke( string $customer, string $mandate ) {
		return self::call( 'DELETE', '/customers/' . rawurlencode( $customer ) . '/mandates/' . rawurlencode( $mandate ) );
	}

	/**
	 * A payment, fetched from Mollie (the only way a payment is believed).
	 *
	 * @param string $id Payment id (tr_...).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function payment( string $id ) {
		return self::call( 'GET', '/payments/' . rawurlencode( $id ) );
	}

	/**
	 * Creates a first payment for a customer: it saves a new mandate when it is paid.
	 *
	 * @param array<string, mixed> $data Payment fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_payment( array $data ) {
		return self::call( 'POST', '/payments', $data );
	}
}
