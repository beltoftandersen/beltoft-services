<?php
/**
 * The client used when live storage is chosen but cannot be reached.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Every call fails with the same error, so jobs wait and retry. It never falls back to the fake, which would record
 * steps as done that never happened at the provider.
 */
final class UnavailableStorageClient implements StorageClient {

	/**
	 * The one error.
	 */
	private function no(): \WP_Error {
		return new \WP_Error( 'storage_unavailable', 'The storage provider connection is not usable: the address or token is missing, or live calls are not allowed.' );
	}

	public function types() {
		return $this->no();
	}

	public function find_box( string $name ) {
		return $this->no();
	}

	public function get_box( string $id ) {
		return $this->no();
	}

	public function create_box( string $name, string $type, string $location, string $password, array $keys, array $access ) {
		return $this->no();
	}

	public function change_type( string $id, string $type ) {
		return $this->no();
	}

	public function reset_password( string $id, string $password ) {
		return $this->no();
	}

	public function set_access( string $id, array $access ) {
		return $this->no();
	}

	public function set_protection( string $id, bool $protected ) {
		return $this->no();
	}

	public function delete_box( string $id ) {
		return $this->no();
	}

	public function snapshots( string $id ) {
		return $this->no();
	}

	public function create_snapshot( string $id, string $name ) {
		return $this->no();
	}

	public function delete_snapshot( string $id, string $snapshot ) {
		return $this->no();
	}

	public function rollback_snapshot( string $id, string $snapshot ) {
		return $this->no();
	}

	public function set_snapshot_plan( string $id, ?array $plan ) {
		return $this->no();
	}
}
