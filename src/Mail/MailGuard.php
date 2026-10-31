<?php
/**
 * The checks every mailbox and alias operation starts with.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * An operation runs only on a live, ready mail domain, only on names inside that domain, and only with names that are
 * plain local parts. Errors are WP_Error with a code a screen can map and a message for the customer.
 */
final class MailGuard {

	/**
	 * Null when the resource may be operated on, else the reason.
	 *
	 * @param Resource $r Resource.
	 */
	public static function check( Resource $r ): ?\WP_Error {
		if ( MailDriver::ID !== $r->get( 'driver' ) || ! $r->is_live() || '' === (string) $r->get( MailDomain::META_DOMAIN ) ) {
			return new \WP_Error( 'not_mail', __( 'This is not an email service.', 'beltoft-services' ) );
		}

		if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) ) {
			return new \WP_Error( 'not_ready', __( 'This email service is not ready.', 'beltoft-services' ) );
		}

		return null;
	}

	/**
	 * The domain of the resource.
	 *
	 * @param Resource $r Resource.
	 */
	public static function domain( Resource $r ): string {
		return (string) $r->get( MailDomain::META_DOMAIN );
	}

	/**
	 * A local part: lower case letters, digits and . _ - , starting and ending with a letter or digit, no two dots in a row.
	 *
	 * @param mixed $raw Typed value.
	 * @return string|null Normalised, or null.
	 */
	public static function local( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$local = strtolower( trim( $raw ) );

		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/D', $local ) && false === strpos( $local, '..' ) ? $local : null;
	}

	/**
	 * The full address of a local part, or null when it is not valid.
	 *
	 * @param Resource $r   Resource.
	 * @param mixed    $raw Local part.
	 */
	public static function address( Resource $r, $raw ): ?string {
		$local = self::local( $raw );

		return null === $local ? null : $local . '@' . self::domain( $r );
	}

	/**
	 * Is the address inside the resource's domain, and a valid address?
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $email Address.
	 * @return string|null Normalised, or null.
	 */
	public static function inside( Resource $r, $email ): ?string {
		if ( ! is_string( $email ) || 1 !== substr_count( $email, '@' ) ) {
			return null;
		}

		list( $local, $domain ) = explode( '@', strtolower( trim( $email ) ) );

		return $domain === self::domain( $r ) && null !== self::local( $local ) ? $local . '@' . $domain : null;
	}

	/**
	 * The limits the mail server enforces for this domain: what was applied, else the plan.
	 *
	 * @param Resource $r Resource.
	 * @return array{mailboxes:int,quota_mb:int,aliases:int,domains:int}
	 */
	public static function limits( Resource $r ): array {
		$applied = $r->get( 'mail_limits' );

		return MailDriver::limits_from( is_array( $applied ) && array() !== $applied ? $applied : (array) $r->get( 'plan' ) );
	}

	/**
	 * A failed client call as an error for a screen, without the mailcow detail.
	 *
	 * @param \WP_Error $e    The client error.
	 * @param string    $what What was being done, for the message.
	 */
	public static function failed( \WP_Error $e, string $what ): \WP_Error {
		$key = (string) ( ( (array) $e->get_error_data() )['key'] ?? '' );

		if ( 'mailcow_exists' === $e->get_error_code() ) {
			return new \WP_Error( 'exists', __( 'That address is already in use.', 'beltoft-services' ) );
		}

		if ( in_array( $key, array( 'max_mailbox_exceeded', 'max_alias_exceeded' ), true ) ) {
			return new \WP_Error( 'limit', __( 'Your plan has no room for more.', 'beltoft-services' ) );
		}

		if ( 'mailcow_transport' === $e->get_error_code() || 'mailcow_http' === $e->get_error_code() ) {
			return new \WP_Error( 'unavailable', __( 'The mail server could not be reached. Try again in a moment.', 'beltoft-services' ) );
		}

		/* translators: %s: what was being done, such as "create the mailbox" */
		return new \WP_Error( 'failed', sprintf( __( 'We could not %s.', 'beltoft-services' ), $what ) );
	}
}
