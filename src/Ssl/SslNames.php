<?php
/** Names on a certificate: validation, wildcard form and the customer's zones. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class SslNames {
	/** The mailbox names a CA may send an approval mail to (set by the CA/B Forum rules). */
	public const APPROVERS = array( 'admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster' );

	/** Orders one customer may place in a day. */
	public const ORDERS_PER_DAY = 5;

	/**
	 * A name typed for a certificate, without a wildcard prefix: lowercase host name, or null.
	 *
	 * @param mixed $raw Typed value.
	 */
	public static function base( $raw ): ?string {
		$raw = is_string( $raw ) ? trim( $raw ) : '';

		if ( 0 === strpos( $raw, '*.' ) ) {
			$raw = substr( $raw, 2 );
		}

		return HostName::normalise( $raw );
	}

	/**
	 * Did the customer type a wildcard name?
	 *
	 * @param mixed $raw Typed value.
	 */
	public static function typed_wildcard( $raw ): bool {
		return is_string( $raw ) && 0 === strpos( trim( $raw ), '*.' );
	}

	/**
	 * The common name that is ordered.
	 *
	 * @param string $base     Host name.
	 * @param bool   $wildcard Wildcard product.
	 */
	public static function common_name( string $base, bool $wildcard ): string {
		return $wildcard ? '*.' . $base : $base;
	}

	/**
	 * The customer's zone on our name servers that holds this name (the name itself or a parent), or null.
	 *
	 * @param string $name    Host name without a wildcard prefix.
	 * @param int    $user_id Customer.
	 */
	public static function zone_for( string $name, int $user_id ): ?string {
		$best = null;

		foreach ( ZoneAccess::zones( $user_id ) as $zone ) {
			if ( ( $name === $zone || str_ends_with( $name, '.' . $zone ) ) && ( null === $best || strlen( $zone ) > strlen( $best ) ) ) {
				$best = $zone;
			}
		}

		return $best;
	}

	/**
	 * The approval address for a prefix, or null when it is not one a CA accepts.
	 *
	 * @param string $prefix Mailbox name.
	 * @param string $base   Host name.
	 */
	public static function approver( string $prefix, string $base ): ?string {
		return in_array( $prefix, self::APPROVERS, true ) ? $prefix . '@' . $base : null;
	}

	/**
	 * Why this customer cannot order a certificate for this name now (a live one exists, or too many were ordered today), or null.
	 *
	 * @param int    $user_id Customer.
	 * @param string $name    Common name that would be ordered.
	 * @param int    $ignore  Resource to leave out of the check.
	 */
	public static function refusal( int $user_id, string $name, int $ignore = 0 ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}

		$recent = 0;

		foreach ( Licenses::where( 'user_id', $user_id ) as $license ) {
			$r = Resource::for_license( (int) $license['id'] );

			if ( ! $r || SslDriver::ID !== $r->get( 'driver' ) || $r->id() === $ignore ) {
				continue;
			}

			if ( $r->is_live() && strtolower( (string) $r->get( 'ssl_name' ) ) === strtolower( $name ) ) {
				/* translators: %s: name. */
				return sprintf( __( 'You already have a certificate for %s. Reissue it from your account instead of ordering another.', 'beltoft-services' ), $name );
			}

			if ( strtotime( (string) ( $license['created_at'] ?? '' ) . ' UTC' ) > time() - DAY_IN_SECONDS ) {
				++$recent;
			}
		}

		return $recent >= self::ORDERS_PER_DAY ? __( 'Too many certificates were ordered today. Please try again tomorrow.', 'beltoft-services' ) : null;
	}
}
