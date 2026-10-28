<?php
/**
 * Proof of control for an externally registered domain: the real delegation from the parent zone, a TXT
 * record checked on every delegated server, the parent DS, and a scan of common records for import.
 *
 * The parent zone's referral is the only authority on which servers the Internet uses. Name server names
 * are customer controlled, so every address is checked before use and the checked address is the one
 * the socket connects to. If any delegated server is ours, the domain goes to staff.
 *
 * @package BeltoftServices
 */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class ProofResolver {
	public const MAX_NS           = 10;
	public const BUDGET           = 30;
	public const MAX_SCAN_QUERIES = 60;
	public const TXT_LABEL        = '_beltoft-verify';

	private const HOSTS    = array( 'www', 'mail', 'smtp', 'imap', 'pop', 'webmail', 'ftp', 'autodiscover', 'autoconfig' );
	private const TXT_OWNERS = array( '_dmarc', 'default._domainkey', 'google._domainkey', 'selector1._domainkey', 'selector2._domainkey', 'k1._domainkey' );
	private const SRV_OWNERS = array( '_sip._tls', '_sipfederationtls._tcp', '_autodiscover._tcp' );

	/**
	 * The delegation of $domain as its parent zone publishes it.
	 *
	 * @return array{status: string, reason: string, ns: string[], ips: array<string, string[]>, ds: ?bool, ours: bool}
	 */
	public static function delegation( string $domain ): array {
		$deadline = self::deadline();
		$ascii    = Search::normalise( $domain );
		if ( is_wp_error( $ascii ) || ! PublicSuffix::is_registrable_apex( $ascii ) ) { return self::delegation_result( 'support', 'unsupported_name' ); }
		$servers = self::parent_servers( $ascii );
		if ( ! $servers ) { return self::delegation_result( 'unknown', 'no_parent_server' ); }

		// Ask up to two parent servers; their answers must agree.
		$outcomes = array();
		$glue     = array();
		$asked    = array();
		foreach ( array_slice( $servers, 0, 2 ) as $ip ) {
			$response = self::ask( $ip, $ascii, DnsWire::NS, $deadline );
			if ( is_wp_error( $response ) ) {
				if ( 'budget' === $response->get_error_code() ) { return self::delegation_result( 'unknown', 'budget' ); }
				continue;
			}
			$asked[] = $ip;
			if ( 3 === $response['rcode'] ) { $outcomes[] = 'nxdomain'; continue; }
			if ( 0 !== $response['rcode'] ) { continue; }
			$section = $response['aa'] ? $response['answer'] : $response['authority'];
			$names   = array();
			foreach ( $section as $rr ) {
				if ( DnsWire::NS === $rr['type'] && $rr['name'] === $ascii && is_string( $rr['data'] ) && '' !== $rr['data'] ) { $names[ $rr['data'] ] = $rr['data']; }
			}
			if ( ! $names ) { $outcomes[] = 'nxdomain'; continue; }
			ksort( $names );
			$outcomes[] = implode( ',', $names );
			foreach ( $response['additional'] as $rr ) {
				if ( in_array( $rr['type'], array( DnsWire::A, DnsWire::AAAA ), true ) && is_string( $rr['data'] ) ) { $glue[ $rr['name'] ][ $rr['data'] ] = $rr['data']; }
			}
		}
		if ( ! $outcomes ) { return self::delegation_result( 'unknown', 'parent_unreachable' ); }
		if ( count( array_unique( $outcomes ) ) > 1 ) { return self::delegation_result( 'unknown', 'parent_disagrees' ); }
		if ( 'nxdomain' === $outcomes[0] ) { return self::delegation_result( 'support', 'not_delegated' ); }
		$names = explode( ',', $outcomes[0] );
		if ( count( $names ) > self::MAX_NS ) { return self::delegation_result( 'support', 'too_many_ns' ); }

		$ips = array();
		foreach ( $names as $name ) {
			if ( self::expired( $deadline ) ) { return self::delegation_result( 'unknown', 'budget' ); }
			$inside = $name === $ascii || str_ends_with( $name, '.' . $ascii );
			if ( $inside ) {
				$ips[ $name ] = array_values( array_filter( array_values( $glue[ $name ] ?? array() ), array( DnsAddress::class, 'is_public' ) ) );
			} else {
				$ips[ $name ] = DnsAddress::public_ips( $name );
			}
		}

		$ours = array_intersect( $names, Zone::nameservers() ) ? true : false;
		if ( ! $ours ) {
			$own = DnsAddress::our_ips();
			foreach ( $ips as $list ) {
				if ( array_intersect( $list, $own ) ) { $ours = true; break; }
			}
		}
		if ( $ours ) { return self::delegation_result( 'support', 'delegated_to_us', $names, $ips, null, true ); }

		return self::delegation_result( 'ok', 'delegated', $names, $ips, self::parent_ds( $ascii, $asked, $deadline ) );
	}

	/**
	 * Is the TXT record at _beltoft-verify.<domain> published, with exactly $value, on every delegated server?
	 *
	 * @return array{status: string, servers: array<string, string>, reason: string}
	 */
	public static function check_txt( string $domain, string $value, array $delegation ): array {
		if ( 'ok' !== ( $delegation['status'] ?? '' ) ) { return array( 'status' => (string) ( $delegation['status'] ?? 'unknown' ), 'servers' => array(), 'reason' => (string) ( $delegation['reason'] ?? 'no_delegation' ) ); }
		$deadline = self::deadline();
		$name     = self::TXT_LABEL . '.' . strtolower( rtrim( $domain, '.' ) );
		$servers  = array();
		foreach ( (array) ( $delegation['ns'] ?? array() ) as $ns ) {
			$servers[ $ns ] = ! empty( $delegation['ips'][ $ns ] ) ? 'timeout' : 'no_address'; // Every name has a state, also when the budget runs out before it is asked.
		}
		if ( ! $servers ) { return array( 'status' => 'unknown', 'servers' => array(), 'reason' => 'no_servers' ); }
		$spent = false;
		foreach ( $servers as $ns => $state ) {
			foreach ( (array) ( $delegation['ips'][ $ns ] ?? array() ) as $ip ) {
				$response = self::ask( (string) $ip, $name, DnsWire::TXT, $deadline );
				if ( is_wp_error( $response ) ) {
					if ( 'budget' === $response->get_error_code() ) { $spent = true; break 2; }
					$servers[ $ns ] = 'dns_timeout' === $response->get_error_code() ? 'timeout' : 'error';
					continue; // Try the next address of this name.
				}
				$servers[ $ns ] = self::txt_state( $response, $name, $value );
				break;
			}
		}
		if ( $spent ) { return array( 'status' => 'unknown', 'servers' => $servers, 'reason' => 'budget' ); }
		$states = array_values( $servers );
		if ( ! array_diff( $states, array( 'found' ) ) ) { return array( 'status' => 'found', 'servers' => $servers, 'reason' => 'found' ); }
		if ( in_array( 'cname', $states, true ) ) { return array( 'status' => 'missing', 'servers' => $servers, 'reason' => 'cname' ); }
		$failed = array_intersect( $states, array( 'timeout', 'error', 'no_address' ) );
		if ( $failed && ! array_intersect( $states, array( 'missing', 'wrong' ) ) ) { return array( 'status' => 'unknown', 'servers' => $servers, 'reason' => 'incomplete' ); }
		return array( 'status' => 'missing', 'servers' => $servers, 'reason' => in_array( 'missing', $states, true ) ? 'not_found' : 'wrong_value' );
	}

	/**
	 * Common records of the domain from one responsive delegated server, as record rows (Records shape).
	 * A CNAME is read from the A, AAAA or TXT answer for the name, not asked for separately: an
	 * authoritative server answers a CNAME there, and it saves queries.
	 *
	 * @return array{records: array<int, array<string, mixed>>, server: string, queries: int}
	 */
	public static function scan( string $domain, array $delegation ): array {
		$out = array( 'records' => array(), 'server' => '', 'queries' => 0 );
		if ( 'ok' !== ( $delegation['status'] ?? '' ) ) { return $out; }
		$deadline = self::deadline();
		$domain   = strtolower( rtrim( $domain, '.' ) );
		$queries  = 0;
		$cache    = array();
		$server   = '';
		$ip       = '';
		$ask      = function ( string $relative, int $type ) use ( &$queries, &$cache, &$ip, $domain, $deadline ) {
			$key = $relative . '|' . $type;
			if ( array_key_exists( $key, $cache ) ) { return $cache[ $key ]; }
			if ( $queries >= self::MAX_SCAN_QUERIES ) { return null; }
			++$queries;
			$fqdn = '@' === $relative ? $domain : $relative . '.' . $domain;
			$response = self::ask( $ip, $fqdn, $type, $deadline );
			return $cache[ $key ] = ( is_wp_error( $response ) || ! $response['aa'] || ! in_array( $response['rcode'], array( 0, 3 ), true ) ) ? null : $response;
		};

		// The first server that answers authoritatively for the apex A question is the one that is read.
		foreach ( (array) ( $delegation['ns'] ?? array() ) as $ns ) {
			foreach ( (array) ( $delegation['ips'][ $ns ] ?? array() ) as $candidate ) {
				$ip = (string) $candidate;
				unset( $cache['@|1'] );
				if ( null !== $ask( '@', DnsWire::A ) ) { $server = (string) $ns; break 2; }
				if ( $queries >= self::MAX_SCAN_QUERIES || self::expired( $deadline ) ) { break 2; }
			}
		}
		if ( '' === $server ) { return array( 'records' => array(), 'server' => '', 'queries' => $queries ); }

		$rows = array();
		$add  = function ( string $relative, string $type, int $ttl, string $value, array $extra = array() ) use ( &$rows ) {
			$row = array( 'name' => $relative, 'type' => $type, 'ttl' => max( 60, min( 86400, $ttl ) ), 'value' => $value ) + $extra;
			$rows[ md5( (string) wp_json_encode( array( $relative, $type, $value, $extra ) ) ) ] = $row;
		};
		$of   = static function ( ?array $response, string $relative, int $type ) use ( $domain ): array {
			if ( null === $response ) { return array(); }
			$fqdn = '@' === $relative ? $domain : $relative . '.' . $domain;
			return array_values( array_filter( $response['answer'], static fn( $rr ) => $rr['type'] === $type && $rr['name'] === $fqdn ) );
		};
		$cname = function ( ?array $response, string $relative ) use ( $of, $add ): bool {
			foreach ( $of( $response, $relative, DnsWire::CNAME ) as $rr ) { $add( $relative, 'CNAME', $rr['ttl'], (string) $rr['data'] ); return true; }
			return false;
		};
		$txt  = function ( ?array $response, string $relative ) use ( $of, $add ): void {
			foreach ( $of( $response, $relative, DnsWire::TXT ) as $rr ) {
				$text = implode( '', (array) $rr['data'] );
				if ( '' === $text || strlen( $text ) > 255 || preg_match( '/[\x00-\x1F\x7F]/', $text ) || false !== stripos( $text, self::TXT_LABEL ) ) { continue; }
				$add( $relative, 'TXT', $rr['ttl'], $text );
			}
		};

		foreach ( array( DnsWire::A => 'A', DnsWire::AAAA => 'AAAA' ) as $type => $label ) {
			foreach ( $of( $ask( '@', $type ), '@', $type ) as $rr ) { $add( '@', $label, $rr['ttl'], (string) $rr['data'] ); }
		}
		foreach ( $of( $ask( '@', DnsWire::MX ), '@', DnsWire::MX ) as $rr ) { $add( '@', 'MX', $rr['ttl'], (string) $rr['data']['target'], array( 'priority' => $rr['data']['priority'] ) ); }
		$txt( $ask( '@', DnsWire::TXT ), '@' );
		foreach ( $of( $ask( '@', DnsWire::CAA ), '@', DnsWire::CAA ) as $rr ) { $add( '@', 'CAA', $rr['ttl'], $rr['data']['value'], array( 'flag' => $rr['data']['flag'], 'tag' => $rr['data']['tag'] ) ); }

		foreach ( self::HOSTS as $host ) {
			$a = $ask( $host, DnsWire::A );
			if ( $cname( $a, $host ) ) { continue; }
			foreach ( $of( $a, $host, DnsWire::A ) as $rr ) { $add( $host, 'A', $rr['ttl'], (string) $rr['data'] ); }
			$aaaa = $ask( $host, DnsWire::AAAA );
			if ( $cname( $aaaa, $host ) ) { continue; }
			foreach ( $of( $aaaa, $host, DnsWire::AAAA ) as $rr ) { $add( $host, 'AAAA', $rr['ttl'], (string) $rr['data'] ); }
		}
		foreach ( self::TXT_OWNERS as $owner ) {
			$response = $ask( $owner, DnsWire::TXT );
			if ( ! $cname( $response, $owner ) ) { $txt( $response, $owner ); }
		}
		foreach ( self::SRV_OWNERS as $owner ) {
			foreach ( $of( $ask( $owner, DnsWire::SRV ), $owner, DnsWire::SRV ) as $rr ) {
				$add( $owner, 'SRV', $rr['ttl'], (string) $rr['data']['target'], array( 'priority' => $rr['data']['priority'], 'weight' => $rr['data']['weight'], 'port' => $rr['data']['port'] ) );
			}
		}
		return array( 'records' => array_values( $rows ), 'server' => $server, 'queries' => $queries );
	}

	/** The parent servers' public addresses (one per server, at most two servers), walking up for a parent with servers. */
	private static function parent_servers( string $domain ): array {
		$labels = explode( '.', $domain );
		for ( $i = 1; $i < count( $labels ); ++$i ) {
			$names = DnsAddress::parent_servers( implode( '.', array_slice( $labels, $i ) ) );
			if ( ! $names ) { continue; }
			$ips = array();
			foreach ( $names as $name ) {
				$found = DnsAddress::public_ips( $name );
				if ( $found ) { $ips[] = $found[0]; }
				if ( count( $ips ) >= 2 ) { break; }
			}
			return $ips;
		}
		return array();
	}

	/** DS at the parent: true when published, false when the parent answers without one, null when it cannot be told. */
	private static function parent_ds( string $domain, array $servers, float $deadline ): ?bool {
		$state = null;
		foreach ( $servers as $ip ) {
			$response = self::ask( $ip, $domain, DnsWire::DS, $deadline );
			if ( is_wp_error( $response ) || 0 !== $response['rcode'] ) { continue; }
			foreach ( $response['answer'] as $rr ) {
				if ( DnsWire::DS === $rr['type'] && $rr['name'] === $domain ) { return true; }
			}
			$state = false;
		}
		return $state;
	}

	/** One server's answer to the TXT question, as a per-server state. */
	private static function txt_state( array $response, string $name, string $value ): string {
		if ( ! $response['aa'] || ! in_array( $response['rcode'], array( 0, 3 ), true ) ) { return 'error'; }
		$wrong = false;
		foreach ( $response['answer'] as $rr ) {
			if ( $rr['name'] !== $name ) { continue; }
			if ( DnsWire::CNAME === $rr['type'] ) { return 'cname'; }
			if ( DnsWire::TXT === $rr['type'] ) {
				if ( implode( '', (array) $rr['data'] ) === $value ) { return 'found'; }
				$wrong = true;
			}
		}
		return $wrong ? 'wrong' : 'missing';
	}

	/** One question with the time budget applied; WP_Error 'budget' when it is spent. */
	private static function ask( string $ip, string $name, int $type, float $deadline ): array|\WP_Error {
		if ( self::expired( $deadline ) ) { return new \WP_Error( 'budget', 'The time budget is spent.' ); }
		return DnsTransport::ask( $ip, $name, $type );
	}

	private static function deadline(): float {
		/** Seconds one public call may take in total (tests lower it). */
		$budget = (float) apply_filters( 'beltoft_services_dns_budget', (float) self::BUDGET );
		return microtime( true ) + $budget;
	}

	private static function expired( float $deadline ): bool { return microtime( true ) >= $deadline; }

	private static function delegation_result( string $status, string $reason, array $ns = array(), array $ips = array(), ?bool $ds = null, bool $ours = false ): array {
		sort( $ns );
		ksort( $ips );
		return array( 'status' => $status, 'reason' => $reason, 'ns' => array_values( $ns ), 'ips' => $ips, 'ds' => $ds, 'ours' => $ours );
	}
}
