<?php
/**
 * Which node a new VM is built on.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

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
	 * The node to build on, from the capacity cache only (Proxmox is never asked): of the allowed, online nodes whose free
	 * memory (maxmem minus the committed memory, see Capacity and ProxmoxJobs) covers the plan, the one with the most free
	 * memory (the template's node on a tie). When none has room, the one with the most room left (the least over its
	 * budget): capacity never stops a placement (owner decision 2026-11-11). A node that is offline or not allowed is
	 * never chosen. Null only when the cache is missing (the caller waits for the capacity job) or no node is usable.
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
		$fits      = false;

		foreach ( (array) Capacity::free_by_node( $cluster_id ) as $name => $free ) {
			$ok = $free >= $bytes;

			// A node with room beats any node without; among equals the most free memory, then the template's node.
			if ( null === $best_free || ( $ok && ! $fits ) || ( $ok === $fits && ( $free > $best_free || ( $free === $best_free && (string) $name === $template_node ) ) ) ) {
				$best      = (string) $name;
				$best_free = $free;
				$fits      = $ok;
			}
		}

		return $best;
	}
}
