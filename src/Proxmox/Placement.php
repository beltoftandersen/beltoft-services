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
	 * The node to build on: of the allowed, online nodes whose free memory (maxmem minus the
	 * committed memory of the capacity cache, see Capacity and ProxmoxJobs) covers the plan, the one
	 * with the most free memory. Proxmox is never asked here. Null when the cache is missing (the
	 * caller waits for the capacity job; true for a single node too) or no node has room.
	 *
	 * @param string               $cluster_id    Cluster id.
	 * @param array<string, mixed> $cluster       Cluster settings.
	 * @param string               $template_node Node the template is on (preferred on a tie: no `target` needed).
	 * @param int                  $bytes         Memory the new VM needs.
	 * @return string|null
	 */
	public static function node( string $cluster_id, array $cluster, string $template_node = '', int $bytes = 0 ): ?string {
		unset( $cluster ); // The allowed nodes are applied by Capacity::free_by_node().

		$best      = null;
		$best_free = null;

		foreach ( (array) Capacity::free_by_node( $cluster_id ) as $name => $free ) {
			if ( $free < $bytes ) {
				continue;
			}

			if ( null === $best_free || $free > $best_free || ( $free === $best_free && (string) $name === $template_node ) ) {
				$best      = (string) $name;
				$best_free = $free;
			}
		}

		return $best;
	}
}
