<?php
/**
 * The contract of the DNS writer for storage host name aliases.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Four calls on one zone. A record is an array: id, name (lower case, no trailing dot), type, content, proxied. Every method
 * returns its value or a WP_Error whose message is for the admin (never a token). The caller (HostAlias) decides what is
 * ours: it never passes a name outside the alias pattern.
 */
interface AliasDns {

	/**
	 * The records at a name (any type).
	 *
	 * @param string $name Full host name.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function find( string $name );

	/**
	 * Makes a CNAME that is not proxied.
	 *
	 * @param string $name   Full host name.
	 * @param string $target Target host name.
	 * @return array<string, mixed>|\WP_Error The record.
	 */
	public function create( string $name, string $target );

	/**
	 * Points an existing CNAME at a new target.
	 *
	 * @param string $id     Record id.
	 * @param string $name   Full host name.
	 * @param string $target Target host name.
	 * @return true|\WP_Error
	 */
	public function update( string $id, string $name, string $target );

	/**
	 * Deletes a record.
	 *
	 * @param string $id Record id.
	 * @return true|\WP_Error
	 */
	public function delete( string $id );
}
