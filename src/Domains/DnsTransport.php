<?php
/** Ask one authoritative DNS server one question over UDP, then TCP when truncated. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class DnsTransport {
	/** @var DnsChannel|null The channel that moves the bytes; null is the socket one. Tests swap it. */
	private static ?DnsChannel $channel = null;

	/** Swaps the channel that moves bytes (tests); null restores the socket channel. */
	public static function use( ?DnsChannel $channel ): void {
		self::$channel = $channel;
	}

	private static function channel(): DnsChannel {
		return self::$channel ?? new SocketChannel();
	}

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

	/**
	 * One prepared question to a name server, read over UDP and, when the answer is truncated (TC bit), again over TCP.
	 * No address check and no parsing: the caller trusts its server and reads the packet (DnsQuery).
	 *
	 * @param string $host Server address or name (an IPv6 address in brackets).
	 * @param string $wire The question packet.
	 * @param int    $port Port.
	 * @return string|\WP_Error The answer packet.
	 */
	public static function exchange( string $host, string $wire, int $port = 53 ): string|\WP_Error {
		$data = self::channel()->send( $host, $port, $wire, 'udp' );
		if ( ! is_string( $data ) || strlen( $data ) < 12 || ( ord( $data[2] ) & 0x02 ) === 0 ) { return $data; }
		return self::channel()->send( $host, $port, $wire, 'tcp' );
	}

	private static function once( string $ip, string $name, int $type, string $protocol ): array|\WP_Error {
		$id   = random_int( 0, 65535 );
		$wire = DnsWire::query( $name, $type, $id );
		if ( is_wp_error( $wire ) ) { return $wire; }
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
		$data = self::channel()->send( $host, 53, $wire, 'tcp' === $protocol ? 'tcp' : 'udp' );
		return is_string( $data ) ? DnsWire::parse( $data, $id, $name, $type ) : $data;
	}
}
