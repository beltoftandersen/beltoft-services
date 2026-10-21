<?php
/**
 * Secrets at rest.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets with libsodium secretbox, using a key that lives in wp-config.php
 * (constant BELTOFT_SERVICES_KEY, base64 of 32 bytes), never in the database.
 *
 * Stored format: "bs1:" . base64( nonce . ciphertext ).
 */
final class Crypto {

	private const PREFIX = 'bs1:';

	/**
	 * The raw 32 byte key, or null when none is configured.
	 */
	private static function key(): ?string {
		$configured = defined( 'BELTOFT_SERVICES_KEY' ) ? (string) constant( 'BELTOFT_SERVICES_KEY' ) : '';

		/**
		 * Filters the base64 encoded secrets key. Receives the value of the constant
		 * BELTOFT_SERVICES_KEY, or an empty string when it is not defined.
		 *
		 * @param string $configured Base64 of 32 random bytes.
		 */
		$configured = apply_filters( 'beltoft_services_key', $configured );

		if ( ! is_string( $configured ) || '' === $configured || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}

		$raw = base64_decode( $configured, true );

		return ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) ? $raw : null;
	}

	/**
	 * Whether a valid key is configured.
	 */
	public static function available(): bool {
		return null !== self::key();
	}

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plain Plain text.
	 * @return string Value to store.
	 * @throws \RuntimeException When no key is configured.
	 */
	public static function encrypt( string $plain ): string {
		$key = self::key();

		if ( null === $key ) {
			throw new \RuntimeException( 'BELTOFT_SERVICES_KEY is not configured.' );
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, $key );

		sodium_memzero( $key );

		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text, not obfuscation.
	}

	/**
	 * Decrypts a stored secret.
	 *
	 * @param string $stored Value from encrypt().
	 * @return string|null Plain text, or null when it is not valid, was tampered with, or the key is missing or different.
	 */
	public static function decrypt( string $stored ): ?string {
		$key = self::key();

		if ( null === $key || 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary from text.

		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);

		sodium_memzero( $key );

		return false === $plain ? null : $plain;
	}

	/**
	 * A fresh random key, ready to paste into wp-config.php.
	 */
	public static function suggested_key(): string {
		return base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text, not obfuscation.
	}
}
