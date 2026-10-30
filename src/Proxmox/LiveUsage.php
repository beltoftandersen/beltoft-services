<?php
/**
 * Live CPU, memory and disk of a customer's servers, for the bars on the Servers list and the Overview.
 *
 * Power and CPU come from one /cluster/resources call per cluster, kept 15 seconds. Memory is the guest's own
 * MemAvailable (PVE counts page cache as used), read through the guest agent and kept 60 seconds per VM; without
 * an agent answer PVE's figure is used. Disk is the guest agent's filesystem reading, refreshed here at most once
 * in ten minutes per VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Api\Routes;
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
	public const MEM_TTL     = 60;
	public const DISK_TTL    = 10 * MINUTE_IN_SECONDS;
	/** Seconds between two refreshes in the browser. */
	public const INTERVAL = 30;
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

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/usage-live.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/usage-live.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesUsage',
			array(
				'url'      => esc_url_raw( rest_url( Routes::NAMESPACE . '/usage' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'interval' => self::INTERVAL * 1000,
			)
		);
	}

	/**
	 * GET usage: { resources: { <id>: { power, cpu, mem, disk } } } for the current user's ready servers.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$limited = Routes::limited( $request, Routes::STATUS_RATE_LIMIT );

		if ( $limited ) {
			return $limited;
		}

		$user_id = get_current_user_id();
		$out     = $user_id > 0 ? self::for_user( $user_id ) : array();
		$response = new \WP_REST_Response( array( 'resources' => (object) $out ), 200 );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * The readings of a user's ready, running or stopped proxmox servers (license owner only).
	 *
	 * @param int $user_id User id.
	 * @return array<int, array{power: string, cpu: ?int, mem: ?int, disk: ?int}>
	 */
	public static function for_user( int $user_id ): array {
		if ( \BeltoftServices\Settings::get( 'pause_all' ) || ! class_exists( Endpoint::class ) ) {
			return array();
		}

		$by_cluster = array();

		foreach ( array_slice( Endpoint::licenses_for_user( $user_id ), 0, 100 ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || 'proxmox' !== $resource->get( 'driver' ) || 'ready' !== $resource->get( 'state' ) || ! $resource->shows_status() || $resource->on_hold() ) {
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
				$entry   = array( 'power' => $power, 'cpu' => null, 'mem' => null, 'disk' => null );

				if ( 'running' === $power ) {
					$entry['cpu']  = self::percent( (float) ( $vm['cpu'] ?? 0 ) * 100 );
					$entry['mem']  = self::memory( $resource, $client, $vm );
					$entry['disk'] = self::disk( $resource );
				}

				$out[ $resource->id() ] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The memory percent kept for a server, while the reading is fresh; null otherwise. No Proxmox call.
	 *
	 * @param int $resource_id Resource id.
	 */
	public static function cached_memory( int $resource_id ): ?int {
		$kept = get_transient( self::MEM_PREFIX . $resource_id );

		return is_array( $kept ) && isset( $kept['percent'] ) ? (int) $kept['percent'] : null;
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
				ProxmoxJobs::remember_status( (int) $ours[ $vmid ], $vm );
			}
		}

		set_transient( self::ROWS_PREFIX . $cluster_id, $rows, self::ROWS_TTL );

		return $rows;
	}

	/**
	 * Memory percent of a running VM: the guest's (MemTotal - MemAvailable) / MemTotal, kept 60 seconds;
	 * PVE's mem over the plan's memory when the agent does not answer.
	 *
	 * @param Resource             $resource Resource.
	 * @param Client               $client   Client.
	 * @param array<string, mixed> $vm       The VM's cluster row.
	 */
	private static function memory( Resource $resource, Client $client, array $vm ): ?int {
		$key  = self::MEM_PREFIX . $resource->id();
		$kept = get_transient( $key );

		if ( is_array( $kept ) ) {
			return isset( $kept['percent'] ) ? (int) $kept['percent'] : self::pve_memory( $resource, $vm );
		}

		$node    = (string) ( $vm['node'] ?? '' );
		$percent = null;

		// The agent is only asked on a VM that carries this resource's tag (the same proof as every other VM access).
		if ( '' !== $node ) {
			$config = Ownership::config( $client, $node, (int) $vm['vmid'] );

			if ( ! is_wp_error( $config ) && Ownership::has_tag( (string) ( $config['description'] ?? '' ), Ownership::tag( $resource ) ) ) {
				$percent = self::guest_memory( $client, $node, (int) $vm['vmid'] );
			}
		}

		// A failed read or a missing tag is kept as well, so an absent agent is asked once a minute, not every poll.
		set_transient( $key, null === $percent ? array( 'failed' => true ) : array( 'percent' => $percent ), self::MEM_TTL );

		return $percent ?? self::pve_memory( $resource, $vm );
	}

	private static function pve_memory( Resource $resource, array $vm ): ?int {
		$plan   = (array) $resource->get( 'plan' );
		$memory = (int) ( $plan['memory_mb'] ?? 0 ) * 1048576;

		return $memory > 0 ? self::percent( (int) ( $vm['mem'] ?? 0 ) / $memory * 100 ) : null;
	}

	/**
	 * Reads /proc/meminfo through the guest agent.
	 *
	 * @param Client $client Client.
	 * @param string $node   Node.
	 * @param int    $vmid   VMID.
	 */
	private static function guest_memory( Client $client, string $node, int $vmid ): ?int {
		if ( '' === $node ) {
			return null;
		}

		$path   = '/nodes/' . rawurlencode( $node ) . '/qemu/' . $vmid . '/agent/file-read';
		$params = array( 'file' => '/proc/meminfo' );
		$reply  = $client instanceof LiveClient ? $client->request_with_timeout( 'GET', $path, $params, 5 ) : $client->request( 'GET', $path, $params );

		if ( is_wp_error( $reply ) || ! is_array( $reply ) || ! is_string( $reply['content'] ?? null ) ) {
			return null;
		}

		return self::meminfo( $reply['content'] );
	}

	/**
	 * Percent used from the text of /proc/meminfo, or null when it lacks MemTotal or MemAvailable.
	 *
	 * @param string $text File content.
	 */
	public static function meminfo( string $text ): ?int {
		if ( ! preg_match( '/^MemTotal:\s+(\d+)\s*kB/mi', $text, $total ) || ! preg_match( '/^MemAvailable:\s+(\d+)\s*kB/mi', $text, $available ) ) {
			return null;
		}

		$total     = (int) $total[1];
		$available = (int) $available[1];

		return $total > 0 && $available <= $total ? self::percent( ( $total - $available ) / $total * 100 ) : null;
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
