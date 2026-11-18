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
	 * POST /order/server/transaction (Server ordering). Fields: product_id, location, dist, password, addon[], test.
	 *
	 * @param array<string, mixed> $fields product_id (string), location, dist, password (strings), addon (string[]), test (bool).
	 * @return array<string, mixed>|\WP_Error The `transaction` object.
	 */
	public function order( array $fields );

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
