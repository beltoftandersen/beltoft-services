<?php
/**
 * Stored Proxmox clusters.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Http\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * The clusters the Proxmox driver can use: where the API is, how to log in, which nodes, storage
 * and network to build on, and which templates to clone. A list with add, edit and delete, so it has
 * its own option (as Connections does). The token secret is encrypted with Crypto. No value has a
 * default that names real infrastructure.
 */
final class Clusters {

	public const OPTION = 'beltoft_services_proxmox_clusters';

	private const POOL_APIS = array( 'pools', 'pools_id' );

	/**
	 * Defaults for a new cluster.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'label'             => '',
			'api_url'           => '',
			'token_id'          => '',
			'token_secret'      => '',
			'ca_file'           => '',
			'pin_sha256'        => '',
			'client'            => 'fake',
			'pool_api'          => 'pools',
			'nodes'             => array(),
			'storage'           => '',
			'cloudinit_storage' => '',
			'bridge'            => '',
			'vlan'              => 0,
			'pool'              => '',
			'boot_disk'         => 'scsi0',
			'gateway'           => '',
			'nameservers'       => array(),
			'prefix'            => 32,
			'vmid_min'          => 200,
			'vmid_max'          => 999,
			'templates'         => array(),
			'default_template'  => 0,
			'vendor_snippet'    => '',
			'block_smtp'        => 1,
		);
	}

	/**
	 * Every cluster by id, the token secret still encrypted. A cluster stored as live reads as
	 * fake unless wp-config.php allows live calls, so no caller can read `live` raw.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$list = self::stored();

		if ( ! Clients::live_allowed() ) {
			foreach ( $list as $id => $cluster ) {
				if ( 'live' === $cluster['client'] ) {
					$list[ $id ]['client'] = 'fake';
				}
			}
		}

		return $list;
	}

	/**
	 * The clusters exactly as stored (only for writing them back).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$list   = array();

		if ( ! is_array( $stored ) ) {
			return $list;
		}

		foreach ( $stored as $id => $cluster ) {
			if ( is_array( $cluster ) ) {
				$list[ (string) $id ] = array_merge( self::defaults(), $cluster );
			}
		}

		return $list;
	}

	/**
	 * One cluster with its token secret decrypted (an empty string when none is saved or it
	 * cannot be decrypted). A cluster stored as live reads as fake unless wp-config.php allows
	 * live calls, so a copied database never goes live by itself.
	 *
	 * @param string $id Cluster id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}

		$cluster                 = $all[ $id ];
		$cluster['token_secret'] = '' === (string) $cluster['token_secret'] ? '' : (string) Crypto::decrypt( (string) $cluster['token_secret'] );

		if ( 'live' === $cluster['client'] && ! Clients::live_allowed() ) {
			$cluster['client'] = 'fake';
		}

		return $cluster;
	}

	/**
	 * Whether a token secret is saved for the cluster.
	 *
	 * @param string $id Cluster id.
	 */
	public static function has_secret( string $id ): bool {
		$all = self::all();

		return isset( $all[ $id ] ) && '' !== (string) $all[ $id ]['token_secret'];
	}

	/**
	 * The templates of a cluster: VMID => label.
	 *
	 * @param string $cluster Cluster id.
	 * @return array<int, string>
	 */
	public static function templates( string $cluster ): array {
		$all = self::all();

		return isset( $all[ $cluster ] ) && is_array( $all[ $cluster ]['templates'] ) ? $all[ $cluster ]['templates'] : array();
	}

	/**
	 * The template preselected for customers: the one marked as default in the cluster's settings
	 * when it is still in the list, otherwise the first template of the list, 0 when there is none.
	 *
	 * @param string $cluster Cluster id.
	 */
	public static function default_template( string $cluster ): int {
		$all       = self::all();
		$templates = self::templates( $cluster );

		if ( array() === $templates ) {
			return 0;
		}

		$marked = isset( $all[ $cluster ] ) ? (int) $all[ $cluster ]['default_template'] : 0;

		return isset( $templates[ $marked ] ) ? $marked : (int) array_key_first( $templates );
	}

	/**
	 * How many VM records use a cluster: the rows of the VM table when it exists, plus whatever
	 * the filter adds.
	 *
	 * @param string $id Cluster id.
	 */
	public static function in_use( string $id ): int {
		global $wpdb;

		$count = 0;
		$table = $wpdb->prefix . 'beltoft_services_vms';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a rare admin check.
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is built from the prefix.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE cluster = %s', $table, $id ) );
		}

		/**
		 * Filters how many VM records use a cluster. A cluster in use cannot be deleted.
		 *
		 * @param int    $count Count so far.
		 * @param string $id    Cluster id.
		 */
		return max( 0, (int) apply_filters( 'beltoft_services_proxmox_cluster_in_use', $count, $id ) );
	}

	/**
	 * Deletes a cluster, unless a VM record uses it.
	 *
	 * @param string $id Cluster id.
	 */
	public static function delete( string $id ): bool {
		$all = self::stored();

		if ( ! isset( $all[ $id ] ) || self::in_use( $id ) > 0 ) {
			return false;
		}

		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		return true;
	}

	/**
	 * Validates and saves a cluster. An empty `token_secret` keeps the saved one.
	 *
	 * @param string               $id    Cluster id: 1 to 40 of a-z, 0-9, dash, underscore.
	 * @param array<string, mixed> $input Raw values, as posted.
	 * @return true|\WP_Error
	 */
	public static function save( string $id, array $input ) {
		if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/', $id ) ) {
			return self::error( 'id', __( 'A cluster id is 1 to 40 characters: lowercase letters, numbers, dashes and underscores.', 'beltoft-services' ) );
		}

		$all      = self::stored();
		$existing = $all[ $id ] ?? array();
		$clean    = array_merge( self::defaults(), $existing );

		$clean['label'] = sanitize_text_field( (string) ( $input['label'] ?? '' ) );

		if ( '' === $clean['label'] ) {
			return self::error( 'label', __( 'A cluster needs a label.', 'beltoft-services' ) );
		}

		$url = self::clean_api_url( (string) ( $input['api_url'] ?? '' ) );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$clean['api_url'] = $url;

		$token_id = trim( (string) ( $input['token_id'] ?? '' ) );

		if ( ! preg_match( '/^[^\s@!]{1,64}@[A-Za-z0-9._-]{1,64}![A-Za-z0-9._-]{1,64}$/', $token_id ) ) {
			return self::error( 'token_id', __( 'The token id looks like user@realm!tokenname.', 'beltoft-services' ) );
		}

		$clean['token_id'] = $token_id;

		$ca = trim( (string) ( $input['ca_file'] ?? '' ) );

		if ( '' !== $ca && ( '/' !== $ca[0] || false !== strpos( $ca, "\0" ) || ! is_file( $ca ) || ! is_readable( $ca ) ) ) {
			return self::error( 'ca_file', __( 'The CA file must be an absolute path to a file this site can read.', 'beltoft-services' ) );
		}

		$clean['ca_file'] = $ca;

		$pin = trim( (string) ( $input['pin_sha256'] ?? '' ) );

		if ( '' !== $pin ) {
			$raw = base64_decode( $pin, true );

			if ( false === $raw || 32 !== strlen( $raw ) ) {
				return self::error( 'pin', __( 'The pin must be the base64 of a SHA-256 hash (44 characters).', 'beltoft-services' ) );
			}
		}

		$clean['pin_sha256'] = $pin;

		$client = (string) ( $input['client'] ?? 'fake' );

		if ( ! in_array( $client, array( 'fake', 'live' ), true ) ) {
			return self::error( 'client', __( 'The client must be fake or live.', 'beltoft-services' ) );
		}

		if ( 'live' === $client && ! Clients::live_allowed() ) {
			return self::error( 'live', __( 'Live Proxmox calls are switched off. Define BELTOFT_SERVICES_PROXMOX_LIVE as true in wp-config.php first.', 'beltoft-services' ) );
		}

		$clean['client'] = $client;

		$pool_api = (string) ( $input['pool_api'] ?? 'pools' );

		if ( ! in_array( $pool_api, self::POOL_APIS, true ) ) {
			return self::error( 'pool_api', __( 'The pool API must be pools or pools_id.', 'beltoft-services' ) );
		}

		$clean['pool_api'] = $pool_api;

		$nodes = array();

		foreach ( preg_split( '/[\s,]+/', (string) ( $input['nodes'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $node ) {
			if ( ! preg_match( '/^[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?$/', $node ) ) {
				return self::error( 'nodes', __( 'Node names contain letters, numbers and dashes only.', 'beltoft-services' ) );
			}

			$nodes[ $node ] = $node;
		}

		if ( array() === $nodes ) {
			return self::error( 'nodes', __( 'Name at least one node.', 'beltoft-services' ) );
		}

		$clean['nodes'] = array_values( $nodes );

		foreach ( array(
			'storage'           => __( 'The disk storage', 'beltoft-services' ),
			'cloudinit_storage' => __( 'The cloud-init storage', 'beltoft-services' ),
		) as $key => $name ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );

			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*[A-Za-z0-9]$/', $value ) ) {
				/* translators: %s: field name, such as "The disk storage". */
				return self::error( $key, sprintf( __( '%s must be a storage id (letters, numbers, dots, dashes, underscores).', 'beltoft-services' ), $name ) );
			}

			$clean[ $key ] = $value;
		}

		$bridge = trim( (string) ( $input['bridge'] ?? '' ) );

		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_.-]{0,14}$/', $bridge ) ) {
			return self::error( 'bridge', __( 'The bridge is a network device name such as vmbr1.', 'beltoft-services' ) );
		}

		$clean['bridge'] = $bridge;

		$vlan = trim( (string) ( $input['vlan'] ?? '' ) );

		if ( '' !== $vlan && ( ! ctype_digit( $vlan ) || (int) $vlan < 1 || (int) $vlan > 4094 ) ) {
			return self::error( 'vlan', __( 'The VLAN tag is a number from 1 to 4094, or empty.', 'beltoft-services' ) );
		}

		$clean['vlan'] = '' === $vlan ? 0 : (int) $vlan;

		$pool = trim( (string) ( $input['pool'] ?? '' ) );

		if ( '' !== $pool && ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $pool ) ) {
			return self::error( 'pool', __( 'The pool id contains letters, numbers, dots, dashes and underscores only.', 'beltoft-services' ) );
		}

		$clean['pool'] = $pool;

		$boot = trim( (string) ( $input['boot_disk'] ?? '' ) );
		$boot = '' === $boot ? 'scsi0' : $boot;

		if ( ! preg_match( '/^(scsi|virtio|sata|ide)\d{1,2}$/', $boot ) ) {
			return self::error( 'boot_disk', __( 'The boot disk is a disk slot such as scsi0 or virtio0.', 'beltoft-services' ) );
		}

		$clean['boot_disk'] = $boot;

		$gateway = trim( (string) ( $input['gateway'] ?? '' ) );

		if ( false === filter_var( $gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::error( 'gateway', __( 'The gateway must be an IPv4 address.', 'beltoft-services' ) );
		}

		$clean['gateway'] = $gateway;

		$nameservers = array();

		foreach ( preg_split( '/[\s,]+/', (string) ( $input['nameservers'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $ns ) {
			if ( false === filter_var( $ns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return self::error( 'nameservers', __( 'Name servers are IPv4 addresses, separated by commas.', 'beltoft-services' ) );
			}

			$nameservers[] = $ns;
		}

		$clean['nameservers'] = $nameservers;

		$prefix = trim( (string) ( $input['prefix'] ?? '' ) );
		$prefix = '' === $prefix ? '32' : $prefix;

		if ( ! ctype_digit( $prefix ) || (int) $prefix < 1 || (int) $prefix > 32 ) {
			return self::error( 'prefix', __( 'The network prefix is a number from 1 to 32.', 'beltoft-services' ) );
		}

		$clean['prefix'] = (int) $prefix;

		$min = trim( (string) ( $input['vmid_min'] ?? '' ) );
		$max = trim( (string) ( $input['vmid_max'] ?? '' ) );

		// Proxmox accepts new ids from 100 to 999999999 (API viewer, qemu clone `newid`).
		if ( ! ctype_digit( $min ) || ! ctype_digit( $max ) || (int) $min < 100 || (int) $max > 999999999 || (int) $min >= (int) $max ) {
			return self::error( 'vmid', __( 'The VMID range runs from at least 100 to at most 999999999, and the first must be lower than the last.', 'beltoft-services' ) );
		}

		$clean['vmid_min'] = (int) $min;
		$clean['vmid_max'] = (int) $max;

		$templates = self::parse_templates( (string) ( $input['templates'] ?? '' ) );

		if ( is_wp_error( $templates ) ) {
			return $templates;
		}

		foreach ( array_keys( $templates ) as $vmid ) {
			if ( $vmid >= $clean['vmid_min'] && $vmid <= $clean['vmid_max'] ) {
				return self::error( 'templates', __( 'A template cannot sit inside the customer VMID range: it could then be changed or deleted like a customer machine.', 'beltoft-services' ) );
			}
		}

		$clean['templates'] = $templates;

		$default = trim( (string) ( $input['default_template'] ?? '' ) );

		if ( '' !== $default && ( ! ctype_digit( $default ) || ! isset( $templates[ (int) $default ] ) ) ) {
			return self::error( 'default_template', __( 'The default template must be one of the VMIDs in the template list, or empty to use the first.', 'beltoft-services' ) );
		}

		$clean['default_template'] = '' === $default ? 0 : (int) $default;

		$snippet = trim( (string) ( $input['vendor_snippet'] ?? '' ) );

		if ( '' !== $snippet && ( ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*:snippets\/[A-Za-z0-9._\/-]+$/', $snippet ) || false !== strpos( $snippet, '..' ) ) ) {
			return self::error( 'vendor_snippet', __( 'The vendor snippet is a volume such as local:snippets/vendor.yaml.', 'beltoft-services' ) );
		}

		$clean['vendor_snippet'] = $snippet;

		if ( array_key_exists( 'block_smtp', $input ) ) {
			$clean['block_smtp'] = empty( $input['block_smtp'] ) ? 0 : 1;
		}

		$secret = self::clean_secret( $input, (string) ( $existing['token_secret'] ?? '' ) );

		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		if ( '' === $secret ) {
			return self::error( 'secret', __( 'A cluster needs the API token secret.', 'beltoft-services' ) );
		}

		$clean['token_secret'] = $secret;

		$all[ $id ] = $clean;
		update_option( self::OPTION, $all, false );

		return true;
	}

	/**
	 * Lines of `vmid = label` to VMID => label.
	 *
	 * @param string $text Raw lines.
	 * @return array<int, string>|\WP_Error
	 */
	private static function parse_templates( string $text ) {
		$templates = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = explode( '=', $line, 2 );
			$vmid  = trim( $parts[0] );
			$label = sanitize_text_field( trim( $parts[1] ?? '' ) );

			if ( ! ctype_digit( $vmid ) || (int) $vmid < 100 || (int) $vmid > 999999999 || '' === $label ) {
				return self::error( 'templates', __( 'Write each template as a line: VMID = label. The VMID is at least 100.', 'beltoft-services' ) );
			}

			if ( isset( $templates[ (int) $vmid ] ) ) {
				return self::error( 'templates', __( 'A template VMID is listed twice.', 'beltoft-services' ) );
			}

			$templates[ (int) $vmid ] = $label;
		}

		return $templates;
	}

	/**
	 * The API address: https, a host, an optional port, nothing else.
	 *
	 * @param string $url Raw value.
	 * @return string|\WP_Error Without a trailing slash.
	 */
	private static function clean_api_url( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );

		$ok = is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['query'] )
			&& ! isset( $parts['fragment'] )
			&& in_array( $parts['path'] ?? '', array( '', '/' ), true )
			&& 1 === preg_match( '/^[A-Za-z0-9.\-\[\]:]+$/', $parts['host'] );

		if ( ! $ok ) {
			return self::error( 'api_url', __( 'The API address must be https, such as https://pve.example.invalid:8006, with no path.', 'beltoft-services' ) );
		}

		return rtrim( $url, '/' );
	}

	/**
	 * Encrypts a new token secret, or keeps the saved one when the field is empty.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @param string               $saved The stored (encrypted) secret.
	 * @return string|\WP_Error
	 */
	private static function clean_secret( array $input, string $saved ) {
		$new = trim( (string) ( $input['token_secret'] ?? '' ) );

		if ( '' === $new ) {
			return $saved;
		}

		if ( strlen( $new ) < Connections::MIN_SECRET ) {
			/* translators: %d: minimum length. */
			return self::error( 'secret_short', sprintf( __( 'A new secret needs at least %d characters.', 'beltoft-services' ), Connections::MIN_SECRET ) );
		}

		if ( ! Crypto::available() ) {
			return new \WP_Error( 'beltoft_services_no_key', __( 'Add BELTOFT_SERVICES_KEY to wp-config.php before saving secrets.', 'beltoft-services' ) );
		}

		return Crypto::encrypt( $new );
	}

	/**
	 * A validation error.
	 *
	 * @param string $code    Code suffix.
	 * @param string $message Translated message.
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'beltoft_services_cluster_' . $code, $message );
	}
}
