<?php
/**
 * Live CPU, memory and disk of a customer's servers, for the bars on the Servers list and the Overview.
 *
 * Power and CPU come from one /cluster/resources call per cluster, kept 15 seconds. Memory is the guest's own
 * MemAvailable (PVE counts page cache as used), read through the guest agent and kept 10 seconds per VM; without
 * an agent answer there is no figure (PVE's is never shown). Every fresh reading goes to GuestMemory (latest reading and
 * history). Disk is the guest agent's filesystem reading, refreshed here at most once in ten minutes per VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Api\Routes;
use BeltoftServices\Proxmox\Pve\Client;
use BeltoftServices\Proxmox\Pve\Clients;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\GuestDisk;
use BeltoftServices\Proxmox\Vm\PowerJobs;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class LiveUsage {
	/** Transient of a cluster's VM rows. */
	public const ROWS_PREFIX = 'beltoft_services_proxmox_vmrows_';
	/** Transient of a VM's guest memory reading. */
	public const MEM_PREFIX = 'beltoft_services_proxmox_memavail_';
	/** Transient that holds a VM's next allowed disk refresh. */
	public const DISK_PREFIX = 'beltoft_services_proxmox_diskpoll_';
	public const ROWS_TTL    = 15;
	public const MEM_TTL     = 10;
	public const DISK_TTL    = 10 * MINUTE_IN_SECONDS;
	/** Seconds between two refreshes in the browser, the slower refresh after ten minutes without a page interaction, and that idle time. */
	public const INTERVAL      = 10;
	public const SLOW_INTERVAL = 60;
	public const IDLE_AFTER    = 10 * MINUTE_IN_SECONDS;
	public const HANDLE   = 'beltoft-services-usage-live';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'ids' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Only these service ids (comma separated). The page asks for the servers on screen.',
					),
				),
			)
		);
	}

	/**
	 * Loads the script that keeps the bars current. Called where the bars are printed.
	 */
	public static function enqueue(): void {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/usage-live.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/usage-live.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesUsage',
			array(
				'url'      => esc_url_raw( rest_url( Routes::NAMESPACE . '/usage' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'interval' => self::INTERVAL * 1000,
				'slow'     => self::SLOW_INTERVAL * 1000,
				'idle'     => self::IDLE_AFTER * 1000,
				'strings'  => array(
					/* translators: %d: seconds. */
					'secondsAgo' => __( '%d s ago', 'beltoft-services' ),
					/* translators: %d: minutes. */
					'minutesAgo' => __( '%d min ago', 'beltoft-services' ),
					/* translators: 1: memory in use in GB, 2: total memory in GB, 3: how long ago the reading was taken, such as "8 s ago". */
					'line'       => __( '%1$s of %2$s GB, updated %3$s', 'beltoft-services' ),
					'hint'       => GuestMemory::agent_hint(),
				),
			)
		);
	}

	/**
	 * GET usage: { resources: { <id>: { power, cpu, mem, disk, memory } } } for the current user's ready servers. With ids only those.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$limited = Routes::limited( $request, Routes::STATUS_RATE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$user_id = get_current_user_id();
		$ids     = (string) $request->get_param( 'ids' );
		$only    = '' === $ids ? null : array_slice( array_values( array_filter( array_map( 'absint', explode( ',', $ids ) ) ) ), 0, 100 );
		$out     = $user_id > 0 ? self::for_user( $user_id, $only ) : array();
		$response = new \WP_REST_Response( array( 'resources' => (object) $out ), 200 );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * The readings of a user's ready, running or stopped proxmox servers (license owner only). Only the servers in $only
	 * when it is given (the ones on screen: the guest agent is asked for those only).
	 *
	 * @param int                $user_id User id.
	 * @param array<int, int>|null $only  Resource ids, or null for all.
	 * @return array<int, array{power: string, cpu: ?int, mem: ?int, disk: ?int, memory: array<string, mixed>}>
	 */
	public static function for_user( int $user_id, ?array $only = null ): array {
		if ( \BeltoftServices\Settings::get( 'pause_all' ) || ! class_exists( Endpoint::class ) ) {
			return array();
		}

		$by_cluster = array();

		foreach ( array_slice( Endpoint::licenses_for_user( $user_id ), 0, 100 ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || ProxmoxDriver::ID !== $resource->get( 'driver' ) || 'ready' !== $resource->get( 'state' ) || ! $resource->shows_status() || $resource->on_hold() ) {
				continue;
			}

			if ( null !== $only && ! in_array( $resource->id(), $only, true ) ) {
				continue;
			}

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
				list( $resource, $vmid ) = $item;

				$vm = $rows[ $vmid ] ?? null;

				if ( null === $vm ) {
					continue;
				}

				$power   = (string) $vm['status'];
				$entry   = array( 'power' => $power, 'cpu' => null, 'mem' => null, 'disk' => null, 'memory' => array( 'state' => 'stopped' ) );

				if ( 'running' === $power ) {
					$entry['cpu']    = self::percent( (float) ( $vm['cpu'] ?? 0 ) * 100 );
					$entry['mem']    = self::memory( $resource, $client, $vm );
					$entry['disk']   = self::disk( $resource );
					$entry['memory'] = GuestMemory::view( $resource->id() );
				}

				$out[ $resource->id() ] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The memory percent of a server's newest reading (up to 15 minutes old); null otherwise. No Proxmox call.
	 *
	 * @param int $resource_id Resource id.
	 */
	public static function cached_memory( int $resource_id ): ?int {
		$latest = GuestMemory::latest( $resource_id );

		return null === $latest ? null : $latest['percent'];
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
	 * Memory percent of a running VM: the guest's (MemTotal - MemAvailable) / MemTotal, kept 10 seconds, so several tabs
	 * share one reading; null when the agent does not answer (PVE's own mem counts the guest's file cache, so it is never
	 * shown). A fresh reading is kept by GuestMemory (latest value, and a history row once in five minutes).
	 *
	 * @param Resource             $resource Resource.
	 * @param Client               $client   Client.
	 * @param array<string, mixed> $vm       The VM's cluster row.
	 */
	private static function memory( Resource $resource, Client $client, array $vm ): ?int {
		$key  = self::MEM_PREFIX . $resource->id();
		$kept = get_transient( $key );

		if ( is_array( $kept ) ) {
			return isset( $kept['percent'] ) ? (int) $kept['percent'] : null;
		}

		$reading = GuestMemory::fetch( $resource, $client, (string) ( $vm['node'] ?? '' ), (int) $vm['vmid'] );
		$percent = null;

		if ( null === $reading ) {
			GuestMemory::failed( $resource->id() );
		} else {
			GuestMemory::store( $resource->id(), $reading );
			$percent = GuestMemory::percent( $reading['used_mb'], $reading['total_mb'] );
		}

		// A failed read or a missing tag is kept as well, so an absent agent is asked once per window, not every poll.
		set_transient( $key, null === $percent ? array( 'failed' => true ) : array( 'percent' => $percent ), self::MEM_TTL );

		return $percent;
	}

	/**
	 * Percent used from the text of /proc/meminfo, or null when it lacks MemTotal or MemAvailable.
	 *
	 * @param string $text File content.
	 */
	public static function meminfo( string $text ): ?int {
		$reading = GuestMemory::parse( $text );

		return null === $reading ? null : GuestMemory::percent( $reading['used_mb'], $reading['total_mb'] );
	}

	/**
	 * Disk percent from the stored guest reading. A reading older than ten minutes is read again here, at most
	 * once in ten minutes per VM (the alert schedule of ProxmoxJobs is not changed).
	 *
	 * @param Resource $resource Resource.
	 */
	private static function disk( Resource $resource ): ?int {
		$key = self::DISK_PREFIX . $resource->id();

		if ( time() - (int) $resource->get( 'disk_read_at' ) >= self::DISK_TTL && false === get_transient( $key ) ) {
			set_transient( $key, 1, self::DISK_TTL );
			GuestDisk::read( $resource, true );
			$resource = Resource::find( $resource->id() ) ?? $resource;
		}

		$disk = GuestDisk::cached( $resource );

		return null === $disk ? null : self::percent( $disk['used'] / $disk['total'] * 100 );
	}

	private static function percent( float $value ): int {
		return max( 0, min( 100, (int) round( $value ) ) );
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
