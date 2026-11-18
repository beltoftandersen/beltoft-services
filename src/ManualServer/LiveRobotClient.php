<?php
/**
 * The HTTP client of the Robot webservice.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP Basic Auth, POST parameters as application/x-www-form-urlencoded, JSON answers, HTTPS only (doc, "General"). The one
 * exception is a loopback address, used by the test server on staging (DedicatedClients refuses it for the real host). Built
 * only by DedicatedClients, which checks the live constant. Credentials stay in this object: never logged, never in an error.
 */
final class LiveRobotClient implements RobotClient {

	/** @var string */
	private $base;

	/** @var string */
	private $user;

	/** @var string */
	private $password;

	/**
	 * @param string $base     Base address, no trailing slash.
	 * @param string $user     Web service user.
	 * @param string $password Web service password.
	 * @throws \InvalidArgumentException For an address that is not https (or loopback http) or empty credentials.
	 */
	public function __construct( string $base, string $user, string $password ) {
		$base  = rtrim( $base, '/' );
		$parts = wp_parse_url( $base );
		$https = is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' );
		$local = is_array( $parts ) && 'http' === ( $parts['scheme'] ?? '' ) && in_array( $parts['host'] ?? '', array( '127.0.0.1', 'localhost' ), true );

		if ( ! ( $https || $local ) || '' === $user || '' === $password ) {
			throw new \InvalidArgumentException( 'The Robot address must be https and the login must be set.' );
		}

		$this->base     = $base;
		$this->user     = $user;
		$this->password = $password;
	}

	/**
	 * @param array<string, mixed> $fields Fields.
	 */
	public function order( array $fields ) {
		$body = array();

		foreach ( array( 'product_id', 'location', 'dist', 'password' ) as $key ) {
			if ( isset( $fields[ $key ] ) && '' !== (string) $fields[ $key ] ) {
				$body[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $fields[ $key ] );
			}
		}

		foreach ( (array) ( $fields['authorized_key'] ?? array() ) as $fingerprint ) {
			$body[] = rawurlencode( 'authorized_key[]' ) . '=' . rawurlencode( (string) $fingerprint );
		}

		foreach ( (array) ( $fields['addon'] ?? array() ) as $addon ) {
			$body[] = rawurlencode( 'addon[]' ) . '=' . rawurlencode( (string) $addon );
		}

		if ( ! empty( $fields['test'] ) ) {
			$body[] = 'test=true';
		}

		$answer = $this->request( 'POST', '/order/server/transaction', implode( '&', $body ) );

		return is_wp_error( $answer ) ? $answer : (array) ( $answer['transaction'] ?? array() );
	}

	public function add_key( string $name, string $data ) {
		$answer = $this->request( 'POST', '/key', 'name=' . rawurlencode( $name ) . '&data=' . rawurlencode( $data ) );

		return is_wp_error( $answer ) ? $answer : (array) ( $answer['key'] ?? array() );
	}

	public function keys() {
		$answer = $this->request( 'GET', '/key' );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return array_values( array_filter( array_map( static fn( $row ) => is_array( $row ) && is_array( $row['key'] ?? null ) ? $row['key'] : null, $answer ) ) );
	}

	public function transaction( string $id ) {
		$answer = $this->request( 'GET', '/order/server/transaction/' . rawurlencode( $id ) );

		return is_wp_error( $answer ) ? $answer : (array) ( $answer['transaction'] ?? array() );
	}

	public function server( int $number ) {
		$answer = $this->request( 'GET', '/server/' . $number );

		return is_wp_error( $answer ) ? $answer : (array) ( $answer['server'] ?? array() );
	}

	public function cancel( int $number ) {
		$answer = $this->request( 'POST', '/server/' . $number . '/cancellation', 'cancellation_date=now&reserve_location=false' );

		return is_wp_error( $answer ) ? $answer : (array) ( $answer['cancellation'] ?? array() );
	}

	public function reset_options( int $number ) {
		$answer = $this->request( 'GET', '/reset/' . $number );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['reset'] ?? array() );
	}

	public function reset_server( int $number, string $type ) {
		$answer = $this->request( 'POST', '/reset/' . $number, 'type=' . rawurlencode( $type ) );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['reset'] ?? array() );
	}

	public function wol_available( int $number ) {
		$answer = $this->request( 'GET', '/wol/' . $number );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['wol'] ?? array() );
	}

	public function wol( int $number ) {
		$answer = $this->request( 'POST', '/wol/' . $number, '' );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['wol'] ?? array() );
	}

	public function rescue_status( int $number ) {
		$answer = $this->request( 'GET', '/boot/' . $number . '/rescue' );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['rescue'] ?? array() );
	}

	public function rescue_activate( int $number, string $os, array $keys ) {
		$body = 'os=' . rawurlencode( $os );
		foreach ( $keys as $fingerprint ) {
			$body .= '&' . rawurlencode( 'authorized_key[]' ) . '=' . rawurlencode( (string) $fingerprint );
		}
		$answer = $this->request( 'POST', '/boot/' . $number . '/rescue', $body );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['rescue'] ?? array() );
	}

	public function rescue_deactivate( int $number ) {
		$answer = $this->request( 'DELETE', '/boot/' . $number . '/rescue' );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['rescue'] ?? array() );
	}

	public function rdns_list( string $server_ip ) {
		$answer = $this->request( 'GET', '/rdns?server_ip=' . rawurlencode( $server_ip ) );
		if ( is_wp_error( $answer ) ) {
			$data = $answer->get_error_data();
			return 'NOT_FOUND' === $answer->get_error_code() && is_array( $data ) && 404 === (int) ( $data['status'] ?? 0 ) ? array() : $answer;
		}
		return array_values( array_filter( array_map( static fn( $row ) => is_array( $row ) && is_array( $row['rdns'] ?? null ) ? $row['rdns'] : null, $answer ) ) );
	}

	public function rdns_set( string $ip, string $ptr ) {
		$answer = $this->request( 'POST', '/rdns/' . rawurlencode( $ip ), 'ptr=' . rawurlencode( $ptr ) );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['rdns'] ?? array() );
	}

	public function rdns_delete( string $ip ) {
		$answer = $this->request( 'DELETE', '/rdns/' . rawurlencode( $ip ) );
		return is_wp_error( $answer ) ? $answer : true;
	}

	public function firewall( int $number ) {
		$answer = $this->request( 'GET', '/firewall/' . $number );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['firewall'] ?? array() );
	}

	public function firewall_set( int $number, array $config ) {
		// Doc, Firewall: rules[{direction}][{rule index}][{rule field}], x-www-form-urlencoded; null fields are left out (a wildcard).
		$fields = array();
		if ( isset( $config['status'] ) ) {
			$fields['status'] = (string) $config['status'];
		}
		foreach ( array( 'filter_ipv6', 'whitelist_hos' ) as $flag ) {
			if ( isset( $config[ $flag ] ) ) {
				$fields[ $flag ] = $config[ $flag ] ? 'true' : 'false';
			}
		}
		foreach ( (array) ( $config['rules'] ?? array() ) as $direction => $rules ) {
			foreach ( array_values( (array) $rules ) as $i => $rule ) {
				foreach ( (array) $rule as $field => $value ) {
					if ( null !== $value && '' !== (string) $value ) {
						$fields['rules'][ $direction ][ $i ][ $field ] = (string) $value;
					}
				}
			}
		}
		$answer = $this->request( 'POST', '/firewall/' . $number, http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ) );
		return is_wp_error( $answer ) ? $answer : (array) ( $answer['firewall'] ?? array() );
	}

	public function products() {
		$answer = $this->request( 'GET', '/order/server/product' );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		return array_values( array_filter( array_map( static fn( $row ) => is_array( $row ) && is_array( $row['product'] ?? null ) ? $row['product'] : null, $answer ) ) );
	}

	/**
	 * One call.
	 *
	 * @param string $method GET, POST or DELETE.
	 * @param string $path   Path.
	 * @param string $body   Form body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, string $body = '' ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth, as the Robot documentation says.
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = $body;
		}

		$response = wp_remote_request( $this->base . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'robot_unreachable', 'The Robot webservice did not answer.', array( 'status' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			// DELETE /rdns/{ip} documents no output.
			return is_array( $data ) ? $data : array();
		}

		$error = is_array( $data ) && is_array( $data['error'] ?? null ) ? $data['error'] : array();
		$code  = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : ( 401 === $status ? 'UNAUTHORIZED' : ( 503 === $status ? 'UNAVAILABLE' : 'ROBOT_ERROR' ) );

		return new \WP_Error( preg_replace( '/[^A-Z0-9_]/', '', strtoupper( $code ) ), isset( $error['message'] ) && is_string( $error['message'] ) ? substr( $error['message'], 0, 200 ) : 'The Robot webservice refused the call.', array( 'status' => $status ) );
	}
}
