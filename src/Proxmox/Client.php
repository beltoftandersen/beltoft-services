<?php
/**
 * The Proxmox API client interface.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * What the Proxmox driver needs from a cluster: one request, a task call and a task wait.
 * Implemented by FakeClient (tests, staging) and LiveClient (guarded, never enabled by default).
 *
 * Errors are WP_Error with the codes transport, http_<status> (data['status']), bad_response,
 * task_failed and task_pending.
 */
interface Client {

	/**
	 * One API call. $path is relative to /api2/json and starts with a slash. GET and DELETE send
	 * the parameters in the query string, POST and PUT form-encoded. The JSON "data" member is
	 * returned.
	 *
	 * @param string               $method GET, POST, PUT or DELETE.
	 * @param string               $path   Path such as /nodes.
	 * @param array<string, mixed> $params Parameters by the documented names.
	 * @return array|string|int|float|bool|null|\WP_Error
	 */
	public function request( string $method, string $path, array $params = array() );

	/**
	 * A call whose answer must be a task id (UPID).
	 *
	 * @param string               $method GET, POST, PUT or DELETE.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 * @return string|\WP_Error The UPID.
	 */
	public function task( string $method, string $path, array $params = array() );

	/**
	 * Polls a task every 2 seconds until it stops, or the timeout runs out.
	 *
	 * @param string $upid    Task id.
	 * @param int    $timeout Seconds to wait at most.
	 * @return true|\WP_Error true only when the exit status is exactly OK; task_failed otherwise;
	 *                        task_pending when the time ran out (callers retry, never fail).
	 */
	public function wait_task( string $upid, int $timeout );
}
