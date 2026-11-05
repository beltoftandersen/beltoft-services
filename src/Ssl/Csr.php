<?php
/** Certificate requests and certificates, read and made with PHP's openssl. @package BeltoftServices */
namespace BeltoftServices\Ssl;

defined( 'ABSPATH' ) || exit;

/**
 * The private key never leaves this server unencrypted and is never sent to the registrar: a request is made here, and only
 * its public part is ordered. A request the customer supplies is checked before anything is bought: it must be a request,
 * for the name that was paid for, with a strong key, and an OV or EV request must name the organization.
 */
final class Csr {
	/** The largest request text accepted. */
	public const MAX_BYTES = 8192;

	/** The smallest RSA key accepted. */
	public const MIN_BITS = 2048;

	/**
	 * Runs a callback with a minimal openssl configuration, so no default subject entries of the server's own openssl.cnf
	 * end up in a request. The file is removed afterwards.
	 *
	 * @param callable $work Receives the config path.
	 * @return mixed What the callback returns.
	 */
	private static function with_config( callable $work ) {
		$path = (string) wp_tempnam( 'beltoft-ssl' );

		file_put_contents( $path, "[req]\ndistinguished_name = dn\n[dn]\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary openssl config.

		try {
			return $work( $path );
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * Makes a private key and a request for one name. The subject carries the organization entries only when they are
	 * given (OV and EV).
	 *
	 * @param string               $name Common name, for a wildcard "*.example.com".
	 * @param array<string, string> $org  organizationName, stateOrProvinceName, localityName, countryName (any may be missing).
	 * @return array{key: string, csr: string}|\WP_Error
	 */
	public static function generate( string $name, array $org = array() ) {
		return self::with_config(
			static function ( string $config ) use ( $name, $org ) {
				$key = openssl_pkey_new( array( 'private_key_bits' => self::MIN_BITS, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config ) );

				if ( ! $key ) {
					return new \WP_Error( 'ssl_key', 'The key could not be made.' );
				}

				$dn = array( 'commonName' => $name );

				foreach ( array( 'organizationName', 'stateOrProvinceName', 'localityName', 'countryName' ) as $field ) {
					if ( isset( $org[ $field ] ) && '' !== trim( (string) $org[ $field ] ) ) {
						$dn[ $field ] = trim( (string) $org[ $field ] );
					}
				}

				$request = openssl_csr_new( $dn, $key, array( 'digest_alg' => 'sha256', 'config' => $config ) );

				if ( ! $request || ! openssl_csr_export( $request, $csr ) || ! openssl_pkey_export( $key, $pem, null, array( 'config' => $config ) ) ) {
					return new \WP_Error( 'ssl_csr', 'The certificate request could not be made.' );
				}

				return array( 'key' => (string) $pem, 'csr' => (string) $csr );
			}
		);
	}

	/**
	 * What a request says, or why it is not usable. Messages are for the customer.
	 *
	 * @param string $csr PEM text.
	 * @return array{cn: string, subject: array<string, string>, bits: int, type: string, public: string}|\WP_Error
	 */
	public static function parse( string $csr ) {
		$csr = trim( $csr );

		if ( '' === $csr || strlen( $csr ) > self::MAX_BYTES || 0 !== strpos( $csr, '-----BEGIN CERTIFICATE REQUEST-----' ) && 0 !== strpos( $csr, '-----BEGIN NEW CERTIFICATE REQUEST-----' ) ) {
			return new \WP_Error( 'ssl_csr_format', __( 'Paste a certificate request (CSR) in PEM format, starting with BEGIN CERTIFICATE REQUEST.', 'beltoft-services' ) );
		}

		$subject = openssl_csr_get_subject( $csr, true );
		$public  = openssl_csr_get_public_key( $csr );
		$details = $public ? openssl_pkey_get_details( $public ) : false;

		if ( ! is_array( $subject ) || ! $details ) {
			return new \WP_Error( 'ssl_csr_format', __( 'The certificate request could not be read. Check that it is complete.', 'beltoft-services' ) );
		}

		$type = OPENSSL_KEYTYPE_RSA === $details['type'] ? 'rsa' : ( OPENSSL_KEYTYPE_EC === $details['type'] ? 'ec' : 'other' );
		$bits = (int) ( $details['bits'] ?? 0 );

		if ( 'other' === $type || ( 'rsa' === $type && $bits < self::MIN_BITS ) || ( 'ec' === $type && $bits < 256 ) ) {
			return new \WP_Error( 'ssl_csr_key', __( 'The key of the certificate request is too weak. Use an RSA key of at least 2048 bits.', 'beltoft-services' ) );
		}

		$clean = array();

		foreach ( $subject as $field => $value ) {
			$clean[ (string) $field ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return array( 'cn' => strtolower( $clean['CN'] ?? '' ), 'subject' => $clean, 'bits' => $bits, 'type' => $type, 'public' => (string) $details['key'] );
	}

	/**
	 * Checks a request against what was bought.
	 *
	 * @param string $csr   PEM text.
	 * @param string $name  The name the certificate is for ("*.example.com" for a wildcard).
	 * @param string $level dv, ov or ev.
	 * @return array{cn: string, subject: array<string, string>, bits: int, type: string, public: string}|\WP_Error
	 */
	public static function check( string $csr, string $name, string $level ) {
		$read = self::parse( $csr );

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		if ( $read['cn'] !== strtolower( $name ) ) {
			return new \WP_Error(
				'ssl_csr_name',
				/* translators: %s: the name the certificate is for. */
				sprintf( __( 'The common name of the certificate request must be %s.', 'beltoft-services' ), $name )
			);
		}

		if ( in_array( $level, array( 'ov', 'ev' ), true ) && ( empty( $read['subject']['O'] ) || empty( $read['subject']['C'] ) || empty( $read['subject']['ST'] ) ) ) {
			return new \WP_Error( 'ssl_csr_org', __( 'This certificate type needs the organization in the request: company name (O), state (ST) and country (C).', 'beltoft-services' ) );
		}

		return $read;
	}

	/**
	 * A certificate read for what we store and compare.
	 *
	 * @param string $pem Certificate PEM.
	 * @return array{serial: string, from: int, to: int, cn: string, public: string}|\WP_Error
	 */
	public static function certificate( string $pem ) {
		$parsed = openssl_x509_parse( $pem );
		$public = openssl_pkey_get_public( $pem );
		$detail = $public ? openssl_pkey_get_details( $public ) : false;

		if ( ! is_array( $parsed ) || ! $detail || empty( $parsed['serialNumberHex'] ) ) {
			return new \WP_Error( 'ssl_cert', 'The certificate could not be read.' );
		}

		return array(
			'serial' => strtoupper( (string) $parsed['serialNumberHex'] ),
			'from'   => (int) $parsed['validFrom_time_t'],
			'to'     => (int) $parsed['validTo_time_t'],
			'cn'     => strtolower( (string) ( $parsed['subject']['CN'] ?? '' ) ),
			'public' => (string) $detail['key'],
		);
	}

	/**
	 * The certificate, then its chain, as one PEM text.
	 *
	 * @param string $certificate  Leaf.
	 * @param string $intermediate Intermediate certificates.
	 * @param string $root         Root certificate (often left out of a chain file).
	 * @param bool   $with_root    Include the root.
	 */
	public static function fullchain( string $certificate, string $intermediate, string $root = '', bool $with_root = false ): string {
		$parts = array( $certificate, $intermediate );

		if ( $with_root ) {
			$parts[] = $root;
		}

		return implode( "\n", array_map( static fn( $p ) => trim( $p ), array_filter( $parts, static fn( $p ) => '' !== trim( (string) $p ) ) ) ) . "\n";
	}
}
