<?php
/** Moves bytes to a name server and back, nothing else. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

interface DnsChannel {
	/**
	 * Sends one question packet and returns the raw answer packet.
	 *
	 * @param string $host     Server address (an IPv6 address in brackets) or name.
	 * @param int    $port     Port.
	 * @param string $wire     The question packet (without the TCP length prefix).
	 * @param string $protocol 'udp' or 'tcp'.
	 * @return string|\WP_Error The answer packet (without the TCP length prefix); dns_unreachable or dns_timeout on failure.
	 */
	public function send( string $host, int $port, string $wire, string $protocol ): string|\WP_Error;
}
