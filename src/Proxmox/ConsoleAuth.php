<?php
/** Internal authorization for the console websocket proxy. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class ConsoleAuth {
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'handle' ), 0 );
	}

	/** The web server's internal location must supply a per-site shared marker. */
	public static function internal( string $provided ): bool {
		$expected = defined( 'BELTOFT_SERVICES_CONSOLE_SECRET' ) ? constant( 'BELTOFT_SERVICES_CONSOLE_SECRET' ) : '';
		return is_string( $expected ) && strlen( $expected ) >= 32 && '' !== $provided && hash_equals( $expected, $provided );
	}

	/**
	 * Returns only internal proxy headers. The upstream ticket is consumed after all local checks.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function authorize( string $token, int $user_id, string $ip ) {
		if ( ! Console::enabled() ) {
			return new \WP_Error( 'console_forbidden', 'The console is not available.', array( 'status' => 403 ) );
		}
		$entry = ConsoleSessions::peek( $token, $user_id, $ip );
		if ( null === $entry ) {
			return new \WP_Error( 'console_forbidden', 'The console is not available.', array( 'status' => 403 ) );
		}
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
		if ( ! self::same_origin( $origin ) ) {
			return new \WP_Error( 'console_forbidden', 'The console is not available.', array( 'status' => 403 ) );
		}
		$r = Resource::find( (int) $entry['resource'] );
		$license = $r ? $r->license() : array();
		$record = $r ? VmRecords::for_resource( $r->id() ) : null;
		if ( ! $r || ! $r->shows_status() || ! $r->accepts_actions() || (int) ( $license['user_id'] ?? 0 ) !== $user_id || ! $record || (string) $record['cluster'] !== (string) $entry['cluster'] || (string) $record['node'] !== (string) $entry['node'] || (int) $record['vmid'] !== (int) $entry['vmid'] ) {
			return new \WP_Error( 'console_forbidden', 'The console is not available.', array( 'status' => 403 ) );
		}
		$cluster = Clusters::get( (string) $record['cluster'] );
		if ( ! $cluster || null !== Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() ) ) {
			return new \WP_Error( 'console_unavailable', 'The console is not available.', array( 'status' => 503 ) );
		}
		$live = 'live' === ( $cluster['client'] ?? 'fake' );
		if ( $live && ! ConsoleClient::configured( $cluster ) ) {
			return new \WP_Error( 'console_unavailable', 'The console is not available.', array( 'status' => 503 ) );
		}
		if ( $live && ( '' === (string) ( $cluster['ca_file'] ?? '' ) && '' === (string) ( $cluster['pin_sha256'] ?? '' ) ) ) {
			return new \WP_Error( 'console_unavailable', 'The console is not available.', array( 'status' => 503 ) );
		}
		$url = wp_parse_url( (string) ( $cluster['api_url'] ?? '' ) );
		$host = is_array( $url ) ? (string) ( $url['host'] ?? '' ) : '';
		$port = is_array( $url ) ? (int) ( $url['port'] ?? 8006 ) : 0;
		$dns_host = 1 === preg_match( '/^[A-Za-z0-9.-]+$/D', $host );
		$ipv6_host = 1 === preg_match( '/^\[[a-fA-F0-9:]+\]$/D', $host ) && false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		if ( ( $live && 'https' !== ( $url['scheme'] ?? '' ) ) || ( ! $dns_host && ! $ipv6_host ) || $port < 1 || $port > 65535 || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) || ( isset( $url['path'] ) && ! in_array( $url['path'], array( '', '/' ), true ) ) ) {
			return new \WP_Error( 'console_unavailable', 'The console is not available.', array( 'status' => 503 ) );
		}
		if ( $live && preg_match( '/[\r\n]/', (string) $cluster['console_token_id'] . (string) $cluster['console_token_secret'] ) ) {
			return new \WP_Error( 'console_unavailable', 'The console is not available.', array( 'status' => 503 ) );
		}
		$ticket = ConsoleSessions::consume( $entry );
		if ( null === $ticket ) {
			return new \WP_Error( 'console_forbidden', 'The console is not available.', array( 'status' => 403 ) );
		}
		$uri = '/api2/json/nodes/' . rawurlencode( (string) $entry['node'] ) . '/qemu/' . (int) $entry['vmid'] . '/vncwebsocket?port=' . (int) $entry['port'] . '&vncticket=' . rawurlencode( $ticket );
		return array(
			'X-Console-Target' => $host . ':' . $port,
			'X-Console-Uri' => $uri,
			'X-Console-Auth' => $live ? 'PVEAPIToken=' . $cluster['console_token_id'] . '=' . $cluster['console_token_secret'] : '',
			'X-Console-Resource' => (string) $r->id(),
			'X-Console-User' => (string) $user_id,
		);
	}

	/** Reject websocket handshakes initiated from another site or without an Origin. */
	private static function same_origin( string $origin ): bool {
		$site = wp_parse_url( home_url() );
		$sent = wp_parse_url( $origin );
		if ( ! is_array( $site ) || ! is_array( $sent ) || ! isset( $site['scheme'], $site['host'], $sent['scheme'], $sent['host'] ) || isset( $sent['user'] ) || isset( $sent['pass'] ) || isset( $sent['path'] ) || isset( $sent['query'] ) || isset( $sent['fragment'] ) ) {
			return false;
		}
		$site_port = (int) ( $site['port'] ?? ( 'https' === $site['scheme'] ? 443 : 80 ) );
		$sent_port = (int) ( $sent['port'] ?? ( 'https' === $sent['scheme'] ? 443 : 80 ) );
		return in_array( $sent['scheme'], array( 'http', 'https' ), true ) && strtolower( $site['scheme'] ) === strtolower( $sent['scheme'] ) && strtolower( $site['host'] ) === strtolower( $sent['host'] ) && $site_port === $sent_port;
	}

	/** The public path always refuses unless nginx's internal location provided the marker. */
	public static function handle(): void {
		if ( ! isset( $_GET['beltoft-console-auth'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- internal authentication endpoint.
			return;
		}
		nocache_headers();
		$provided = isset( $_SERVER['HTTP_X_CONSOLE_INTERNAL'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_CONSOLE_INTERNAL'] ) : '';
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::internal( $provided ) ) {
			status_header( 403 );
			exit;
		}
		$token = isset( $_SERVER['HTTP_X_CONSOLE_TICKET'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_CONSOLE_TICKET'] ) : '';
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$reply = self::authorize( $token, get_current_user_id(), $ip );
		if ( is_wp_error( $reply ) ) {
			status_header( (int) ( $reply->get_error_data()['status'] ?? 403 ) );
			exit;
		}
		foreach ( $reply as $name => $value ) {
			header( $name . ': ' . $value, true );
		}
		status_header( 200 );
		exit;
	}
}
