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
	 * Options: do, size, rd (the question, see DnsWire::query), and the two that only our own name servers use:
	 * trusted (skips the public address check: the server is named in our settings, never by a customer) and port.
	 * Nothing that takes a customer's name passes them; port is ignored without trusted.
	 *
	 * @param array{trusted?: bool, port?: int, rd?: bool, do?: bool, size?: int} $options Options.
	 * @return array{rcode: int, aa: bool, tc: bool, ad: bool, ra: bool, opt: ?array, answer: array, authority: array, additional: array}|\WP_Error
	 */
	public static function ask( string $ip, string $name, int $type, array $options = array() ): array|\WP_Error {
		$first = self::once( $ip, $name, $type, 'udp', $options );
		if ( is_wp_error( $first ) || ! $first['tc'] ) { return $first; }
		return self::once( $ip, $name, $type, 'tcp', $options );
	}

	private static function once( string $ip, string $name, int $type, string $protocol, array $options = array() ): array|\WP_Error {
		$id   = random_int( 0, 65535 );
		$wire = DnsWire::query( $name, $type, $id, array_intersect_key( $options, array( 'rd' => 1, 'do' => 1, 'size' => 1 ) ) );
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
		$trusted = ! empty( $options['trusted'] );
		if ( ! $trusted && ! DnsAddress::is_public( $ip ) ) { return new \WP_Error( 'dns_unreachable', 'The address is not a public one.' ); }
		$host = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		$data = self::channel()->send( $host, $trusted ? (int) ( $options['port'] ?? 53 ) : 53, $wire, 'tcp' === $protocol ? 'tcp' : 'udp' );
		return is_string( $data ) ? DnsWire::parse( $data, $id, $name, $type ) : $data;
	}
}
