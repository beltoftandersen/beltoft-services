<?php
/** The customer's domains that an app may point at its server. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Domains\HostName;
use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\Zone;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Domains\ZoneWriter;
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
			if ( ! HostName::label( $label ) ) {
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
			if ( ! in_array( $row['type'], array( 'A', 'AAAA', 'CNAME' ), true ) || Records::fqdn_of_set( $zone, (string) $row['name'] ) !== $fqdn ) {
				continue;
			}
			$found[ $row['type'] ][] = (string) $row['value'];
		}
		return $found;
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
	 * Would a name and address make a record the DNS layer accepts? Used at checkout so a customer is refused before paying.
	 *
	 * @return string|null The reason, or null when the record could be written.
	 */
	public static function dry_run( string $zone, string $fqdn ): ?string {
		$labels = explode( '.', $fqdn );
		if ( strlen( $fqdn ) > 253 || count( array_filter( $labels, static fn( $l ) => strlen( $l ) > 63 || '' === $l ) ) > 0 ) {
			return __( 'The domain name is too long.', 'beltoft-services' );
		}
		$set = Records::validate( $zone, array( array( 'name' => $fqdn, 'type' => 'A', 'ttl' => self::TTL, 'value' => '192.0.2.1' ) ) );
		return is_wp_error( $set ) ? __( 'The domain name is not valid for a DNS record.', 'beltoft-services' ) : null;
	}

	/**
	 * Adds the A record as one RRset of its own: nothing else in the zone is read back and rewritten. A name
	 * that has an address record already is refused; a zone that is not the customer's is refused.
	 *
	 * @param bool $strict A record of any type at the name refuses the write (the www name: a customer's own records there are never touched).
	 * @return true|string|\WP_Error True when the record is there, the reason in plain words when it can never work,
	 *                               or a WP_Error when the DNS layer failed and a later try may succeed.
	 */
	public static function publish( int $user_id, string $zone, string $fqdn, string $ip, ?bool &$wrote = null, bool $strict = false ) {
		$wrote = false;
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return __( 'The server address is not known yet.', 'beltoft-services' );
		}
		if ( $fqdn !== $zone && ! str_ends_with( $fqdn, '.' . $zone ) ) {
			return __( 'The domain is not one of your domains on our name servers.', 'beltoft-services' );
		}
		$rows   = array( array( 'name' => $fqdn, 'type' => 'A', 'ttl' => self::TTL, 'value' => $ip ) );
		$policy = static function ( array $zone_data, array $sets ) use ( $zone, $fqdn, $ip, $strict ) {
			$have = array();
			foreach ( Records::to_rows( $zone, (array) ( $zone_data['rrsets'] ?? array() ) ) as $row ) {
				if ( in_array( $row['type'], array( 'A', 'AAAA', 'CNAME' ), true ) && Records::fqdn_of_set( $zone, (string) $row['name'] ) === $fqdn ) {
					$have[ $row['type'] ][] = (string) $row['value'];
				}
			}
			$at = self::types_at( $zone, $fqdn, (array) ( $zone_data['rrsets'] ?? array() ) );
			if ( array( 'A' => array( $ip ) ) === $have && ( ! $strict || 1 === $at ) ) {
				return true;
			}
			if ( ( $strict && 0 !== $at ) || array() !== $have ) {
				return __( 'The name already has a DNS record.', 'beltoft-services' );
			}
			$sets[0]['changetype'] = 'REPLACE';
			return $sets;
		};
		// The zone lock covers the read and the write, so an editor save or a pause cannot slip in between.
		$result = ZoneWriter::write( $user_id, $zone, $rows, $policy, true );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'transient', __( 'The DNS zone is being changed.', 'beltoft-services' ) );
		}
		if ( 'refused' === $result['status'] ) {
			switch ( $result['reason'] ) {
				case 'not_ours':
					return __( 'The domain is not one of your domains on our name servers.', 'beltoft-services' );
				case 'invalid':
					return __( 'The domain name is not valid for a DNS record.', 'beltoft-services' );
				case 'no_zone':
					return __( 'The DNS zone does not exist.', 'beltoft-services' );
				case 'paused':
					return __( 'DNS hosting is paused for this domain.', 'beltoft-services' );
				default:
					return $result['reason'];
			}
		}
		$wrote = $result['wrote'];
		return true;
	}

	/** How many record sets of any type a name has in the raw rrsets of a zone. */
	private static function types_at( string $zone, string $fqdn, array $rrsets ): int {
		$n = 0;
		foreach ( $rrsets as $set ) {
			if ( is_array( $set ) && Records::fqdn_of_set( $zone, (string) ( $set['name'] ?? '' ) ) === $fqdn ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Check a domain choice for checkout or reinstall using the same customer-facing refusals.
	 *
	 * @param array<string, mixed> $app App definition or checkout offer entry.
	 * @param mixed                $sub Raw form input; non-strings are refused by sub().
	 * @return array{zone: string, fqdn: string}|string A usable name or the refusal text.
	 */
	public static function choose( array $app, string $zone, $sub, int $user, bool $check_dns, string $ip = '' ) {
		if ( empty( $app['domain'] ) ) {
			return __( 'This app does not use a domain at install. You can add one later.', 'beltoft-services' );
		}

		$zone = strtolower( trim( $zone ) );
		$sub  = self::sub( $sub );

		if ( '' === $zone || ! in_array( $zone, ZoneAccess::zones( $user ), true ) ) {
			return __( 'Choose one of your domains that uses our name servers.', 'beltoft-services' );
		}

		if ( null === $sub ) {
			return __( 'The subdomain can have letters (a to z), digits and hyphens, up to three parts separated by dots.', 'beltoft-services' );
		}

		$fqdn = self::fqdn( $zone, $sub );
		$bad  = self::dry_run( $zone, $fqdn );

		if ( null !== $bad ) {
			return str_contains( $bad, 'long' ) ? __( 'That domain name is too long. A name can have up to 253 characters and each part up to 63. Choose a shorter subdomain.', 'beltoft-services' ) : __( 'That domain name cannot be used for a DNS record. Choose another subdomain.', 'beltoft-services' );
		}

		if ( $check_dns ) {
			$free = self::is_free( $zone, $fqdn, $ip );

			if ( null === $free ) {
				return __( 'Your DNS records could not be checked right now. Try again, or leave the domain out and add it later.', 'beltoft-services' );
			}

			if ( ! $free ) {
				/* translators: %s: a domain name such as shop.example.com */
				return sprintf( __( '%s already has a DNS record. Choose another subdomain.', 'beltoft-services' ), $fqdn );
			}
		}

		return array( 'zone' => $zone, 'fqdn' => $fqdn );
	}
}
