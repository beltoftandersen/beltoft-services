<?php
/**
 * The private addresses of the containers of a node.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Locks;

defined( 'ABSPATH' ) || exit;

/**
 * Hands out one IPv4 address per container from the node's private range (its `lan_cidr`, starting at its
 * `ip_first` host number, so the gateway, the edge and the database server keep their low addresses). Never
 * a public address: hosting needs none per customer.
 */
final class CtIpPool {

	/**
	 * Gives the resource an address: the one it has, or the lowest free one of the node's range.
	 *
	 * @param int                  $resource_id Activation id.
	 * @param array<string, mixed> $node        Node settings (lan_cidr, ip_first).
	 * @param string               $node_id     Node record id.
	 * @return string|null The address, or null when the range is used up or the settings are not usable.
	 * @throws \RuntimeException When the allocation lock cannot be taken in time.
	 */
	public static function assign( int $resource_id, array $node, string $node_id ): ?string {
		list( $locked, $address ) = Locks::with(
			Locks::name( 'hosting-ip', 0 ),
			Locks::allocation_wait(),
			static function () use ( $resource_id, $node, $node_id ) {
				$record = Records::for_resource( $resource_id );

				if ( ! $record ) {
					return null;
				}

				if ( null !== $record['ip'] ) {
					return $record['ip'];
				}

				$range = self::range( (string) $node['lan_cidr'], (int) $node['ip_first'] );

				if ( null === $range ) {
					return null;
				}

				$used = array_flip( Records::used( $node_id, 'ip' ) );

				for ( $n = $range[0]; $n <= $range[1]; $n++ ) {
					$ip = long2ip( $n );

					if ( ! isset( $used[ $ip ] ) && Records::claim( $resource_id, 'ip', $ip ) ) {
						return $ip;
					}
				}

				return null;
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The address allocation is busy.' );
		}

		return null === $address ? null : (string) $address;
	}

	/**
	 * The first and last usable address of a range as integers: the network address plus the first host
	 * number, up to the one before the broadcast address.
	 *
	 * @param string $cidr  For example 10.200.0.0/16.
	 * @param int    $first Host number of the first address to give out.
	 * @return array{0: int, 1: int}|null
	 */
	public static function range( string $cidr, int $first ): ?array {
		if ( 1 !== preg_match( '#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#D', $cidr, $m ) || false === ip2long( $m[1] ) ) {
			return null;
		}

		$bits = (int) $m[2];

		if ( $bits < 16 || $bits > 29 || $first < 1 ) {
			return null;
		}

		$size    = 1 << ( 32 - $bits );
		$network = ip2long( $m[1] ) & ( ( 0xFFFFFFFF << ( 32 - $bits ) ) & 0xFFFFFFFF );

		if ( $first > $size - 2 ) {
			return null;
		}

		return array( $network + $first, $network + $size - 2 );
	}
}
