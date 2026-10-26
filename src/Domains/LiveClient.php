<?php
/** Openprovider HTTP client. @package BeltoftServices */
namespace BeltoftServices\Domains;

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
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) { return $cached; }
		if ( '' === $this->username || '' === $this->password ) {
			return new \WP_Error( 'credentials_missing', 'Registrar credentials are not configured.' );
		}
		$response = $this->http( 'POST', '/auth/login', array( 'username' => $this->username, 'password' => $this->password, 'ip' => '0.0.0.0' ), array(), '' );
		if ( is_wp_error( $response ) ) { return $response; }
		$token = $response['token'] ?? '';
		if ( ! is_string( $token ) || '' === $token ) {
			return new \WP_Error( 'bad_response', 'Registrar login returned no token.' );
		}
		set_transient( $key, $token, 47 * HOUR_IN_SECONDS );
		return $token;
	}

	private function send( string $method, string $path, array $body, array $query, string $token ): array|\WP_Error {
		return $this->http( $method, $path, $body, $query, 'Bearer ' . $token );
	}

	private function http( string $method, string $path, array $body, array $query, string $authorization ): array|\WP_Error {
		$url = $this->base . $path;
		if ( $query ) { $url = add_query_arg( $query, $url ); }
		$args = array(
			'method' => $method,
			'timeout' => 30,
			'redirection' => 0,
			'sslverify' => true,
			'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ),
		);
		if ( '' !== $authorization ) { $args['headers']['Authorization'] = $authorization; }
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) { $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { return new \WP_Error( 'transport', 'Registrar request could not be completed.' ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$code = is_array( $decoded ) ? (int) ( $decoded['code'] ?? 0 ) : 0;
			return new \WP_Error( $code > 0 && 401 !== $status ? 'op_' . $code : 'http_' . $status, 'Registrar request failed.', array( 'status' => $status ) );
		}
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return new \WP_Error( 'bad_response', 'Registrar returned an invalid response.' );
		}
		return $decoded['data'];
	}
}
