<?php
/** The meta a built server record gets, the same for every build profile. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Vm;

defined( 'ABSPATH' ) || exit;

/**
 * The post_build_meta() of BuildProfile for profiles that keep the record's own cluster, node and vmid.
 */
trait PostBuildMeta {

	public function post_build_meta( array $record, ?array $ip ): array {
		return array(
			'disks_check' => null,
			'ip'          => $ip['address'] ?? null,
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
