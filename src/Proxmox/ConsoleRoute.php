<?php
/** Customer REST request to open a server console. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Routes;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class ConsoleRoute {
	public const FLOOR_LIMIT = 20;
	public const FLOOR_WINDOW = 600;
	public const RATE_LIMIT = 6;

	public static function register(): void {
		Console::register();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/resources/(?P<id>\d+)/console', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'open' ),
			'permission_callback' => 'is_user_logged_in',
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
		) );
	}

	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		$response = rest_convert_error_to_response( new \WP_Error( $code, $message, array( 'status' => $status ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** A fresh ownership and power check is required before creating an upstream console. */
	public static function open( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		if ( RateLimit::exceeded( $user_id, 'beltoft-services:console-floor', self::FLOOR_LIMIT, self::FLOOR_WINDOW ) ) {
			$response = self::error( 'beltoft_services_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'beltoft-services' ), 429 );
			$response->header( 'Retry-After', '600' );
			return $response;
		}

		$r = Routes::guard( $request, true, self::RATE_LIMIT );
		if ( ! $r instanceof Resource ) {
			$r->header( 'Cache-Control', 'no-store' );
			return $r;
		}
		if ( ! Console::enabled() || 'proxmox' !== $r->get( 'driver' ) ) {
			return self::error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), 403 );
		}
		if ( ConsoleSessions::at_limit( $user_id, $r->id() ) ) {
			return self::error( 'beltoft_services_console_busy', __( 'A console is already being opened. Please wait a moment.', 'beltoft-services' ), 409 );
		}

		$record = VmRecords::for_resource( $r->id() );
		$cluster = $record ? Clusters::get( (string) $record['cluster'] ) : null;
		if ( ! $record || ! $cluster || null !== Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() ) ) {
			return self::error( 'beltoft_services_console_unavailable', __( 'The console is not available right now.', 'beltoft-services' ), 503 );
		}
		try {
			$client = ConsoleClient::for( $cluster );
		} catch ( \Throwable $e ) {
			return self::error( 'beltoft_services_console_unavailable', __( 'The console is not available right now.', 'beltoft-services' ), 503 );
		}
		$verified = Ownership::verify( $client, $record, $r );
		if ( is_wp_error( $verified ) ) {
			return self::error( 'beltoft_services_console_unavailable', __( 'The console is not available right now.', 'beltoft-services' ), 503 );
		}
		if ( 'running' !== $verified['status'] || '' !== $verified['lock'] ) {
			return self::error( 'beltoft_services_console_stopped', __( 'The console is not available while the server is stopped or busy.', 'beltoft-services' ), 409 );
		}

		$path = '/nodes/' . rawurlencode( $verified['node'] ) . '/qemu/' . (int) $record['vmid'] . '/vncproxy';
		$reply = $client->request( 'POST', $path, array( 'websocket' => 1, 'generate-password' => 1 ) );
		if ( is_wp_error( $reply ) || ! is_array( $reply ) || ! ctype_digit( (string) ( $reply['port'] ?? '' ) ) || empty( $reply['ticket'] ) || ! is_string( $reply['ticket'] ) ) {
			return self::error( 'beltoft_services_console_unavailable', __( 'The console is not available right now.', 'beltoft-services' ), 503 );
		}
		$port = (int) $reply['port'];
		if ( $port < 1 || $port > 65535 ) {
			return self::error( 'beltoft_services_console_unavailable', __( 'The console is not available right now.', 'beltoft-services' ), 503 );
		}
		$password = $reply['password'] ?? null;
		if ( ! is_string( $password ) || '' === $password ) {
			return self::error( 'beltoft_services_console_unavailable', __( 'The console is not available right now.', 'beltoft-services' ), 503 );
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$token = ConsoleSessions::create( $user_id, $r->id(), $ip, (string) $record['cluster'], (string) $verified['node'], (int) $record['vmid'], $port, $reply['ticket'] );
		if ( is_wp_error( $token ) ) {
			return self::error( 'beltoft_services_console_busy', __( 'A console is already being opened. Please wait a moment.', 'beltoft-services' ), 409 );
		}
		Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Console opened' );
		$response = new \WP_REST_Response( array(
			'path' => '/beltoft-console/ws/' . $token,
			'password' => $password,
			'expires_in' => ConsoleSessions::TTL,
			'transport' => $client instanceof FakeClient ? 'demo' : 'novnc',
		), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
