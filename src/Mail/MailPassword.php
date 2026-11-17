<?php
/**
 * The rules of a mailbox password the customer types.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that says whether a typed mailbox password is acceptable. The REST routes call it (the server is the
 * authority); assets/mail.js mirrors the same rules for a quick answer on the page. The password is never put in an error message.
 */
final class MailPassword {

	/** Shortest password, and the filter that changes it. */
	public const MIN        = 10;
	public const MIN_FILTER = 'beltoft_services_mail_password_min';

	/** Longest password. */
	public const MAX = 128;

	/** The local part is only matched when it has at least this many characters. */
	public const LOCAL_MIN = 4;

	/**
	 * The shortest password allowed (filterable, never below 8).
	 */
	public static function min(): int {
		return max( 8, min( self::MAX, (int) apply_filters( self::MIN_FILTER, self::MIN ) ) );
	}

	/**
	 * The rules in one sentence, for the page.
	 */
	public static function hint(): string {
		/* translators: 1: shortest length, 2: longest length. */
		return sprintf( __( 'Use %1$d to %2$d characters, with at least one letter and one digit.', 'beltoft-services' ), self::min(), self::MAX );
	}

	/**
	 * Checks a typed password for a mailbox.
	 *
	 * @param string $password The password.
	 * @param string $email    The mailbox address.
	 * @return \WP_Error|null Error with code `invalid` and a short sentence, or null when the password is fine.
	 */
	public static function check( string $password, string $email ): ?\WP_Error {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $password, 'UTF-8' ) : strlen( $password );

		if ( $length < self::min() || $length > self::MAX ) {
			/* translators: 1: shortest length, 2: longest length. */
			return self::bad( sprintf( __( 'The password must be %1$d to %2$d characters long.', 'beltoft-services' ), self::min(), self::MAX ) );
		}

		if ( preg_match( '/[\x00-\x1F\x7F]/', $password ) || 1 === preg_match( '/\p{Cc}/u', $password ) ) {
			return self::bad( __( 'The password cannot contain control characters.', 'beltoft-services' ) );
		}

		if ( 1 === preg_match( '/^\s|\s\z/u', $password ) ) {
			return self::bad( __( 'The password cannot start or end with a space.', 'beltoft-services' ) );
		}

		if ( 1 !== preg_match( '/\p{L}/u', $password ) || 1 !== preg_match( '/\p{Nd}/u', $password ) ) {
			return self::bad( __( 'The password needs at least one letter and one digit.', 'beltoft-services' ) );
		}

		$email = strtolower( trim( $email ) );
		$local = (string) strstr( $email, '@', true );
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $password, 'UTF-8' ) : strtolower( $password );

		if ( '' !== $email && false !== strpos( $lower, $email ) ) {
			return self::bad( __( 'The password cannot contain the mailbox address.', 'beltoft-services' ) );
		}

		if ( strlen( $local ) >= self::LOCAL_MIN && false !== strpos( $lower, $local ) ) {
			return self::bad( __( 'The password cannot contain the name before the @ of the mailbox.', 'beltoft-services' ) );
		}

		return null;
	}

	/**
	 * Reads the optional password of a request: null when none was sent (a generated one is used), a string otherwise.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error|null
	 */
	public static function from_request( \WP_REST_Request $request ) {
		$sent = $request->get_param( 'password' );

		if ( null === $sent ) {
			return null;
		}

		if ( ! is_string( $sent ) ) {
			return self::bad( __( 'The password must be text.', 'beltoft-services' ) );
		}

		$again = $request->get_param( 'password_again' );

		if ( null !== $again && ( ! is_string( $again ) || ! hash_equals( $sent, $again ) ) ) {
			return self::bad( __( 'The two passwords are not the same.', 'beltoft-services' ) );
		}

		return $sent;
	}

	/**
	 * @param string $text Sentence.
	 */
	private static function bad( string $text ): \WP_Error {
		return new \WP_Error( 'invalid', $text );
	}
}
