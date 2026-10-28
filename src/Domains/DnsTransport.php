<?php
/** Ask one authoritative DNS server one question over UDP, then TCP when truncated. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class DnsTransport {
	private const TIMEOUT = 3;

	/**
	 * The parsed response of $ip for $name and $type. The address must already be checked with
	 * DnsAddress::is_public(); it is checked again here, and used for the socket as it is (no second lookup).
	 *
	 * @return array{rcode: int, aa: bool, tc: bool, answer: array, authority: array, additional: array}|\WP_Error
	 */
	public static function ask( string $ip, string $name, int $type ): array|\WP_Error {
		$first = self::once( $ip, $name, $type, 'udp' );
		if ( is_wp_error( $first ) || ! $first['tc'] ) { return $first; }
		return self::once( $ip, $name, $type, 'tcp' );
	}

	private static function once( string $ip, string $name, int $type, string $protocol ): array|\WP_Error {
		$id = random_int( 0, 65535 );
		/**
		 * Tests: a non-null return replaces the network, a raw response packet or a WP_Error. The fifth argument
		 * is the protocol ('udp' or 'tcp'). A packet from here carries its own id, which is then the one expected.
		 */
		$test = apply_filters( 'beltoft_services_dns_wire', null, $ip, $name, $type, $protocol );
		if ( null !== $test ) {
			if ( is_wp_error( $test ) ) { return $test; }
			if ( ! is_string( $test ) ) { return new \WP_Error( 'dns_malformed', 'Malformed DNS message.' ); }
			return DnsWire::parse( $test, strlen( $test ) >= 2 ? unpack( 'n', $test )[1] : 0, $name, $type );
		}
		if ( ! DnsAddress::is_public( $ip ) ) { return new \WP_Error( 'dns_unreachable', 'The address is not a public one.' ); }
		$host = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		$wire = DnsWire::query( $name, $type, $id );
		$data = 'tcp' === $protocol ? self::tcp( $host, $wire ) : self::udp( $host, $wire );
		return is_string( $data ) ? DnsWire::parse( $data, $id, $name, $type ) : $data;
	}

	private static function udp( string $host, string $wire ): string|\WP_Error {
		$socket = @fsockopen( 'udp://' . $host, 53, $errno, $message, self::TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- UDP DNS query, not file I/O; transport error returned below.
		if ( ! $socket ) { return new \WP_Error( 'dns_unreachable', 'Name server could not be reached.' ); }
		stream_set_timeout( $socket, self::TIMEOUT );
		fwrite( $socket, $wire ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UDP DNS socket, not file I/O.
		$data = fread( $socket, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- UDP DNS socket, not file I/O.
		$timed_out = (bool) ( stream_get_meta_data( $socket )['timed_out'] ?? false );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- UDP DNS socket, not file I/O.
		if ( is_string( $data ) && '' !== $data ) { return $data; }
		return new \WP_Error( $timed_out ? 'dns_timeout' : 'dns_unreachable', 'Name server did not answer.' );
	}

	private static function tcp( string $host, string $wire ): string|\WP_Error {
		$socket = @fsockopen( 'tcp://' . $host, 53, $errno, $message, self::TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- TCP DNS query, not file I/O; transport error returned below.
		if ( ! $socket ) { return new \WP_Error( 110 === $errno ? 'dns_timeout' : 'dns_unreachable', 'Name server could not be reached.' ); }
		stream_set_timeout( $socket, self::TIMEOUT );
		fwrite( $socket, pack( 'n', strlen( $wire ) ) . $wire ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- TCP DNS socket, not file I/O.
		$deadline = microtime( true ) + self::TIMEOUT;
		$head     = self::read_exactly( $socket, 2, $deadline );
		$data     = null === $head ? null : self::read_exactly( $socket, unpack( 'n', $head )[1], $deadline );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- TCP DNS socket, not file I/O.
		return null === $data ? new \WP_Error( 'dns_timeout', 'Name server did not answer.' ) : $data;
	}

	/** @param resource $socket */
	private static function read_exactly( $socket, int $length, float $deadline ): ?string {
		$buffer = '';
		while ( strlen( $buffer ) < $length ) {
			if ( microtime( true ) > $deadline || feof( $socket ) ) { return null; }
			$chunk = fread( $socket, $length - strlen( $buffer ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- TCP DNS socket, not file I/O.
			if ( false === $chunk || '' === $chunk ) { return null; }
			$buffer .= $chunk;
		}
		return $buffer;
	}
}
