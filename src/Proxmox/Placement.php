<?php
/**
 * Which node a new VM is built on.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * The allowed node with the most free memory. Reads the capacity cache first (a transient per
 * cluster, written by the hourly job), then GET /nodes once. With one allowed node there is
 * nothing to choose.
 */
final class Placement {

	/**
	 * Transient prefix of a cluster's node list (rows of GET /nodes: node, status, maxmem, mem).
	 */
	public const CAPACITY_PREFIX = 'beltoft_services_proxmox_capacity_';

	/**
	 * The node to build on.
	 *
	 * @param string               $cluster_id    Cluster id.
	 * @param array<string, mixed> $cluster       Cluster settings.
	 * @param Client               $client        Client.
	 * @param string               $template_node Node the template is on (preferred on a tie: no `target` needed).
	 * @return string|null Null when no allowed node is online (or the node list cannot be read).
	 */
	public static function node( string $cluster_id, array $cluster, Client $client, string $template_node = '' ): ?string {
		$allowed = array_values( array_map( 'strval', (array) $cluster['nodes'] ) );

		if ( array() === $allowed ) {
			return null;
		}

		if ( 1 === count( $allowed ) ) {
			return $allowed[0];
		}

		$rows = get_transient( self::CAPACITY_PREFIX . $cluster_id );

		if ( ! is_array( $rows ) || array() === $rows ) {
			$rows = $client->request( 'GET', '/nodes' );
		}

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$best      = null;
		$best_free = -1;

		foreach ( $rows as $row ) {
			$name = is_array( $row ) ? (string) ( $row['node'] ?? '' ) : '';

			if ( ! in_array( $name, $allowed, true ) || 'online' !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}

			$free = (int) ( $row['maxmem'] ?? 0 ) - (int) ( $row['mem'] ?? 0 );

			if ( $free > $best_free || ( $free === $best_free && $name === $template_node ) ) {
				$best      = $name;
				$best_free = $free;
			}
		}

		return $best;
	}
}
