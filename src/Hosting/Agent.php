<?php
/**
 * The node agent client interface.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * What the hosting driver needs from the agent on a node: submit one job of the fixed operation list, and look
 * at it later. Implemented by FakeAgent (tests, staging) and LiveAgent. The live client is built by Clients
 * only when BELTOFT_SERVICES_HOSTING_LIVE is true and the node says live.
 *
 * Job ids make a retry safe: the same id twice returns the first job, never runs it again (plan, node agent).
 *
 * A job is an array: id, status (queued, running, done, failed), result (array, done only) and error (string,
 * failed only). Transport and refusal errors are WP_Error with the codes transport, http_<status>
 * (data['status']), unknown_op and invalid.
 */
interface Agent {

	/**
	 * Submits a job (POST /jobs, 202).
	 *
	 * @param string               $op     Operation, for example site.create.
	 * @param array<string, mixed> $args   Arguments, validated by the operation's schema.
	 * @param string               $job_id Job id, chosen by the caller.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function submit( string $op, array $args, string $job_id );

	/**
	 * Reads a job (GET /jobs/{id}).
	 *
	 * @param string $job_id Job id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function job( string $job_id );
}
