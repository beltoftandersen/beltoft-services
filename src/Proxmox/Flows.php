<?php
/**
 * The signed per-server connection counts from the host, and the signals read from them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * POST beltoft-services/v1/flows, sent every minute by the collector on the host: `{"v":1,"at":<unix>,"sources":{"<ipv4>":
 * {"entries","dsts","dports","unreplied","udp_dsts"}}}`, counts only, signed with `X-Beltoft-Signature` (hex
 * HMAC-SHA256 of the raw body with the wp-config constant BELTOFT_SERVICES_FLOW_SECRET). Without the constant the
 * route refuses everything. Checked in this order: the sending address (setting `flow_sources`), the body size, the
 * signature, the shape, the time (120 s), and a time not seen before (replay).
 *
 * Each address is mapped to its server through the IP pool (assigned addresses only; an unknown address, a server that
 * is not ready and an adopted (linked) server are ignored). The last 60 samples per server are kept in a transient. A
 * signal (scanning: addresses or ports; SYN flood: unanswered attempts; UDP flood: UDP destinations) counts when every one
 * of the last N one-minute samples is over its limit; a gap of more than 150 seconds between samples starts the count again.
 *
 * The mode (`traffic_quarantine`): off does nothing, report mails the alert address once per episode (re-armed after
 * six quiet hours), on also starts the network quarantine (Quarantine).
 */
final class Flows {

	public const ROUTE  = '/flows';
	public const SECRET = 'BELTOFT_SERVICES_FLOW_SECRET';

	public const MAX_BYTES   = 65536;
	public const MAX_SOURCES = 64;
	public const WINDOW      = 120;
	public const KEEP        = 60;
	public const MAX_GAP     = 150;
	public const REARM       = 6 * HOUR_IN_SECONDS;

	public const SEEN_TRANSIENT   = 'beltoft_services_flows_seen';
	public const SAMPLE_PREFIX    = 'beltoft_services_flows_';
	private const SEEN_KEEP       = 10;
	private const COUNTS          = array( 'entries', 'dsts', 'dports', 'unreplied', 'udp_dsts' );
	private const MAX_COUNT       = 2147483647;
	public const SIGNALS          = array( 'scan', 'syn', 'udp' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the route: no login, the signature is the permission.
	 */
	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => array( __CLASS__, 'permit' ),
			)
		);
	}

	/**
	 * The shared secret, or '' when the constant is not set.
	 */
	private static function secret(): string {
		return defined( self::SECRET ) && is_string( constant( self::SECRET ) ) ? (string) constant( self::SECRET ) : '';
	}

	/**
	 * The addresses the counts may come from.
	 *
	 * @return string[]
	 */
	private static function sources(): array {
		return array_values( array_filter( preg_split( '/[\s,]+/', (string) Settings::get( 'flow_sources' ), -1, PREG_SPLIT_NO_EMPTY ) ) );
	}

	/**
	 * The permission: sender, size, signature, shape, time, replay.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permit( \WP_REST_Request $request ) {
		$secret = self::secret();
		$refuse = new \WP_Error( 'beltoft_services_forbidden', __( 'Not allowed.', 'beltoft-services' ), array( 'status' => 403 ) );

		if ( '' === $secret ) {
			return $refuse;
		}

		$from = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! in_array( $from, self::sources(), true ) ) {
			return $refuse;
		}

		$body = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new \WP_Error( 'beltoft_services_too_large', __( 'Not allowed.', 'beltoft-services' ), array( 'status' => 413 ) );
		}

		$given = strtolower( trim( (string) $request->get_header( 'x-beltoft-signature' ) ) );

		if ( '' === $given || ! hash_equals( hash_hmac( 'sha256', $body, $secret ), $given ) ) {
			return $refuse;
		}

		$data = self::parse( $body );

		if ( null === $data ) {
			return new \WP_Error( 'beltoft_services_bad_request', __( 'Invalid request.', 'beltoft-services' ), array( 'status' => 400 ) );
		}

		if ( abs( time() - $data['at'] ) > self::WINDOW ) {
			return new \WP_Error( 'beltoft_services_stale', __( 'Invalid request.', 'beltoft-services' ), array( 'status' => 400 ) );
		}

		$seen = get_transient( self::SEEN_TRANSIENT );
		$seen = is_array( $seen ) ? array_map( 'intval', $seen ) : array();

		if ( in_array( $data['at'], $seen, true ) ) {
			return new \WP_Error( 'beltoft_services_replay', __( 'Invalid request.', 'beltoft-services' ), array( 'status' => 409 ) );
		}

		$seen[] = $data['at'];
		set_transient( self::SEEN_TRANSIENT, array_slice( $seen, -self::SEEN_KEEP ), HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * The strict shape: v 1, an integer time, at most 64 IPv4 sources with the five counts as non-negative integers.
	 *
	 * @param string $body Raw body.
	 * @return array{at: int, sources: array<string, array<string, int>>}|null
	 */
	public static function parse( string $body ): ?array {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || 3 !== count( $data ) || ! isset( $data['v'], $data['at'], $data['sources'] ) ) {
			return null;
		}

		if ( 1 !== $data['v'] || ! is_int( $data['at'] ) || $data['at'] <= 0 || ! is_array( $data['sources'] ) || count( $data['sources'] ) > self::MAX_SOURCES ) {
			return null;
		}

		$sources = array();

		foreach ( $data['sources'] as $ip => $counts ) {
			$ip = (string) $ip;

			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || ! is_array( $counts ) || count( $counts ) !== count( self::COUNTS ) ) {
				return null;
			}

			foreach ( self::COUNTS as $key ) {
				if ( ! isset( $counts[ $key ] ) || ! is_int( $counts[ $key ] ) || $counts[ $key ] < 0 || $counts[ $key ] > self::MAX_COUNT ) {
					return null;
				}
			}

			$sources[ $ip ] = array_intersect_key( $counts, array_flip( self::COUNTS ) );
		}

		return array( 'at' => $data['at'], 'sources' => $sources );
	}

	/**
	 * The route: stores the samples and judges each server.
	 *
	 * @param \WP_REST_Request $request Request (already permitted).
	 */
	public static function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::parse( (string) $request->get_body() );
		$used = 0;

		if ( null !== $data && 'off' !== (string) Settings::get( 'traffic_quarantine' ) ) {
			foreach ( $data['sources'] as $ip => $counts ) {
				$resource = self::resource_of( $ip );

				if ( $resource ) {
					self::ingest( $resource, $data['at'], $counts );
					++$used;
				}
			}
		}

		$response = new \WP_REST_Response( array( 'ok' => true, 'used' => $used ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The server behind an address: an assigned pool address of a ready server that is not adopted. Else null.
	 *
	 * @param string $ip Address.
	 */
	private static function resource_of( string $ip ): ?Resource {
		$row = IpPool::find( $ip );

		if ( null === $row || 'assigned' !== $row['state'] || empty( $row['resource_id'] ) ) {
			return null;
		}

		$r = Resource::find( (int) $row['resource_id'] );

		if ( ! $r || SshKeyField::DRIVER !== $r->get( 'driver' ) || 'ready' !== $r->get( 'state' ) || true === $r->get( 'linked' ) ) {
			return null;
		}

		return $r;
	}

	/**
	 * Keeps one sample and judges the server.
	 *
	 * @param Resource           $r      Server.
	 * @param int                $at     Time of the sample.
	 * @param array<string, int> $counts Counts.
	 */
	public static function ingest( Resource $r, int $at, array $counts ): void {
		$key     = self::SAMPLE_PREFIX . $r->id();
		$samples = get_transient( $key );
		$samples = is_array( $samples ) ? $samples : array();

		$samples[] = array( 'at' => $at ) + $counts;
		usort( $samples, static fn( $a, $b ) => $a['at'] <=> $b['at'] );
		$samples = array_slice( $samples, -self::KEEP );

		set_transient( $key, $samples, 2 * HOUR_IN_SECONDS );

		self::judge( $r, $samples, $at );
	}

	/**
	 * The samples kept for a server (oldest first).
	 *
	 * @param int $resource_id Resource id.
	 * @return array<int, array<string, int>>
	 */
	public static function samples( int $resource_id ): array {
		$samples = get_transient( self::SAMPLE_PREFIX . $resource_id );

		return is_array( $samples ) ? $samples : array();
	}

	/**
	 * Does a sample show a signal?
	 *
	 * @param string             $signal scan, syn or udp.
	 * @param array<string, int> $sample Sample.
	 */
	private static function over( string $signal, array $sample ): bool {
		switch ( $signal ) {
			case 'scan':
				return (int) $sample['dsts'] > (int) Settings::get( 'flow_scan_dsts' ) || (int) $sample['dports'] > (int) Settings::get( 'flow_scan_dports' );
			case 'syn':
				return (int) $sample['unreplied'] > (int) Settings::get( 'flow_syn_unreplied' );
			case 'udp':
				return (int) $sample['udp_dsts'] > (int) Settings::get( 'flow_udp_dsts' );
		}

		return false;
	}

	/**
	 * Is the signal over its limit in each of the last N one-minute samples, without a gap?
	 *
	 * @param string                           $signal  scan, syn or udp.
	 * @param array<int, array<string, int>>   $samples Samples, oldest first.
	 */
	public static function sustained( string $signal, array $samples ): bool {
		$need = max( 1, (int) Settings::get( 'flow_minutes' ) );

		if ( count( $samples ) < $need ) {
			return false;
		}

		$tail = array_slice( $samples, -$need );

		foreach ( $tail as $i => $sample ) {
			if ( ! self::over( $signal, $sample ) || ( $i > 0 && $sample['at'] - $tail[ $i - 1 ]['at'] > self::MAX_GAP ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Judges a server by its samples: an alert once per episode (re-armed after six quiet hours), and in mode on the
	 * quarantine.
	 *
	 * @param Resource                       $r       Server.
	 * @param array<int, array<string, int>> $samples Samples, oldest first.
	 * @param int                            $now     Time.
	 */
	private static function judge( Resource $r, array $samples, int $now ): void {
		$mode  = (string) Settings::get( 'traffic_quarantine' );
		$last  = $samples[ count( $samples ) - 1 ];
		$names = array(
			'scan' => 'scanning',
			'syn'  => 'SYN flood',
			'udp'  => 'UDP flood',
		);

		$present = array();

		foreach ( self::SIGNALS as $signal ) {
			$present[ $signal ] = self::sustained( $signal, $samples );
		}

		$r->locked(
			static function () use ( $r, $present, $last, $now, $mode, $names ): bool {
				if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) ) {
					return false;
				}

				$counts = array_intersect_key( $last, array_flip( self::COUNTS ) );
				$meta   = array();
				$first  = '';

				foreach ( $present as $signal => $here ) {
					$notified = (int) $r->get( 'flow_' . $signal . '_notified' );
					$clear    = (int) $r->get( 'flow_' . $signal . '_clear' );

					if ( $here ) {
						$first = '' === $first ? $signal : $first;

						if ( $clear > 0 ) {
							$meta[ 'flow_' . $signal . '_clear' ] = null;
						}

						if ( 0 === $notified ) {
							$measured = sprintf( '%s in each of the last %d minutes. Latest minute (%s UTC): %s', $names[ $signal ], (int) Settings::get( 'flow_minutes' ), gmdate( 'H:i', $last['at'] ), self::counts_text( $counts ) );

							if ( Alerts::flow( $r, $signal, $measured, $mode ) ) {
								$meta[ 'flow_' . $signal . '_notified' ] = $now;
								Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Connection signal (' . $signal . '): ' . $measured );
							}
						}
					} elseif ( $notified > 0 ) {
						if ( 0 === $clear ) {
							$meta[ 'flow_' . $signal . '_clear' ] = $now;
						} elseif ( $now - $clear >= self::REARM ) {
							$meta[ 'flow_' . $signal . '_notified' ] = null;
							$meta[ 'flow_' . $signal . '_clear' ]    = null;
						}
					}
				}

				$stored = array() === $meta || $r->set( $meta );

				if ( 'on' === $mode && '' !== $first ) {
					$stored = Quarantine::begin( $r, $first, $counts, $now ) || $stored;
				}

				return $stored;
			}
		);
	}

	/**
	 * The counts of a sample as text.
	 *
	 * @param array<string, int> $counts Counts.
	 */
	private static function counts_text( array $counts ): string {
		return sprintf(
			'%d connections, %d destination addresses, %d destination ports, %d unanswered attempts, %d UDP destinations',
			(int) $counts['entries'],
			(int) $counts['dsts'],
			(int) $counts['dports'],
			(int) $counts['unreplied'],
			(int) $counts['udp_dsts']
		);
	}
}
