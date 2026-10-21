<?php
/**
 * Crypto.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Crypto;

/**
 * Libsodium secrets.
 */
class CryptoTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		parent::tear_down();
	}

	private function use_key(): void {
		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
	}

	public function test_encrypt_round_trip(): void {
		$this->use_key();
		$this->assertTrue( Crypto::available() );

		$stored = Crypto::encrypt( 'top secret' );
		$this->assertStringStartsWith( 'bs1:', $stored );
		$this->assertNotSame( $stored, Crypto::encrypt( 'top secret' ), 'random nonce' );
		$this->assertSame( 'top secret', Crypto::decrypt( $stored ) );
	}

	public function test_decrypt_refuses_tampered_value(): void {
		$this->use_key();
		$stored = Crypto::encrypt( 'top secret' );
		$raw    = base64_decode( substr( $stored, 4 ) );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );

		$this->assertNull( Crypto::decrypt( 'bs1:' . base64_encode( $raw ) ) );
		$this->assertNull( Crypto::decrypt( 'bs1:!!!' ) );
		$this->assertNull( Crypto::decrypt( 'plain' ) );
		$this->assertNull( Crypto::decrypt( '' ) );
	}

	public function test_decrypt_with_another_key_is_null(): void {
		$this->use_key();
		$stored = Crypto::encrypt( 'top secret' );
		remove_all_filters( 'beltoft_services_key' );
		$this->use_key();

		$this->assertNull( Crypto::decrypt( $stored ) );
	}

	public function test_no_key_means_not_available_and_secrets_refused(): void {
		$this->assertFalse( Crypto::available() );
		$this->assertNull( Crypto::decrypt( 'bs1:abc' ) );

		try {
			Crypto::encrypt( 'x' );
			$this->fail( 'Expected an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'BELTOFT_SERVICES_KEY', $e->getMessage() );
		}
	}

	public function test_wrong_length_key_is_not_a_key(): void {
		add_filter( 'beltoft_services_key', fn() => base64_encode( 'short' ) );
		$this->assertFalse( Crypto::available() );
	}

	public function test_suggested_key_is_a_valid_key(): void {
		$suggested = Crypto::suggested_key();
		$this->assertSame( 32, strlen( base64_decode( $suggested, true ) ) );
		$this->assertNotSame( $suggested, Crypto::suggested_key() );
	}
}
