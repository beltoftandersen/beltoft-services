<?php
/**
 * The public SSH ports of the containers of a node.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Locks;

defined( 'ABSPATH' ) || exit;

/**
 * One public SSH port per container, from the node's range (`ssh_port_min` to `ssh_port_max`); the node forwards
 * the port to the container's own port 22.
 */
final class SshPorts {

	/**
	 * Gives the resource a port: the one it has, or the lowest free one of the node's range.
	 *
	 * @param int                  $resource_id Activation id.
	 * @param array<string, mixed> $node        Node settings (ssh_port_min, ssh_port_max).
	 * @param string               $node_id     Node record id.
	 * @return int|null The port, or null when the range is used up.
	 * @throws \RuntimeException When the allocation lock cannot be taken in time.
	 */
	public static function assign( int $resource_id, array $node, string $node_id ): ?int {
		list( $locked, $port ) = Locks::with(
			Locks::name( 'hosting-port', 0 ),
			Locks::allocation_wait(),
			static function () use ( $resource_id, $node, $node_id ) {
				$record = Records::for_resource( $resource_id );

				if ( ! $record ) {
					return null;
				}

				if ( null !== $record['ssh_port'] ) {
					return $record['ssh_port'];
				}

				$used = array_flip( Records::used( $node_id, 'ssh_port' ) );

				for ( $p = (int) $node['ssh_port_min']; $p <= (int) $node['ssh_port_max']; $p++ ) {
					if ( ! isset( $used[ (string) $p ] ) && Records::claim( $resource_id, 'ssh_port', $p ) ) {
						return $p;
					}
				}

				return null;
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The SSH port allocation is busy.' );
		}

		return null === $port ? null : (int) $port;
	}
}
