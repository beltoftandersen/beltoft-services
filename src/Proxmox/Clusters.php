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
	public const BRIDGE_PATTERN = '/^[A-Za-z][A-Za-z0-9_.-]{0,14}$/';

	private const POOL_APIS = array( 'pools', 'pools_id' );

	/**
	 * Defaults for a new cluster.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'label'                => '',
			'api_url'              => '',
			'token_id'             => '',
			'token_secret'         => '',
			'console_token_id'     => '',
			'console_token_secret' => '',
			'ca_file'              => '',
			'pin_sha256'           => '',
			'client'            => 'fake',
			'pool_api'          => 'pools',
			'nodes'             => array(),
			'storage'           => '',
			'cloudinit_storage' => '',
			'bridge'            => '',
			'private_bridge'    => '',
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
			'db_vendor_snippet' => '',
			'apps_ready'        => 0,
			'apps_enabled'      => array(),
			'template_families' => array(),
			'backup_storage'    => '',
			'block_smtp'        => 1,
			'fw_enabled'        => 0,
			'fw_default_mode'   => 'standard',
			'fw_max_rules'      => 30,
			'fw_ipfilter'       => 0,
			'fw_pilot'          => array(),
		);
	}

	/**
	 * Every cluster by id, the token secret still encrypted. A cluster stored as live reads as
	 * fake unless wp-config.php allows live calls, so no caller can read `live` raw.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return self::view( Clients::live_allowed() );
	}

	/**
	 * Every cluster as all() gives it, for a given allowance: a cluster stored as live reads as
	 * fake unless live calls are allowed.
	 *
	 * @param bool $allowed Whether wp-config.php allows live calls.
	 * @return array<string, array<string, mixed>>
	 */
	public static function view( bool $allowed ): array {
		$list = self::stored();

		// Only the missing allowance turns a live cluster into a fake one. A live cluster without a
		// site tag stays live and is refused where a client is built (Clients::gate()), never
		// served by a fake that would report success.
		if ( ! $allowed ) {
			foreach ( $list as $id => $cluster ) {
				if ( 'live' === $cluster['client'] ) {
					$list[ $id ]['client'] = 'fake';
				}
			}
		}

		return $list;
	}

	/**
	 * Why a cluster cannot be saved as live, or null when it can.
	 *
	 * @param bool $allowed Whether wp-config.php allows live calls.
	 */
	public static function live_refusal( bool $allowed ): ?\WP_Error {
		if ( ! $allowed ) {
			return self::error( 'live', __( 'Live Proxmox calls are switched off. Define BELTOFT_SERVICES_PROXMOX_LIVE as true in wp-config.php first.', 'beltoft-services' ) );
		}

		if ( '' === ProxmoxSettings::explicit_site_tag() ) {
			return self::error( 'site_tag', __( 'Set the site tag in Settings, Proxmox first. A live cluster needs one you chose: the host name is not used for live clusters.', 'beltoft-services' ) );
		}

		return null;
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
		$cluster['console_token_secret'] = '' === (string) $cluster['console_token_secret'] ? '' : (string) Crypto::decrypt( (string) $cluster['console_token_secret'] );

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

	/** Whether a separate console token secret is saved for the cluster. */
	public static function has_console_secret( string $id ): bool {
		$all = self::all();
		return isset( $all[ $id ] ) && '' !== (string) $all[ $id ]['console_token_secret'];
	}

	/**
	 * Template families of managed services (a database server is built from them, a customer never picks them as
	 * an operating system), by prefix: postgres-18. A template that carries several versions lists several families, comma separated:
	 * postgres-17,postgres-18.
	 */
	public const SERVICE_FAMILIES = array( 'postgres-' );

	/**
	 * The operating system templates of a cluster, the ones customers choose from: VMID => label. Templates whose
	 * family is a managed service family are left out (see service_templates()).
	 *
	 * @param string $cluster Cluster id.
	 * @return array<int, string>
	 */
	public static function templates( string $cluster ): array {
		$all  = self::all();
		$list = isset( $all[ $cluster ] ) && is_array( $all[ $cluster ]['templates'] ) ? $all[ $cluster ]['templates'] : array();

		foreach ( array_keys( $list ) as $vmid ) {
			if ( array() !== self::service_families( $all[ $cluster ], (int) $vmid ) ) {
				unset( $list[ $vmid ] );
			}
		}

		return $list;
	}

	/**
	 * The cluster's templates of one managed service family (for example postgres-18): VMID => label.
	 *
	 * @param string $cluster Cluster id.
	 * @param string $family  Family, for example postgres-18.
	 * @return array<int, string>
	 */
	public static function service_templates( string $cluster, string $family ): array {
		$all  = self::all();
		$list = isset( $all[ $cluster ] ) && is_array( $all[ $cluster ]['templates'] ) ? $all[ $cluster ]['templates'] : array();
		$out  = array();

		foreach ( $list as $vmid => $label ) {
			if ( in_array( $family, self::service_families( $all[ $cluster ], (int) $vmid ), true ) ) {
				$out[ $vmid ] = $label;
			}
		}

		return $out;
	}

	/**
	 * The managed service families of a template (one, or several for a template that carries more than one
	 * version, as postgres-17,postgres-18); empty when it is an operating system template.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param int                  $vmid    Template VMID.
	 * @return array<int, string>
	 */
	private static function service_families( array $cluster, int $vmid ): array {
		$value = (string) ( ( (array) ( $cluster['template_families'] ?? array() ) )[ $vmid ] ?? '' );
		$out   = array();

		foreach ( explode( ',', $value ) as $family ) {
			foreach ( self::SERVICE_FAMILIES as $prefix ) {
				if ( 0 === strpos( $family, $prefix ) ) {
					$out[] = $family;
				}
			}
		}

		return $out;
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
		Stock::sync(); // Its plans have no known cluster now: they close.

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
		if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/D', $id ) ) {
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

		if ( ! preg_match( '/^[^\s@!]{1,64}@[A-Za-z0-9._-]{1,64}![A-Za-z0-9._-]{1,64}$/D', $token_id ) ) {
			return self::error( 'token_id', __( 'The token id looks like user@realm!tokenname.', 'beltoft-services' ) );
		}

		$clean['token_id'] = $token_id;
		$console_token_id = trim( (string) ( $input['console_token_id'] ?? $clean['console_token_id'] ) );
		if ( '' !== $console_token_id && ! preg_match( '/^[^\s@!]{1,64}@[A-Za-z0-9._-]{1,64}![A-Za-z0-9._-]{1,64}$/D', $console_token_id ) ) {
			return self::error( 'console_token_id', __( 'The console token id looks like user@realm!tokenname.', 'beltoft-services' ) );
		}
		if ( '' !== $console_token_id && $console_token_id === $token_id ) {
			return self::error( 'console_token_id', __( 'Use a separate token for the console.', 'beltoft-services' ) );
		}
		$clean['console_token_id'] = $console_token_id;

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

		$refusal = 'live' === $client ? self::live_refusal( Clients::live_allowed() ) : null;

		if ( $refusal ) {
			return $refusal;
		}

		$clean['client'] = $client;

		$pool_api = (string) ( $input['pool_api'] ?? 'pools' );

		if ( ! in_array( $pool_api, self::POOL_APIS, true ) ) {
			return self::error( 'pool_api', __( 'The pool API must be pools or pools_id.', 'beltoft-services' ) );
		}

		$clean['pool_api'] = $pool_api;

		$nodes = array();

		foreach ( preg_split( '/[\s,]+/', (string) ( $input['nodes'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $node ) {
			if ( ! preg_match( '/^[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?$/D', $node ) ) {
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

			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*[A-Za-z0-9]$/D', $value ) ) {
				/* translators: %s: field name, such as "The disk storage". */
				return self::error( $key, sprintf( __( '%s must be a storage id (letters, numbers, dots, dashes, underscores).', 'beltoft-services' ), $name ) );
			}

			$clean[ $key ] = $value;
		}

		$bridge = trim( (string) ( $input['bridge'] ?? '' ) );

		if ( ! preg_match( self::BRIDGE_PATTERN, $bridge ) ) {
			return self::error( 'bridge', __( 'The bridge is a network device name such as vmbr1.', 'beltoft-services' ) );
		}

		$clean['bridge'] = $bridge;

		$private_bridge = trim( (string) ( $input['private_bridge'] ?? '' ) );
		if ( '' !== $private_bridge && ! preg_match( self::BRIDGE_PATTERN, $private_bridge ) ) {
			return self::error( 'private_bridge', __( 'The private bridge is a network device name such as vmbr1.', 'beltoft-services' ) );
		}
		if ( '' !== $private_bridge && $private_bridge === $bridge ) {
			return self::error( 'private_bridge', __( 'The private bridge must differ from the public bridge.', 'beltoft-services' ) );
		}
		$clean['private_bridge'] = $private_bridge;

		$vlan = trim( (string) ( $input['vlan'] ?? '' ) );

		if ( '' !== $vlan && ( ! ctype_digit( $vlan ) || (int) $vlan < 1 || (int) $vlan > 4094 ) ) {
			return self::error( 'vlan', __( 'The VLAN tag is a number from 1 to 4094, or empty.', 'beltoft-services' ) );
		}

		$clean['vlan'] = '' === $vlan ? 0 : (int) $vlan;
		if ( $clean['vlan'] > 0 && PrivateNets::uses_vlan( $clean['vlan'] ) ) {
			return self::error( 'vlan', __( 'The public VLAN is reserved for a private network.', 'beltoft-services' ) );
		}

		$pool = trim( (string) ( $input['pool'] ?? '' ) );

		if ( '' !== $pool && ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $pool ) ) {
			return self::error( 'pool', __( 'The pool id contains letters, numbers, dots, dashes and underscores only.', 'beltoft-services' ) );
		}

		$clean['pool'] = $pool;

		$boot = trim( (string) ( $input['boot_disk'] ?? '' ) );
		$boot = '' === $boot ? 'scsi0' : $boot;

		if ( ! preg_match( '/^(scsi|virtio|sata|ide)\d{1,2}$/D', $boot ) ) {
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

		if ( '' !== $snippet && ( ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*:snippets\/[A-Za-z0-9._\/-]+$/D', $snippet ) || false !== strpos( $snippet, '..' ) ) ) {
			return self::error( 'vendor_snippet', __( 'The vendor snippet is a volume such as local:snippets/vendor.yaml.', 'beltoft-services' ) );
		}

		$clean['vendor_snippet'] = $snippet;

		$db_snippet = trim( (string) ( $input['db_vendor_snippet'] ?? '' ) );

		if ( '' !== $db_snippet && ( ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*:snippets\/[A-Za-z0-9._\/-]+$/D', $db_snippet ) || false !== strpos( $db_snippet, '..' ) ) ) {
			return self::error( 'db_vendor_snippet', __( 'The database vendor snippet is a volume such as local:snippets/vm-vendor-cloudinit-db-v1.yaml.', 'beltoft-services' ) );
		}

		$clean['db_vendor_snippet'] = $db_snippet;

		// The app controls. The settings form sends apps_form, so an unticked box means off; any other caller
		// (a script, a test) that leaves the keys out keeps what the cluster has.
		$apps_form = ! empty( $input['apps_form'] );

		if ( $apps_form || array_key_exists( 'apps_ready', $input ) ) {
			$clean['apps_ready'] = empty( $input['apps_ready'] ) ? 0 : 1;
		}
		if ( $apps_form || array_key_exists( 'apps_enabled', $input ) ) {
			$ids = is_array( $input['apps_enabled'] ?? null ) ? $input['apps_enabled'] : array();
			$clean['apps_enabled'] = array_values( array_unique( array_filter( array_map( 'strval', $ids ), static fn( $v ) => (bool) preg_match( '/^[a-z0-9-]+$/D', $v ) ) ) );
		}
		if ( isset( $input['template_families_text'] ) && is_string( $input['template_families_text'] ) ) {
			$input['template_families'] = array();
			foreach ( preg_split( '/\R/', $input['template_families_text'] ) as $line ) {
				if ( 1 === preg_match( '/^\s*(\d+)\s*=\s*(\S+)\s*$/D', $line, $m ) ) {
					$input['template_families'][ (int) $m[1] ] = strtolower( $m[2] );
				}
			}
		}
		if ( array_key_exists( 'template_families', $input ) && is_array( $input['template_families'] ) ) {
			$families = array();
			foreach ( array_intersect_key( $input['template_families'], $templates ) as $vmid => $family ) {
				if ( is_string( $family ) && preg_match( '/^[a-z0-9]+(?:[-.][a-z0-9]+)*(?:,[a-z0-9]+(?:[-.][a-z0-9]+)*)*$/D', $family ) ) {
					$families[ (int) $vmid ] = $family;
				}
			}
			$clean['template_families'] = $families;
		}

		$backup = trim( (string) ( $input['backup_storage'] ?? '' ) );

		if ( '' !== $backup && ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*[A-Za-z0-9]$/D', $backup ) ) {
			return self::error( 'backup_storage', __( 'The backup storage must be a storage id (letters, numbers, dots, dashes, underscores), or empty to turn backups off.', 'beltoft-services' ) );
		}

		$clean['backup_storage'] = $backup;

		if ( array_key_exists( 'block_smtp', $input ) ) {
			$clean['block_smtp'] = empty( $input['block_smtp'] ) ? 0 : 1;
		}

		// The dashboard firewall (Firewall): the switch, what a new server starts with, the most rules, the anti-spoofing filter.
		foreach ( array( 'fw_enabled', 'fw_ipfilter' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
			}
		}

		// Servers (license IDs) that get the firewall while the switch is off: a live test or a staged start.
		if ( array_key_exists( 'fw_pilot', $input ) ) {
			$ids               = is_array( $input['fw_pilot'] ) ? $input['fw_pilot'] : preg_split( '/[\s,]+/', (string) $input['fw_pilot'] );
			$clean['fw_pilot'] = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) ), 0, 50 );
		}

		if ( array_key_exists( 'fw_default_mode', $input ) ) {
			$clean['fw_default_mode'] = 'open' === $input['fw_default_mode'] ? 'open' : 'standard';
		}

		if ( array_key_exists( 'fw_max_rules', $input ) ) {
			$clean['fw_max_rules'] = max( 1, min( Firewall::MAX_CEILING, (int) $input['fw_max_rules'] ) );
		}

		$secret = self::clean_secret( $input, (string) ( $existing['token_secret'] ?? '' ) );

		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		if ( '' === $secret ) {
			return self::error( 'secret', __( 'A cluster needs the API token secret.', 'beltoft-services' ) );
		}

		$clean['token_secret'] = $secret;
		// A Proxmox token secret is a UUID: anything else (a pasted table row, the token id) is refused, not saved.
		$console_new = trim( (string) ( $input['console_token_secret'] ?? '' ) );
		if ( '' !== $console_new && ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $console_new ) ) {
			return self::error( 'console_token_secret', __( 'The console token secret is the value Proxmox shows once, like 0a1b2c3d-1111-2222-3333-444455556666. Paste only that value.', 'beltoft-services' ) );
		}
		$console_secret = self::clean_secret( $input, (string) ( $existing['console_token_secret'] ?? '' ), 'console_token_secret' );
		if ( is_wp_error( $console_secret ) ) {
			return $console_secret;
		}
		$clean['console_token_secret'] = $console_secret;

		$all[ $id ] = $clean;
		update_option( self::OPTION, $all, false );
		ProxmoxJobs::queue_capacity( $id );
		Stock::sync( $id ); // A new node list or a new cluster changes what fits (the old cache stays until the run).

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
			&& 1 === preg_match( '/^[A-Za-z0-9.\-\[\]:]+$/D', $parts['host'] );

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
	private static function clean_secret( array $input, string $saved, string $field = 'token_secret' ) {
		$new = trim( (string) ( $input[ $field ] ?? '' ) );

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
