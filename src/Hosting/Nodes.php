<?php
/**
 * Stored hosting nodes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Crypto;
use BeltoftServices\Http\Connections;
use BeltoftServices\Proxmox\Clusters;

defined( 'ABSPATH' ) || exit;

/**
 * The hosting nodes (Proxmox VE machines that run the customer containers): where the node API and the node
 * agent are, how to log in, where to build and which template to clone. A list with add, edit and delete, so it
 * has its own option (as the Proxmox clusters do). The token secret and the agent key are encrypted with Crypto
 * and never logged. No value has a default that names real infrastructure.
 */
final class Nodes {

	public const OPTION = 'beltoft_services_hosting_nodes';

	/**
	 * Defaults for a new node.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'label'         => '',
			'node_group'    => '',
			'enabled'       => 1,
			'client'        => 'fake',
			'api_url'       => '',
			'token_id'      => '',
			'token_secret'  => '',
			'ca_file'       => '',
			'pin_sha256'    => '',
			'pve_node'      => '',
			'agent_url'     => '',
			'agent_key_id'  => '',
			'agent_key'     => '',
			'agent_pin'     => '',
			'agent_tenant'  => 'hosting',
			'agent_client_cert' => '',
			'agent_client_key' => '',
			'agent_ca'      => '',
			'pool'          => '',
			'storage'       => '',
			'bridge'        => '',
			'gateway'       => '',
			'lan_cidr'      => '',
			'ip_first'      => 10,
			'ssh_port_min'  => 22001,
			'ssh_port_max'  => 22999,
			'vmid_min'      => 1000,
			'vmid_max'      => 1999,
			'template'      => 0,
			'net_mbit'      => 0,
			'mem_gb'        => 0,
			'disk_gb'       => 0,
			'db_host'       => '',
			'backup_storage' => '',
			'restore_safety' => 'vzdump',
			'scratch_vmid'  => 0,
			'fw_group'      => 'hosting-ct',
			'edge_ip'       => '',
			'edge_ipv6'     => '',
		);
	}

	/**
	 * Fields that hold a secret: encrypted at rest.
	 */
	public const SECRETS = array( 'token_secret', 'agent_key', 'agent_client_key' );

	/**
	 * Every node by id, the secrets still encrypted. A node stored as live reads as fake unless wp-config.php
	 * allows live calls, so no caller can read `live` raw.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return self::view( Clients::live_allowed() );
	}

	/**
	 * Every node as all() gives it, for a given allowance.
	 *
	 * @param bool $allowed Whether wp-config.php allows live calls.
	 * @return array<string, array<string, mixed>>
	 */
	public static function view( bool $allowed ): array {
		$list = self::stored();

		if ( ! $allowed ) {
			foreach ( $list as $id => $node ) {
				if ( 'live' === $node['client'] ) {
					$list[ $id ]['client'] = 'fake';
				}
			}
		}

		return $list;
	}

	/**
	 * One node with its secrets decrypted (an empty string when none is saved or it cannot be decrypted).
	 *
	 * @param string $id Node id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}

		$node = $all[ $id ];

		foreach ( self::SECRETS as $key ) {
			$node[ $key ] = '' === (string) $node[ $key ] ? '' : (string) Crypto::decrypt( (string) $node[ $key ] );
		}

		return $node;
	}

	/**
	 * The enabled nodes of a node group, ids in stored order.
	 *
	 * @param string $group Node group.
	 * @return string[]
	 */
	public static function in_group( string $group ): array {
		$ids = array();

		foreach ( self::all() as $id => $node ) {
			if ( $group === $node['node_group'] && $node['enabled'] ) {
				$ids[] = (string) $id;
			}
		}

		return $ids;
	}

	/**
	 * Whether a secret is saved for the node's field.
	 *
	 * @param string $id  Node id.
	 * @param string $key token_secret or agent_key.
	 */
	public static function has_secret( string $id, string $key = 'token_secret' ): bool {
		$all = self::all();

		return in_array( $key, self::SECRETS, true ) && isset( $all[ $id ] ) && '' !== (string) $all[ $id ][ $key ];
	}

	/**
	 * How many hosting records use a node.
	 *
	 * @param string $id Node id.
	 */
	public static function in_use( string $id ): int {
		return Records::count_on_node( $id );
	}

	/**
	 * Deletes a node, unless a hosting record uses it.
	 *
	 * @param string $id Node id.
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
	 * Validates and saves a node. An empty secret keeps the saved one.
	 *
	 * @param string               $id    Node id: 1 to 40 of a-z, 0-9, dash, underscore.
	 * @param array<string, mixed> $input Raw values, as posted.
	 * @return true|\WP_Error
	 */
	public static function save( string $id, array $input ) {
		if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/D', $id ) ) {
			return self::error( 'id', __( 'A node id is 1 to 40 characters: lowercase letters, numbers, dashes and underscores.', 'beltoft-services' ) );
		}

		$all      = self::stored();
		$existing = $all[ $id ] ?? array();
		$clean    = array_merge( self::defaults(), $existing );

		$clean['label'] = sanitize_text_field( (string) ( $input['label'] ?? '' ) );

		if ( '' === $clean['label'] ) {
			return self::error( 'label', __( 'A node needs a label.', 'beltoft-services' ) );
		}

		$group = trim( (string) ( $input['node_group'] ?? '' ) );

		if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/D', $group ) ) {
			return self::error( 'node_group', __( 'The node group is 1 to 40 characters: lowercase letters, numbers, dashes and underscores.', 'beltoft-services' ) );
		}

		$clean['node_group'] = $group;
		$clean['enabled']    = empty( $input['enabled'] ) && array_key_exists( 'enabled', $input ) ? 0 : 1;

		foreach ( array(
			'api_url'   => __( 'The node API address', 'beltoft-services' ),
			'agent_url' => __( 'The agent address', 'beltoft-services' ),
		) as $key => $name ) {
			$url = self::clean_url( (string) ( $input[ $key ] ?? '' ) );

			if ( null === $url ) {
				/* translators: %s: field name. */
				return self::error( $key, sprintf( __( '%s must be https, with a host and no path, user or query.', 'beltoft-services' ), $name ) );
			}

			$clean[ $key ] = $url;
		}

		$token_id = trim( (string) ( $input['token_id'] ?? '' ) );

		if ( ! preg_match( '/^[^\s@!]{1,64}@[A-Za-z0-9._-]{1,64}![A-Za-z0-9._-]{1,64}$/D', $token_id ) ) {
			return self::error( 'token_id', __( 'The token id looks like user@realm!tokenname.', 'beltoft-services' ) );
		}

		$clean['token_id'] = $token_id;

		$key_id = trim( (string) ( $input['agent_key_id'] ?? '' ) );

		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,40}$/D', $key_id ) ) {
			return self::error( 'agent_key_id', __( 'The agent key id is 1 to 40 characters: letters, numbers, dots, dashes and underscores.', 'beltoft-services' ) );
		}

		$clean['agent_key_id'] = $key_id;

		$tenant = trim( (string) ( $input['agent_tenant'] ?? $clean['agent_tenant'] ) );

		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,120}$/D', $tenant ) ) {
			return self::error( 'agent_tenant', __( 'The agent tenant is 1 to 120 characters: letters, numbers, dots, colons, dashes and underscores.', 'beltoft-services' ) );
		}

		$clean['agent_tenant'] = $tenant;

		foreach ( array(
			'agent_client_cert' => __( 'The agent client certificate', 'beltoft-services' ),
			'agent_ca'          => __( 'The agent CA certificate', 'beltoft-services' ),
		) as $key => $name ) {
			$pem = trim( str_replace( "\r", '', (string) ( $input[ $key ] ?? $clean[ $key ] ) ) );

			if ( '' !== $pem && ( 0 !== strpos( $pem, '-----BEGIN CERTIFICATE-----' ) || false === strpos( $pem, '-----END CERTIFICATE-----' ) || strlen( $pem ) > 20000 ) ) {
				/* translators: %s: field name. */
				return self::error( $key, sprintf( __( '%s must be a PEM certificate (starting with BEGIN CERTIFICATE).', 'beltoft-services' ), $name ) );
			}

			$clean[ $key ] = $pem;
		}

		$ca = trim( (string) ( $input['ca_file'] ?? '' ) );

		if ( '' !== $ca && ( '/' !== $ca[0] || false !== strpos( $ca, "\0" ) || ! is_file( $ca ) || ! is_readable( $ca ) ) ) {
			return self::error( 'ca_file', __( 'The CA file must be an absolute path to a file this site can read.', 'beltoft-services' ) );
		}

		$clean['ca_file'] = $ca;

		foreach ( array(
			'pin_sha256' => __( 'The node pin', 'beltoft-services' ),
			'agent_pin'  => __( 'The agent pin', 'beltoft-services' ),
		) as $key => $name ) {
			$pin = trim( (string) ( $input[ $key ] ?? '' ) );

			if ( '' !== $pin ) {
				$raw = base64_decode( $pin, true );

				if ( false === $raw || 32 !== strlen( $raw ) ) {
					/* translators: %s: field name. */
					return self::error( $key, sprintf( __( '%s must be the base64 of a SHA-256 hash (44 characters).', 'beltoft-services' ), $name ) );
				}
			}

			$clean[ $key ] = $pin;
		}

		$client = (string) ( $input['client'] ?? 'fake' );

		if ( ! in_array( $client, array( 'fake', 'live' ), true ) ) {
			return self::error( 'client', __( 'The client must be fake or live.', 'beltoft-services' ) );
		}

		$refusal = 'live' === $client ? self::live_refusal( Clients::live_allowed() ) : null;

		if ( $refusal ) {
			return $refusal;
		}

		$clean['client'] = $client;

		foreach ( array(
			'pve_node' => __( 'The node name', 'beltoft-services' ),
		) as $key => $name ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );

			if ( ! preg_match( '/^[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?$/D', $value ) ) {
				/* translators: %s: field name. */
				return self::error( $key, sprintf( __( '%s contains letters, numbers and dashes only.', 'beltoft-services' ), $name ) );
			}

			$clean[ $key ] = $value;
		}

		foreach ( array(
			'pool'    => __( 'The pool', 'beltoft-services' ),
			'storage' => __( 'The storage', 'beltoft-services' ),
		) as $key => $name ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );

			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*[A-Za-z0-9]$/D', $value ) ) {
				/* translators: %s: field name. */
				return self::error( $key, sprintf( __( '%s must be an id (letters, numbers, dots, dashes, underscores).', 'beltoft-services' ), $name ) );
			}

			$clean[ $key ] = $value;
		}

		$bridge = trim( (string) ( $input['bridge'] ?? '' ) );

		if ( ! preg_match( Clusters::BRIDGE_PATTERN, $bridge ) ) {
			return self::error( 'bridge', __( 'The bridge is a network device name such as hbr0.', 'beltoft-services' ) );
		}

		$clean['bridge'] = $bridge;

		$cidr = trim( (string) ( $input['lan_cidr'] ?? '' ) );
		$ip_first = (int) ( $input['ip_first'] ?? $clean['ip_first'] );

		if ( null === CtIpPool::range( $cidr, $ip_first ) ) {
			return self::error( 'lan_cidr', __( 'The container network is an IPv4 range such as 10.200.0.0/16 (a /16 to a /29), and the first host number must fit in it.', 'beltoft-services' ) );
		}

		$clean['lan_cidr'] = $cidr;
		$clean['ip_first'] = $ip_first;

		foreach ( array( 'gateway', 'db_host' ) as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );

			if ( false === filter_var( $value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				/* translators: %s: field name. */
				return self::error( $key, sprintf( __( 'The %s must be an IPv4 address.', 'beltoft-services' ), $key ) );
			}

			$clean[ $key ] = $value;
		}

		$backup = trim( (string) ( $input['backup_storage'] ?? '' ) );

		if ( '' !== $backup && 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9._-]*[A-Za-z0-9]$/D', $backup ) ) {
			return self::error( 'backup_storage', __( 'The backup storage must be a storage id (letters, numbers, dots, dashes, underscores), or empty for no backups.', 'beltoft-services' ) );
		}

		$clean['backup_storage'] = $backup;

		$group_fw = trim( (string) ( $input['fw_group'] ?? $clean['fw_group'] ) );

		if ( '' !== $group_fw && 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,19}$/D', $group_fw ) ) {
			return self::error( 'fw_group', __( 'The firewall security group is a name of up to 20 letters, numbers, dashes and underscores, or empty for none.', 'beltoft-services' ) );
		}

		$clean['fw_group'] = $group_fw;

		$safety = (string) ( $input['restore_safety'] ?? $clean['restore_safety'] );

		if ( ! in_array( $safety, array( 'snapshot', 'vzdump' ), true ) ) {
			return self::error( 'restore_safety', __( 'The safety point for an account restore is a snapshot or a vzdump.', 'beltoft-services' ) );
		}

		$clean['restore_safety'] = $safety;

		foreach ( array(
			'edge_ip'   => FILTER_FLAG_IPV4,
			'edge_ipv6' => FILTER_FLAG_IPV6,
		) as $key => $flag ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );

			if ( '' !== $value && false === filter_var( $value, FILTER_VALIDATE_IP, $flag ) ) {
				/* translators: %s: field name. */
				return self::error( $key, sprintf( __( 'The %s must be an address of the right kind, or empty.', 'beltoft-services' ), $key ) );
			}

			$clean[ $key ] = $value;
		}

		foreach ( array(
			'ssh_port_min' => array( 1, 65535 ),
			'ssh_port_max' => array( 1, 65535 ),
			'vmid_min'     => array( 100, 999999999 ),
			'vmid_max'     => array( 100, 999999999 ),
			'template'     => array( 100, 999999999 ),
			'scratch_vmid' => array( 0, 999999999 ),
			'net_mbit'     => array( 0, 100000 ),
			'mem_gb'       => array( 1, 100000 ),
			'disk_gb'      => array( 1, 10000000 ),
		) as $key => $range ) {
			$value = trim( (string) ( $input[ $key ] ?? $clean[ $key ] ) );

			if ( ! ctype_digit( $value ) || (int) $value < $range[0] || (int) $value > $range[1] ) {
				/* translators: 1: field key, 2: lowest value, 3: highest value. */
				return self::error( $key, sprintf( __( 'The %1$s is a whole number from %2$d to %3$d.', 'beltoft-services' ), $key, $range[0], $range[1] ) );
			}

			$clean[ $key ] = (int) $value;
		}

		if ( (int) $clean['scratch_vmid'] > 0 && (int) $clean['scratch_vmid'] === (int) $clean['template'] ) {
			return self::error( 'scratch_vmid', __( 'The scratch container id cannot be the container template.', 'beltoft-services' ) );
		}

		if ( $clean['ssh_port_max'] < $clean['ssh_port_min'] || $clean['vmid_max'] < $clean['vmid_min'] ) {
			return self::error( 'range', __( 'A range ends before it starts.', 'beltoft-services' ) );
		}

		foreach ( array(
			'token_secret' => array( 'token_secret', __( 'The token secret', 'beltoft-services' ) ),
			'agent_key'    => array( 'agent_key', __( 'The agent key', 'beltoft-services' ) ),
			'agent_client_key' => array( 'agent_client_key', __( 'The agent client key', 'beltoft-services' ) ),
		) as $key => $names ) {
			$secret = self::clean_secret( $input, (string) ( $existing[ $key ] ?? '' ), $key, $names[1] );

			if ( is_wp_error( $secret ) ) {
				return $secret;
			}

			$clean[ $key ] = $secret;
		}

		if ( '' === (string) $clean['token_secret'] || '' === (string) $clean['agent_key'] ) {
			return self::error( 'secret', __( 'A node needs a token secret and an agent key.', 'beltoft-services' ) );
		}

		if ( '' !== (string) $clean['agent_client_key'] && false === strpos( (string) Crypto::decrypt( (string) $clean['agent_client_key'] ), '-----BEGIN' ) ) {
			return self::error( 'agent_client_key', __( 'The agent client key must be a PEM private key.', 'beltoft-services' ) );
		}

		$all[ $id ] = $clean;
		update_option( self::OPTION, $all, false );

		if ( (int) ( $existing['template'] ?? 0 ) !== (int) $clean['template'] ) {
			/**
			 * A node's container template setting changed (or the node is new): containers made from an older one are refreshed.
			 *
			 * @param string $id Node id.
			 */
			do_action( 'beltoft_services_hosting_template_changed', $id );
		}

		return true;
	}

	/**
	 * Why a node cannot be saved as live, or null when it can. Both the constant and an explicit site tag
	 * are needed, as for the Proxmox clusters.
	 *
	 * @param bool $allowed Whether wp-config.php allows live calls.
	 */
	public static function live_refusal( bool $allowed ): ?\WP_Error {
		if ( ! $allowed ) {
			return self::error( 'live', __( 'Live hosting calls are switched off. Define BELTOFT_SERVICES_HOSTING_LIVE as true in wp-config.php first.', 'beltoft-services' ) );
		}

		if ( '' === \BeltoftServices\Proxmox\ProxmoxSettings::explicit_site_tag() ) {
			return self::error( 'site_tag', __( 'Set the site tag in Settings, Proxmox first. A live node needs one you chose: the host name is not used for live nodes.', 'beltoft-services' ) );
		}

		return null;
	}

	/**
	 * The nodes exactly as stored (also the way back for writing them).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$list   = array();

		if ( ! is_array( $stored ) ) {
			return $list;
		}

		foreach ( $stored as $id => $node ) {
			if ( is_array( $node ) ) {
				$list[ (string) $id ] = array_merge( self::defaults(), $node );
			}
		}

		return $list;
	}

	/**
	 * A clean https address with a host and no path, user or query, or null.
	 *
	 * @param string $url Address.
	 */
	private static function clean_url( string $url ): ?string {
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

		return $ok ? rtrim( $url, '/' ) : null;
	}

	/**
	 * Encrypts a new secret, or keeps the saved one when the field is empty.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @param string               $saved The stored (encrypted) secret.
	 * @param string               $field Field key.
	 * @param string               $name  Field name for messages.
	 * @return string|\WP_Error
	 */
	private static function clean_secret( array $input, string $saved, string $field, string $name ) {
		$new = trim( (string) ( $input[ $field ] ?? '' ) );

		if ( '' === $new ) {
			return $saved;
		}

		if ( strlen( $new ) < Connections::MIN_SECRET ) {
			/* translators: 1: field name, 2: minimum length. */
			return self::error( 'secret_short', sprintf( __( '%1$s needs at least %2$d characters.', 'beltoft-services' ), $name, Connections::MIN_SECRET ) );
		}

		if ( ! Crypto::available() ) {
			return self::error( 'no_key', __( 'BELTOFT_SERVICES_KEY is not configured: a secret cannot be stored safely.', 'beltoft-services' ) );
		}

		return Crypto::encrypt( $new );
	}

	/**
	 * An error.
	 *
	 * @param string $code    Code suffix.
	 * @param string $message Message.
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'beltoft_services_hosting_node_' . $code, $message );
	}
}
