<?php
/** Direct authoritative UDP queries for DNS health. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DnsQuery {
	/** The primary can have a private route when its public IP does not hairpin locally. */
	public static function address( string $server ): string {
		$nameservers = Zone::nameservers();
		$local = Settings::get( 'domain_dns_primary_query_address' );
		if ( $nameservers && strtolower( rtrim( $server, '.' ) ) === $nameservers[0] && is_string( $local ) && filter_var( $local, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { return $local; }
		return $server;
	}

	public static function zone_serial( array $zone ): ?int {
		if ( isset( $zone['serial'] ) && is_numeric( $zone['serial'] ) ) { return (int) $zone['serial']; }
		foreach ( (array) ( $zone['rrsets'] ?? array() ) as $set ) {
			if ( 'SOA' !== ( $set['type'] ?? '' ) ) { continue; }
			$parts = preg_split( '/\s+/', trim( (string) ( $set['records'][0]['content'] ?? '' ) ) );
			if ( isset( $parts[2] ) && ctype_digit( $parts[2] ) ) { return (int) $parts[2]; }
		}
		return null;
	}
	/** Read a SOA serial (type 6), the earliest apex RRSIG expiration (46) or the number of apex DNSKEY records (48) from an authoritative server. */
	public static function read( string $server, string $domain, int $type ): int|\WP_Error {
		$test = apply_filters( 'beltoft_services_dns_query', null, $server, $domain, $type );
		if ( null !== $test ) { return is_int( $test ) || is_wp_error( $test ) ? $test : new \WP_Error( 'bad_query', 'Invalid DNS query result.' ); }
		$client = Clients::dns();
		if ( $client instanceof FakeDns ) {
			$zone = $client->zone( $domain );
			if ( ! is_array( $zone ) ) { return new \WP_Error( 'dns_missing', 'Fake zone is missing.' ); }
			if ( 48 === $type ) { return ! empty( $zone['secured'] ) ? 1 : new \WP_Error( 'dns_missing', 'Fake zone has no DNSKEY.' ); }
			return 6 === $type ? (int) ( $zone['serial'] ?? 1 ) : time() + 30 * DAY_IN_SECONDS;
		}
		return self::read_wire( self::address( $server ), $domain, $type );
	}

	/**
	 * The question to one of our own name servers over the strict wire layer, and the value in its answer. The server is named in
	 * our settings (or is its private address), so the public address check is skipped (option trusted); nothing that takes a
	 * customer's name passes that option. The signature check (46) asks for the zone's SOA with signatures requested (DO) because a
	 * name server refuses a direct RRSIG question; a key check (48) asks with a larger UDP size so the key set fits.
	 *
	 * @param string $host   Address or name of the server.
	 * @param string $domain Zone.
	 * @param int    $type   6, 46 or 48.
	 * @param int    $port   Port (tests use a high one).
	 */
	public static function read_wire( string $host, string $domain, int $type, int $port = 53 ): int|\WP_Error {
		$options = array( 'trusted' => true, 'port' => $port ) + ( 46 === $type ? array( 'do' => true, 'size' => 4096 ) : array() ) + ( 48 === $type ? array( 'size' => 4096 ) : array() );
		$answer  = DnsTransport::ask( $host, $domain, 46 === $type ? DnsWire::SOA : $type, $options );
		if ( is_wp_error( $answer ) ) {
			return in_array( $answer->get_error_code(), array( 'dns_malformed', 'dns_mismatch', 'dns_bad_name' ), true ) ? new \WP_Error( 'dns_response', 'Name server did not return an authoritative answer.' ) : new \WP_Error( 'dns_transport', 'Name server did not answer.' );
		}
		return self::value_of( $answer, $type, $domain );
	}

	/**
	 * The value in a parsed authoritative answer: the SOA serial (6), the earliest RRSIG expiry (46) or the number of DNSKEY
	 * records (48). Records of another owner name are refused (dns_response, not dns_missing: Zone::remove reads dns_missing as
	 * "the zone is gone"). Pure, so a canned response tests it.
	 *
	 * @param array $response A DnsWire::parse() result.
	 */
	public static function value_of( array $response, int $type, string $domain ): int|\WP_Error {
		$domain = strtolower( rtrim( $domain, '.' ) );
		if ( ! $response['aa'] && in_array( $response['rcode'], array( 3, 5 ), true ) ) { return new \WP_Error( 'dns_not_authoritative', 'Name server does not serve this zone.' ); }
		if ( ! $response['aa'] || $response['tc'] || 0 !== $response['rcode'] ) { return new \WP_Error( 'dns_response', 'Name server did not return an authoritative answer.' ); }
		$values = array();
		foreach ( $response['answer'] as $rr ) {
			if ( $rr['type'] !== $type ) { continue; }
			if ( $rr['name'] !== $domain ) { return new \WP_Error( 'dns_response', 'The answer is for another name.' ); }
			if ( 6 === $type ) { $values[] = (int) $rr['data']['serial']; } elseif ( 48 === $type ) { $values[] = 1; } else { $values[] = (int) $rr['data']['expiration']; }
		}
		return $values ? ( 48 === $type ? count( $values ) : min( $values ) ) : new \WP_Error( 'dns_missing', 'Name server did not return the requested record.' );
	}

	/**
	 * The addresses a public name resolves to, as any visitor would see them (not an authoritative read of our own
	 * zones): the A records (type 'A') or AAAA records (type 'AAAA') of a name, following an alias. An empty list means
	 * the name has none yet. A resolver that cannot be reached is an error, so a caller does not take it for "no record".
	 * Tests answer through the filter beltoft_services_dns_resolve; nothing in a test run reaches the network.
	 *
	 * @param string $name Host name.
	 * @param string $type A or AAAA.
	 * @return array<int, string>|\WP_Error
	 */
	public static function addresses( string $name, string $type = 'A' ) {
		$type = 'AAAA' === $type ? 'AAAA' : 'A';
		$test = apply_filters( 'beltoft_services_dns_resolve', null, $name, $type );
		if ( null !== $test ) {
			if ( is_wp_error( $test ) ) { return $test; }
			return is_array( $test ) ? array_values( array_map( 'strval', $test ) ) : new \WP_Error( 'bad_query', 'Invalid DNS query result.' );
		}
		// The system resolver reports "no such name" and "cannot reach a resolver" the same way; both mean not yet.
		$values = PublicDns::resolve( $name, $type );
		return is_wp_error( $values ) ? $values : array_values( array_unique( $values ) );
	}

	/**
	 * The TXT strings of a name through the system resolver. Tests answer through the filter
	 * beltoft_services_dns_resolve with type TXT; nothing in a test run reaches the network.
	 *
	 * @param string $name Host name.
	 * @return array<int, string>|\WP_Error
	 */
	public static function texts( string $name ) {
		$test = apply_filters( 'beltoft_services_dns_resolve', null, $name, 'TXT' );
		if ( null !== $test ) {
			if ( is_wp_error( $test ) ) { return $test; }
			return is_array( $test ) ? array_values( array_map( 'strval', $test ) ) : new \WP_Error( 'bad_query', 'Invalid DNS query result.' );
		}
		$values = PublicDns::resolve( $name, 'TXT' );
		return is_wp_error( $values ) ? $values : array_values( array_unique( $values ) );
	}
}
