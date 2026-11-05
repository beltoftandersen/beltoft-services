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
			return new \WP_Error( 'ssl_csr_key', __( 'The key of the certificate request is not accepted. Use an RSA key of at least 2048 bits, or an EC key on P-256 or P-384.', 'beltoft-services' ) );
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

		// A request that names more than was ordered would get a certificate for more than was paid for.
		$allowed = array( strtolower( $name ) );

		if ( '*.' === substr( $name, 0, 2 ) ) {
			$allowed[] = strtolower( substr( $name, 2 ) );
		}

		foreach ( self::sans( $csr ) as $san ) {
			if ( ! in_array( strtolower( $san ), $allowed, true ) ) {
				return new \WP_Error( 'ssl_csr_san', __( 'The certificate request names more than this certificate covers. Remove the extra names (subject alternative names) from the request.', 'beltoft-services' ) );
			}
		}

		// The registrar refuses a request without a valid country ("The CSR contains an invalid country code"), whatever the level.
		if ( 1 !== preg_match( '/^[A-Za-z]{2}$/D', (string) ( $read['subject']['C'] ?? '' ) ) ) {
			return new \WP_Error( 'ssl_csr_country', __( 'The certificate request must include a country (C), for example C=DK.', 'beltoft-services' ) );
		}

		if ( in_array( $level, array( 'ov', 'ev' ), true ) && ( empty( $read['subject']['O'] ) || empty( $read['subject']['ST'] ) ) ) {
			return new \WP_Error( 'ssl_csr_org', __( 'This certificate type needs the organization in the request: company name (O), state (ST) and country (C).', 'beltoft-services' ) );
		}

		return $read;
	}

	/**
	 * The subject alternative names a request asks for, read from its DER (PHP has no function for it). A name that is not a DNS
	 * name (an address, an email) comes back as "#other", so it never matches an ordered name. Empty when there is no such extension.
	 *
	 * @param string $csr PEM text.
	 * @return string[]
	 */
	public static function sans( string $csr ): array {
		$body = (string) preg_replace( '/-----[A-Z ]+-----|\s+/', '', $csr );
		$der  = base64_decode( $body, true );

		if ( false === $der ) {
			return array();
		}

		// OID 2.5.29.17 (subjectAltName), then an optional BOOLEAN, then an OCTET STRING that holds a SEQUENCE of names.
		$at = strpos( $der, "\x06\x03\x55\x1d\x11" );

		if ( false === $at ) {
			return array();
		}

		$pos = $at + 5;

		if ( "\x01\x01" === substr( $der, $pos, 2 ) ) {
			$pos += 3;
		}

		if ( "\x04" !== ( $der[ $pos ] ?? '' ) ) {
			return array( '#other' );
		}

		$octet = self::der_length( $der, $pos + 1 );

		if ( null === $octet || "\x30" !== ( $der[ $octet[1] ] ?? '' ) ) {
			return array( '#other' );
		}

		$seq = self::der_length( $der, $octet[1] + 1 );

		if ( null === $seq ) {
			return array( '#other' );
		}

		$names = array();
		$pos   = $seq[1];
		$end   = $seq[1] + $seq[0];

		while ( $pos < $end ) {
			$tag = ord( $der[ $pos ] ?? "\0" );
			$len = self::der_length( $der, $pos + 1 );

			if ( null === $len ) {
				return array( '#other' );
			}

			$names[] = 0x82 === $tag ? substr( $der, $len[1], $len[0] ) : '#other';
			$pos     = $len[1] + $len[0];
		}

		return $names;
	}

	/**
	 * A DER length at an offset.
	 *
	 * @param string $der    Bytes.
	 * @param int    $offset Offset of the first length byte.
	 * @return array{0: int, 1: int}|null Length and the offset where the value starts.
	 */
	private static function der_length( string $der, int $offset ): ?array {
		if ( $offset >= strlen( $der ) ) {
			return null;
		}

		$first = ord( $der[ $offset ] );

		if ( $first < 0x80 ) {
			return array( $first, $offset + 1 );
		}

		$count = $first & 0x7f;

		if ( $count < 1 || $count > 3 || $offset + $count >= strlen( $der ) ) {
			return null;
		}

		$length = 0;

		for ( $i = 1; $i <= $count; $i++ ) {
			$length = ( $length << 8 ) | ord( $der[ $offset + $i ] );
		}

		return array( $length, $offset + 1 + $count );
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
