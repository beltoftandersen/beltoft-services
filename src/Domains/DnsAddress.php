<?php
/** Name to checked public addresses for DNS proof queries. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class DnsAddress {
	/** Addresses that are never queried: private, loopback, link-local, documentation, transition and multicast ranges. */
	private const BLOCKED = array(
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
		'192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
		'::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
	);
	private const MAX_IPS = 8;

	/** @var array<string, string[]> */
	private static array $own = array();

	/** The canonical text of an address (so equal addresses compare equal), or null when it is not one. */
	public static function canonical( string $ip ): ?string {
		$bin = @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on text that is not an address; false is handled.
		return false === $bin ? null : inet_ntop( $bin );
	}

	public static function is_public( string $ip ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on text that is not an address; false is handled.
		if ( false === $bin ) { return false; }
		foreach ( self::BLOCKED as $cidr ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$net_bin = inet_pton( $net );
			if ( strlen( $net_bin ) !== strlen( $bin ) ) { continue; }
			$bits = (int) $bits;
			$full = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $full ) !== substr( $net_bin, 0, $full ) ) { continue; }
			$rest = $bits % 8;
			if ( 0 === $rest || ( ( ord( $bin[ $full ] ) ^ ord( $net_bin[ $full ] ) ) & ( 0xff << ( 8 - $rest ) ) & 0xff ) === 0 ) { return false; }
		}
		return true;
	}

	/** The public A and AAAA addresses of a host to query, canonical, at most 8. Anything private is dropped. */
	public static function public_ips( string $host ): array {
		return array_slice( self::all_public_ips( $host ), 0, self::MAX_IPS );
	}

	/** Every public address of a host, canonical and not capped: what a comparison against our own addresses must see. */
	public static function all_public_ips( string $host ): array {
		$host = strtolower( rtrim( trim( $host ), '.' ) );
		if ( '' === $host || strlen( $host ) > 253 || ! preg_match( '/^[a-z0-9_]([a-z0-9_-]{0,62})(\.[a-z0-9_]([a-z0-9_-]{0,62}))*$/D', $host ) ) { return array(); }
		/** Tests: a non-null array of IPs replaces the system lookup for this host. */
		$found = apply_filters( 'beltoft_services_dns_resolve', null, $host );
		if ( ! is_array( $found ) ) {
			$found = array();
			foreach ( array( DNS_A => 'ip', DNS_AAAA => 'ipv6' ) as $type => $field ) {
				$records = @dns_get_record( $host, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The resolver warns on a failed lookup; false is handled.
				foreach ( is_array( $records ) ? $records : array() as $record ) {
					if ( isset( $record[ $field ] ) ) { $found[] = (string) $record[ $field ]; }
				}
			}
		}
		$ips = array();
		foreach ( $found as $ip ) {
			$ip = is_string( $ip ) ? self::canonical( $ip ) : null;
			if ( null !== $ip && self::is_public( $ip ) ) { $ips[ $ip ] = $ip; }
		}
		return array_values( $ips );
	}

	/** The public IPs of our own name servers, plus any the site adds with a filter. */
	public static function our_ips(): array {
		$hosts = Zone::nameservers();
		$key   = implode( ',', $hosts );
		if ( ! isset( self::$own[ $key ] ) ) {
			$ips = array();
			foreach ( $hosts as $host ) { $ips = array_merge( $ips, self::all_public_ips( $host ) ); }
			self::$own[ $key ] = array_values( array_unique( $ips ) );
		}
		$extra = apply_filters( 'beltoft_services_dns_own_ips', array() );
		$more  = array();
		foreach ( is_array( $extra ) ? $extra : array() as $ip ) {
			$ip = is_string( $ip ) ? self::canonical( $ip ) : null;
			if ( null !== $ip && self::is_public( $ip ) ) { $more[] = $ip; }
		}
		return array_values( array_unique( array_merge( self::$own[ $key ], $more ) ) );
	}

	/** The name servers of a zone from the system resolver, at most 8. */
	public static function parent_servers( string $zone ): array {
		$zone = strtolower( rtrim( trim( $zone ), '.' ) );
		/** Tests: a non-null array of name server names replaces the system lookup for this zone. */
		$found = apply_filters( 'beltoft_services_dns_zone_ns', null, $zone );
		if ( ! is_array( $found ) ) {
			$found   = array();
			$records = '' === $zone ? false : @dns_get_record( $zone, DNS_NS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The resolver warns on a failed lookup; false is handled.
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( isset( $record['target'] ) ) { $found[] = (string) $record['target']; }
			}
		}
		$names = array();
		foreach ( $found as $name ) {
			$name = strtolower( rtrim( trim( (string) $name ), '.' ) );
			if ( '' !== $name ) { $names[ $name ] = $name; }
		}
		return array_slice( array_values( $names ), 0, 8 );
	}

	/** Forget the cached own addresses (tests). */
	public static function flush(): void { self::$own = array(); }
}
