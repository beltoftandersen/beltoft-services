<?php
/**
 * The monitoring adapter of the hosting accounts: where the containers run and how their memory, rrd samples and storage are read.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Jobs;
use BeltoftServices\Monitoring\Guest;
use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Proxmox\Pve\Ownership;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Inputs only: the listing of a node, the proof that a container carries our tag, the node agent's `ct.memory`, the rrd
 * read and the stored storage figures. Thresholds, history and mail are in the Monitoring folder. Reads only.
 */
final class HostingGuest implements Guest {

	use Runs;

	/** Transient prefix of a node's container rows. */
	public const ROWS_PREFIX = 'beltoft_services_hosting_rows_';
	public const ROWS_TTL    = 15;
	/** Seconds one agent reading is allowed to take: how often it polls the job, and how long it waits between polls. */
	public const POLLS    = 4;
	public const POLL_MS  = 250;
	/** Seconds of one agent job id: asked twice inside it, the agent answers with the first job. */
	public const JOB_SPAN = 10;

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
		return HostingDriver::ID === $r->get( 'driver' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function running(): array {
		$out = array();

		foreach ( array_keys( Nodes::all() ) as $node_id ) {
			$context = self::context( (string) $node_id );

			if ( null === $context ) {
				continue;
			}

			$rows = self::rows( (string) $node_id, $context['client'], $context['pve_node'] );

			foreach ( Records::resource_ids( (string) $node_id ) as $resource_id ) {
				$record   = Records::for_resource( $resource_id );
				$resource = Resource::find( $resource_id );
				$row      = null === $record || null === $record['vmid'] ? null : ( $rows[ $record['vmid'] ] ?? null );

				if ( ! $resource || null === $row || 'running' !== (string) $row['status'] || '' !== (string) ( $row['lock'] ?? '' ) || ! $this->owns( $resource ) ) {
					continue;
				}

				$out[] = array(
					'resource' => $resource,
					'group'    => 'hosting/' . $node_id,
					'uptime'   => isset( $row['uptime'] ) ? (int) $row['uptime'] : null,
					'ref'      => array( $context['client'], $context['agent'], (string) ( $row['node'] ?? $context['pve_node'] ), (int) $record['vmid'] ),
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
		$by_node = array();

		foreach ( $resources as $resource ) {
			$record = Records::for_resource( $resource->id() );

			if ( null !== $record && null !== $record['vmid'] ) {
				$by_node[ $record['node'] ][] = array( $resource, (int) $record['vmid'] );
			}
		}

		$out = array();

		foreach ( $by_node as $node_id => $items ) {
			$context = self::context( (string) $node_id );

			if ( null === $context ) {
				continue;
			}

			$rows = self::rows( (string) $node_id, $context['client'], $context['pve_node'] );

			foreach ( $items as $item ) {
				$row = $rows[ $item[1] ] ?? null;

				if ( null !== $row ) {
					$out[ $item[0]->id() ] = array(
						'power'  => (string) $row['status'],
						'cpu'    => (float) ( $row['cpu'] ?? 0 ),
						'uptime' => isset( $row['uptime'] ) ? (int) $row['uptime'] : null,
						'ref'    => array( $context['client'], $context['agent'], (string) ( $row['node'] ?? $context['pve_node'] ), $item[1] ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * The memory in use without the file cache, from the node agent (`ct.memory`), only for a container that carries this
	 * resource's tag. The agent reads inside the container; a number from Proxmox is never used.
	 *
	 * @param Resource  $r         Resource.
	 * @param mixed     $ref       array( client, agent, pve node, vmid ).
	 * @param bool|null $node_down Set to true when the node itself did not answer (not the container).
	 */
	public function memory( Resource $r, $ref, ?bool &$node_down = null ): ?array {
		$node_down = false;

		list( $client, $agent, $pve_node, $vmid ) = array_pad( (array) $ref, 4, null );

		if ( ! $client instanceof Client || ! $agent instanceof Agent || '' === (string) $pve_node || (int) $vmid <= 0 ) {
			return null;
		}

		$config = CtOwnership::config( $client, (string) $pve_node, (int) $vmid );

		if ( is_wp_error( $config ) ) {
			$node_down = true;

			return null;
		}

		if ( ! Ownership::has_tag( (string) ( $config['description'] ?? '' ), CtOwnership::tag( $r ) ) ) {
			return null;
		}

		$id  = $r->get( 'uuid' ) . ':mem:' . intdiv( Jobs::now(), self::JOB_SPAN );
		$job = $agent->submit( 'ct.memory', array( 'vmid' => (int) $vmid ), $id );

		for ( $i = 0; $i < self::POLLS && ! is_wp_error( $job ) && in_array( (string) ( $job['status'] ?? '' ), array( 'queued', 'running' ), true ); $i++ ) {
			usleep( self::POLL_MS * 1000 );
			$job = $agent->job( $id );
		}

		if ( is_wp_error( $job ) || 'done' !== (string) ( $job['status'] ?? '' ) || ! is_array( $job['result'] ?? null ) ) {
			return null;
		}

		$used  = (int) ( $job['result']['used_mb'] ?? -1 );
		$total = (int) ( $job['result']['total_mb'] ?? 0 );

		return $used < 0 || $total <= 0 ? null : array( 'used_mb' => min( $used, $total ), 'total_mb' => $total );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r         Resource.
	 * @param string   $timeframe hour, day or month.
	 */
	public function samples( Resource $r, string $timeframe ): ?array {
		$this->r = $r;

		return null !== $this->connect() ? null : $this->rrd( $timeframe );
	}

	/**
	 * Storage: files plus databases (the hourly usage reading) against the plan's storage, in bytes. Stored values only.
	 *
	 * @param Resource $r       Resource.
	 * @param bool     $refresh Unused: the hourly job is the only reader.
	 */
	public function disk( Resource $r, bool $refresh = false ): ?array {
		unset( $refresh );

		$usage = (array) $r->get( 'usage' );
		$plan  = Plan::typed( (array) $r->get( 'plan' ) );

		if ( ! isset( $usage['disk_mb'], $usage['db_mb'] ) || (int) $plan['disk_gb'] <= 0 ) {
			return null;
		}

		return array(
			'used'  => ( (int) $usage['disk_mb'] + (int) $usage['db_mb'] ) * 1048576,
			'total' => (int) $plan['disk_gb'] * 1073741824,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function record_table(): string {
		return Schema::tables()['hosting'];
	}

	/**
	 * The node API client, the agent client and the node name of an enabled node that may be called (the live gate), or null.
	 *
	 * @param string $node_id Node id.
	 * @return array{client: Client, agent: Agent, pve_node: string}|null
	 */
	private static function context( string $node_id ): ?array {
		$node = Nodes::get( $node_id );

		if ( null === $node || ! $node['enabled'] || '' === (string) $node['pve_node'] || null !== Clients::gate( $node_id, $node, Clients::live_allowed() ) ) {
			return null;
		}

		try {
			return array(
				'client'   => Clients::for_node( $node ),
				'agent'    => Clients::agent_for( $node ),
				'pve_node' => (string) $node['pve_node'],
			);
		} catch ( \Throwable $e ) {
			unset( $e );

			return null;
		}
	}

	/**
	 * The containers of a node by vmid, from one /cluster/resources call kept 15 seconds (a failed read is kept as well).
	 *
	 * @param string $node_id  Node id.
	 * @param Client $client   Client.
	 * @param string $pve_node Node name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( string $node_id, Client $client, string $pve_node ): array {
		$kept = get_transient( self::ROWS_PREFIX . $node_id );

		if ( is_array( $kept ) ) {
			return $kept;
		}

		$list = Ownership::listing( $client );
		$rows = array();

		foreach ( is_wp_error( $list ) ? array() : $list as $row ) {
			if ( 'lxc' === ( $row['type'] ?? 'lxc' ) && empty( $row['template'] ) && '' !== (string) ( $row['status'] ?? '' ) && isset( $row['vmid'] ) ) {
				$rows[ (int) $row['vmid'] ] = $row;
			}
		}

		set_transient( self::ROWS_PREFIX . $node_id, $rows, self::ROWS_TTL );

		return $rows;
	}
}
