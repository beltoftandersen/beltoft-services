<?php
/**
 * Live CPU, memory and disk of a customer's servers and hosting accounts, for the bars on the Servers list, the Overview and the hosting page.
 *
 * Power and CPU come from the guest adapter's listing (kept 15 seconds). Memory is the guest's own reading without the file
 * cache (never the hypervisor's figure), kept 10 seconds per guest; without an answer there is no figure. Every fresh
 * reading goes to GuestMemory (latest reading and history). The disk is the adapter's reading.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Monitoring;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class LiveUsage {
	/** Transient of a VM's guest memory reading. */
	public const MEM_PREFIX = 'beltoft_services_proxmox_memavail_';
	public const MEM_TTL = 10;
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
	 * GET usage: { resources: { <id>: { power, cpu, mem, disk, memory } } } for the current user's ready servers and hosting accounts. With ids only those.
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

		$by_guest = array();
		$guests   = Guests::all();

		foreach ( array_slice( Endpoint::licenses_for_user( $user_id ), 0, 100 ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || 'ready' !== $resource->get( 'state' ) || ! $resource->shows_status() || $resource->on_hold() ) {
				continue;
			}

			if ( null !== $only && ! in_array( $resource->id(), $only, true ) ) {
				continue;
			}

			foreach ( $guests as $index => $guest ) {
				if ( $guest->owns( $resource ) ) {
					$by_guest[ $index ][] = $resource;
					break;
				}
			}
		}

		$out = array();

		foreach ( $by_guest as $index => $resources ) {
			$guest = $guests[ $index ];
			$rows  = $guest->live_rows( $resources );

			foreach ( $resources as $resource ) {
				$row = $rows[ $resource->id() ] ?? null;

				if ( null === $row ) {
					continue;
				}

				$entry = array( 'power' => $row['power'], 'cpu' => null, 'mem' => null, 'disk' => null, 'memory' => array( 'state' => 'stopped' ) );

				if ( 'running' === $row['power'] ) {
					$disk = $guest->disk( $resource, true );

					$entry['cpu']    = self::percent( $row['cpu'] * 100 );
					$entry['mem']    = self::memory( $guest, $resource, $row['ref'] );
					$entry['disk']   = null === $disk || $disk['total'] <= 0 ? null : self::percent( $disk['used'] / $disk['total'] * 100 );
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
	 * Memory percent of a running guest: the guest's own reading (used without the file cache over total), kept 10 seconds, so
	 * several tabs share one reading; null when the guest cannot be read (the hypervisor's own figure counts the file cache, so it
	 * is never shown). A fresh reading is kept by GuestMemory (latest value, and a history row once in five minutes).
	 *
	 * @param Guest    $guest    Adapter.
	 * @param Resource $resource Resource.
	 * @param mixed    $ref      The adapter's handle of the guest.
	 */
	private static function memory( Guest $guest, Resource $resource, $ref ): ?int {
		$key  = self::MEM_PREFIX . $resource->id();
		$kept = get_transient( $key );

		if ( is_array( $kept ) ) {
			return isset( $kept['percent'] ) ? (int) $kept['percent'] : null;
		}

		$reading = $guest->memory( $resource, $ref );
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

	private static function percent( float $value ): int {
		return max( 0, min( 100, (int) round( $value ) ) );
	}
}
