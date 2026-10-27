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
	/** Read a SOA serial or the earliest apex RRSIG expiration from an authoritative server. */
	public static function read( string $server, string $domain, int $type ): int|\WP_Error {
		$test = apply_filters( 'beltoft_services_dns_query', null, $server, $domain, $type );
		if ( null !== $test ) { return is_int( $test ) || is_wp_error( $test ) ? $test : new \WP_Error( 'bad_query', 'Invalid DNS query result.' ); }
		$client = Clients::dns();
		if ( $client instanceof FakeDns ) {
			$zone = $client->zone( $domain );
			if ( ! is_array( $zone ) ) { return new \WP_Error( 'dns_missing', 'Fake zone is missing.' ); }
			return 6 === $type ? (int) ( $zone['serial'] ?? 1 ) : time() + 30 * DAY_IN_SECONDS;
		}
		$id = random_int( 1, 65535 );
		$wire = self::packet( $id, $domain, $type );
		$socket = @fsockopen( 'udp://' . self::address( $server ), 53, $errno, $message, 3 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- UDP DNS query, not file I/O; transport error returned below.
		if ( ! $socket ) { return new \WP_Error( 'dns_transport', 'Name server did not answer.' ); }
		stream_set_timeout( $socket, 3 );
		fwrite( $socket, $wire ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UDP DNS socket, not file I/O.
		$data = fread( $socket, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- UDP DNS socket, not file I/O.
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- UDP DNS socket, not file I/O.
		if ( ! is_string( $data ) || strlen( $data ) < 12 ) { return new \WP_Error( 'dns_transport', 'Name server did not answer.' ); }
		$head = unpack( 'nid/nflags/nquestions/nanswers/nauthority/nadditional', substr( $data, 0, 12 ) );
		if ( (int) $head['id'] === $id && ( $head['flags'] & 0x8000 ) !== 0 && ( $head['flags'] & 0x0400 ) === 0 && in_array( $head['flags'] & 15, array( 3, 5 ), true ) ) { return new \WP_Error( 'dns_not_authoritative', 'Name server does not serve this zone.' ); }
		if ( (int) $head['id'] !== $id || ( $head['flags'] & 0x8000 ) === 0 || ( $head['flags'] & 0x0400 ) === 0 || ( $head['flags'] & 0x0200 ) !== 0 || ( $head['flags'] & 15 ) !== 0 ) { return new \WP_Error( 'dns_response', 'Name server did not return an authoritative answer.' ); }
		$offset = 12;
		for ( $q = 0; $q < $head['questions']; ++$q ) { if ( ! self::skip_name( $data, $offset ) || $offset + 4 > strlen( $data ) ) { return new \WP_Error( 'dns_response', 'Invalid DNS question.' ); } $offset += 4; }
		$values = array();
		for ( $a = 0; $a < $head['answers']; ++$a ) {
			if ( ! self::skip_name( $data, $offset ) || $offset + 10 > strlen( $data ) ) { return new \WP_Error( 'dns_response', 'Invalid DNS answer.' ); }
			$fields = unpack( 'ntype/nclass/Nttl/nlength', substr( $data, $offset, 10 ) );
			$offset += 10;
			$end = $offset + $fields['length'];
			if ( $end > strlen( $data ) ) { return new \WP_Error( 'dns_response', 'Invalid DNS answer.' ); }
			if ( $fields['type'] === $type && 1 === $fields['class'] ) {
				if ( 6 === $type ) {
					$pos = $offset;
					if ( self::skip_name( $data, $pos ) && self::skip_name( $data, $pos ) && $pos + 4 <= $end ) { $values[] = unpack( 'Nserial', substr( $data, $pos, 4 ) )['serial']; }
				} elseif ( 46 === $type && $offset + 12 <= $end ) { $values[] = unpack( 'Nexpiry', substr( $data, $offset + 8, 4 ) )['expiry']; }
			}
			$offset = $end;
		}
		return $values ? min( $values ) : new \WP_Error( 'dns_missing', 'Name server did not return the requested record.' );
	}

	/**
	 * The question sent to a name server. A name server refuses a direct question for the record
	 * type RRSIG, so the signature check (type 46) asks for the zone's SOA with signatures
	 * requested (EDNS0, DO bit) and reads the RRSIG that comes back with it.
	 */
	public static function packet( int $id, string $domain, int $type ): string {
		$signed = 46 === $type;
		$wire   = pack( 'nnnnnn', $id, 0x0100, 1, 0, 0, $signed ? 1 : 0 );
		foreach ( explode( '.', rtrim( $domain, '.' ) ) as $label ) { $wire .= chr( strlen( $label ) ) . $label; }
		$wire .= "\0" . pack( 'nn', $signed ? 6 : $type, 1 );
		if ( $signed ) { $wire .= "\0" . pack( 'nnNn', 41, 4096, 0x8000, 0 ); }
		return $wire;
	}

	/** Advance over wire-format labels, including a compressed pointer. */
	private static function skip_name( string $data, int &$offset ): bool {
		$length = strlen( $data );
		for ( $part = 0; $part < 128; ++$part ) {
			if ( $offset >= $length ) { return false; }
			$size = ord( $data[ $offset++ ] );
			if ( 0 === $size ) { return true; }
			if ( 0xc0 === ( $size & 0xc0 ) ) { if ( $offset >= $length ) { return false; } ++$offset; return true; }
			if ( $size > 63 || $offset + $size > $length ) { return false; }
			$offset += $size;
		}
		return false;
	}
}
