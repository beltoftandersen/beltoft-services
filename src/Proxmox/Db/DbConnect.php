<?php
/**
 * The "Connect to your database" box of the Access tab: what the customer has to do, in order, to reach the database from
 * their own computer, and what the connection looks like.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Proxmox\Network\FirewallRules;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: DbCard::view() hands in what it already knows (the addresses, the allow-list, the visitor's address and the engine), the
 * template templates/account/db-connect.php prints the answer in the shape of the "Point your domain" box (numbered steps, a table
 * with a copy button per value).
 */
final class DbConnect {

	/**
	 * The box.
	 *
	 * @param DbEngine             $engine Engine of the database.
	 * @param array<string, mixed> $in     address (public IPv4 or ''), address6 (or ''), my_ip (the visitor's address as this server sees it,
	 *                                     or ''), entries (the allow-list as stored, CIDR strings), world (open to everyone), editable (the list
	 *                                     may be changed now), add_url, add_price, adding, sold_out (the IPv4 add-on), private_host, private_string.
	 * @return array<string, mixed>
	 */
	public static function build( DbEngine $engine, array $in ): array {
		$v4     = (string) ( $in['address'] ?? '' );
		$v6     = (string) ( $in['address6'] ?? '' );
		$mine   = (string) ( $in['my_ip'] ?? '' );
		$family = '' === $mine ? 0 : ( false !== strpos( $mine, ':' ) ? 6 : 4 );
		$world  = ! empty( $in['world'] );
		$state  = 'unknown';

		if ( $world ) {
			$state = 'open';
		} elseif ( 0 !== $family ) {
			if ( ( 6 === $family && '' === $v6 ) || ( 4 === $family && '' === $v4 ) ) {
				// The visitor's own address is of a kind the database has no address for: it cannot connect from here.
				$state = 'unreachable';
			} else {
				$state = FirewallRules::source_covers( $mine, array_map( 'strval', (array) ( $in['entries'] ?? array() ) ) ) && array() !== (array) ( $in['entries'] ?? array() ) ? 'allowed' : 'missing';
			}
		}

		// The host of the ready strings: the kind the visitor connects from when both exist, else the one that exists.
		$host = 4 === $family && '' !== $v4 ? $v4 : ( '' !== $v6 ? $v6 : $v4 );
		$hosts = array();

		if ( '' !== $v6 ) {
			$hosts[] = array( 'label' => __( 'IPv6 address', 'beltoft-services' ), 'value' => $v6 );
		}

		if ( '' !== $v4 ) {
			$hosts[] = array( 'label' => __( 'IPv4 address', 'beltoft-services' ), 'value' => $v4 );
		}

		return array(
			'engine'         => $engine->id(),
			'my_ip'          => $mine,
			'my_family'      => $family,
			'state'          => $state,
			'can_allow'      => 'missing' === $state && ! empty( $in['editable'] ),
			'hosts'          => $hosts,
			'host'           => $host,
			'port'           => $engine->port(),
			'user'           => $engine->login(),
			'database'       => $engine->database(),
			'command'        => '' === $host ? '' : $engine->command( $host ),
			'ca_hint'        => $engine->ca_hint(),
			'ipv6_only'      => '' === $v4 && '' !== $v6,
			'add_url'        => (string) ( $in['add_url'] ?? '' ),
			'add_price'      => (string) ( $in['add_price'] ?? '' ),
			'adding'         => ! empty( $in['adding'] ),
			'sold_out'       => ! empty( $in['sold_out'] ),
			'private_host'   => (string) ( $in['private_host'] ?? '' ),
			'private_string' => (string) ( $in['private_string'] ?? '' ),
		);
	}
}
