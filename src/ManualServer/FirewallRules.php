<?php
/**
 * The input rules of the firewall of a dedicated server: checking what a customer sends and judging a lockout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Documentation: Robot webservice, "Firewall", POST /firewall/{server-id}, "Rule data": name, ip_version ('ipv4', 'ipv6'), dst_ip and
 * src_ip ("only usable in combination with ip_version 'ipv4'"), dst_port, src_port ("Destination TCP/UDP port"), protocol ('tcp', 'udp',
 * 'gre', 'icmp', 'ipip', 'ah', 'esp'), tcp_flags, action ('discard', 'accept'). "All rule data except 'action' is optional."
 * Limitations IPv6: "For rules with IP version IPv6 or no specification of IP version, it is not possible to filter on destination and
 * source IP address. Without specifying the IP version, it is not possible to filter on a specific protocol."
 * The documentation names no highest number of rules: none is invented here (FIREWALL_RULE_LIMIT_EXCEEDED is the supplier's answer).
 * The most this plugin takes in one request, MAX_RULES, only bounds the size of a request.
 */
final class FirewallRules {

	public const MAX_RULES = 100;

	public const PROTOCOLS = array( 'tcp', 'udp', 'gre', 'icmp', 'ipip', 'ah', 'esp' );

	private const FIELDS = array( 'name', 'ip_version', 'src_ip', 'dst_ip', 'src_port', 'dst_port', 'protocol', 'tcp_flags', 'action' );

	/**
	 * Checks a list of input rules and returns them in the shape the web service takes (empty fields left out), or the reason.
	 *
	 * @param mixed $rules A list of rules as a customer sent them.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public static function clean( $rules ) {
		if ( ! is_array( $rules ) || count( $rules ) > self::MAX_RULES ) {
			return self::bad( __( 'The rules are not valid.', 'beltoft-services' ) );
		}

		$out = array();

		foreach ( array_values( $rules ) as $i => $rule ) {
			$clean = self::rule( is_array( $rule ) ? $rule : array(), $i + 1 );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$out[] = $clean;
		}

		return $out;
	}

	/**
	 * One rule.
	 *
	 * @param array<string, mixed> $rule Raw.
	 * @param int                  $n    Its number for the message.
	 * @return array<string, string>|\WP_Error
	 */
	private static function rule( array $rule, int $n ) {
		$v = array();

		foreach ( self::FIELDS as $field ) {
			$value = isset( $rule[ $field ] ) && ( is_string( $rule[ $field ] ) || is_int( $rule[ $field ] ) ) ? trim( (string) $rule[ $field ] ) : '';
			// A control character or a very long text is never a rule.
			if ( strlen( $value ) > 80 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
				/* translators: %d: rule number. */
				return self::bad( sprintf( __( 'Rule %d is not valid.', 'beltoft-services' ), $n ) );
			}

			$v[ $field ] = $value;
		}

		$no = static function ( string $why ) use ( $n ): \WP_Error {
			/* translators: 1: rule number, 2: reason. */
			return self::bad( sprintf( __( 'Rule %1$d: %2$s', 'beltoft-services' ), $n, $why ) );
		};

		if ( ! in_array( $v['action'], array( 'accept', 'discard' ), true ) ) {
			return $no( __( 'choose accept or discard.', 'beltoft-services' ) );
		}

		if ( ! in_array( $v['ip_version'], array( '', 'ipv4', 'ipv6' ), true ) ) {
			return $no( __( 'the IP version is IPv4 or IPv6.', 'beltoft-services' ) );
		}

		if ( '' !== $v['protocol'] && ! in_array( $v['protocol'], self::PROTOCOLS, true ) ) {
			return $no( __( 'the protocol is not one of tcp, udp, gre, icmp, ipip, ah, esp.', 'beltoft-services' ) );
		}

		if ( '' !== $v['protocol'] && '' === $v['ip_version'] ) {
			return $no( __( 'a protocol needs the IP version.', 'beltoft-services' ) );
		}

		foreach ( array( 'src_ip', 'dst_ip' ) as $ip ) {
			if ( '' === $v[ $ip ] ) {
				continue;
			}

			if ( 'ipv4' !== $v['ip_version'] ) {
				return $no( __( 'an address can only be used with IPv4.', 'beltoft-services' ) );
			}

			if ( ! self::cidr4( $v[ $ip ] ) ) {
				return $no( __( 'the address must be an IPv4 address or network such as 203.0.113.0/24.', 'beltoft-services' ) );
			}
		}

		foreach ( array( 'src_port', 'dst_port' ) as $port ) {
			if ( '' !== $v[ $port ] && ! self::port( $v[ $port ] ) ) {
				return $no( __( 'a port is a number from 1 to 65535, or a range such as 8000-8100.', 'beltoft-services' ) );
			}
		}

		if ( '' !== $v['tcp_flags'] && ! preg_match( '/^[a-z&|! ]{1,40}$/D', $v['tcp_flags'] ) ) {
			return $no( __( 'the TCP flags are not valid.', 'beltoft-services' ) );
		}

		return array_filter( $v, static fn( string $value ): bool => '' !== $value );
	}

	/**
	 * Is it an IPv4 address or an IPv4 network in CIDR notation?
	 *
	 * @param string $text Text.
	 */
	private static function cidr4( string $text ): bool {
		$parts = explode( '/', $text );

		if ( count( $parts ) > 2 || false === filter_var( $parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		return 1 === count( $parts ) || ( ctype_digit( $parts[1] ) && (int) $parts[1] >= 0 && (int) $parts[1] <= 32 );
	}

	/**
	 * A port or a range of ports.
	 *
	 * @param string $text Text.
	 */
	private static function port( string $text ): bool {
		if ( ! preg_match( '/^(\d{1,5})(?:-(\d{1,5}))?$/D', $text, $m ) ) {
			return false;
		}

		$a = (int) $m[1];
		$b = isset( $m[2] ) ? (int) $m[2] : $a;

		return $a >= 1 && $b <= 65535 && $a <= $b;
	}

	/**
	 * Could this configuration lock the customer out of the server? True when the firewall is (or stays) active and no input rule accepts
	 * TCP port 22 from anywhere: the usual way to log in. A configuration that disables the firewall never locks anyone out.
	 *
	 * @param string                                $status Status after the change (active or disabled).
	 * @param array<int, array<string, string>> $rules  Input rules after the change, cleaned.
	 */
	public static function risky( string $status, array $rules ): bool {
		if ( 'active' !== $status ) {
			return false;
		}

		foreach ( $rules as $rule ) {
			if ( 'accept' !== ( $rule['action'] ?? '' ) || ! empty( $rule['src_ip'] ) || ! empty( $rule['src_port'] ) || ! empty( $rule['dst_ip'] ) ) {
				continue;
			}

			if ( 'ipv6' === ( $rule['ip_version'] ?? '' ) ) {
				continue;
			}

			$protocol = (string) ( $rule['protocol'] ?? '' );

			if ( '' !== $protocol && 'tcp' !== $protocol ) {
				continue;
			}

			if ( empty( $rule['dst_port'] ) || self::covers( (string) $rule['dst_port'], 22 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Does a port or range include a port?
	 *
	 * @param string $text Port or range.
	 * @param int    $port Port.
	 */
	private static function covers( string $text, int $port ): bool {
		$parts = explode( '-', $text );

		return $port >= (int) $parts[0] && $port <= (int) end( $parts );
	}

	/**
	 * The rules of a firewall answer for the customer: only the fields the customer edits.
	 *
	 * @param mixed $rules `rules.input` of the answer.
	 * @return array<int, array<string, string>>
	 */
	public static function shown( $rules ): array {
		$out = array();

		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$row = array();

			foreach ( self::FIELDS as $field ) {
				$row[ $field ] = isset( $rule[ $field ] ) && null !== $rule[ $field ] ? (string) $rule[ $field ] : '';
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @param string $message Message.
	 */
	private static function bad( string $message ): \WP_Error {
		return new \WP_Error( 'beltoft_services_dedicated_rules', $message, array( 'status' => 400 ) );
	}
}
