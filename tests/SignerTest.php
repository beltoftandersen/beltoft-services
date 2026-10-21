<?php
/**
 * Request signatures and open tokens.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Http\Signer;

/**
 * Signer tests.
 */
class SignerTest extends TestCase {

	/**
	 * A connection as Connections::get() returns it.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function connection( array $over = array() ): array {
		return array_merge(
			array(
				'auth'             => 'hmac',
				'secret'           => 's3cret',
				'header_timestamp' => 'X-Beltoft-Timestamp',
				'header_tenant'    => 'X-Beltoft-Tenant',
				'header_signature' => 'X-Beltoft-Signature',
				'header_static'    => '',
			),
			$over
		);
	}

	public function test_signature_matches_pinned_canonical_string(): void {
		$headers = Signer::headers( $this->connection(), 'POST', '/api/tenants/?x=1', '{"a":1}', 't-1', 1700000000 );

		$canonical = "POST\n/api/tenants/?x=1\n1700000000\nt-1\n" . hash( 'sha256', '{"a":1}' );
		$expected  = hash_hmac( 'sha256', $canonical, 's3cret' );

		$this->assertSame(
			array(
				'X-Beltoft-Timestamp' => '1700000000',
				'X-Beltoft-Tenant'    => 't-1',
				'X-Beltoft-Signature' => $expected,
			),
			$headers
		);

		// The same vector as a literal, so the service side (Django) can pin it too.
		$this->assertSame( '1a09418d4cbad6f078369d6f7db767b529835406b82ddf42c61025af60a90f76', $expected );
	}

	public function test_signature_changes_with_every_part(): void {
		$base = Signer::headers( $this->connection(), 'POST', '/p', 'b', 't', 1 )['X-Beltoft-Signature'];

		$this->assertNotSame( $base, Signer::headers( $this->connection(), 'PUT', '/p', 'b', 't', 1 )['X-Beltoft-Signature'] );
		$this->assertNotSame( $base, Signer::headers( $this->connection(), 'POST', '/q', 'b', 't', 1 )['X-Beltoft-Signature'] );
		$this->assertNotSame( $base, Signer::headers( $this->connection(), 'POST', '/p', 'c', 't', 1 )['X-Beltoft-Signature'] );
		$this->assertNotSame( $base, Signer::headers( $this->connection(), 'POST', '/p', 'b', 'u', 1 )['X-Beltoft-Signature'] );
		$this->assertNotSame( $base, Signer::headers( $this->connection(), 'POST', '/p', 'b', 't', 2 )['X-Beltoft-Signature'] );
		$this->assertNotSame( $base, Signer::headers( $this->connection( array( 'secret' => 'other' ) ), 'POST', '/p', 'b', 't', 1 )['X-Beltoft-Signature'] );
	}

	public function test_header_names_come_from_the_connection(): void {
		$headers = Signer::headers(
			$this->connection(
				array(
					'header_timestamp' => 'X-Ts',
					'header_tenant'    => 'X-Who',
					'header_signature' => 'X-Sig',
				)
			),
			'GET',
			'/x',
			'',
			't',
			5
		);

		$this->assertSame( array( 'X-Ts', 'X-Who', 'X-Sig' ), array_keys( $headers ) );
		$this->assertSame( hash_hmac( 'sha256', "GET\n/x\n5\nt\n" . hash( 'sha256', '' ), 's3cret' ), $headers['X-Sig'] );
	}

	public function test_header_auth_sends_the_secret_in_the_named_header(): void {
		$headers = Signer::headers(
			$this->connection(
				array(
					'auth'          => 'header',
					'header_static' => 'X-Api-Key',
				)
			),
			'POST',
			'/x',
			'{}',
			't-1',
			5
		);

		$this->assertSame(
			array(
				'X-Api-Key'        => 's3cret',
				'X-Beltoft-Tenant' => 't-1',
			),
			$headers
		);
	}

	public function test_open_token_expires_in_five_minutes_and_verifies_with_the_secret(): void {
		$connection = $this->connection();
		$now        = 1700000000;
		$token      = Signer::open_token( $connection, 't-1', 'a@example.com', 'KEY-1', $now );

		list( $encoded, $mac ) = explode( '.', $token );
		$payload               = base64_decode( strtr( $encoded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test.

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $encoded, 'base64url, no padding' );
		$this->assertSame(
			array(
				'tenant'      => 't-1',
				'email'       => 'a@example.com',
				'license_key' => 'KEY-1',
				'exp'         => $now + 300,
			),
			json_decode( $payload, true )
		);
		$this->assertSame( hash_hmac( 'sha256', $payload, 's3cret' ), $mac );

		$expected = array(
			'tenant'      => 't-1',
			'email'       => 'a@example.com',
			'license_key' => 'KEY-1',
			'exp'         => $now + 300,
		);

		$this->assertSame( $expected, Signer::verify_open_token( $connection, $token, $now ) );
		$this->assertSame( $expected, Signer::verify_open_token( $connection, $token, $now + 300 ) );
		$this->assertNull( Signer::verify_open_token( $connection, $token, $now + 301 ), 'expired after five minutes' );
		$this->assertNull( Signer::verify_open_token( $this->connection( array( 'secret' => 'other' ) ), $token, $now ), 'another secret' );
		$this->assertNull( Signer::verify_open_token( $this->connection( array( 'secret' => '' ) ), $token, $now ), 'no secret' );

		$forged = rtrim( strtr( base64_encode( str_replace( 'KEY-1', 'KEY-2', $payload ) ), '+/', '-_' ), '=' ) . '.' . $mac; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test.
		$this->assertNull( Signer::verify_open_token( $connection, $forged, $now ), 'changed payload' );
		$this->assertNull( Signer::verify_open_token( $connection, 'garbage', $now ) );
		$this->assertNull( Signer::verify_open_token( $connection, $encoded . '.' . $mac . '.x', $now ) );
	}
}
