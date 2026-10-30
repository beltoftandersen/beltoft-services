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
 * Phase 3: private network only. The address comes from PrivateNets, so the profile belongs to one resource.
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
		return array( 'net', 'vmid', 'clone', 'configure', 'start', 'ready', 'db_init', 'db_ready' );
	}

	public function uses_public_ip(): bool {
		return false;
	}

	public function hold_address( int $resource_id ): ?array {
		unset( $resource_id );

		return null;
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
		unset( $ip, $net0 );
		$nic = $this->nic( $cluster );

		return is_wp_error( $nic ) ? array() : $nic;
	}

	public function cicustom( array $cluster ): ?string {
		return '' !== (string) ( $cluster['db_vendor_snippet'] ?? '' ) ? 'vendor=' . $cluster['db_vendor_snippet'] : null;
	}

	public function post_build_meta( array $record, ?array $ip ): array {
		unset( $ip );

		return array(
			'disks_check' => null,
			'ip'          => null,
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
