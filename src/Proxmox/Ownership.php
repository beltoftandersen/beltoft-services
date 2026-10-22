<?php
/**
 * Proof that a virtual machine is ours before anything destructive is done to it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The ownership tag is a line in the VM description: `beltoft-services:<site_tag>:<resource uuid>`
 * (API viewer: https://pve.proxmox.com/pve-docs/api-viewer/index.html#/nodes/{node}/qemu/{vmid}/config,
 * field `description`). A VM is ours only when its description carries that exact line as a whole
 * line, its VMID is inside the cluster's customer range and this plugin recorded the VMID for this
 * resource. Anything else is read-only.
 *
 * Absence is not deletion: a VM that /cluster/resources does not list may be hidden from the token.
 * The HTTP status of "VM does not exist" is not documented, so no code relies on a 404; the id is
 * confirmed free only by GET /cluster/nextid?vmid=<id> succeeding.
 */
final class Ownership {

	/**
	 * The tag line of a resource.
	 *
	 * @param Resource $r Resource.
	 */
	public static function tag( Resource $r ): string {
		return 'beltoft-services:' . ProxmoxSettings::site_tag() . ':' . $r->get( 'uuid' );
	}

	/**
	 * Does the description contain the tag as a whole line?
	 *
	 * @param string $description VM description.
	 * @param string $tag         Tag line.
	 */
	public static function has_tag( string $description, string $tag ): bool {
		if ( '' === $tag || ':' === substr( $tag, -1 ) ) {
			return false; // A tag without a resource uuid proves nothing.
		}

		foreach ( preg_split( '/\R/', $description ) as $line ) {
			if ( trim( $line ) === $tag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The VMs of the cluster: GET /cluster/resources?type=vm.
	 *
	 * @param Client $c Client.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function listing( Client $c ) {
		$list = $c->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) );

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : new \WP_Error( 'bad_response', 'Proxmox sent no resource list.' );
	}

	/**
	 * One VM from a listing.
	 *
	 * @param array<int, array<string, mixed>> $list Listing.
	 * @param int                              $vmid VMID.
	 * @return array<string, mixed>|null
	 */
	public static function entry( array $list, int $vmid ): ?array {
		foreach ( $list as $entry ) {
			if ( isset( $entry['vmid'] ) && (int) $entry['vmid'] === $vmid ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Is this VMID free on the cluster? GET /cluster/nextid?vmid=<id> ("Pass a VMID to assert that
	 * it's free", API viewer #/cluster/nextid).
	 *
	 * @param Client $c    Client.
	 * @param int    $vmid VMID.
	 * @return bool|\WP_Error True: free. False: taken (the assertion failed with HTTP 400, a
	 *                        parameter error). Anything else (transport, 5xx) is not an answer.
	 */
	public static function is_free( Client $c, int $vmid ) {
		$answer = $c->request( 'GET', '/cluster/nextid', array( 'vmid' => $vmid ) );

		if ( ! is_wp_error( $answer ) ) {
			return true;
		}

		$data = $answer->get_error_data();

		return is_array( $data ) && 400 === (int) ( $data['status'] ?? 0 ) ? false : $answer;
	}

	/**
	 * The config of a VM.
	 *
	 * @param Client $c    Client.
	 * @param string $node Node.
	 * @param int    $vmid VMID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function config( Client $c, string $node, int $vmid ) {
		$config = $c->request( 'GET', '/nodes/' . rawurlencode( $node ) . '/qemu/' . $vmid . '/config' );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return is_array( $config ) ? $config : new \WP_Error( 'bad_response', 'Proxmox sent no VM config.' );
	}

	/**
	 * Verifies that the recorded VM is ours, and where it runs now.
	 *
	 * Error codes: no_cluster, out_of_range, not_recorded, absent (not listed: not proof that it
	 * is gone), not_ours (another description, not a qemu VM), is_template, or the client's error.
	 *
	 * @param Client               $c      Client of the record's cluster.
	 * @param array<string, mixed> $record VmRecords::for_resource() row.
	 * @param Resource             $r      Resource.
	 * @return array{node: string, config: array<string, mixed>, status: string, lock: string}|\WP_Error
	 */
	public static function verify( Client $c, array $record, Resource $r ) {
		$cluster_id = (string) $record['cluster'];
		$vmid       = (int) $record['vmid'];
		$cluster    = Clusters::get( $cluster_id );

		if ( null === $cluster ) {
			return new \WP_Error( 'no_cluster', 'The cluster of this VM is not configured.' );
		}

		if ( $vmid < (int) $cluster['vmid_min'] || $vmid > (int) $cluster['vmid_max'] ) {
			return new \WP_Error( 'out_of_range', sprintf( 'VMID %d is outside the customer range.', $vmid ) );
		}

		if ( '' === $r->get( 'uuid' ) || ! VmRecords::owned( $cluster_id, $vmid, $r->id() ) ) {
			return new \WP_Error( 'not_recorded', sprintf( 'VMID %d is not recorded for this service.', $vmid ) );
		}

		$list = self::listing( $c );

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$entry = self::entry( $list, $vmid );

		if ( null === $entry ) {
			return new \WP_Error( 'absent', sprintf( 'VMID %d is not listed.', $vmid ) );
		}

		if ( 'qemu' !== (string) ( $entry['type'] ?? 'qemu' ) ) {
			return new \WP_Error( 'not_ours', sprintf( 'VMID %d is not a virtual machine.', $vmid ) );
		}

		if ( ! empty( $entry['template'] ) ) {
			return new \WP_Error( 'is_template', sprintf( 'VMID %d is a template.', $vmid ) );
		}

		$node   = (string) ( $entry['node'] ?? '' );
		$config = self::config( $c, $node, $vmid );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( ! self::has_tag( (string) ( $config['description'] ?? '' ), self::tag( $r ) ) ) {
			return new \WP_Error( 'not_ours', sprintf( 'VMID %d does not carry this service\'s tag.', $vmid ) );
		}

		if ( $node !== (string) $record['node'] ) {
			VmRecords::move( $r->id(), $node );
		}

		return array(
			'node'   => $node,
			'config' => $config,
			'status' => (string) ( $entry['status'] ?? '' ),
			'lock'   => (string) ( $entry['lock'] ?? '' ),
		);
	}
}
