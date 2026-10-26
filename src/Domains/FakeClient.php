<?php
/** Offline registrar with scriptable replies. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

class FakeClient implements Client {
	private array $calls = array();
	private array $replies = array();

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
		return ! empty( $this->replies[ $key ] ) ? array_shift( $this->replies[ $key ] ) : new \WP_Error( 'fake_unconfigured', 'No fake registrar reply is configured.' );
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
