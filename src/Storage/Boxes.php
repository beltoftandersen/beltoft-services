<?php
/**
 * Reading and describing the box of a storage resource.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Crypto;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The shared helpers of the Storage folder: the meta keys, the stored (encrypted) password, the usage refresh and the
 * connection facts of a box. Stored data never holds class names.
 */
final class Boxes {

	/** Seconds a usage read is reused. */
	public const FRESH = 60;

	/** Ports of the SSH based protocols: SFTP and SCP on 22, rsync, borg and SFTP on the SSH port 23. */
	public const PORT_SFTP = 22;
	public const PORT_SSH  = 23;

	/**
	 * The provider's id of the box, '' when there is none yet.
	 *
	 * @param Resource $r Resource.
	 */
	public static function id( Resource $r ): string {
		return (string) $r->get( 'storage_box_id' );
	}

	/**
	 * A new main account password: long, with a special character so the provider accepts it.
	 */
	public static function make_password(): string {
		return wp_generate_password( 24, false ) . '-' . wp_generate_password( 6, false );
	}

	/**
	 * The password, decrypted, or null when there is none or it cannot be read. Only the reveal route may send it out.
	 *
	 * @param Resource $r Resource.
	 */
	public static function password( Resource $r ): ?string {
		$stored = (string) $r->get( 'storage_password' );

		return '' === $stored ? null : Crypto::decrypt( $stored );
	}

	/**
	 * The access switches a new box gets and a resumed box goes back to: SSH based access on, the rest off.
	 *
	 * @return array<string, bool>
	 */
	public static function default_access(): array {
		return array(
			'reachable_externally' => true,
			'ssh_enabled'          => true,
			'samba_enabled'        => false,
			'webdav_enabled'       => false,
			'zfs_enabled'          => true,
		);
	}

	/**
	 * The switches that mean "no access": nothing reaches the box from outside, no protocol is on.
	 *
	 * @return array<string, bool>
	 */
	public static function locked_access(): array {
		return array(
			'reachable_externally' => false,
			'ssh_enabled'          => false,
			'samba_enabled'        => false,
			'webdav_enabled'       => false,
		);
	}

	/**
	 * Reads the box and keeps its usage in the resource meta. A read in a request is allowed; it is reused for FRESH seconds.
	 *
	 * @param Resource $r     Resource.
	 * @param bool     $force Read now, whatever the age.
	 * @return array<string, mixed>|null The box, or null when it could not be read.
	 */
	public static function refresh( Resource $r, bool $force = false ): ?array {
		$id = self::id( $r );

		if ( '' === $id || ( ! $force && time() - (int) $r->get( 'storage_checked_at' ) < self::FRESH ) ) {
			return null;
		}

		$box = StorageClients::for()->get_box( $id );

		if ( is_wp_error( $box ) ) {
			return null;
		}

		$r->set(
			array(
				'storage_size_bytes'     => (int) $box['size_bytes'],
				'storage_used_bytes'     => (int) $box['used_bytes'],
				'storage_snapshot_bytes' => (int) $box['snapshot_bytes'],
				'storage_access'         => (array) $box['access'],
				'storage_checked_at'     => time(),
			)
		);

		return $box;
	}

	/**
	 * The facts a customer needs to connect. The password is never in here.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function connection( Resource $r ): array {
		$user   = (string) $r->get( 'storage_username' );
		$access = (array) $r->get( 'storage_access' );
		$size   = (int) $r->get( 'storage_size_bytes' );
		$used   = (int) $r->get( 'storage_used_bytes' );
		$snaps  = (int) $r->get( 'storage_snapshot_bytes' );

		return array(
			'host'           => '' === $user ? '' : StorageSettings::shown_host( (string) $r->get( 'storage_alias' ), $user, (string) $r->get( 'storage_host' ) ),
			'tls_host'       => (string) $r->get( 'storage_host' ),
			'user'           => $user,
			'port_sftp'      => self::PORT_SFTP,
			'port_ssh'       => self::PORT_SSH,
			'ssh'            => ! empty( $access['ssh_enabled'] ),
			'smb'            => ! empty( $access['samba_enabled'] ),
			'webdav'         => ! empty( $access['webdav_enabled'] ),
			'country'        => StorageSettings::country(),
			'size_bytes'     => $size,
			'used_bytes'     => $used,
			'snapshot_bytes' => $snaps,
			'percent'        => $size > 0 ? (int) min( 100, round( 100 * ( $used + $snaps ) / $size ) ) : 0,
		);
	}

	/**
	 * A byte count in words: 1.2 TB.
	 *
	 * @param int $bytes Bytes.
	 */
	public static function size_text( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$value = (float) max( 0, $bytes );
		$i     = 0;

		while ( $value >= 1000 && $i < count( $units ) - 1 ) {
			$value /= 1000;
			++$i;
		}

		return ( $value >= 100 || 0 === $i ? number_format_i18n( $value, 0 ) : number_format_i18n( $value, 1 ) ) . ' ' . $units[ $i ];
	}

	/**
	 * The error text for the history and the retry reason: the provider message for the admin, never a secret.
	 *
	 * @param \WP_Error $e Error.
	 */
	public static function why( \WP_Error $e ): string {
		return $e->get_error_message();
	}
}
