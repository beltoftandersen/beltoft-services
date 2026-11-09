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
	 * @param string[] $sans Names for the subject alternative name extension (none for a single name).
	 * @param string   $type DNS for host names, email for mailboxes.
	 * @return mixed What the callback returns.
	 */
	private static function with_config( callable $work, array $sans = array(), string $type = 'DNS' ) {
		$path = (string) wp_tempnam( 'beltoft-ssl' );
		$text = "[req]\ndistinguished_name = dn\n[dn]\n";

		if ( array() !== $sans ) {
			$names = array_map( static fn( $n ) => $type . ':' . $n, $sans );
			$text  = "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\n[dn]\n[v3_req]\nsubjectAltName = " . implode( ',', $names ) . "\n";
		}

		file_put_contents( $path, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary openssl config.

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
	 * @param string[]              $names All names of a multi domain certificate (the common name first); they become the subject alternative names. Empty or one name: no extension.
	 * @return array{key: string, csr: string}|\WP_Error
	 */
	public static function generate( string $name, array $org = array(), array $names = array() ) {
		$sans = count( $names ) > 1 ? array_values( array_filter( array_map( 'strtolower', $names ), static fn( $n ) => 1 === preg_match( '/^[a-z0-9.-]{1,253}$/D', $n ) ) ) : array();

		return self::with_config(
			static function ( string $config ) use ( $name, $org ) {
				$key = openssl_pkey_new( array( 'private_key_bits' => self::MIN_BITS, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config ) );

				if ( ! $key ) {
					return new \WP_Error( 'ssl_key', __( 'The key could not be made.', 'beltoft-services' ) );
				}

				$dn = array( 'commonName' => $name );

				foreach ( array( 'organizationName', 'stateOrProvinceName', 'localityName', 'countryName' ) as $field ) {
					if ( isset( $org[ $field ] ) && '' !== trim( (string) $org[ $field ] ) ) {
						$dn[ $field ] = trim( (string) $org[ $field ] );
					}
				}

				$request = openssl_csr_new( $dn, $key, array( 'digest_alg' => 'sha256', 'config' => $config ) );

				if ( ! $request || ! openssl_csr_export( $request, $csr ) || ! openssl_pkey_export( $key, $pem, null, array( 'config' => $config ) ) ) {
					return new \WP_Error( 'ssl_csr', __( 'The certificate request could not be made.', 'beltoft-services' ) );
				}

				return array( 'key' => (string) $pem, 'csr' => (string) $csr );
			},
			$sans
		);
	}

	/**
	 * Makes a private key and an S/MIME request for one mailbox: the mailbox is the subject's emailAddress and the only subject
	 * alternative name (an email entry, rfc822Name); the common name is the person's name when one is given.
	 *
	 * @param string $mailbox Email address.
	 * @param string $person  Name of the person (may be empty).
	 * @param string $country Two letter country.
	 * @return array{key: string, csr: string}|\WP_Error
	 */
	public static function generate_email( string $mailbox, string $person, string $country ) {
		$mailbox = strtolower( trim( $mailbox ) );

		if ( ! is_email( $mailbox ) || 1 !== preg_match( '/^[^\s,;:]+$/D', $mailbox ) ) {
			return new \WP_Error( 'ssl_mailbox', __( 'The mailbox is not a valid email address.', 'beltoft-services' ) );
		}

		return self::with_config(
			static function ( string $config ) use ( $mailbox, $person, $country ) {
				$key = openssl_pkey_new( array( 'private_key_bits' => self::MIN_BITS, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config ) );

				if ( ! $key ) {
					return new \WP_Error( 'ssl_key', __( 'The key could not be made.', 'beltoft-services' ) );
				}

				$dn     = array();
				$person = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $person ) );

				if ( '' !== $person ) {
					$dn['commonName'] = mb_substr( $person, 0, 64 );
				}

				$dn['emailAddress'] = $mailbox;

				if ( 1 === preg_match( '/^[A-Za-z]{2}$/D', $country ) ) {
					$dn['countryName'] = strtoupper( $country );
				}

				$request = openssl_csr_new( $dn, $key, array( 'digest_alg' => 'sha256', 'config' => $config ) );

				if ( ! $request || ! openssl_csr_export( $request, $csr ) || ! openssl_pkey_export( $key, $pem, null, array( 'config' => $config ) ) ) {
					return new \WP_Error( 'ssl_csr', __( 'The certificate request could not be made.', 'beltoft-services' ) );
				}

				return array( 'key' => (string) $pem, 'csr' => (string) $csr );
			},
			array( $mailbox ),
			'email'
		);
	}

	/**
	 * Checks an S/MIME request against the mailbox that was paid for: a usable request whose email (subject or subject alternative
	 * name) is that mailbox and names nothing else, with a country.
	 *
	 * @param string $csr     PEM text.
	 * @param string $mailbox Email address the certificate is for.
	 * @return array{cn: string, subject: array<string, string>, bits: int, type: string, public: string}|\WP_Error
	 */
	public static function check_email( string $csr, string $mailbox ) {
		$read = self::parse( $csr );

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		$mailbox = strtolower( trim( $mailbox ) );
		$subject = strtolower( (string) ( $read['subject']['emailAddress'] ?? '' ) );
		$sans    = array_map( 'strtolower', self::sans( $csr, true ) );

		if ( '' === $subject && array() === $sans ) {
			/* translators: %s: email address. */
			return new \WP_Error( 'ssl_csr_mailbox', sprintf( __( 'The certificate request must contain the email address %s.', 'beltoft-services' ), $mailbox ) );
		}

		foreach ( array_merge( '' !== $subject ? array( $subject ) : array(), $sans ) as $found ) {
			if ( $found !== $mailbox ) {
				/* translators: %s: email address. */
				return new \WP_Error( 'ssl_csr_mailbox', sprintf( __( 'The certificate request may contain only the email address %s, and nothing else (no host names or other addresses).', 'beltoft-services' ), $mailbox ) );
			}
		}

		if ( 1 !== preg_match( '/^[A-Za-z]{2}$/D', (string) ( $read['subject']['C'] ?? '' ) ) ) {
			return new \WP_Error( 'ssl_csr_country', __( 'The certificate request must include a country (C), for example C=DK.', 'beltoft-services' ) );
		}

		return $read;
	}

	/**
	 * Does the certificate belong to the mailbox: the address is in its subject or its subject alternative names?
	 *
	 * @param string $pem     Certificate PEM.
	 * @param string $mailbox Email address.
	 */
	public static function certificate_has_mailbox( string $pem, string $mailbox ): bool {
		$parsed  = openssl_x509_parse( $pem );
		$mailbox = strtolower( trim( $mailbox ) );

		if ( ! is_array( $parsed ) ) {
			return false;
		}

		if ( strtolower( (string) ( $parsed['subject']['emailAddress'] ?? '' ) ) === $mailbox ) {
			return true;
		}

		foreach ( preg_split( '/\s*,\s*/', (string) ( $parsed['extensions']['subjectAltName'] ?? '' ) ) ?: array() as $entry ) {
			if ( 0 === stripos( $entry, 'email:' ) && strtolower( trim( substr( $entry, 6 ) ) ) === $mailbox ) {
				return true;
			}
		}

		return false;
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
	 * @param string[] $names All names of a multi domain certificate (the common name first); empty for a single name one.
	 * @return array{cn: string, subject: array<string, string>, bits: int, type: string, public: string}|\WP_Error
	 */
	public static function check( string $csr, string $name, string $level, array $names = array() ) {
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
		$allowed = array_values( array_unique( array_map( 'strtolower', array_merge( array( $name ), $names ) ) ) );

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
	 * The subject alternative names a request asks for, read by walking its DER structure (PHP has no function for it):
	 * CertificationRequest, its info, the attributes ([0]), the extensionRequest attribute and every subjectAltName extension in it.
	 * Bytes that merely look like the OID elsewhere (in the subject, say) are not read. A name that is not a DNS name (an address,
	 * an email) comes back as "#other", so it never matches an ordered name, and so does anything that cannot be parsed. Empty when
	 * there is no such extension.
	 *
	 * @param string $csr   PEM text.
	 * @param bool   $email Read the email entries (rfc822Name, for S/MIME) instead of the DNS ones; every other kind is then "#other".
	 * @return string[]
	 */
	public static function sans( string $csr, bool $email = false ): array {
		$body = (string) preg_replace( '/-----[A-Z ]+-----|\s+/', '', $csr );
		$der  = base64_decode( $body, true );

		if ( false === $der ) {
			return array();
		}

		$top  = self::tlv( $der, 0 );
		$info = $top && 0x30 === $top[0] ? self::tlv( $der, $top[1] ) : null;

		if ( ! $info || 0x30 !== $info[0] ) {
			return array( '#other' );
		}

		$names = array();
		$pos   = $info[1];

		// version, subject, public key, then the attributes.
		while ( $pos < $info[2] ) {
			$item = self::tlv( $der, $pos );

			if ( ! $item ) {
				return array( '#other' );
			}

			if ( 0xA0 === $item[0] ) {
				$found = self::attribute_names( $der, $item[1], $item[2], $email ? 0x81 : 0x82 );

				if ( null === $found ) {
					return array( '#other' );
				}

				$names = array_merge( $names, $found );
			}

			$pos = $item[2];
		}

		return $names;
	}

	/**
	 * One element at an offset: tag, where its value starts and where it ends. Null when it does not fit.
	 *
	 * @param string $der Bytes.
	 * @param int    $pos Offset of the tag.
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function tlv( string $der, int $pos ): ?array {
		if ( $pos >= strlen( $der ) ) {
			return null;
		}

		$len = self::der_length( $der, $pos + 1 );

		return $len && $len[1] + $len[0] <= strlen( $der ) ? array( ord( $der[ $pos ] ), $len[1], $len[1] + $len[0] ) : null;
	}

	/**
	 * The DNS names of every subjectAltName extension inside the extensionRequest attributes of a request.
	 *
	 * @param string $der   Bytes.
	 * @param int    $start Start of the attribute list.
	 * @param int    $end   End of the attribute list.
	 * @param int    $want  The general name tag read: 0x82 DNS name, 0x81 email.
	 * @return string[]|null Null when the structure is malformed.
	 */
	private static function attribute_names( string $der, int $start, int $end, int $want = 0x82 ): ?array {
		$ext_req = "\x2a\x86\x48\x86\xf7\x0d\x01\x09\x0e";
		$san     = "\x55\x1d\x11";
		$names   = array();

		for ( $pos = $start; $pos < $end; ) {
			$attr = self::tlv( $der, $pos );
			$oid  = $attr && 0x30 === $attr[0] ? self::tlv( $der, $attr[1] ) : null;

			if ( ! $oid || 0x06 !== $oid[0] ) {
				return null;
			}

			$pos = $attr[2];

			if ( substr( $der, $oid[1], $oid[2] - $oid[1] ) !== $ext_req ) {
				continue;
			}

			$set = self::tlv( $der, $oid[2] );
			$seq = $set && 0x31 === $set[0] ? self::tlv( $der, $set[1] ) : null;

			if ( ! $seq || 0x30 !== $seq[0] ) {
				return null;
			}

			for ( $e = $seq[1]; $e < $seq[2]; ) {
				$ext = self::tlv( $der, $e );
				$id  = $ext && 0x30 === $ext[0] ? self::tlv( $der, $ext[1] ) : null;

				if ( ! $id || 0x06 !== $id[0] ) {
					return null;
				}

				$e = $ext[2];

				if ( substr( $der, $id[1], $id[2] - $id[1] ) !== $san ) {
					continue;
				}

				$next = self::tlv( $der, $id[2] );

				// An optional "critical" BOOLEAN comes before the OCTET STRING.
				if ( $next && 0x01 === $next[0] ) {
					$next = self::tlv( $der, $next[2] );
				}

				$general = $next && 0x04 === $next[0] ? self::tlv( $der, $next[1] ) : null;

				if ( ! $general || 0x30 !== $general[0] ) {
					return null;
				}

				for ( $g = $general[1]; $g < $general[2]; ) {
					$name = self::tlv( $der, $g );

					if ( ! $name ) {
						return null;
					}

					$names[] = $want === $name[0] ? substr( $der, $name[1], $name[2] - $name[1] ) : '#other';
					$g       = $name[2];
				}
			}
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
			return new \WP_Error( 'ssl_cert', __( 'The certificate could not be read.', 'beltoft-services' ) );
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
