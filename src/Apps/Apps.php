<?php
/** Versioned, file-backed VPS app catalogue. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Proxmox\Pve\Client;

defined( 'ABSPATH' ) || exit;

final class Apps {
	public const JOB = '/var/lib/beltoft-app/job.json';
	public const STATUS = '/var/lib/beltoft-app/status.json';
	public const CREDENTIALS = '/root/.beltoft-app/credentials.json';

	/** The operating system family most apps run on, and the one a cluster is asked for first. An app version lists its own families in "os". */
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

	/**
	 * A text of an app definition for the customer: translated through the plugin's strings (AppTexts), then passed through the filter
	 * beltoft_services_app_text( string $text, string $app_id, string $field ). Fields: summary, getting_started.
	 *
	 * @param string $text   Text as app.json has it.
	 * @param string $id     App id.
	 * @param string $field  Field.
	 */
	public static function text( string $text, string $id, string $field ): string {
		return (string) apply_filters( 'beltoft_services_app_text', AppTexts::translate( $text ), $id, $field );
	}

	/**
	 * The customer-visible texts of a definition (summary and getting started; port labels are technical and stored in firewall rules, so they stay as they are) in the current language.
	 *
	 * @param mixed  $data Definition.
	 * @param string $id   App id.
	 * @return mixed
	 */
	public static function localise( $data, string $id ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( array( 'summary', 'getting_started' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				$data[ $field ] = self::text( $data[ $field ], $id, $field );
			}
		}

		return $data;
	}

	/** The app's definition, without choosing a version. */
	public static function definition( string $id ): ?array {
		if ( ! preg_match( '/^[a-z0-9-]+$/D', $id ) ) {
			return null;
		}
		$file = BELTOFT_SERVICES_PATH . 'apps/' . $id . '/app.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$data = self::localise( apply_filters( 'beltoft_services_apps', $data, $id ), $id );
		return is_array( $data ) && ( $data['id'] ?? '' ) === $id && is_array( $data['versions'] ?? null ) ? $data : null;
	}

	/** The template of an operating system family on this cluster (VMID, the first listed), or 0 when there is none. */
	public static function os_template( array $cluster, string $family = self::FAMILY ): int {
		foreach ( (array) ( $cluster['template_families'] ?? array() ) as $vmid => $of ) {
			if ( $family === $of && isset( ( (array) ( $cluster['templates'] ?? array() ) )[ (int) $vmid ] ) ) {
				return (int) $vmid;
			}
		}
		return 0;
	}

	/**
	 * The template an app version is installed on: the preferred one (what the customer chose) when the version runs on its
	 * family, else the first of the version's families that has a template here (in the order of its "os" list). 0 when none.
	 *
	 * @param array<string, mixed> $cluster Cluster.
	 * @param array<string, mixed> $version Full version.
	 * @param int                  $prefer  Template the customer chose, or 0.
	 */
	public static function version_template( array $cluster, array $version, int $prefer = 0 ): int {
		// A system other than Ubuntu needs the vendor snippet v4 (v2 and v3 cannot install on it): such a family is not available on an older cluster.
		$families = array_values( array_filter( (array) ( $version['os'] ?? array() ), static fn( $f ) => is_string( $f ) && self::family_supported( $cluster, $f ) ) );
		$mapped   = (array) ( $cluster['template_families'] ?? array() );

		if ( $prefer > 0 && isset( ( (array) ( $cluster['templates'] ?? array() ) )[ $prefer ] ) && in_array( (string) ( $mapped[ $prefer ] ?? '' ), $families, true ) ) {
			return $prefer;
		}

		foreach ( $families as $family ) {
			$vmid = is_string( $family ) ? self::os_template( $cluster, $family ) : 0;

			if ( $vmid > 0 ) {
				return $vmid;
			}
		}

		return 0;
	}

	/** The vendor snippet level of a cluster: 4 for vm-vendor-cloudinit-v4.yaml, 3 for v3, 2 for v2, 0 for anything else. */
	public static function snippet_level( array $cluster ): int {
		return 1 === preg_match( '#:snippets/vm-vendor-cloudinit-v([234])\.yaml$#', (string) ( $cluster['vendor_snippet'] ?? '' ), $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Can apps of this system family be installed with the cluster's vendor snippet? Ubuntu needs v2 or later, every other system v4:
	 * v3 waits for the job file with `jq -e`, which on AlmaLinux 9 (jq 1.6) passes on the still empty file, so the job is read before the guest agent wrote it.
	 */
	public static function family_supported( array $cluster, string $family ): bool {
		$level = self::snippet_level( $cluster );

		return str_starts_with( $family, 'ubuntu-' ) ? $level >= 2 : $level >= 4;
	}

	/** The family of a template on this cluster, or ''. */
	public static function family_of( array $cluster, int $vmid ): string {
		return (string) ( ( (array) ( $cluster['template_families'] ?? array() ) )[ $vmid ] ?? '' );
	}

	/** Every operating system family any shipped app version runs on. */
	private static function known_families(): array {
		$out = array();
		foreach ( self::ids() as $id ) {
			$def = self::definition( $id );
			$out = array_merge( $out, self::families( (array) ( $def['versions'] ?? array() ) ) );
		}
		return array_values( array_unique( $out ) );
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
	 * Why an app version cannot be installed with the system chosen: "This app needs Ubuntu 24.04 and cannot be installed here ...".
	 *
	 * @param array<string, mixed> $cluster Cluster.
	 * @param array<string, mixed> $version Full version.
	 */
	public static function needs_system_text( array $cluster, array $version ): string {
		$list = self::os_names( $cluster, array_values( array_filter( (array) ( $version['os'] ?? array() ), 'is_string' ) ) );
		/* translators: %s: operating systems such as Ubuntu 24.04 or AlmaLinux 9 */
		return sprintf( __( 'This app needs %1$s and cannot be installed here with the chosen system. Choose %1$s, or no app.', 'beltoft-services' ), $list );
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

	/** Are apps on offer at all on this cluster: the flag, the v2 or later snippet and a template of a family some app runs on (Ubuntu 24.04, AlmaLinux 9). */
	public static function cluster_ready( array $cluster ): bool {
		if ( empty( $cluster['apps_ready'] ) || self::snippet_level( $cluster ) < 2 ) {
			return false;
		}
		foreach ( self::known_families() as $family ) {
			if ( self::family_supported( $cluster, $family ) && self::os_template( $cluster, $family ) > 0 ) {
				return true;
			}
		}
		return false;
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
			// A version whose system has no template on this cluster cannot be ordered here.
			$choices = array_filter( $choices, static fn( $v ) => self::version_template( $cluster, (array) $v ) > 0 );
			// A version marked "default" in app.json (Plesk on AlmaLinux 10) comes first when this cluster can install it; else the order stays.
			$default = array_filter( $choices, static fn( $v ) => ! empty( ( (array) $v )['default'] ) );
			if ( array() !== $default ) {
				$choices = $default + array_diff_key( $choices, $default );
			}
			if ( array() === $choices ) {
				continue;
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
		$data = self::localise( apply_filters( 'beltoft_services_apps', $data, $id ), $id );
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

	/**
	 * Refuse builds unless this cluster explicitly opted in and the plan fits (a NEW order, or a reinstall).
	 *
	 * @param array<string, mixed> $cluster         Cluster.
	 * @param array<string, mixed> $plan            Plan with the template.
	 * @param string               $id              App id.
	 * @param string               $version_id      Version id.
	 * @param bool                 $require_enabled Whether the app must be on the cluster's apps_enabled list. An install of an order that was
	 *                                               already accepted passes false: an admin turning the app off for new orders afterwards does not
	 *                                               strand a customer who ordered (and paid for) it; the app must still be installable there.
	 */
	public static function check( array $cluster, array $plan, string $id, string $version_id, bool $require_enabled = true ): ?string {
		if ( empty( $cluster['apps_ready'] ) || ( $require_enabled && ! in_array( $id, (array) ( $cluster['apps_enabled'] ?? array() ), true ) ) || self::snippet_level( $cluster ) < 2 ) {
			return $require_enabled ? 'The app is not enabled on this cluster' : 'The cluster cannot install apps (not ready or no vendor snippet)';
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
		if ( ! self::family_supported( $cluster, $family ) ) {
			return 'The cluster vendor snippet cannot install apps on the selected operating system (it needs vm-vendor-cloudinit-v4.yaml)';
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
