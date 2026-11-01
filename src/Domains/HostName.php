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
		$name = strtolower( rtrim( trim( $raw ), '.' ) );
		if ( '' === $name || strlen( $name ) > 253 ) {
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
}
