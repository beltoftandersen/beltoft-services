<?php
/**
 * The build profile of a VPS: one public IP address from the pool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The behaviour Provision and Operations::cleanup always had.
 */
final class VpsProfile implements BuildProfile {

	use PostBuildMeta;

	/**
	 * The resource being built, when there is one: its VMID gives the IPv6 address. A profile made without one (a filter, a
	 * test) writes IPv4 only.
	 *
	 * @var Resource|null
	 */
	private $r;

	public function __construct( ?Resource $r = null ) {
		$this->r = $r;
	}

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

		$ip6    = '';
		$record = null === $this->r ? null : VmRecords::for_resource( $this->r->id() );

		if ( null !== $record ) {
			$ip6 = Ipv6::ipconfig( $cluster, (int) $record['vmid'] );
		}

		return array(
			'ipconfig0' => 'ip=' . ( $ip['address'] ?? '' ) . '/' . (int) $cluster['prefix'] . ',gw=' . $gw . ( '' === $ip6 ? '' : ',' . $ip6 ),
			'net0'      => $net0,
		);
	}

	public function cicustom( array $cluster ): ?string {
		return '' !== (string) $cluster['vendor_snippet'] ? 'vendor=' . $cluster['vendor_snippet'] : null;
	}
}
