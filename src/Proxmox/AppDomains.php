<?php
/** The customer's domains that an app may point at its server. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\Zone;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Only zones that our own DNS serves for this customer are offered, and the A record is written
 * only into one of those zones: the ownership is checked again at the moment the record is made,
 * never taken from what the checkout form said. Existing records are never changed or removed.
 */
final class AppDomains {

	/** Seconds a record may be cached by resolvers; short, so a later change takes effect fast. */
	public const TTL = 300;

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
			if ( ! $r || ! in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) || ! $r->is_live() || 'ready' !== $r->get( 'state' ) ) {
				continue;
			}
			$name = strtolower( (string) $r->get( 'domain' ) );
			if ( '' === $name || $r->get( 'dns_external' ) || $r->get( 'records_off' ) || ( 'domain' === $r->get( 'driver' ) && 'custom' === $r->get( 'ns_mode' ) ) ) {
				continue;
			}
			if ( 'dns' === $r->get( 'driver' ) && ! Zone::served_for( $name, $user_id ) ) {
				continue;
			}
			$zones[ $name ] = $name;
		}
		$zones = array_values( $zones );
		sort( $zones );
		return $zones;
	}

	/** The part before the zone: '' for the domain itself, else lowercase labels, or null when it is not acceptable. */
	public static function sub( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$sub = strtolower( trim( $raw ) );
		if ( '' === $sub || '@' === $sub ) {
			return '';
		}
		if ( strlen( $sub ) > 120 || count( explode( '.', $sub ) ) > 3 ) {
			return null;
		}
		foreach ( explode( '.', $sub ) as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				return null;
			}
		}
		return $sub;
	}

	/** The full name an app is reached on. */
	public static function fqdn( string $zone, string $sub ): string {
		return '' === $sub ? $zone : $sub . '.' . $zone;
	}

	/**
	 * The address records already at a name: [ 'A' => [ip, ...], 'CNAME' => [...] ], or null when the zone cannot be read.
	 *
	 * @return array<string, array<int, string>>|null
	 */
	public static function existing( string $zone, string $fqdn ): ?array {
		$zone_data = DnsClients::dns()->zone( $zone );
		if ( ! is_array( $zone_data ) ) {
			return null;
		}
		$found = array();
		foreach ( Records::to_rows( $zone, (array) ( $zone_data['rrsets'] ?? array() ) ) as $row ) {
			if ( ! in_array( $row['type'], array( 'A', 'AAAA', 'CNAME' ), true ) || self::row_name( $zone, (string) $row['name'] ) !== $fqdn ) {
				continue;
			}
			$found[ $row['type'] ][] = (string) $row['value'];
		}
		return $found;
	}

	private static function row_name( string $zone, string $name ): string {
		$name = strtolower( rtrim( $name, '.' ) );
		if ( '@' === $name || '' === $name || $name === $zone ) {
			return $zone;
		}
		return str_ends_with( $name, '.' . $zone ) ? $name : $name . '.' . $zone;
	}

	/**
	 * Is the name free for a new A record? Nothing at all, or the record this server's address already has.
	 *
	 * @param string $ip The server address when it is known, '' at checkout.
	 */
	public static function is_free( string $zone, string $fqdn, string $ip = '' ): ?bool {
		$found = self::existing( $zone, $fqdn );
		if ( null === $found ) {
			return null;
		}
		if ( array() === $found ) {
			return true;
		}
		return '' !== $ip && array( 'A' => array( $ip ) ) === $found;
	}

	/**
	 * Adds the A record. Every record already in the zone is kept as it is; a name that has an address
	 * record already is refused; a zone that is not the customer's is refused.
	 *
	 * @return true|string True when the record is there, or the reason in plain words.
	 */
	public static function publish( int $user_id, string $zone, string $fqdn, string $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 'The server address is not known yet.';
		}
		if ( ! in_array( $zone, self::zones( $user_id ), true ) || ( $fqdn !== $zone && ! str_ends_with( $fqdn, '.' . $zone ) ) ) {
			return 'The domain is not one of your domains on our name servers.';
		}
		$zone_data = DnsClients::dns()->zone( $zone );
		if ( ! is_array( $zone_data ) ) {
			return 'The DNS zone could not be read.';
		}
		$rows = Records::to_rows( $zone, (array) ( $zone_data['rrsets'] ?? array() ) );
		$have = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row['type'], array( 'A', 'AAAA', 'CNAME' ), true ) && self::row_name( $zone, (string) $row['name'] ) === $fqdn ) {
				$have[ $row['type'] ][] = (string) $row['value'];
			}
		}
		if ( array( 'A' => array( $ip ) ) === $have ) {
			return true;
		}
		if ( array() !== $have ) {
			return 'The name already has a DNS record.';
		}
		$rows[] = array( 'name' => $fqdn, 'type' => 'A', 'ttl' => self::TTL, 'value' => $ip );
		$done   = Records::replace( $zone, $rows );
		return is_wp_error( $done ) ? 'The DNS record could not be saved.' : true;
	}
}
