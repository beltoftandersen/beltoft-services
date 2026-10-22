<?php
/**
 * Which node a new VM is built on.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * The allowed node with the most free memory, from the capacity cache only.
 */
final class Placement {

	/**
	 * Transient prefix of a cluster's node list (rows: node, status, maxmem, `mem` = committed memory).
	 */
	public const CAPACITY_PREFIX = 'beltoft_services_proxmox_capacity_';

	/**
	 * The node to build on: the allowed node with the most free memory by the capacity cache
	 * (Capacity: committed memory, see ProxmoxJobs). Proxmox is never asked here, and with several
	 * nodes and no cache there is no answer: the caller retries until the capacity job has run.
	 *
	 * @param string               $cluster_id    Cluster id.
	 * @param array<string, mixed> $cluster       Cluster settings.
	 * @param string               $template_node Node the template is on (preferred on a tie: no `target` needed).
	 * @return string|null Null when no allowed node is online or there is no capacity cache.
	 */
	public static function node( string $cluster_id, array $cluster, string $template_node = '' ): ?string {
		$allowed = array_values( array_map( 'strval', (array) $cluster['nodes'] ) );

		if ( array() === $allowed ) {
			return null;
		}

		if ( 1 === count( $allowed ) ) {
			return $allowed[0]; // Nothing to choose.
		}

		$best      = null;
		$best_free = null;

		foreach ( (array) Capacity::free_by_node( $cluster_id ) as $name => $free ) {
			if ( null === $best_free || $free > $best_free || ( $free === $best_free && (string) $name === $template_node ) ) {
				$best      = (string) $name;
				$best_free = $free;
			}
		}

		return $best;
	}
}
