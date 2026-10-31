<?php
/**
 * The aliases of one email domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * An alias is an address in the domain that delivers to one or more other addresses, in the domain or (when the setting
 * allows it) outside. There is no catch-all: the name before the @ is always a plain local part. Mailcow deletes an
 * alias by its numeric id, so the id is looked up by address first.
 */
final class Aliases {

	/** Destinations of one alias. */
	public const MAX_GOTO = 10;

	/**
	 * The aliases of the domain, leaving out the entries mailcow keeps for each mailbox (an address that goes to itself).
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{id:int,address:string,goto:string[]}>|\WP_Error
	 */
	public static function all( Resource $r ) {
		$rows = MailClients::for()->list_aliases( MailGuard::domain( $r ) );

		if ( is_wp_error( $rows ) ) {
			return MailGuard::failed( $rows, __( 'read the aliases', 'beltoft-services' ) );
		}

		$out = array();

		foreach ( $rows as $row ) {
			$address = strtolower( (string) ( $row['address'] ?? '' ) );
			$goto    = array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $row['goto'] ?? '' ) ) ) ) ) );

			if ( '' === $address || array( $address ) === $goto || 0 === strpos( $address, '@' ) || 0 === strpos( $address, '*' ) ) {
				continue;
			}

			$out[] = array( 'id' => (int) ( $row['id'] ?? 0 ), 'address' => $address, 'goto' => $goto );
		}

		return $out;
	}

	/**
	 * The destinations as a clean list: valid addresses, lower case, no duplicates.
	 *
	 * @param mixed $raw A list, or text separated by commas, spaces or lines.
	 * @return string[]|null Null when any is not an address or none is given.
	 */
	public static function destinations( $raw ): ?array {
		$parts = is_array( $raw ) ? $raw : preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();

		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( strtolower( trim( (string) $part ) ) );

			if ( ! is_email( $email ) ) {
				return null;
			}

			$out[ $email ] = $email;
		}

		return array() === $out ? null : array_values( $out );
	}

	/**
	 * Creates an alias.
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $local Local part (never empty, never a wildcard).
	 * @param mixed    $goto  Destinations.
	 * @return array{address:string,goto:string[]}|\WP_Error
	 */
	public static function create( Resource $r, $local, $goto ) {
		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return $bad;
		}

		$address = MailGuard::address( $r, $local );

		if ( null === $address ) {
			return new \WP_Error( 'invalid', __( 'Use letters, digits, dots, hyphens and underscores for the name before the @. A catch-all address is not offered.', 'beltoft-services' ) );
		}

		$targets = self::destinations( $goto );

		if ( null === $targets || count( $targets ) > self::MAX_GOTO ) {
			return new \WP_Error( 'invalid', __( 'Enter between one and ten valid email addresses to deliver to.', 'beltoft-services' ) );
		}

		if ( in_array( $address, $targets, true ) ) {
			return new \WP_Error( 'invalid', __( 'An alias cannot deliver to itself.', 'beltoft-services' ) );
		}

		if ( ! (bool) \BeltoftServices\Settings::get( 'mail_allow_external_forward' ) ) {
			foreach ( $targets as $target ) {
				if ( substr( (string) strrchr( $target, '@' ), 1 ) !== MailGuard::domain( $r ) ) {
					return new \WP_Error( 'external', __( 'An alias can only deliver to addresses in this domain.', 'beltoft-services' ) );
				}
			}
		}

		$have = self::all( $r );

		if ( is_wp_error( $have ) ) {
			return $have;
		}

		if ( count( $have ) >= MailGuard::limits( $r )['aliases'] ) {
			return new \WP_Error( 'limit', __( 'Your plan has no room for more aliases.', 'beltoft-services' ) );
		}

		$added = MailClients::for()->add_alias( $address, implode( ',', $targets ) );

		if ( is_wp_error( $added ) ) {
			return MailGuard::failed( $added, __( 'create the alias', 'beltoft-services' ) );
		}

		return array( 'address' => $address, 'goto' => $targets );
	}

	/**
	 * Deletes an alias of this domain. An alias that is gone already is not an error.
	 *
	 * @param Resource $r       Resource.
	 * @param mixed    $address Alias address.
	 * @return true|\WP_Error
	 */
	public static function delete( Resource $r, $address ) {
		$bad = MailGuard::check( $r );

		if ( $bad ) {
			return $bad;
		}

		$address = MailGuard::inside( $r, $address );

		if ( null === $address ) {
			return new \WP_Error( 'invalid', __( 'That alias is not part of this domain.', 'beltoft-services' ) );
		}

		$all = self::all( $r );

		if ( is_wp_error( $all ) ) {
			return $all;
		}

		foreach ( $all as $alias ) {
			if ( $alias['address'] === $address ) {
				$gone = $alias['id'] > 0 ? MailClients::for()->delete_alias( $alias['id'] ) : null;

				return is_wp_error( $gone ) ? MailGuard::failed( $gone, __( 'delete the alias', 'beltoft-services' ) ) : true;
			}
		}

		return true;
	}
}
