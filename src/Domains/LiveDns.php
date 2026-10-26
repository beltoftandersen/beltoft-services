<?php
/** Loopback PowerDNS API client. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

class LiveDns implements Dns {
	private string $base;
	private string $key;

	public function __construct( string $url, string $key, bool $allowed = false ) {
		if ( ! $allowed ) { throw new \RuntimeException( 'Live DNS client is disabled.' ); }
		if ( ! preg_match( '#^http://127\.0\.0\.1:[1-9][0-9]{0,4}/?$#', $url ) || (int) wp_parse_url( $url, PHP_URL_PORT ) > 65535 ) {
			throw new \InvalidArgumentException( 'DNS API must use an IPv4 loopback address and port.' );
		}
		$this->base = rtrim( $url, '/' ) . '/api/v1/servers/localhost/zones';
		$this->key = $key;
	}

	private function path( string $name ): string {
		return '/' . rawurlencode( strtolower( rtrim( $name, '.' ) ) . '.' );
	}

	/** @return array|\WP_Error */
	private function call( string $method, string $path, ?array $body = null ): array|\WP_Error {
		if ( '' === $this->key ) { return new \WP_Error( 'credentials_missing', 'DNS API key is not configured.' ); }
		$args = array( 'method' => $method, 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json', 'X-API-Key' => $this->key ) );
		if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $response ) ) { return new \WP_Error( 'transport', 'DNS request could not be completed.' ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) { return new \WP_Error( 'http_' . $status, 'DNS request failed.', array( 'status' => $status ) ); }
		$raw = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( '' === $raw ) { return array(); }
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : new \WP_Error( 'bad_response', 'DNS returned invalid JSON.' );
	}

	public function zone( string $name ): array|\WP_Error|null {
		$result = $this->call( 'GET', $this->path( $name ) );
		return is_wp_error( $result ) && 'http_404' === $result->get_error_code() ? null : $result;
	}

	public function zones(): array|\WP_Error { return $this->call( 'GET', '' ); }

	public function create_zone( string $name, array $nameservers ): true|\WP_Error {
		$result = $this->call( 'POST', '', array( 'name' => strtolower( rtrim( $name, '.' ) ) . '.', 'kind' => 'Master', 'nameservers' => array_map( static fn( $host ) => rtrim( strtolower( (string) $host ), '.' ) . '.', $nameservers ) ) );
		return is_wp_error( $result ) ? $result : true;
	}

	public function delete_zone( string $name ): true|\WP_Error {
		$result = $this->call( 'DELETE', $this->path( $name ) );
		return is_wp_error( $result ) ? $result : true;
	}

	public function replace_rrsets( string $zone, array $rrsets ): true|\WP_Error {
		$result = $this->call( 'PATCH', $this->path( $zone ), array( 'rrsets' => $rrsets ) );
		return is_wp_error( $result ) ? $result : true;
	}

	public function secure( string $zone ): array|\WP_Error {
		$current = $this->call( 'GET', $this->path( $zone ) );
		if ( is_wp_error( $current ) ) { return $current; }
		if ( empty( $current['dnssec'] ) || empty( $current['api_rectify'] ) ) {
			$enabled = $this->call( 'PUT', $this->path( $zone ), array( 'dnssec' => true, 'api_rectify' => true ) );
			if ( is_wp_error( $enabled ) ) { return $enabled; }
		}
		$keys = $this->call( 'GET', $this->path( $zone ) . '/cryptokeys' );
		if ( is_wp_error( $keys ) ) { return $keys; }
		$result = null;
		foreach ( $keys as $key ) {
			if ( is_array( $key ) && ! empty( $key['active'] ) && 'ksk' === strtolower( (string) ( $key['keytype'] ?? '' ) ) && ! empty( $key['dnskey'] ) ) { $result = $key; break; }
		}
		if ( null === $result ) { return new \WP_Error( 'bad_response', 'DNS did not return an active signing key.' ); }
		$parts = preg_split( '/\s+/', trim( (string) ( $result['dnskey'] ?? '' ) ), 4 );
		if ( 4 !== count( $parts ) || (int) $parts[0] !== 257 || (int) $parts[1] !== 3 ) {
			return new \WP_Error( 'bad_response', 'DNS did not return a valid DNSKEY.' );
		}
		return array( array( 'flags' => (int) $parts[0], 'protocol' => (int) $parts[1], 'alg' => (int) $parts[2], 'pub_key' => $parts[3] ) );
	}
}
