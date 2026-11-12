<?php
/**
 * The temporary name of a website, made from the customer's own details.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * The customer is never asked for the name in the temporary address: it is the title, the company, the person or the
 * email name, cut short and followed by a dash and a few random characters, for example `myfirm-k7`. The random part is
 * always there, so another customer's name cannot be guessed. A name is checked with `Sites::valid_slug()` and
 * `Sites::taken()`, and at checkout it is held with `Sites::reserve()` the moment it is drawn, as a typed name is.
 */
final class SiteNames {

	/** No 0, o, 1, l or i: the characters that are read wrongly. */
	public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

	/** Longest readable part of a name. */
	public const BASE_MAX = 20;

	/** Draws at each suffix length. */
	public const TRIES = 20;

	/** Name made in this request by owner, line and base, so a second look at the same line gives the same name. */
	private static array $made = array();

	/**
	 * The readable part: the first of title, company, first and last name, and the name part of the email that gives
	 * letters or numbers, in ASCII.
	 *
	 * @param array<string, string> $source title, company, first_name, last_name, email.
	 */
	public static function base( array $source ): string {
		$candidates = array(
			(string) ( $source['title'] ?? '' ),
			(string) ( $source['company'] ?? '' ),
			trim( (string) ( $source['first_name'] ?? '' ) . ' ' . (string) ( $source['last_name'] ?? '' ) ),
			(string) strstr( (string) ( $source['email'] ?? '' ), '@', true ),
		);

		foreach ( $candidates as $text ) {
			$base = self::clean( $text );

			if ( '' !== $base ) {
				return Sites::reserved( $base ) ? 'site' : $base;
			}
		}

		return 'site';
	}

	/**
	 * Letters and numbers only: national letters spelled out, everything else a dash, at most BASE_MAX characters.
	 *
	 * @param string $text Any text.
	 */
	public static function clean( string $text ): string {
		$text = strtr(
			mb_strtolower( $text ),
			array(
				"\u{e6}" => 'ae',
				"\u{f8}" => 'oe',
				"\u{e5}" => 'aa',
			)
		);
		$text = strtolower( remove_accents( $text ) );
		$text = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $text ), '-' );

		return rtrim( substr( $text, 0, self::BASE_MAX ), '-' );
	}

	/**
	 * A free name, ready to use. With $reserve the name is held for the owner as a typed name is.
	 *
	 * @param array<string, string> $source  See base().
	 * @param string                $owner   Who holds the name.
	 * @param string                $line    Cart line, so the same line gets the same name when it is looked at again ('' for none).
	 * @param bool                  $reserve Hold the name (checkout) or only check it is free.
	 * @return string The name, or '' when no free name was found (not expected).
	 */
	public static function generate( array $source, string $owner, string $line = '', bool $reserve = true ): string {
		$base = self::base( $source );
		$memo = $owner . '|' . $line . '|' . $base;

		if ( '' !== $line && isset( self::$made[ $memo ] ) ) {
			$again = self::$made[ $memo ];

			if ( ! Sites::taken( $again ) && ( ! $reserve || Sites::reserve( $again, $owner ) ) ) {
				return $again;
			}
		}

		$used = array_values( self::$made );

		for ( $length = 2; $length <= 3; $length++ ) {
			for ( $try = 1; $try <= self::TRIES; $try++ ) {
				$suffix = self::suffix( $length );
				$name   = $base . '-' . $suffix;

				/**
				 * Filters the temporary name drawn for a website, before it is checked. A site can supply its own: return
				 * the whole name. The checks (valid, not reserved, free) still follow.
				 *
				 * @param string               $name    Candidate, for example myfirm-k7.
				 * @param array<string, mixed> $context base, suffix, length (of the suffix), attempt (1 to TRIES at that length), owner, source.
				 */
				$name = strtolower( trim( (string) apply_filters( 'beltoft_services_hosting_generated_name', $name, array( 'base' => $base, 'suffix' => $suffix, 'length' => $length, 'attempt' => $try, 'owner' => $owner, 'source' => $source ) ) ) );

				if ( in_array( $name, $used, true ) || ! Sites::valid_slug( $name ) || Sites::taken( $name ) ) {
					continue;
				}

				if ( $reserve && ! Sites::reserve( $name, $owner ) ) {
					continue;
				}

				if ( '' !== $line ) {
					self::$made[ $memo ] = $name;
				}

				return $name;
			}
		}

		return '';
	}

	/**
	 * Random characters from the alphabet.
	 *
	 * @param int $length How many.
	 */
	private static function suffix( int $length ): string {
		$out = '';
		$max = strlen( self::ALPHABET ) - 1;

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= self::ALPHABET[ wp_rand( 0, $max ) ];
		}

		return $out;
	}

	/**
	 * What the customer's account says, for a name made after the checkout.
	 *
	 * @param int    $user_id Customer.
	 * @param string $email   Email to fall back on.
	 * @return array<string, string>
	 */
	public static function source_for_user( int $user_id, string $email = '' ): array {
		$meta = static fn( string $key ): string => $user_id > 0 ? trim( (string) get_user_meta( $user_id, $key, true ) ) : '';
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return array(
			'company'    => $meta( 'billing_company' ),
			'first_name' => '' !== $meta( 'billing_first_name' ) ? $meta( 'billing_first_name' ) : $meta( 'first_name' ),
			'last_name'  => '' !== $meta( 'billing_last_name' ) ? $meta( 'billing_last_name' ) : $meta( 'last_name' ),
			'email'      => '' !== $meta( 'billing_email' ) ? $meta( 'billing_email' ) : ( $user ? (string) $user->user_email : $email ),
		);
	}

	/**
	 * What the checkout form posted as billing details; an empty value is taken from the signed-in customer.
	 *
	 * @param int $user_id Customer, 0 for a guest.
	 * @return array<string, string>
	 */
	public static function source_from_post( int $user_id ): array {
		$out  = array();
		$keys = array(
			'company'    => 'billing_company',
			'first_name' => 'billing_first_name',
			'last_name'  => 'billing_last_name',
			'email'      => 'billing_email',
		);

		foreach ( $keys as $field => $post ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only read inside WooCommerce's checkout run, which verified its nonce; the value only seeds a name that is then cleaned to a-z, 0-9 and dashes.
			$value         = isset( $_POST[ $post ] ) && is_string( $_POST[ $post ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $post ] ) ) ) : '';
			$out[ $field ] = $value;
		}

		$account = self::source_for_user( $user_id );

		foreach ( $out as $field => $value ) {
			if ( '' === $value ) {
				$out[ $field ] = $account[ $field ];
			}
		}

		return $out;
	}
}
