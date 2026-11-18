<?php
/**
 * The calls of the Robot webservice that the dedicated server driver uses, and nothing else.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Documented at https://robot.hetzner.com/doc/webservice/en.html. Every method returns the documented object (the content of
 * `transaction`, `server` or `cancellation`) or a WP_Error whose code is the documented error code (INVALID_INPUT,
 * PRECONDITION_FAILED, RATE_LIMIT_EXCEEDED, NOT_FOUND, SERVER_NOT_FOUND, CONFLICT, INTERNAL_ERROR) and whose data holds `status`
 * (the HTTP status; 0 when no answer came). The error message never holds a request body (it may hold the root password).
 */
interface RobotClient {

	/**
	 * POST /order/server/transaction (Server ordering). Fields: product_id, location, dist, password or authorized_key[] (the documentation: either one), addon[], test.
	 *
	 * @param array<string, mixed> $fields product_id (string), location, dist, password (strings), authorized_key and addon (string[]), test (bool).
	 * @return array<string, mixed>|\WP_Error The `transaction` object.
	 */
	public function order( array $fields );

	/**
	 * POST /key (SSH keys): "Add a new SSH key"; fields name and data ("SSH key data in OpenSSH or SSH2 format"). 201 with the key
	 * (name, fingerprint, type, size, data); 409 KEY_ALREADY_EXISTS "The supplied key already exists"; 400 INVALID_INPUT.
	 *
	 * @param string $name Key name.
	 * @param string $data Key data.
	 * @return array<string, mixed>|\WP_Error The `key` object.
	 */
	public function add_key( string $name, string $data );

	/**
	 * GET /key (SSH keys): "Query all SSH keys", each with fingerprint and data. A 404 NOT_FOUND is documented for it.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error The `key` objects.
	 */
	public function keys();

	/**
	 * GET /order/server/transaction/{id}.
	 *
	 * @param string $id Transaction id.
	 * @return array<string, mixed>|\WP_Error The `transaction` object.
	 */
	public function transaction( string $id );

	/**
	 * GET /server/{server-number}.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error The `server` object.
	 */
	public function server( int $number );

	/**
	 * POST /server/{server-number}/cancellation with cancellation_date=now and reserve_location=false.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error The `cancellation` object.
	 */
	public function cancel( int $number );
}
