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
	 * A new main account password the provider accepts. Its policy: 12 to 128 characters from its allowed set, at least one
	 * upper case letter, one lower case letter, one number and one special character. Letters, digits, "-" and "_" are all
	 * allowed; one of each kind is placed for certain, the rest is random, then the order is shuffled.
	 */
	public static function make_password(): string {
		$sets  = array( 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '-_' );
		$all   = implode( '', $sets );
		$chars = array();
		foreach ( $sets as $set ) {
			$chars[] = $set[ random_int( 0, strlen( $set ) - 1 ) ];
		}
		while ( count( $chars ) < 30 ) {
			$chars[] = $all[ random_int( 0, strlen( $all ) - 1 ) ];
		}
		for ( $i = count( $chars ) - 1; $i > 0; --$i ) {
			$j           = random_int( 0, $i );
			$t           = $chars[ $i ];
			$chars[ $i ] = $chars[ $j ];
			$chars[ $j ] = $t;
		}

		return implode( '', $chars );
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

		// Steps of 1024: the provider sizes its boxes in binary units and sells 1 099 511 627 776 bytes as "1 TB" (owner
		// 2026-11-18: the list said "1.1 TB" for the 1 TB box). Used space is counted the same way, as backup tools do.
		while ( $value >= 1024 && $i < count( $units ) - 1 ) {
			$value /= 1024;
			++$i;
		}

		$whole = $value >= 100 || 0 === $i || abs( $value - round( $value ) ) < 0.05; // "1 TB", not "1.0 TB".

		return ( $whole ? number_format_i18n( $value, 0 ) : number_format_i18n( $value, 1 ) ) . ' ' . $units[ $i ];
	}

	/** Transient prefix of the snapshot list a page shows (one minute per box). */
	public const SNAPSHOTS_CACHE = 'beltoft_services_storage_snaps_';

	/**
	 * The box's snapshots for the account page: read from the provider at most once a minute per box (the read takes about half a
	 * second and the page made it on every load, owner 2026-11-18: "page is a bit slow"). A take, delete or restore clears it
	 * ({@see Boxes::forget_snapshots()}), so the customer sees the change at once; a planned snapshot shows within a minute.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function snapshots( Resource $r ) {
		$id  = self::id( $r );
		$key = self::SNAPSHOTS_CACHE . $r->id();
		$hit = get_transient( $key );

		if ( is_array( $hit ) && ( $hit['box'] ?? '' ) === $id ) {
			return (array) $hit['list'];
		}

		$list = StorageClients::for()->snapshots( $id );

		if ( is_array( $list ) ) {
			set_transient( $key, array( 'box' => $id, 'list' => $list ), MINUTE_IN_SECONDS );
		}

		return $list;
	}

	/**
	 * Forgets the cached snapshot list of a box (after a take, delete or restore).
	 *
	 * @param Resource $r Resource.
	 */
	public static function forget_snapshots( Resource $r ): void {
		delete_transient( self::SNAPSHOTS_CACHE . $r->id() );
	}

	/**
	 * Is this error "the box (or record) is not there"? For a delete that means it is done.
	 *
	 * @param \WP_Error $e Error.
	 */
	public static function not_found( \WP_Error $e ): bool {
		$data = $e->get_error_data();

		return str_ends_with( $e->get_error_code(), 'not_found' ) || str_ends_with( $e->get_error_code(), 'http_404' ) || ( is_array( $data ) && 404 === (int) ( $data['status'] ?? 0 ) );
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
