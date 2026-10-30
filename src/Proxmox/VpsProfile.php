<?php
/**
 * The build profile of a VPS: one public IP address from the pool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * The behaviour Provision and Operations::cleanup always had.
 */
final class VpsProfile implements BuildProfile {

	public function steps(): array {
		return array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' );
	}

	public function uses_public_ip(): bool {
		return true;
	}

	public function hold_address( int $resource_id ): ?array {
		return IpPool::hold( $resource_id );
	}

	public function network_params( array $cluster, ?array $ip, string $net0 ): array {
		$gw = null !== $ip && null !== $ip['gateway'] && '' !== $ip['gateway'] ? $ip['gateway'] : (string) $cluster['gateway'];

		return array(
			'ipconfig0' => 'ip=' . ( $ip['address'] ?? '' ) . '/' . (int) $cluster['prefix'] . ',gw=' . $gw,
			'net0'      => $net0,
		);
	}

	public function cicustom( array $cluster ): ?string {
		return '' !== (string) $cluster['vendor_snippet'] ? 'vendor=' . $cluster['vendor_snippet'] : null;
	}

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
