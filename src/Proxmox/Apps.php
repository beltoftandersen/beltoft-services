<?php
/** Versioned, file-backed VPS app catalogue. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

final class Apps {
	public const JOB = '/var/lib/beltoft-app/job.json';
	public const STATUS = '/var/lib/beltoft-app/status.json';
	public const CREDENTIALS = '/root/.beltoft-app/credentials.json';

	/** The operating system family every v1 app runs on. */
	public const FAMILY = 'ubuntu-24.04';

	/** App ids shipped with the plugin, by directory name. */
	public static function ids(): array {
		$ids = array();
		foreach ( (array) glob( BELTOFT_SERVICES_PATH . 'apps/*/app.json' ) as $file ) {
			$id = basename( dirname( $file ) );
			if ( preg_match( '/^[a-z0-9-]+$/D', $id ) ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return $ids;
	}

	/** The app's definition, without choosing a version. */
	public static function definition( string $id ): ?array {
		if ( ! preg_match( '/^[a-z0-9-]+$/D', $id ) ) {
			return null;
		}
		$file = BELTOFT_SERVICES_PATH . 'apps/' . $id . '/app.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$data = apply_filters( 'beltoft_services_apps', $data, $id );
		return is_array( $data ) && ( $data['id'] ?? '' ) === $id && is_array( $data['versions'] ?? null ) ? $data : null;
	}

	/** The template that carries the app operating system on this cluster (VMID), or 0 when there is none. */
	public static function os_template( array $cluster ): int {
		foreach ( (array) ( $cluster['template_families'] ?? array() ) as $vmid => $family ) {
			if ( self::FAMILY === $family && isset( ( (array) ( $cluster['templates'] ?? array() ) )[ (int) $vmid ] ) ) {
				return (int) $vmid;
			}
		}
		return 0;
	}

	/**
	 * The operating system families some app versions run on (their "os" lists), in order, each once.
	 *
	 * @param array<int|string, mixed> $versions Full versions.
	 * @return array<int, string>
	 */
	public static function families( array $versions ): array {
		$out = array();
		foreach ( $versions as $version ) {
			foreach ( (array) ( is_array( $version ) ? ( $version['os'] ?? array() ) : array() ) as $family ) {
				if ( is_string( $family ) && '' !== $family && ! in_array( $family, $out, true ) ) {
					$out[] = $family;
				}
			}
		}
		return $out;
	}

	/**
	 * "Runs on Ubuntu 24.04", or "Runs on Ubuntu 24.04 or Debian 12".
	 *
	 * @param array<string, mixed> $cluster  Cluster.
	 * @param array<int, string>   $families Families.
	 */
	public static function runs_on( array $cluster, array $families ): string {
		$list = self::os_names( $cluster, $families );
		/* translators: %s: operating systems such as Ubuntu 24.04 */
		return '' === $list ? '' : sprintf( __( 'Runs on %s', 'beltoft-services' ), $list );
	}

	/**
	 * What the app tile's script says when a chosen system drops the app: "WordPress runs on Ubuntu 24.04, so no app is selected now."
	 *
	 * @param array<string, mixed> $cluster  Cluster.
	 * @param array<int, string>   $families Families.
	 * @param string               $name     App name.
	 */
	public static function dropped_text( array $cluster, array $families, string $name ): string {
		$list = self::os_names( $cluster, $families );
		/* translators: 1: app name, 2: operating systems such as Ubuntu 24.04 */
		return '' === $list ? '' : sprintf( __( '%1$s runs on %2$s, so no app is selected now.', 'beltoft-services' ), $name, $list );
	}

	/**
	 * "Ubuntu 24.04" or "Ubuntu 24.04 or Debian 12": names from the cluster's template labels.
	 *
	 * @param array<string, mixed> $cluster  Cluster.
	 * @param array<int, string>   $families Families.
	 */
	private static function os_names( array $cluster, array $families ): string {
		$labels = (array) ( $cluster['templates'] ?? array() );
		$names  = array();
		foreach ( $families as $family ) {
			$name = '';
			foreach ( (array) ( $cluster['template_families'] ?? array() ) as $vmid => $of ) {
				if ( $of === $family && isset( $labels[ (int) $vmid ] ) ) {
					$name = (string) preg_replace( '/\s+LTS$/i', '', trim( (string) $labels[ (int) $vmid ] ) );
					break;
				}
			}
			$names[] = '' !== $name ? $name : ucwords( str_replace( '-', ' ', $family ) );
		}
		if ( array() === $names ) {
			return '';
		}
		$last = array_pop( $names );
		/* translators: 1: operating systems separated by commas, 2: the last one */
		return array() === $names ? $last : sprintf( __( '%1$s or %2$s', 'beltoft-services' ), implode( ', ', $names ), $last );
	}

	/** Are apps on offer at all on this cluster: the flag, the v2 snippet and an Ubuntu 24.04 template. */
	public static function cluster_ready( array $cluster ): bool {
		return ! empty( $cluster['apps_ready'] ) && 1 === preg_match( '#:snippets/vm-vendor-cloudinit-v2\.yaml$#', (string) ( $cluster['vendor_snippet'] ?? '' ) ) && self::os_template( $cluster ) > 0;
	}

	/**
	 * What checkout offers on a cluster: apps that are enabled there, each with its versions that can be
	 * ordered (current first, then the others in file order). Retired versions are never listed.
	 *
	 * @return array<string, array<string, mixed>> App id => definition plus `choices` (version id => version).
	 */
	public static function offered( array $cluster ): array {
		if ( ! self::cluster_ready( $cluster ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( $cluster['apps_enabled'] ?? array() ) as $id ) {
			$data = is_string( $id ) ? self::definition( $id ) : null;
			if ( null === $data ) {
				continue;
			}
			$current = self::current( $id );
			if ( null === $current ) {
				continue;
			}
			$choices = array( $current['version_id'] => $current );
			foreach ( $data['versions'] as $version ) {
				$vid = is_array( $version ) ? (string) ( $version['id'] ?? '' ) : '';
				if ( '' === $vid || isset( $choices[ $vid ] ) ) {
					continue;
				}
				$full = self::version( $id, $vid );
				if ( null !== $full ) {
					$choices[ $vid ] = $full;
				}
			}
			unset( $data['versions'] );
			$out[ $id ] = $data + array( 'choices' => $choices );
		}
		return $out;
	}

	/** The plan name an app version needs, for customer text. */
	public static function plan_name( array $version ): string {
		return ucfirst( (string) ( $version['min_plan'] ?? '' ) );
	}

	/**
	 * Why a plan cannot run an app version, in words for the customer; null when it can.
	 *
	 * @param array<string, mixed> $plan Plan (cores, memory_mb, disk_gb).
	 */
	public static function plan_refusal( array $version, array $plan ): ?string {
		foreach ( array( 'cores', 'memory_mb', 'disk_gb' ) as $key ) {
			if ( (int) ( $plan[ $key ] ?? 0 ) >= (int) ( $version['min'][ $key ] ?? 0 ) ) {
				continue;
			}
			$name = self::plan_name( $version );
			if ( '' !== $name ) {
				/* translators: 1: app name, 2: plan name such as Performance */
				return sprintf( __( '%1$s needs the %2$s plan or larger. Choose a larger plan or no app.', 'beltoft-services' ), (string) $version['name'], $name );
			}
			/* translators: 1: app name, 2: CPU cores, 3: memory in GB, 4: disk in GB */
			return sprintf( __( '%1$s needs at least %2$d CPU cores, %3$s GB of memory and %4$d GB of disk. Choose a larger plan or no app.', 'beltoft-services' ), (string) $version['name'], (int) $version['min']['cores'], (string) round( (int) $version['min']['memory_mb'] / 1024, 1 ), (int) $version['min']['disk_gb'] );
		}
		return null;
	}

	/** One line for a version in a list: its label, and the end of support when it has a date. */
	public static function version_label( array $version ): string {
		$label = (string) ( $version['label'] ?? $version['version_id'] ?? '' );
		$eol   = (string) ( $version['eol'] ?? '' );
		if ( '' === $eol ) {
			return $label;
		}
		$time = strtotime( $eol . ( 1 === preg_match( '/^\d{4}-\d{2}$/D', $eol ) ? '-01' : '' ) );
		/* translators: 1: version label, 2: end of support month and year */
		return false === $time ? $label : sprintf( __( '%1$s (supported until %2$s)', 'beltoft-services' ), $label, wp_date( 'F Y', $time ) );
	}

	/** A definition and its exact script checksum, or null when invalid. */
	public static function version( string $id, string $version_id, bool $existing = false ): ?array {
		if ( ! preg_match( '/^[a-z0-9-]+$/D', $id ) || ! preg_match( '/^[a-zA-Z0-9.-]+$/D', $version_id ) ) {
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
			if ( ! preg_match( '/^[a-zA-Z0-9.-]+\.script$/D', $script ) || ! is_array( $version['os'] ?? null ) || ! is_array( $data['min'] ?? null ) ) {
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
