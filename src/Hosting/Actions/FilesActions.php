<?php
/**
 * File access of a hosting account: the SFTP details, the SFTP password and the SSH keys.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * File access of a hosting account: the SFTP details, the SFTP password and the SSH keys. Reached through the
 * HostingActions facade; results are arrays and a refusal is a WP_Error. The user name is `web` and is the same for
 * every website of the account. A password is made or accepted here, sent to the node once and returned once to the
 * browser that asked: it is never stored, logged or emailed by this site. Public SSH keys are kept (the node replaces
 * the whole list on every change); they are not secrets. The emailed code step is the route's (HostingRoutes).
 */
final class FilesActions {

	use Base;

	/** Keys per account. */
	public const MAX_KEYS = 10;

	/** The shape the node accepts for a public key (the agent's own check, kept in step). */
	private const KEY = '/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\.com) [A-Za-z0-9+\/=]{20,2000}(?: [^\r\n\x00]{0,100})?$/D';

	/**
	 * What the page shows: where to connect, the keys and whether a password was ever set.
	 *
	 * @return array<string, mixed>
	 */
	public function files(): array {
		$record = Records::for_resource( $this->r->id() );
		$node   = null === $record ? array() : (array) ( Nodes::get( (string) $record['node'] ) ?? array() );
		$fresh  = Resource::find( $this->r->id() ) ?? $this->r;
		$keys   = array();

		foreach ( $this->keys( $fresh ) as $key ) {
			$keys[] = array(
				'fingerprint' => self::fingerprint( $key ),
				'type'        => explode( ' ', $key )[0],
				'comment'     => (string) ( explode( ' ', $key, 3 )[2] ?? '' ),
			);
		}

		return array(
			'host'         => (string) ( $node['edge_ip'] ?? '' ),
			'port'         => (int) ( $record['ssh_port'] ?? 0 ),
			'user'         => 'web',
			'ssh'          => ! empty( $this->plan['ssh'] ),
			'keys'         => $keys,
			'password_set' => (int) $fresh->get( 'sftp_password_at' ) > 0,
		);
	}

	/**
	 * Sets the SFTP password: the one given (16 characters or more), or a generated one. Returned once.
	 *
	 * @param string $chosen The customer's own password, or '' for a generated one.
	 * @return array<string, mixed>|\WP_Error password, host, port, user.
	 */
	public function set_sftp_password( string $chosen = '' ) {
		return $this->locked( fn() => $this->set_sftp_password_locked( $chosen ) );
	}

	/**
	 * set_sftp_password with the lock held.
	 *
	 * @return mixed
	 */
	private function set_sftp_password_locked( string $chosen ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( '' !== $chosen && ( strlen( $chosen ) < 16 || strlen( $chosen ) > 200 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $chosen ) ) ) {
			return $this->error( 'invalid', __( 'A password needs 16 to 200 characters.', 'beltoft-services' ) );
		}

		$password = '' !== $chosen ? $chosen : wp_generate_password( 24, false );

		$this->secrets[] = $password;
		$record          = (array) Records::for_resource( $this->r->id() );
		$done            = $this->call( 'sftp_password', 'ssh.password', array( 'vmid' => (int) $record['vmid'], 'password' => $password ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		( Resource::find( $this->r->id() ) ?? $this->r )->set( array( 'sftp_password_at' => time() ) );
		$info = $this->files();

		return array(
			'password' => $password,
			'host'     => $info['host'],
			'port'     => $info['port'],
			'user'     => $info['user'],
		);
	}

	/**
	 * Adds a public SSH key.
	 *
	 * @param string $key A public key line.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_ssh_key( string $key ) {
		return $this->locked( fn() => $this->change_keys( $key, '' ) );
	}

	/**
	 * Removes a public SSH key by its fingerprint.
	 *
	 * @param string $fingerprint SHA256:... as shown.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_ssh_key( string $fingerprint ) {
		return $this->locked( fn() => $this->change_keys( '', $fingerprint ) );
	}

	/**
	 * The list of keys changes with the lock held: the node gets the whole new list, then it is kept.
	 *
	 * @param string $add    A key to add, or ''.
	 * @param string $remove A fingerprint to remove, or ''.
	 * @return mixed
	 */
	private function change_keys( string $add, string $remove ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$fresh = Resource::find( $this->r->id() ) ?? $this->r;
		$keys  = $this->keys( $fresh );

		if ( '' !== $add ) {
			$line = trim( (string) preg_replace( '/\s+/', ' ', $add ) );

			if ( 1 !== preg_match( self::KEY, $line ) || null === self::fingerprint( $line ) ) {
				return $this->error( 'invalid', __( 'That is not a public SSH key. Paste the line from your .pub file.', 'beltoft-services' ) );
			}

			foreach ( $keys as $have ) {
				if ( self::fingerprint( $have ) === self::fingerprint( $line ) ) {
					return $this->error( 'exists', __( 'That key is already added.', 'beltoft-services' ) );
				}
			}

			if ( count( $keys ) >= self::MAX_KEYS ) {
				/* translators: %d: most keys. */
				return $this->error( 'limit', sprintf( __( 'You can add up to %d keys. Remove one first.', 'beltoft-services' ), self::MAX_KEYS ) );
			}

			$keys[] = $line;
		}

		if ( '' !== $remove ) {
			$left = array_values( array_filter( $keys, static fn( $k ) => self::fingerprint( $k ) !== $remove ) );

			if ( count( $left ) === count( $keys ) ) {
				return $this->error( 'not_found', __( 'That key is not on your account.', 'beltoft-services' ) );
			}

			$keys = $left;
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'sftp_keys', 'ssh.keys', array( 'vmid' => (int) $record['vmid'], 'keys' => $keys ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$fresh->set( array( 'ssh_keys' => $keys ) );

		return array( 'keys' => count( $keys ) );
	}

	/**
	 * The stored public keys.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	private function keys( Resource $r ): array {
		return array_values( array_filter( array_map( 'strval', (array) $r->get( 'ssh_keys' ) ), static fn( $k ) => '' !== $k ) );
	}

	/**
	 * The fingerprint of a public key line, SHA256:<base64 without padding>, or null when the key part is not base64.
	 *
	 * @param string $line Public key line.
	 */
	public static function fingerprint( string $line ): ?string {
		$blob = base64_decode( explode( ' ', $line )[1] ?? '', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a public key's own encoding.

		return false === $blob || '' === $blob ? null : 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the fingerprint format of OpenSSH.
	}
}
