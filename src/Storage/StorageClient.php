<?php
/**
 * The contract of a storage provider client.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * What the Storage driver needs from the storage provider. Every method returns its value or a WP_Error whose message is
 * for the admin (never the token, never a password). A box is an array:
 *
 * id, name, status (initializing, active or locked), username, host, type, location, size_bytes, used_bytes, snapshot_bytes,
 * access (reachable_externally, ssh_enabled, samba_enabled, webdav_enabled, zfs_enabled: booleans), protected (the delete
 * lock), snapshot_plan (null, or every day|week|month, hour, minute, max).
 *
 * A snapshot is an array: id, name, created (unix time), size_bytes, automatic.
 */
interface StorageClient {

	/**
	 * The box types the provider sells: type => size_bytes and snapshots (how many manual snapshots a box may hold).
	 *
	 * @return array<string, array{size_bytes: int, snapshots: int}>|\WP_Error
	 */
	public function types();

	/**
	 * The box with our own name, or null when there is none. This is what makes a create replay safe.
	 *
	 * @param string $name Our box name.
	 * @return array<string, mixed>|null|\WP_Error
	 */
	public function find_box( string $name );

	/**
	 * One box.
	 *
	 * @param string $id Box id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_box( string $id );

	/**
	 * Creates (and so buys) a box. Only the spend guard may lead here.
	 *
	 * @param string               $name     Our box name, unique.
	 * @param string               $type     Box type.
	 * @param string               $location Location code.
	 * @param string               $password Main account password.
	 * @param string[]             $keys     Public SSH keys.
	 * @param array<string, bool>  $access   Access switches.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_box( string $name, string $type, string $location, string $password, array $keys, array $access );

	/**
	 * Changes the type of a box (a larger one; the fake refuses a smaller one).
	 *
	 * @param string $id   Box id.
	 * @param string $type New type.
	 * @return true|\WP_Error
	 */
	public function change_type( string $id, string $type );

	/**
	 * Sets a new main account password.
	 *
	 * @param string $id       Box id.
	 * @param string $password Password.
	 * @return true|\WP_Error
	 */
	public function reset_password( string $id, string $password );

	/**
	 * Sets the access switches (only the keys given change).
	 *
	 * @param string              $id     Box id.
	 * @param array<string, bool> $access Switches.
	 * @return true|\WP_Error
	 */
	public function set_access( string $id, array $access );

	/**
	 * Turns the delete lock of a box on or off.
	 *
	 * @param string $id        Box id.
	 * @param bool   $protected Whether the box is locked against deletion.
	 * @return true|\WP_Error
	 */
	public function set_protection( string $id, bool $protected );

	/**
	 * Deletes a box for good. The fake and the provider refuse while the lock is on.
	 *
	 * @param string $id Box id.
	 * @return true|\WP_Error
	 */
	public function delete_box( string $id );

	/**
	 * The snapshots of a box, newest first.
	 *
	 * @param string $id Box id.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function snapshots( string $id );

	/**
	 * Takes a snapshot.
	 *
	 * @param string $id   Box id.
	 * @param string $name Name of the snapshot.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_snapshot( string $id, string $name );

	/**
	 * Deletes a snapshot.
	 *
	 * @param string $id       Box id.
	 * @param string $snapshot Snapshot id.
	 * @return true|\WP_Error
	 */
	public function delete_snapshot( string $id, string $snapshot );

	/**
	 * Puts the box back as it was at a snapshot (newer snapshots are removed by the provider).
	 *
	 * @param string $id       Box id.
	 * @param string $snapshot Snapshot id.
	 * @return true|\WP_Error
	 */
	public function rollback_snapshot( string $id, string $snapshot );

	/**
	 * Sets the automatic snapshot plan, or turns it off with null.
	 *
	 * @param string                    $id   Box id.
	 * @param array<string, mixed>|null $plan every (day, week or month), hour, minute, max.
	 * @return true|\WP_Error
	 */
	public function set_snapshot_plan( string $id, ?array $plan );
}
