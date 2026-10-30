<?php
/** Offline registrar with scriptable replies. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

class FakeClient implements Client {
	public const OPTION = 'beltoft_services_fake_registrar';
	private array $calls = array();
	private array $replies = array();
	private bool $persist;

	public function __construct( bool $persist = false ) { $this->persist = $persist; }

	public function reply( string $method, string $path, array|\WP_Error $response ): void {
		$this->replies[ strtoupper( $method ) . ' ' . $path ][] = $response;
	}

	public function calls(): array {
		return $this->calls;
	}

	public function request( string $method, string $path, array $body = array(), array $query = array() ): array|\WP_Error {
		$method = strtoupper( $method );
		$this->calls[] = array( 'method' => $method, 'path' => $path, 'body' => $this->redact( $body ), 'query' => $query );
		$key = $method . ' ' . $path;
		if ( ! empty( $this->replies[ $key ] ) ) { return array_shift( $this->replies[ $key ] ); }
		return $this->persist ? $this->model( $method, $path, $body, $query ) : new \WP_Error( 'fake_unconfigured', 'No fake registrar reply is configured.' );
	}

	/** A small persistent model for staging. It never represents a real registry response. */
	private function model( string $method, string $path, array $body, array $query ): array|\WP_Error {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) ) { $state = array(); }
		$state += array( 'next_id' => 1000, 'domains' => array(), 'customers' => array() );
		if ( 'POST' === $method && '/domains/check' === $path ) {
			$results = array();
			foreach ( (array) ( $body['domains'] ?? array() ) as $domain ) {
				$name = strtolower( (string) ( $domain['name'] ?? '' ) . '.' . (string) ( $domain['extension'] ?? '' ) );
				$results[] = array( 'domain' => $name, 'status' => isset( $state['domains'][ $name ] ) ? 'taken' : 'free', 'is_premium' => false );
			}
			return array( 'results' => $results );
		}
		if ( 'POST' === $method && '/customers' === $path ) {
			$handle = 'FAKE' . ++$state['next_id'] . '-XX';
			$state['customers'][ $handle ] = $body;
			update_option( self::OPTION, $state, false );
			return array( 'handle' => $handle );
		}
		if ( 'GET' === $method && '/domains' === $path ) {
			$name = strtolower( (string) ( $query['full_name'] ?? '' ) );
			return array( 'results' => isset( $state['domains'][ $name ] ) ? array( $state['domains'][ $name ] ) : array() );
		}
		if ( 'POST' === $method && in_array( $path, array( '/domains', '/domains/transfer' ), true ) ) {
			$name = strtolower( (string) ( $body['domain']['name'] ?? '' ) . '.' . (string) ( $body['domain']['extension'] ?? '' ) );
			if ( isset( $state['domains'][ $name ] ) ) { return new \WP_Error( 'op_fake_taken', 'Fake domain already exists.' ); }
			$domain = array( 'id' => ++$state['next_id'], 'full_name' => $name, 'status' => 'ACT', 'expiration_date' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ), 'owner_handle' => (string) ( $body['owner_handle'] ?? '' ), 'is_locked' => false );
			$state['domains'][ $name ] = $domain;
			update_option( self::OPTION, $state, false );
			return $domain;
		}
		if ( preg_match( '#^/domains/(\d+)(/renew|/authcode)?$#D', $path, $match ) ) {
			foreach ( $state['domains'] as $name => $domain ) {
				if ( (int) $domain['id'] !== (int) $match[1] ) { continue; }
				if ( 'GET' === $method && '/authcode' === ( $match[2] ?? '' ) ) { return array( 'auth_code' => 'FAKE-CODE-' . $domain['id'] ); }
				if ( 'GET' === $method ) { return $domain; }
				if ( 'POST' === $method && '/renew' === ( $match[2] ?? '' ) ) { $domain['expiration_date'] = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', strtotime( $domain['expiration_date'] ) ) ); }
				elseif ( 'PUT' === $method && '' === ( $match[2] ?? '' ) ) { $domain = array_merge( $domain, $body ); }
				else { break; }
				$state['domains'][ $name ] = $domain;
				update_option( self::OPTION, $state, false );
				return $domain;
			}
			return new \WP_Error( 'http_404', 'Fake domain was not found.' );
		}
		if ( 'GET' === $method && '/resellers' === $path ) { return array( 'balance' => 1000, 'currency' => 'EUR' ); }
		return new \WP_Error( 'fake_unconfigured', 'No fake registrar behavior is configured.' );
	}

	private function redact( array $body ): array {
		foreach ( $body as $key => $value ) {
			if ( in_array( $key, array( 'password', 'auth_code', 'token' ), true ) ) {
				$body[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$body[ $key ] = $this->redact( $value );
			}
		}
		return $body;
	}
}
