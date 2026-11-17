<?php
/**
 * The delete of one backup volume that a customer asked for, shared by servers, databases and hosting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * For a class that uses Steps: sends `DELETE .../storage/{storage}/content/{volid}` and waits the bounded time, and says what an
 * error means. It never judges the outcome: the caller reads the listing again, because the volume being gone is what counts, not
 * the text of an error. The task id is stored in the resource's `task` meta before the wait (like every task step), so a lost answer
 * is polled by the next run, not started again.
 */
trait DeletesVolumes {

	/**
	 * Starts the delete and waits.
	 *
	 * @param string $path  Content path of the volume (node, storage and the encoded volume id).
	 * @param string $step  Step name for the admin text and the stored task.
	 * @param string $phase Phase kept with the task.
	 * @return true|\WP_Error|StepResult True: the task ended OK. WP_Error: the call or the task failed (the listing decides). StepResult: paused, still running, or not stored.
	 */
	protected function send_volume_delete( string $path, string $step, string $phase ) {
		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, $step . ': all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$upid = $this->client->task( 'DELETE', $path );

		if ( is_wp_error( $upid ) ) {
			return $upid;
		}

		$node = 1 === preg_match( '/^UPID:([A-Za-z0-9][A-Za-z0-9.\-]*):/', $upid, $m ) ? $m[1] : '';

		if ( ! $this->r->set( array( 'task' => array( 'step' => $step, 'phase' => $phase, 'upid' => $upid, 'node' => $node ) ) ) ) {
			return StepResult::retry( 0, $step . ': the task id could not be stored' );
		}

		$result = $this->client->wait_task( $upid, $this->task_wait() );

		if ( is_wp_error( $result ) && 'task_pending' === $result->get_error_code() ) {
			return $this->waiting( $step . ': waiting for the Proxmox task', $this->task_budget( $phase ) );
		}

		if ( ! $this->r->set( array( 'task' => null ) ) ) {
			return StepResult::retry( 0, $step . ': the finished task could not be cleared' );
		}

		return $result;
	}

	/**
	 * Is this error one that may pass (no answer, a node that is busy, a rate limit), as opposed to a refusal of the delete (a 4xx,
	 * the 500 Proxmox uses for "no", a task that ended in error)?
	 *
	 * @param \WP_Error $e Error.
	 */
	protected function volume_delete_transient( \WP_Error $e ): bool {
		$data   = $e->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		if ( 'task_failed' === $e->get_error_code() ) {
			return false;
		}

		return 0 === $status || in_array( $status, array( 408, 429 ), true ) || $status >= 501;
	}
}
