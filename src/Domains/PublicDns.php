<?php
/**
 * What public DNS answers for a name.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * A read of public DNS, as any visitor sees it. Used by the checks that wait for a customer's records (mail, certificate
 * validation) so none of them needs to know about another feature.
 */
final class PublicDns {

	/** Tests only: replaces the system resolver call, callable( string $fqdn, int $type_flag ): array|false. Null reads for real. */
	public static $reader = null;

	/** The one call to the system resolver. The name always leaves lowercase with exactly one trailing dot, so the resolver search list never applies. */
	public static function records( string $name, int $flag ): array|false {
		$fqdn = strtolower( rtrim( trim( $name ), '.' ) ) . '.';
		if ( is_callable( self::$reader ) ) { return ( self::$reader )( $fqdn, $flag ); }
		return @dns_get_record( $fqdn, $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is false and handled by the caller.
	}

	/**
	 * What public DNS answers for a name. Tests answer through the filter beltoft_services_mail_dns_lookup; nothing in a
	 * test run reaches the network.
	 *
	 * @param string $fqdn Name.
	 * @param string $type MX, TXT or CNAME.
	 * @return string[]|\WP_Error Values (MX as "priority host"), empty when none; an error when no resolver answered.
	 */
	public static function lookup( string $fqdn, string $type ) {
		/**
		 * Replaces the public DNS read (tests only).
		 *
		 * @param array|\WP_Error|null $answer Null to read for real.
		 * @param string               $fqdn   Name.
		 * @param string               $type   MX, TXT or CNAME.
		 */
		$test = apply_filters( 'beltoft_services_mail_dns_lookup', null, $fqdn, $type );

		if ( null !== $test ) {
			return is_wp_error( $test ) ? $test : array_values( array_map( 'strval', (array) $test ) );
		}

		return self::resolve( $fqdn, $type );
	}

	/**
	 * The one reader of the system resolver: a name and a type (A, AAAA, TXT, MX, CNAME or NS), the values as strings. The name
	 * leaves with a trailing dot (see records()). A resolver that cannot be reached, or a name that does not exist, is a
	 * dns_transport error, because the system resolver reports both the same way; an empty list means no record of that type.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function resolve( string $name, string $type ) {
		$flag    = array( 'A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME, 'NS' => DNS_NS )[ $type ] ?? DNS_TXT;
		$records = self::records( $name, $flag );

		if ( false === $records ) {
			// The system call is false for a name that does not exist and for a resolver that failed. Only a name the resolver says does
			// not exist (NXDOMAIN) is an empty answer; anything else is unknown and stays an error, so nothing takes it for "no record".
			return 3 === self::rcode( $name, $type ) ? array() : new \WP_Error( 'dns_transport', __( 'The name did not resolve.', 'beltoft-services' ) );
		}

		return self::shape( $records, $type );
	}

	/**
	 * The response code the system resolver gives for a name (3 is NXDOMAIN, 2 is SERVFAIL), or null when it cannot be told: no resolver
	 * answered the question, or a test run (tests answer through the filter beltoft_services_dns_rcode).
	 *
	 * @param string $name Name.
	 * @param string $type A, AAAA, MX, TXT, CNAME or NS.
	 */
	public static function rcode( string $name, string $type ): ?int {
		/**
		 * Replaces the response code read (tests only).
		 *
		 * @param int|null $rcode Null to ask the resolver.
		 * @param string   $name  Name.
		 * @param string   $type  Type.
		 */
		$test = apply_filters( 'beltoft_services_dns_rcode', null, $name, $type );

		if ( null !== $test ) {
			return (int) $test;
		}

		if ( is_callable( self::$reader ) ) {
			return null;
		}

		$resolver = '';

		foreach ( (array) @file( '/etc/resolv.conf', FILE_IGNORE_NEW_LINES ) as $line ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no file means no answer.
			if ( 1 === preg_match( '/^\s*nameserver\s+(\S+)/', (string) $line, $m ) && false !== filter_var( $m[1], FILTER_VALIDATE_IP ) ) {
				$resolver = $m[1];
				break;
			}
		}

		$code = array( 'A' => 1, 'AAAA' => 28, 'MX' => 15, 'TXT' => 16, 'CNAME' => 5, 'NS' => 2 )[ $type ] ?? 16;

		if ( '' === $resolver ) {
			return null;
		}

		// The resolver is named by the server's own configuration, never by a customer: trusted.
		$answer = DnsTransport::ask( $resolver, strtolower( rtrim( trim( $name ), '.' ) ), $code, array( 'rd' => true, 'trusted' => true ) );

		return is_wp_error( $answer ) ? null : (int) $answer['rcode'];
	}

	/**
	 * The values of one type in a dns_get_record() result, as strings (MX as "priority host", TXT strings joined, A and AAAA
	 * addresses and name server and alias targets in lowercase). Pure, so a canned result tests it.
	 *
	 * @param array<int, array<string, mixed>> $records A dns_get_record() result.
	 * @return string[]
	 */
	public static function shape( array $records, string $type ): array {
		$out = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || $type !== ( $record['type'] ?? '' ) ) {
				continue;
			}

			if ( 'A' === $type ) {
				$out[] = strtolower( (string) ( $record['ip'] ?? '' ) );
			} elseif ( 'AAAA' === $type ) {
				$out[] = strtolower( (string) ( $record['ipv6'] ?? '' ) );
			} elseif ( 'MX' === $type ) {
				$out[] = (int) ( $record['pri'] ?? 0 ) . ' ' . (string) ( $record['target'] ?? '' );
			} elseif ( 'TXT' === $type ) {
				$out[] = isset( $record['entries'] ) ? implode( '', (array) $record['entries'] ) : (string) ( $record['txt'] ?? '' );
			} else {
				$out[] = (string) ( $record['target'] ?? '' );
			}
		}

		return array_values( array_filter( $out, static fn( $value ) => '' !== $value || 'TXT' === $type ) );
	}
}
