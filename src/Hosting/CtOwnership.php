<?php
/**
 * Proof that a container is ours before anything destructive is done to it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Proxmox\Pve\Ownership;
use BeltoftServices\Proxmox\Pve\ProxmoxSettings;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The same tag format as the virtual machines (Proxmox\Pve\Ownership): a line in the container description,
 * `beltoft-services:<site_tag>:<resource uuid>`. A container is ours only when its description carries that line
 * as a whole line, its id is inside the node's customer range, this plugin recorded the id for this resource and
 * it is not a template. Anything else is read-only. The listing, the free-id check and the line match are the
 * Proxmox\Pve\Ownership ones (they are path-agnostic); only the config path differs (lxc, not qemu).
 *
 * Absence is not deletion: a container that /cluster/resources does not list may be hidden from the token.
 */
final class CtOwnership {

	/**
	 * The tag line of a resource.
	 *
	 * @param Resource $r Resource.
	 */
	public static function tag( Resource $r ): string {
		return 'beltoft-services:' . ProxmoxSettings::site_tag() . ':' . $r->get( 'uuid' );
	}

	/**
	 * The config of a container: GET /nodes/{node}/lxc/{vmid}/config.
	 *
	 * @param Client $c    Client.
	 * @param string $node Proxmox node name.
	 * @param int    $vmid Container id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function config( Client $c, string $node, int $vmid ) {
		$config = $c->request( 'GET', '/nodes/' . rawurlencode( $node ) . '/lxc/' . $vmid . '/config' );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return is_array( $config ) ? $config : new \WP_Error( 'bad_response', 'Proxmox sent no container config.' );
	}

	/**
	 * Verifies that the recorded container is ours, and where it runs now.
	 *
	 * Error codes: no_node (the node's id range or name is not set), out_of_range, not_recorded, absent
	 * (not listed: nothing is assumed), not_ours (no tag line), is_template, or the client's own error.
	 *
	 * @param Client               $c      Client.
	 * @param array<string, mixed> $node   Node settings.
	 * @param array<string, mixed> $record Hosting record of the resource.
	 * @param Resource             $r      Resource.
	 * @return array{node: string, status: string, config: array<string, mixed>}|\WP_Error
	 */
	public static function verify( Client $c, array $node, array $record, Resource $r ) {
		$vmid = (int) ( $record['vmid'] ?? 0 );

		if ( '' === (string) $node['pve_node'] ) {
			return new \WP_Error( 'no_node', 'The hosting node has no node name.' );
		}

		if ( $vmid < (int) $node['vmid_min'] || $vmid > (int) $node['vmid_max'] ) {
			return new \WP_Error( 'out_of_range', 'The container id is outside the customer range of the node.' );
		}

		$stored = Records::for_resource( $r->id() );

		if ( ! $stored || $stored['vmid'] !== $vmid ) {
			return new \WP_Error( 'not_recorded', 'This plugin has not recorded this container id for this resource.' );
		}

		$list = Ownership::listing( $c );

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$entry = Ownership::entry( $list, $vmid );

		if ( null === $entry || 'lxc' !== ( $entry['type'] ?? 'lxc' ) ) {
			return new \WP_Error( 'absent', 'The container is not listed on the node.' );
		}

		if ( ! empty( $entry['template'] ) ) {
			return new \WP_Error( 'is_template', 'The container is a template.' );
		}

		$where  = (string) ( $entry['node'] ?? $node['pve_node'] );
		$config = self::config( $c, $where, $vmid );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( ! Ownership::has_tag( (string) ( $config['description'] ?? '' ), self::tag( $r ) ) ) {
			return new \WP_Error( 'not_ours', 'The container does not carry this resource\'s ownership tag.' );
		}

		return array(
			'node'   => $where,
			'status' => (string) ( $entry['status'] ?? 'unknown' ),
			'config' => $config,
		);
	}
}
