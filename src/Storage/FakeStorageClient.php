<?php
/**
 * The in-memory storage client for tests and staging.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps its state in one option and follows the provider rules that matter: the password policy, unique names, a new box
 * is `initializing` until it has been read twice, the delete lock, no smaller type, the snapshot limit of the type, and no
 * usage per sub-account. Deterministic: no clock, no randomness. Nothing here reaches a network.
 */
final class FakeStorageClient implements StorageClient {

	public const OPTION = 'beltoft_services_storage_fake';

	/** Reads that a new box stays `initializing` for. */
	private const INIT_READS = 1;

	/**
	 * The types it sells: size in bytes and manual snapshot limit.
	 *
	 * @return array<string, array{size_bytes: int, snapshots: int}>
	 */
	public static function catalogue(): array {
		return array(
			'bx11' => array( 'size_bytes' => 1000000000000, 'snapshots' => 10 ),
			'bx21' => array( 'size_bytes' => 5000000000000, 'snapshots' => 20 ),
			'bx31' => array( 'size_bytes' => 10000000000000, 'snapshots' => 30 ),
			'bx41' => array( 'size_bytes' => 20000000000000, 'snapshots' => 40 ),
		);
	}

	/**
	 * The whole state (boxes, snapshots, counters), for tests.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state + array( 'next' => 1000, 'boxes' => array(), 'snaps' => array(), 'creates' => 0 ) : array( 'next' => 1000, 'boxes' => array(), 'snaps' => array(), 'creates' => 0 );
	}

	/**
	 * Forgets everything.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Saves the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * An error in the provider's words.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'storage_' . $code, $message );
	}

	/**
	 * The public form of a stored box (no password hash, no counters).
	 *
	 * @param array<string, mixed> $box Stored box.
	 * @return array<string, mixed>
	 */
	private function view( array $box ): array {
		unset( $box['password_hash'], $box['reads'] );

		return $box;
	}

	/**
	 * Whether a password passes the provider's policy: at least 12 characters and a special character.
	 *
	 * @param string $password Password.
	 */
	public static function password_ok( string $password ): bool {
		return strlen( $password ) >= 12 && 1 === preg_match( '/[^A-Za-z0-9]/', $password );
	}

	public function types() {
		return self::catalogue();
	}

	public function find_box( string $name ) {
		foreach ( self::state()['boxes'] as $box ) {
			if ( $box['name'] === $name ) {
				return $this->view( $box );
			}
		}

		return null;
	}

	public function get_box( string $id ) {
		$state = self::state();

		if ( ! isset( $state['boxes'][ $id ] ) ) {
			return $this->error( 'not_found', 'There is no such box.' );
		}

		$box = $state['boxes'][ $id ];

		if ( 'initializing' === $box['status'] ) {
			$box['reads'] = (int) ( $box['reads'] ?? 0 ) + 1;

			if ( $box['reads'] > self::INIT_READS ) {
				$box['status'] = 'active';
			}

			$state['boxes'][ $id ] = $box;
			$this->save( $state );
		}

		return $this->view( $box );
	}

	public function create_box( string $name, string $type, string $location, string $password, array $keys, array $access ) {
		$state = self::state();
		$types = self::catalogue();

		if ( ! isset( $types[ $type ] ) ) {
			return $this->error( 'invalid_type', 'The box type is not sold.' );
		}

		if ( ! self::password_ok( $password ) ) {
			return $this->error( 'invalid_password', 'The password needs at least 12 characters and a special character.' );
		}

		foreach ( $state['boxes'] as $box ) {
			if ( $box['name'] === $name ) {
				return $this->error( 'name_not_unique', 'A box with this name exists.' );
			}
		}

		unset( $keys );

		$id    = (string) $state['next'];
		$state['next']++;
		$state['creates']++;
		$state['boxes'][ $id ] = array(
			'id'             => $id,
			'name'           => $name,
			'status'         => 'initializing',
			'username'       => 'u' . $id,
			'host'           => 'u' . $id . '.storage.test',
			'type'           => $type,
			'location'       => $location,
			'size_bytes'     => $types[ $type ]['size_bytes'],
			'used_bytes'     => 0,
			'snapshot_bytes' => 0,
			'access'         => $access + array( 'reachable_externally' => false, 'ssh_enabled' => false, 'samba_enabled' => false, 'webdav_enabled' => false, 'zfs_enabled' => false ),
			'protected'      => false,
			'snapshot_plan'  => null,
			'password_hash'  => hash( 'sha256', $password ),
			'reads'          => 0,
		);
		$this->save( $state );

		return $this->view( $state['boxes'][ $id ] );
	}

	/**
	 * A usage figure for tests: sets what a box reports as used.
	 *
	 * @param string $id             Box id.
	 * @param int    $used_bytes     Data.
	 * @param int    $snapshot_bytes Snapshots.
	 */
	public function set_used( string $id, int $used_bytes, int $snapshot_bytes = 0 ): void {
		$state = self::state();

		if ( isset( $state['boxes'][ $id ] ) ) {
			$state['boxes'][ $id ]['used_bytes']     = $used_bytes;
			$state['boxes'][ $id ]['snapshot_bytes'] = $snapshot_bytes;
			$this->save( $state );
		}
	}

	/**
	 * Whether a password is the box's (a test check).
	 *
	 * @param string $id       Box id.
	 * @param string $password Password.
	 */
	public function password_is( string $id, string $password ): bool {
		$state = self::state();

		return isset( $state['boxes'][ $id ] ) && hash_equals( (string) $state['boxes'][ $id ]['password_hash'], hash( 'sha256', $password ) );
	}

	/**
	 * Changes one stored box through a callback; an error when it is not there.
	 *
	 * @param string   $id    Box id.
	 * @param callable $apply Receives the stored box, returns it changed or a WP_Error.
	 * @return true|\WP_Error
	 */
	private function edit( string $id, callable $apply ) {
		$state = self::state();

		if ( ! isset( $state['boxes'][ $id ] ) ) {
			return $this->error( 'not_found', 'There is no such box.' );
		}

		$box = $apply( $state['boxes'][ $id ] );

		if ( $box instanceof \WP_Error ) {
			return $box;
		}

		$state['boxes'][ $id ] = $box;
		$this->save( $state );

		return true;
	}

	public function change_type( string $id, string $type ) {
		$types = self::catalogue();

		return $this->edit(
			$id,
			function ( array $box ) use ( $type, $types ) {
				if ( ! isset( $types[ $type ] ) ) {
					return $this->error( 'invalid_type', 'The box type is not sold.' );
				}

				if ( $types[ $type ]['size_bytes'] < $box['size_bytes'] ) {
					return $this->error( 'downgrade', 'A smaller type is not possible.' );
				}

				$box['type']       = $type;
				$box['size_bytes'] = $types[ $type ]['size_bytes'];

				return $box;
			}
		);
	}

	public function reset_password( string $id, string $password ) {
		return $this->edit(
			$id,
			function ( array $box ) use ( $password ) {
				if ( ! self::password_ok( $password ) ) {
					return $this->error( 'invalid_password', 'The password needs at least 12 characters and a special character.' );
				}

				$box['password_hash'] = hash( 'sha256', $password );

				return $box;
			}
		);
	}

	public function set_access( string $id, array $access ) {
		return $this->edit(
			$id,
			static function ( array $box ) use ( $access ) {
				foreach ( $access as $key => $value ) {
					if ( array_key_exists( $key, $box['access'] ) ) {
						$box['access'][ $key ] = (bool) $value;
					}
				}

				return $box;
			}
		);
	}

	public function set_protection( string $id, bool $protected ) {
		return $this->edit(
			$id,
			static function ( array $box ) use ( $protected ) {
				$box['protected'] = $protected;

				return $box;
			}
		);
	}

	public function delete_box( string $id ) {
		$state = self::state();

		if ( ! isset( $state['boxes'][ $id ] ) ) {
			return true;
		}

		if ( $state['boxes'][ $id ]['protected'] ) {
			return $this->error( 'protected', 'The box is locked against deletion.' );
		}

		unset( $state['boxes'][ $id ], $state['snaps'][ $id ] );
		$this->save( $state );

		return true;
	}

	public function snapshots( string $id ) {
		$state = self::state();

		if ( ! isset( $state['boxes'][ $id ] ) ) {
			return $this->error( 'not_found', 'There is no such box.' );
		}

		return array_reverse( array_values( $state['snaps'][ $id ] ?? array() ) );
	}

	public function create_snapshot( string $id, string $name ) {
		$state = self::state();

		if ( ! isset( $state['boxes'][ $id ] ) ) {
			return $this->error( 'not_found', 'There is no such box.' );
		}

		$limit = self::catalogue()[ $state['boxes'][ $id ]['type'] ]['snapshots'] ?? 10;
		$have  = $state['snaps'][ $id ] ?? array();
		$manual = array_filter( $have, static fn( $s ) => empty( $s['automatic'] ) );

		if ( count( $manual ) >= $limit ) {
			return $this->error( 'snapshot_limit_exceeded', 'The snapshot limit of the box is reached.' );
		}

		$snap                      = array( 'id' => (string) $state['next'], 'name' => $name, 'created' => 1800000000 + (int) $state['next'], 'size_bytes' => 0, 'automatic' => false );
		$state['next']++;
		$state['snaps'][ $id ][ $snap['id'] ] = $snap;
		$this->save( $state );

		return $snap;
	}

	public function delete_snapshot( string $id, string $snapshot ) {
		$state = self::state();

		if ( ! isset( $state['snaps'][ $id ][ $snapshot ] ) ) {
			return $this->error( 'not_found', 'There is no such snapshot.' );
		}

		unset( $state['snaps'][ $id ][ $snapshot ] );
		$this->save( $state );

		return true;
	}

	public function rollback_snapshot( string $id, string $snapshot ) {
		$state = self::state();

		if ( ! isset( $state['snaps'][ $id ][ $snapshot ] ) ) {
			return $this->error( 'not_found', 'There is no such snapshot.' );
		}

		// The provider removes every snapshot newer than the one rolled back to.
		$made = (int) $state['snaps'][ $id ][ $snapshot ]['created'];

		foreach ( $state['snaps'][ $id ] as $key => $snap ) {
			if ( (int) $snap['created'] > $made ) {
				unset( $state['snaps'][ $id ][ $key ] );
			}
		}

		$this->save( $state );

		return true;
	}

	public function set_snapshot_plan( string $id, ?array $plan ) {
		return $this->edit(
			$id,
			static function ( array $box ) use ( $plan ) {
				$box['snapshot_plan'] = $plan;

				return $box;
			}
		);
	}
}
