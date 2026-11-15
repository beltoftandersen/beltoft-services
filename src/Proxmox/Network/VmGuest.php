<?php
/**
 * The monitoring adapter of the virtual machines (and database servers): where they run and how their memory, rrd samples and disk are read.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Monitoring\Guest;
use BeltoftServices\Monitoring\GuestMemory;
use BeltoftServices\Proxmox\Db\DbActions;
use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Proxmox\Pve\Clients;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\JobScope;
use BeltoftServices\Proxmox\Pve\LiveClient;
use BeltoftServices\Proxmox\Pve\Ownership;
use BeltoftServices\Proxmox\Vm\GuestDisk;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\PowerJobs;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Inputs only: the listing of a cluster, the proof that a VM carries our tag, the guest agent's /proc/meminfo, the rrd read
 * of Operations and the stored disk reading. Thresholds, history and mail are in the Monitoring folder.
 */
final class VmGuest implements Guest {

	/** Transient of a cluster's VM rows. */
	public const ROWS_PREFIX = 'beltoft_services_proxmox_vmrows_';
	/** Transient that holds a VM's next allowed disk refresh. */
	public const DISK_PREFIX = 'beltoft_services_proxmox_diskpoll_';
	public const ROWS_TTL    = 15;
	public const DISK_TTL    = 10 * MINUTE_IN_SECONDS;

	/**
	 * Adds the adapter to the monitoring registry.
	 */
	public static function register(): void {
		add_filter(
			'beltoft_services_monitoring_guests',
			static function ( $guests ) {
				$guests   = is_array( $guests ) ? $guests : array();
				$guests[] = new self();

				return $guests;
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function owns( Resource $r ): bool {
		return ProxmoxDriver::ID === $r->get( 'driver' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function running(): array {
		$out = array();

		foreach ( JobScope::clusters() as $id => $one ) {
			$rows = JobScope::vm_rows( $one['client'] );

			if ( null === $rows ) {
				continue;
			}

			$ours = VmRecords::for_cluster( (string) $id );

			foreach ( $rows as $vm ) {
				$vmid = (int) ( $vm['vmid'] ?? 0 );

				if ( ! isset( $ours[ $vmid ] ) || ! empty( $vm['template'] ) || 'running' !== (string) ( $vm['status'] ?? '' ) || '' !== (string) ( $vm['lock'] ?? '' ) ) {
					continue;
				}

				$resource = Resource::find( (int) $ours[ $vmid ] );

				if ( ! $resource || ( ! $this->owns( $resource ) && ! DbActions::is_db( $resource ) ) ) {
					continue;
				}

				$node  = (string) ( $vm['node'] ?? '' );
				$out[] = array(
					'resource' => $resource,
					'group'    => $id . '/' . $node,
					'uptime'   => isset( $vm['uptime'] ) ? (int) $vm['uptime'] : null,
					'ref'      => array( $one['client'], $node, $vmid ),
				);
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource[] $resources Resources.
	 */
	public function live_rows( array $resources ): array {
		$by_cluster = array();

		foreach ( $resources as $resource ) {
			$record = VmRecords::for_resource( $resource->id() );

			if ( null !== $record ) {
				$by_cluster[ (string) $record['cluster'] ][] = array( $resource, (int) $record['vmid'] );
			}
		}

		$out = array();

		foreach ( $by_cluster as $cluster_id => $items ) {
			$client = self::client( $cluster_id );
			$rows   = null === $client ? null : self::cluster_rows( $cluster_id, $client );

			if ( null === $rows ) {
				continue;
			}

			foreach ( $items as $item ) {
				$vm = $rows[ $item[1] ] ?? null;

				if ( null !== $vm ) {
					$out[ $item[0]->id() ] = array(
						'power'  => (string) $vm['status'],
						'cpu'    => (float) ( $vm['cpu'] ?? 0 ),
						'uptime' => isset( $vm['uptime'] ) ? (int) $vm['uptime'] : null,
						'ref'    => array( $client, (string) ( $vm['node'] ?? '' ), (int) $vm['vmid'] ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Reads /proc/meminfo of a running VM through the guest agent: one fixed path, and only on a VM that carries this
	 * resource's tag (the same proof as every other VM access). Never a customer-supplied path. Null when it cannot be read.
	 *
	 * @param Resource  $r         Resource.
	 * @param mixed     $ref       array( client, node, vmid ).
	 * @param bool|null $node_down Set to true when the node itself did not answer (not the guest agent).
	 */
	public function memory( Resource $r, $ref, ?bool &$node_down = null ): ?array {
		$node_down = false;

		list( $client, $node, $vmid ) = array_pad( (array) $ref, 3, null );

		if ( ! $client instanceof Client || '' === (string) $node || (int) $vmid <= 0 ) {
			return null;
		}

		$config = Ownership::config( $client, (string) $node, (int) $vmid );

		if ( is_wp_error( $config ) ) {
			$node_down = true;

			return null;
		}

		if ( ! Ownership::has_tag( (string) ( $config['description'] ?? '' ), Ownership::tag( $r ) ) ) {
			return null;
		}

		$path   = '/nodes/' . rawurlencode( (string) $node ) . '/qemu/' . (int) $vmid . '/agent/file-read';
		$params = array( 'file' => '/proc/meminfo' );
		$reply  = $client instanceof LiveClient ? $client->request_with_timeout( 'GET', $path, $params, 5 ) : $client->request( 'GET', $path, $params );

		if ( is_wp_error( $reply ) || ! is_array( $reply ) || ! is_string( $reply['content'] ?? null ) ) {
			return null;
		}

		return GuestMemory::parse( $reply['content'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r         Resource.
	 * @param string   $timeframe hour, day or month.
	 */
	public function samples( Resource $r, string $timeframe ): ?array {
		return ( new Operations( $r ) )->series( $timeframe );
	}

	/**
	 * The stored guest-agent disk reading. A reading older than ten minutes is read again when asked to, at most once in
	 * ten minutes per VM.
	 *
	 * @param Resource $r       Resource.
	 * @param bool     $refresh Whether a stale reading may be read again.
	 */
	public function disk( Resource $r, bool $refresh = false ): ?array {
		if ( $refresh ) {
			$key = self::DISK_PREFIX . $r->id();

			if ( time() - (int) $r->get( 'disk_read_at' ) >= self::DISK_TTL && false === get_transient( $key ) ) {
				set_transient( $key, 1, self::DISK_TTL );
				GuestDisk::read( $r, true );
				$r = Resource::find( $r->id() ) ?? $r;
			}
		}

		return GuestDisk::cached( $r );
	}

	/**
	 * {@inheritDoc}
	 */
	public function record_table(): string {
		return Schema::tables()['vms'];
	}

	/**
	 * Our VMs of a cluster, by vmid, from one /cluster/resources call kept 15 seconds. The status transients
	 * of the resources are refreshed from it (a VM with a lock is left to its operation).
	 *
	 * @param string $cluster_id Cluster id.
	 * @param Client $client     Client.
	 * @return array<int, array<string, mixed>>|null Null when the cluster cannot be read.
	 */
	private static function cluster_rows( string $cluster_id, Client $client ): ?array {
		$kept = get_transient( self::ROWS_PREFIX . $cluster_id );

		if ( is_array( $kept ) ) {
			return $kept;
		}

		$list = $client->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) );

		if ( ! is_array( $list ) ) {
			// A failed read is not repeated for the window either.
			set_transient( self::ROWS_PREFIX . $cluster_id, array(), self::ROWS_TTL );

			return null;
		}

		$ours = VmRecords::for_cluster( $cluster_id );
		$rows = array();

		foreach ( $list as $vm ) {
			$vmid = is_array( $vm ) ? (int) ( $vm['vmid'] ?? 0 ) : 0;

			if ( ! isset( $ours[ $vmid ] ) || ! empty( $vm['template'] ) || '' === (string) ( $vm['status'] ?? '' ) ) {
				continue;
			}

			$rows[ $vmid ] = $vm;

			if ( '' === (string) ( $vm['lock'] ?? '' ) ) {
				PowerJobs::remember_status( (int) $ours[ $vmid ], $vm );
			}
		}

		set_transient( self::ROWS_PREFIX . $cluster_id, $rows, self::ROWS_TTL );

		return $rows;
	}

	/**
	 * The client of a cluster, or null when it is missing or not allowed.
	 *
	 * @param string $cluster_id Cluster id.
	 */
	private static function client( string $cluster_id ): ?Client {
		$cluster = Clusters::get( $cluster_id );

		if ( null === $cluster || null !== Clients::gate( $cluster_id, $cluster, Clients::live_allowed() ) ) {
			return null;
		}

		try {
			return Clients::for( $cluster );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
