<?php
/**
 * The build profile of a VPS: one public IP address from the pool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The behaviour Provision and Operations::cleanup always had.
 */
final class VpsProfile implements BuildProfile {

	use PostBuildMeta {
		post_build_meta as private base_meta;
	}

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
		$steps = array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' );

		// A server with no IPv4 address (VPS Basic without the add-on) has nothing to hold.
		return $this->uses_public_ip() ? $steps : array_values( array_diff( $steps, array( 'ip' ) ) );
	}

	/**
	 * Does a plan give the server a public IPv4 address? Yes unless the plan field `public_ip` is 0 (VPS Basic: IPv6 only). A plan stored before the field existed has no value and means yes.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function plan_has_ipv4( array $plan ): bool {
		return (int) ( $plan['public_ip'] ?? 1 ) > 0;
	}

	/**
	 * Does the server have a public IPv4 address? Always, unless its plan says `public_ip` 0 (VPS Basic) and the IPv4 add-on has not given it one.
	 * A plan stored before the field existed has no value and means yes. A profile made without a resource (a filter, a test) says yes.
	 */
	public function uses_public_ip(): bool {
		// A plan without IPv4 (VPS Basic) still has one once the IPv4 add-on gave it a main address (meta `ip`).
		// The shared rule (Ipv4Meta::main_wanted), or the main address the server has right now (an add-on being removed keeps it until it is gone).
		return null === $this->r || \BeltoftServices\Proxmox\Ipv4\Ipv4Meta::main_wanted( $this->r ) || false !== filter_var( (string) $this->r->get( 'ip' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	}

	public function hold_address( int $resource_id ): ?array {
		return $this->uses_public_ip() ? IpPool::hold( $resource_id ) : null;
	}

	/**
	 * Why a server with no IPv4 address cannot be built on this cluster: it needs the cluster's IPv6 (its only address) and
	 * IPv6 name servers. Empty when the server has an IPv4 address or the cluster can serve it.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param int                  $vmid    VMID.
	 */
	public function address_error( array $cluster, int $vmid ): string {
		if ( $this->uses_public_ip() ) {
			return '';
		}

		if ( '' === Ipv6::ipconfig( $cluster, $vmid ) ) {
			return 'configure: this server has no IPv4 address and the cluster has no IPv6, so it would have no address';
		}

		return array() === (array) ( $cluster['nameservers6'] ?? array() ) ? 'configure: this server has no IPv4 address and the cluster has no IPv6 name servers' : '';
	}

	public function network_params( array $cluster, ?array $ip, string $net0 ): array {
		$gw = null !== $ip && null !== $ip['gateway'] && '' !== $ip['gateway'] ? $ip['gateway'] : (string) $cluster['gateway'];

		$ip6    = '';
		$record = null === $this->r ? null : VmRecords::for_resource( $this->r->id() );

		if ( null !== $record ) {
			$ip6 = Ipv6::ipconfig( $cluster, (int) $record['vmid'] );
		}

		// No pool address (IPv6 only): ipconfig0 holds the IPv6 part alone.
		$ip4 = null === $ip ? '' : 'ip=' . ( $ip['address'] ?? '' ) . '/' . (int) $cluster['prefix'] . ',gw=' . $gw;

		return array(
			'ipconfig0' => $ip4 . ( '' === $ip4 || '' === $ip6 ? '' : ',' ) . $ip6,
			'net0'      => $net0,
		);
	}

	/**
	 * The meta of a built server. The IPv6 address is recorded whether or not the server has an IPv4 one.
	 *
	 * @param array<string, mixed>      $record VM record.
	 * @param array<string, mixed>|null $ip     The held pool row (null: none).
	 * @return array<string, mixed>
	 */
	public function post_build_meta( array $record, ?array $ip ): array {
		$meta = $this->base_meta( $record, $ip );

		if ( null === $ip && ! $this->uses_public_ip() ) {
			$cluster     = Clusters::get( (string) $record['cluster'] );
			$meta['ip6'] = null === $cluster ? null : Ipv6::address( $cluster, (int) $record['vmid'] );
		}

		return $meta;
	}

	public function cicustom( array $cluster ): ?string {
		return '' !== (string) $cluster['vendor_snippet'] ? 'vendor=' . $cluster['vendor_snippet'] : null;
	}
}
