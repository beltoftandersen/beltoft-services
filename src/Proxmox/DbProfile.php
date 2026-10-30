<?php
/**
 * The build profile of a managed database: no public IP, one private NIC, a data disk.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A private NIC (net1) always, and net0 with a public IPv4 address when the plan has the add-on (`public_ip` 1). The
 * private address comes from PrivateNets, so the profile belongs to one resource.
 */
final class DbProfile implements BuildProfile {

	/**
	 * @var Resource
	 */
	private $r;

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	public function steps(): array {
		$steps = array( 'net', 'vmid', 'clone', 'configure', 'start', 'ready', 'db_init', 'db_ready' );

		return $this->uses_public_ip() ? array_merge( array( 'ip' ), $steps ) : $steps;
	}

	public function uses_public_ip(): bool {
		return DbIp::plan_wants( (array) $this->r->get( 'plan' ) );
	}

	/**
	 * The customer has paid for the address: it is taken even when only the ones kept for servers are left.
	 * (The shop does not sell the add-on in that case, see IpPool::available_for_db().)
	 */
	public function hold_address( int $resource_id ): ?array {
		return $this->uses_public_ip() ? IpPool::hold( $resource_id ) : null;
	}

	/**
	 * net1 on the customer's private VLAN and its address; no net0, no gateway.
	 *
	 * @return array<string, string>
	 */
	public function nic( array $cluster ) {
		$net = PrivateNets::for_resource( $this->r->id() );

		if ( null === $net ) {
			return new \WP_Error( 'invalid_private_network', 'The private network is not configured.' );
		}

		return PrivateNic::params( $net, $net['address'], (string) ( $cluster['private_bridge'] ?? '' ) );
	}

	public function network_params( array $cluster, ?array $ip, string $net0 ): array {
		$nic = $this->nic( $cluster );

		if ( is_wp_error( $nic ) ) {
			return array();
		}

		return null === $ip ? $nic : $nic + DbIp::public_params( $cluster, $ip, $net0 );
	}

	public function cicustom( array $cluster ): ?string {
		return '' !== (string) ( $cluster['db_vendor_snippet'] ?? '' ) ? 'vendor=' . $cluster['db_vendor_snippet'] : null;
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

	/**
	 * Connections the server allows, by memory (a filter may change it).
	 */
	public static function max_connections( Resource $r ): int {
		$mb = (int) ( ( (array) $r->get( 'plan' ) )['memory_mb'] ?? 0 );
		$n  = $mb >= 8192 ? 300 : ( $mb >= 4096 ? 200 : 100 );

		/**
		 * Filters max_connections of a database server.
		 *
		 * @param int      $n Connections.
		 * @param Resource $r Resource.
		 */
		return max( 20, min( 1000, (int) apply_filters( 'beltoft_services_db_max_connections', $n, $r ) ) );
	}
}
