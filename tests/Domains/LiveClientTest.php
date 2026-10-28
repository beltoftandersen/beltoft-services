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

	public function test_availability_check_retries_one_transport_failure(): void {
		$client = new LiveClient( 'sandbox', 'u', 'p' );
		$checks = 0;
		$this->respond_with( function ( $url ) use ( &$checks ) {
			if ( str_ends_with( $url, '/auth/login' ) ) {
				return $this->reply( array( 'data' => array( 'token' => 'token-one' ) ) );
			}
			++$checks;
			return 1 === $checks ? new \WP_Error( 'http_request_failed', 'Temporary network failure.' ) : $this->reply( array( 'data' => array( 'results' => array() ) ) );
		} );
		$this->assertSame( array( 'results' => array() ), $client->request( 'POST', '/domains/check', array( 'domains' => array() ) ) );
		$this->assertSame( 2, $checks );
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
		$this->assertStringNotContainsString( 'top-secret', (string) wp_json_encode( $error->get_error_data() ) );
		$this->assertSame( 'Your account balance is insufficient [hidden]', $error->get_error_data()['desc'], 'Staff get the registrar\'s explanation; an echoed secret is hidden.' );
	}

	public function test_token_cache_is_per_mode(): void {
		$this->assertNotSame( LiveClient::token_key( 'sandbox' ), LiveClient::token_key( 'live' ) );
	}

	/** @return array<string, array{0:\WP_Error,1:bool}> */
	public static function transient_cases(): array {
		return array(
			'transport'                => array( new \WP_Error( 'transport', 'x' ), true ),
			'bad response'             => array( new \WP_Error( 'bad_response', 'x' ), true ),
			'429 with a registrar code' => array( new \WP_Error( 'op_100', 'x', array( 'status' => 429 ) ), true ),
			'408 with a registrar code' => array( new \WP_Error( 'op_100', 'x', array( 'status' => 408 ) ), true ),
			'503 with a registrar code' => array( new \WP_Error( 'op_100', 'x', array( 'status' => 503 ) ), true ),
			'http_502 without status'  => array( new \WP_Error( 'http_502', 'x' ), true ),
			'400 with a registrar code' => array( new \WP_Error( 'op_399', 'x', array( 'status' => 400 ) ), false ),
			'401'                      => array( new \WP_Error( 'http_401', 'x', array( 'status' => 401 ) ), false ),
			'404'                      => array( new \WP_Error( 'http_404', 'x', array( 'status' => 404 ) ), false ),
		);
	}

	/** @dataProvider transient_cases */
	public function test_errors_are_temporary_by_http_status( \WP_Error $error, bool $expected ): void {
		$this->assertSame( $expected, LiveClient::is_transient( $error ) );
	}

	public function test_the_transfer_code_is_scrubbed_from_the_registrars_explanation(): void {
		$client = new LiveClient( 'sandbox', 'u', 'p' );
		$this->respond_with( function ( $url ) {
			if ( str_ends_with( $url, '/auth/login' ) ) { return $this->reply( array( 'data' => array( 'token' => 'token-one' ) ) ); }
			return $this->reply( array( 'code' => 399, 'desc' => 'Invalid auth code Zq9-secret-code for the domain' ), 400 );
		} );
		$error = $client->request( 'POST', '/domains/transfer', array( 'auth_code' => 'Zq9-secret-code' ) );
		$this->assertWPError( $error );
		$this->assertStringNotContainsString( 'Zq9-secret-code', $error->get_error_data()['desc'] );
		$this->assertStringContainsString( '[hidden]', $error->get_error_data()['desc'] );
	}
}
