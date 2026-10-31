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
			if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label ) ) {
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
	 * Would a name and address make a record the DNS layer accepts? Used at checkout so a customer is refused before paying.
	 *
	 * @return string|null The reason, or null when the record could be written.
	 */
	public static function dry_run( string $zone, string $fqdn ): ?string {
		$labels = explode( '.', $fqdn );
		if ( strlen( $fqdn ) > 253 || count( array_filter( $labels, static fn( $l ) => strlen( $l ) > 63 || '' === $l ) ) > 0 ) {
			return 'The domain name is too long.';
		}
		$set = Records::validate( $zone, array( array( 'name' => $fqdn, 'type' => 'A', 'ttl' => self::TTL, 'value' => '192.0.2.1' ) ) );
		return is_wp_error( $set ) ? 'The domain name is not valid for a DNS record.' : null;
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
			return 'The server address is not known yet.';
		}
		if ( ! in_array( $zone, self::zones( $user_id ), true ) || ( $fqdn !== $zone && ! str_ends_with( $fqdn, '.' . $zone ) ) ) {
			return 'The domain is not one of your domains on our name servers.';
		}
		$set = Records::validate( $zone, array( array( 'name' => $fqdn, 'type' => 'A', 'ttl' => self::TTL, 'value' => $ip ) ) );
		if ( is_wp_error( $set ) || 1 !== count( $set ) ) {
			return 'The domain name is not valid for a DNS record.';
		}
		// The zone lock covers the read and the write, so an editor save or a pause cannot slip in between.
		$locked = Records::locked(
			$zone,
			static function () use ( $zone, $fqdn, $ip, $set, &$wrote, $strict ) {
				// Read immediately before the write: what is at the name now decides.
				$zone_data = DnsClients::dns()->zone( $zone );
				if ( is_wp_error( $zone_data ) ) {
					return new \WP_Error( 'transient', 'The DNS zone could not be read.' );
				}
				if ( ! is_array( $zone_data ) ) {
					return 'The DNS zone does not exist.';
				}
				$have = array();
				foreach ( Records::to_rows( $zone, (array) ( $zone_data['rrsets'] ?? array() ) ) as $row ) {
					if ( in_array( $row['type'], array( 'A', 'AAAA', 'CNAME' ), true ) && self::row_name( $zone, (string) $row['name'] ) === $fqdn ) {
						$have[ $row['type'] ][] = (string) $row['value'];
					}
				}
				if ( Records::paused( $zone, $zone_data ) ) {
					return 'DNS hosting is paused for this domain.';
				}
				$sets = self::types_at( $zone, $fqdn, (array) ( $zone_data['rrsets'] ?? array() ) );
				if ( array( 'A' => array( $ip ) ) === $have && ( ! $strict || 1 === $sets ) ) {
					return true;
				}
				if ( $strict && 0 !== $sets ) {
					return 'The name already has a DNS record.';
				}
				if ( array() !== $have ) {
					return 'The name already has a DNS record.';
				}
				$set[0]['changetype'] = 'REPLACE';
				$done = DnsClients::dns()->replace_rrsets( $zone, $set );
				if ( ! is_wp_error( $done ) ) {
					$wrote = true;
				}
				return is_wp_error( $done ) ? new \WP_Error( 'transient', 'The DNS record could not be saved.' ) : true;
			}
		);
		return is_wp_error( $locked ) ? new \WP_Error( 'transient', 'The DNS zone is being changed.' ) : $locked;
	}

	/** How many record sets of any type a name has in the raw rrsets of a zone. */
	private static function types_at( string $zone, string $fqdn, array $rrsets ): int {
		$n = 0;
		foreach ( $rrsets as $set ) {
			if ( is_array( $set ) && self::row_name( $zone, (string) ( $set['name'] ?? '' ) ) === $fqdn ) {
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

		if ( '' === $zone || ! in_array( $zone, self::zones( $user ), true ) ) {
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
