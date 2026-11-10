<?php
/**
 * Which DNS zones a customer may edit (shared by the DNS record routes, the mail checkout and the app domain list).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The one rule behind "this zone is the customer's and our DNS serves it".
 */
final class ZoneAccess {

	/**
	 * The zones the customer holds with our DNS and may edit: registered domains on our name servers and
	 * DNS-only services that are verified. A paused or externally hosted zone is left out.
	 *
	 * @return array<int, string> Zone names, sorted.
	 */
	public static function zones( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( \BeltoftLicenses\Licenses::class ) ) {
			return array();
		}
		$zones = array();
		foreach ( \BeltoftLicenses\Licenses::where( 'user_id', $user_id ) as $license ) {
			$r = Resource::for_license( (int) $license['id'] );
			if ( $r && self::editable( $r, $user_id ) ) {
				$name           = strtolower( (string) $r->get( 'domain' ) );
				$zones[ $name ] = $name;
			}
		}
		$zones = array_values( $zones );
		sort( $zones );
		return $zones;
	}

	/**
	 * The zone of a name among the customer's editable zones: the longest one that equals the name or is a parent of it.
	 *
	 * @return string|null Zone name, or null when the name is in none.
	 */
	public static function zone_of( int $user_id, string $host ): ?string {
		$best = null;
		foreach ( self::zones( $user_id ) as $zone ) {
			if ( ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) && ( null === $best || strlen( $zone ) > strlen( $best ) ) ) {
				$best = $zone;
			}
		}
		return $best;
	}

	/**
	 * May this customer edit the records of the resource's zone? True for a live, ready domain or DNS service whose
	 * zone our own DNS serves for them: not paused, not hosted elsewhere, not on custom name servers, and a zone
	 * that is theirs. The one rule behind the app domain list and the DNS record routes.
	 */
	public static function editable( Resource $r, int $user_id ): bool {
		if ( $user_id <= 0 || ! in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) || ! $r->is_live() || 'ready' !== $r->get( 'state' ) || $r->folded_into() > 0 ) {
			return false;
		}
		if ( (int) ( $r->license()['user_id'] ?? 0 ) !== $user_id ) {
			return false;
		}
		$name = strtolower( (string) $r->get( 'domain' ) );
		if ( '' === $name || $r->get( 'dns_external' ) || $r->get( 'records_off' ) || ( 'domain' === $r->get( 'driver' ) && 'custom' === $r->get( 'ns_mode' ) ) ) {
			return false;
		}
		if ( 'dns' === $r->get( 'driver' ) && ! Zone::served_for( $name, $user_id ) ) {
			return false;
		}
		// A registered domain needs the same proof: not another customer's name, and a zone that is ours for this customer.
		if ( 'domain' === $r->get( 'driver' ) && ( 'other' === Zone::owner( $r ) || ! ( $r->get( 'zone_ready' ) || $r->get( 'zone_claimed' ) || Zone::served_for( $name, $user_id ) ) ) ) {
			return false;
		}
		return true;
	}
}
