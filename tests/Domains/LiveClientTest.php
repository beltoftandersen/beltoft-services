<?php
/** Registrar HTTP client tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Tests\TestCase;

class LiveClientTest extends TestCase {
	private function reply( array $data, int $status = 200 ): array {
		return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $status, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}

	public function test_live_client_refused_without_constant(): void {
		$this->expectException( \RuntimeException::class );
		new LiveClient( 'live', 'u', 'p' );
	}

	public function test_login_token_cached_and_sent_as_bearer(): void {
		$client = new LiveClient( 'sandbox', 'u', 'p' );
		$this->respond_with( function ( $url, $args ) {
			if ( str_ends_with( $url, '/auth/login' ) ) {
				$this->assertSame( '0.0.0.0', json_decode( $args['body'], true )['ip'] );
				return $this->reply( array( 'data' => array( 'token' => 'token-one' ) ) );
			}
			$this->assertSame( 'Bearer token-one', $args['headers']['Authorization'] );
			return $this->reply( array( 'data' => array( 'ok' => true ) ) );
		} );
		$this->assertSame( array( 'ok' => true ), $client->request( 'GET', '/domains' ) );
		$this->assertSame( array( 'ok' => true ), $client->request( 'GET', '/domains' ) );
		$this->assertCount( 3, $this->http_calls() );
	}

	public function test_401_logs_in_again_once(): void {
		$client = new LiveClient( 'sandbox', 'u', 'p' );
		$logins = 0;
		$calls = 0;
		$this->respond_with( function ( $url ) use ( &$logins, &$calls ) {
			if ( str_ends_with( $url, '/auth/login' ) ) {
				++$logins;
				return $this->reply( array( 'data' => array( 'token' => 'token-' . $logins ) ) );
			}
			++$calls;
			return 1 === $calls ? $this->reply( array( 'code' => 401 ), 401 ) : $this->reply( array( 'data' => array( 'ok' => true ) ) );
		} );
		$this->assertSame( array( 'ok' => true ), $client->request( 'GET', '/domains' ) );
		$this->assertSame( 2, $logins );
	}

	public function test_error_code_becomes_op_code_and_password_never_leaks(): void {
		$client = new LiveClient( 'sandbox', 'u', 'top-secret' );
		$this->respond_with( function ( $url ) {
			return str_ends_with( $url, '/auth/login' )
				? $this->reply( array( 'data' => array( 'token' => 'token-one' ) ) )
				: $this->reply( array( 'code' => 920, 'desc' => 'Your account balance is insufficient top-secret' ), 400 );
		} );
		$error = $client->request( 'POST', '/domains', array( 'password' => 'another-secret' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'op_920', $error->get_error_code() );
		$this->assertStringNotContainsString( 'top-secret', $error->get_error_message() );
		$this->assertStringNotContainsString( 'another-secret', $error->get_error_message() );
	}

	public function test_token_cache_is_per_mode(): void {
		$this->assertNotSame( LiveClient::token_key( 'sandbox' ), LiveClient::token_key( 'live' ) );
	}
}
