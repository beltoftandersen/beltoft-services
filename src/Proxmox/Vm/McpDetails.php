<?php
/**
 * What the read tools say about one server: its live figures and what belongs to it, from stored readings.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Monitoring\LiveUsage;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Ipv4\Ipv4Panels;
use BeltoftServices\Proxmox\Network\FirewallRules;
use BeltoftServices\Proxmox\Network\PrivateAttach;
use BeltoftServices\Proxmox\Network\PrivateNets;
use BeltoftServices\Proxmox\Snapshot\PanelData;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every value here is picked by name from what those classes return: their views also carry links with nonces, the
 * customer's own address and button state, which must never reach a result.
 */
final class McpDetails {

	/**
	 * A server's live figures and what it is, from stored readings (no cluster call).
	 *
	 * @param Resource $r Server.
	 * @return array<string, mixed>
	 */
	public static function vm( Resource $r ): array {
		$plan   = (array) $r->get( 'plan' );
		$cached = Operations::cached_status( $r->id() );
		$guest  = LiveUsage::cached_memory( $r->id() );
		$disk   = GuestDisk::cached( $r );

		return array(
			'power'          => null === $cached ? '' : (string) $cached['power'],
			'cpu_percent'    => null !== $cached && 'running' === $cached['power'] ? max( 0, min( 100, (int) round( (float) $cached['cpu'] * 100 ) ) ) : null,
			'memory_percent' => null !== $cached && 'running' === $cached['power'] ? $guest : null,
			'disk_percent'   => null !== $disk ? max( 0, min( 100, (int) round( $disk['used'] / $disk['total'] * 100 ) ) ) : null,
			'ip'             => false !== filter_var( (string) $r->get( 'ip' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? (string) $r->get( 'ip' ) : '',
			'ip6'            => Panels::ip6( $r ),
			'ipv4_addresses' => array_column( self::ipv4_of( $r )['addresses'], 'address' ),
			'system'         => Servers::os_label( $r ),
			'size'           => sprintf( '%d vCPU, %s GB memory, %d GB disk', (int) ( $plan['cores'] ?? 0 ), (string) round( (int) ( $plan['memory_mb'] ?? 0 ) / 1024, 1 ), (int) ( $plan['disk_gb'] ?? 0 ) ),
			'app'            => '' !== (string) $r->get( 'app_id' ) ? (string) $r->get( 'app_id' ) : '',
		);
	}

	/**
	 * What belongs to a server besides its figures: firewall, private network, snapshots and backups.
	 *
	 * @param Resource $r Server.
	 * @return array<string, mixed>
	 */
	public static function extras( Resource $r ): array {
		$out = array( 'app_state' => (string) $r->get( 'app_state' ) );

		if ( FirewallRules::editable( $r ) ) {
			$view = FirewallRules::view( $r );

			$out['firewall'] = array(
				'on'      => $view['on'],
				'rules'   => count( $view['rules'] ),
				'applied' => $view['applied'],
				'state'   => $view['state'],
			);
		}

		if ( PrivateAttach::eligible( $r ) ) {
			$state                  = (string) $r->get( PrivateAttach::STATE );
			$out['private_network'] = '' === $state ? 'detached' : $state;
			$view                   = PrivateAttach::view( $r );

			if ( '' !== $view['network'] ) {
				$out['private_network_name'] = $view['network'];
			}

			$out['private_networks'] = array_map(
				static fn( array $net ): array => array(
					'id'      => $net['id'],
					'name'    => $net['label'],
					'range'   => $net['cidr'],
					'servers' => count( $net['members'] ),
					'full'    => $net['full'],
				),
				PrivateNets::nets_for_user( (int) ( $r->license()['user_id'] ?? 0 ) )
			);
		}

		$snapshots = PanelData::panel( $r );

		if ( null !== $snapshots ) {
			$out['snapshots'] = array( 'enabled' => $snapshots['enabled'], 'slots' => $snapshots['slots'], 'used' => $snapshots['used'] );
		}

		$out['backups'] = array(
			'enabled' => Backups::enabled( $r ),
			'days'    => Backups::days( $r ),
			'last_ok' => Backups::listing( $r )['last_ok'],
		);

		return $out;
	}

	/**
	 * The IPv4 addresses of a server or a database: the ones it has through the add-on, and whether one more can be added
	 * (it is added in the dashboard: the add-on has its own subscription).
	 *
	 * @param Resource $r Server or database.
	 * @return array<string, mixed>
	 */
	public static function ipv4_of( Resource $r ): array {
		$view = Ipv4Panels::view( $r );

		return array(
			'addresses'   => array_map( static fn( array $row ) => array( 'address' => $row['address'], 'role' => $row['role'], 'in_use' => $row['live'] ), null === $view ? array() : $view['addresses'] ),
			'can_add'     => null !== $view && '' !== $view['add_url'],
			'sold_out'    => null !== $view && $view['sold_out'],
			'how_to_add'  => __( 'Add or remove an IPv4 address from the server or the database page in the dashboard. A new database can be ordered with one (order_database).', 'beltoft-services' ),
		);
	}
}
