<?php
/** The meta a built server record gets, the same for every build profile. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\Clusters;

defined( 'ABSPATH' ) || exit;

/**
 * The post_build_meta() of BuildProfile for profiles that keep the record's own cluster, node and vmid.
 */
trait PostBuildMeta {

	public function post_build_meta( array $record, ?array $ip ): array {
		// The IPv6 address goes with a public IPv4 one (a server always has one, a database only with the add-on).
		$cluster = null === $ip ? null : Clusters::get( (string) $record['cluster'] );

		return array(
			'disks_check' => null,
			'ip'          => $ip['address'] ?? null,
			'ip6'         => null === $cluster ? null : Ipv6::address( $cluster, (int) $record['vmid'] ),
			'node'        => $record['node'],
			'vmid'        => $record['vmid'],
			'vm'          => array(
				'cluster' => $record['cluster'],
				'vmid'    => $record['vmid'],
				'node'    => $record['node'],
			),
			'task'        => null,
		);
	}
}
