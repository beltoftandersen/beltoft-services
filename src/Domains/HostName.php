<?php
/**
 * Host name normalisation for names typed by customers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * A name as it would be typed into DNS, shared by the app own-domain choice and the mail domain field.
 */
final class HostName {

	/** Top-level names that can never get a public certificate. */
	private const REFUSED_TLDS = array( 'localhost', 'local', 'internal', 'invalid', 'onion', 'lan', 'home' );

	/**
	 * A host name as it would be typed into DNS: lowercase ASCII letters, digits and hyphens in labels, at least two labels.
	 *
	 * @param mixed $raw Typed value.
	 */
	public static function normalise( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$name = rtrim( trim( $raw ), '.' );
		if ( '' === $name ) {
			return null;
		}
		if ( 1 === preg_match( '/[^\x00-\x7F]/', $name ) ) {
			// Unicode input (for example aeoeaa letters): stored and sent on as punycode.
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return null;
			}
			$ascii = idn_to_ascii( $name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( ! is_string( $ascii ) || '' === $ascii ) {
				return null;
			}
			$name = $ascii;
		}
		$name = strtolower( $name );
		if ( strlen( $name ) > 253 ) {
			return null;
		}
		$labels = explode( '.', $name );
		if ( count( $labels ) < 2 ) {
			return null;
		}
		foreach ( $labels as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label ) ) {
				return null;
			}
		}
		$tld = end( $labels );
		if ( in_array( $tld, self::REFUSED_TLDS, true ) || 1 !== preg_match( '/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D', $tld ) ) {
			return null;
		}
		return $name;
	}

	/**
	 * The form of a stored (punycode) name shown to a person: Unicode letters where the name has any.
	 *
	 * Unchanged when the name is plain ASCII or cannot be converted.
	 *
	 * @param mixed $ascii Stored name.
	 */
	public static function display( $ascii ): string {
		$ascii = is_string( $ascii ) ? $ascii : '';
		if ( '' === $ascii || false === stripos( $ascii, 'xn--' ) || ! function_exists( 'idn_to_utf8' ) ) {
			return $ascii;
		}
		$at = strrpos( $ascii, '@' );
		if ( false !== $at ) {
			return substr( $ascii, 0, $at + 1 ) . self::display( substr( $ascii, $at + 1 ) );
		}
		$utf8 = idn_to_utf8( $ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		return is_string( $utf8 ) && '' !== $utf8 ? $utf8 : $ascii;
	}

	/**
	 * A URL as link text: the host shown in Unicode. Use it for text only, never for the href.
	 *
	 * @param mixed $url Stored URL.
	 */
	public static function display_url( $url ): string {
		$url = is_string( $url ) ? $url : '';
		return (string) preg_replace_callback(
			'#^([a-z][a-z0-9+.-]*://)([^/:?\#]+)#i',
			static fn ( array $m ): string => $m[1] . self::display( $m[2] ),
			$url
		);
	}
}
