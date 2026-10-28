<?php
/** Openprovider HTTP client. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Crypto;

defined( 'ABSPATH' ) || exit;

class LiveClient implements Client {
	private string $mode;
	private string $base;
	private string $username;
	private string $password;

	public function __construct( string $mode, string $username, string $password ) {
		if ( 'live' === $mode && ! Clients::live_allowed() ) {
			throw new \RuntimeException( 'Live domain client is disabled.' );
		}
		if ( ! in_array( $mode, array( 'live', 'sandbox' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown registrar mode.' );
		}
		$this->mode = $mode;
		$this->base = Clients::base_url( $mode );
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Is this error one where the registrar may be fine on a later try (and an order may even have gone through)?
	 * The HTTP status decides first, whatever the registrar's own code: transport, bad answer, timeout (408),
	 * rate limit (429) and 5xx are temporary; any other refusal is definitive.
	 */
	public static function is_transient( \WP_Error $error ): bool {
		$code = (string) $error->get_error_code();
		if ( in_array( $code, array( 'transport', 'bad_response' ), true ) ) { return true; }
		$data = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 0 === $status && 1 === preg_match( '/^http_(\d{3})$/', $code, $match ) ) { $status = (int) $match[1]; }
		return 408 === $status || 429 === $status || $status >= 500;
	}

	public static function token_key( string $mode, string $username = '' ): string {
		return 'beltoft_services_domains_token_' . $mode . '_' . substr( hash( 'sha256', $username ), 0, 12 );
	}

	public function request( string $method, string $path, array $body = array(), array $query = array() ): array|\WP_Error {
		$method = strtoupper( $method );
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) || ! preg_match( '#^/[A-Za-z0-9/_.,-]+$#', $path ) ) {
			return new \WP_Error( 'bad_request', 'Invalid registrar method or path.' );
		}
		$token = $this->token();
		if ( is_wp_error( $token ) ) { return $token; }
		$result = $this->send( $method, $path, $body, $query, $token );
		// An availability check does not place an order, so one transport retry is safe.
		if ( 'POST' === $method && '/domains/check' === $path && is_wp_error( $result ) && 'transport' === $result->get_error_code() ) {
			$result = $this->send( $method, $path, $body, $query, $token );
		}
		if ( is_wp_error( $result ) && 'http_401' === $result->get_error_code() ) {
			delete_transient( self::token_key( $this->mode, $this->username ) );
			$token = $this->token();
			if ( is_wp_error( $token ) ) { return $token; }
			return $this->send( $method, $path, $body, $query, $token );
		}
		return $result;
	}

	private function token(): string|\WP_Error {
		$key = self::token_key( $this->mode, $this->username );
		// The login token is a credential: it is kept encrypted, and not at all when there is no key to encrypt it with.
		$cached = get_transient( $key );
		$plain  = is_string( $cached ) && '' !== $cached ? Crypto::decrypt( $cached ) : null;
		if ( is_string( $plain ) && '' !== $plain ) { return $plain; }
		if ( '' === $this->username || '' === $this->password ) {
			return new \WP_Error( 'credentials_missing', 'Registrar credentials are not configured.' );
		}
		$response = $this->http( 'POST', '/auth/login', array( 'username' => $this->username, 'password' => $this->password, 'ip' => '0.0.0.0' ), array(), '' );
		if ( is_wp_error( $response ) ) { return $response; }
		$token = $response['token'] ?? '';
		if ( ! is_string( $token ) || '' === $token ) {
			return new \WP_Error( 'bad_response', 'Registrar login returned no token.' );
		}
		if ( Crypto::available() ) { set_transient( $key, Crypto::encrypt( $token ), 47 * HOUR_IN_SECONDS ); }
		return $token;
	}

	private function send( string $method, string $path, array $body, array $query, string $token ): array|\WP_Error {
		return $this->http( $method, $path, $body, $query, 'Bearer ' . $token );
	}

	/** Seconds to wait for one request: an order is placed with the registrar by a slow call that may still go through, so it gets longer; no order is ever sent twice by this client. */
	public static function timeout_for( string $method, string $path ): int {
		return 'POST' === strtoupper( $method ) && in_array( $path, array( '/domains', '/domains/transfer' ), true ) ? 90 : 30;
	}

	private function http( string $method, string $path, array $body, array $query, string $authorization ): array|\WP_Error {
		$url = $this->base . $path;
		if ( $query ) { $url = add_query_arg( $query, $url ); }
		$args = array(
			'method' => $method,
			'timeout' => self::timeout_for( $method, $path ),
			'redirection' => 0,
			'sslverify' => true,
			'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ),
		);
		if ( '' !== $authorization ) { $args['headers']['Authorization'] = $authorization; }
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) { $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			// The reason's code (http_request_failed, say) is kept for staff; never its message, which can hold the URL.
			return new \WP_Error( 'transport', 'Registrar request could not be completed.', array( 'transport_code' => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $response->get_error_code() ) ), 0, 60 ) ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$code = is_array( $decoded ) ? (int) ( $decoded['code'] ?? 0 ) : 0;
			// The registrar's own explanation is kept for staff (it is what says why an order was refused); customers never see it.
			$desc = is_array( $decoded ) ? mb_substr( sanitize_text_field( (string) ( $decoded['desc'] ?? '' ) ), 0, 200 ) : '';
			foreach ( array_filter( array( $this->password, preg_replace( '/^Bearer\s+/', '', $authorization ), is_array( $body ) ? (string) ( $body['password'] ?? '' ) : '', is_array( $body ) ? (string) ( $body['auth_code'] ?? $body['authCode'] ?? '' ) : '' ) ) as $secret ) { $desc = str_replace( $secret, '[hidden]', $desc ); }
			return new \WP_Error( $code > 0 && 401 !== $status ? 'op_' . $code : 'http_' . $status, 'Registrar request failed.', array( 'status' => $status, 'desc' => $desc ) );
		}
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return new \WP_Error( 'bad_response', 'Registrar returned an invalid response.' );
		}
		return $decoded['data'];
	}
}
