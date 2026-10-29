<?php
/** Versioned, file-backed VPS app catalogue. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

final class Apps {
	public const JOB = '/var/lib/beltoft-app/job.json';
	public const STATUS = '/var/lib/beltoft-app/status.json';
	public const CREDENTIALS = '/root/.beltoft-app/credentials.json';

	/** A definition and its exact script checksum, or null when invalid. */
	public static function version( string $id, string $version_id, bool $existing = false ): ?array {
		if ( ! preg_match( '/^[a-z0-9-]+$/', $id ) || ! preg_match( '/^[a-zA-Z0-9.-]+$/', $version_id ) ) {
			return null;
		}
		$file = BELTOFT_SERVICES_PATH . 'apps/' . $id . '/app.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$data = apply_filters( 'beltoft_services_apps', $data, $id );
		if ( ! is_array( $data ) || ( $data['id'] ?? '' ) !== $id || ! isset( $data['versions'] ) || ! is_array( $data['versions'] ) ) {
			return null;
		}
		foreach ( $data['versions'] as $version ) {
			if ( ! is_array( $version ) || (string) ( $version['id'] ?? '' ) !== $version_id ) {
				continue;
			}
			if ( ! in_array( $version['status'] ?? '', array( 'current', 'available', 'retired' ), true ) || ( ! $existing && 'retired' === $version['status'] ) ) {
				return null;
			}
			$script = (string) ( $version['install'] ?? '' );
			if ( ! preg_match( '/^[a-zA-Z0-9.-]+\.script$/', $script ) || ! is_array( $version['os'] ?? null ) || ! is_array( $data['min'] ?? null ) ) {
				return null;
			}
			$path = BELTOFT_SERVICES_PATH . 'apps/' . $id . '/' . $version_id . '/' . $script;
			if ( ! is_readable( $path ) ) {
				return null;
			}
			$hash = hash_file( 'sha256', $path );
			if ( ! is_string( $version['sha256'] ?? null ) || ! hash_equals( $version['sha256'], $hash ) ) {
				return null;
			}
			$version['sha256'] = $hash;
			$base = (string) apply_filters( 'beltoft_services_app_base_url', plugins_url( 'apps/', BELTOFT_SERVICES_FILE ) );
			$version['url'] = set_url_scheme( trailingslashit( $base ) . $id . '/' . $version_id . '/' . $script, 'https' );
			return array_merge( $data, $version, array( 'version_id' => $version_id ) );
		}
		return null;
	}

	/** One current version is required; older supported versions remain selectable. */
	public static function current( string $id ): ?array {
		$file = BELTOFT_SERVICES_PATH . 'apps/' . $id . '/app.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$data = apply_filters( 'beltoft_services_apps', $data, $id );
		$matches = array_values( array_filter( (array) ( is_array( $data ) ? ( $data['versions'] ?? array() ) : array() ), static fn( $v ) => is_array( $v ) && 'current' === ( $v['status'] ?? '' ) ) );
		return 1 === count( $matches ) ? self::version( $id, (string) $matches[0]['id'] ) : null;
	}

	/** Refuse builds unless this cluster explicitly opted in and the plan fits. */
	public static function check( array $cluster, array $plan, string $id, string $version_id ): ?string {
		if ( empty( $cluster['apps_ready'] ) || ! in_array( $id, (array) ( $cluster['apps_enabled'] ?? array() ), true ) || ! preg_match( '#:snippets/vm-vendor-cloudinit-v2\.yaml$#', (string) ( $cluster['vendor_snippet'] ?? '' ) ) ) {
			return 'The app is not enabled on this cluster';
		}
		$app = self::version( $id, $version_id );
		if ( null === $app ) {
			return 'The app version is unavailable';
		}
		$families = (array) ( $cluster['template_families'] ?? array() );
		$family = (string) ( $families[ (int) ( $plan['template'] ?? 0 ) ] ?? '' );
		if ( ! in_array( $family, $app['os'], true ) ) {
			return 'The app does not support the selected operating system';
		}
		foreach ( array( 'cores', 'memory_mb', 'disk_gb' ) as $key ) {
			if ( (int) ( $plan[ $key ] ?? 0 ) < (int) ( $app['min'][ $key ] ?? 0 ) ) {
				return 'The server plan is below the app minimum for ' . $key;
			}
		}
		return null;
	}

	/** Read only one bounded guest file. Guest content is never trusted as a status without validation. */
	public static function read_file( Client $client, string $node, int $vmid, string $file ): ?string {
		$reply = $client->request( 'GET', '/nodes/' . rawurlencode( $node ) . '/qemu/' . $vmid . '/agent/file-read', array( 'file' => $file ) );
		if ( is_wp_error( $reply ) || ! is_array( $reply ) || ! empty( $reply['truncated'] ) || ! is_string( $reply['content'] ?? null ) || strlen( $reply['content'] ) > 65536 ) {
			return null;
		}
		return $reply['content'];
	}
}
