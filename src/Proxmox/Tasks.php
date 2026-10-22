<?php
/**
 * Task handling shared by the clients.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * task() and wait_task(), written once on top of request().
 */
trait Tasks {

	/**
	 * Sleeps between two polls.
	 *
	 * @param int $seconds Seconds.
	 */
	abstract protected function pause( int $seconds ): void;

	/**
	 * A call whose answer must be a UPID (wiki API: "UPID:<node>:<pid>:<pstart>:<starttime>:<type>:<id>:<user>:").
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 * @return string|\WP_Error
	 */
	public function task( string $method, string $path, array $params = array() ) {
		$answer = $this->request( $method, $path, $params );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		if ( ! is_string( $answer ) || null === self::upid_node( $answer ) ) {
			return new \WP_Error( 'bad_response', 'Proxmox did not answer with a task id.' );
		}

		return $answer;
	}

	/**
	 * Waits for a task: GET /nodes/{node}/tasks/{upid}/status until status is stopped.
	 *
	 * @param string $upid    Task id.
	 * @param int    $timeout Seconds.
	 * @return true|\WP_Error
	 */
	public function wait_task( string $upid, int $timeout ) {
		$node = self::upid_node( $upid );

		if ( null === $node ) {
			return new \WP_Error( 'bad_response', 'Not a Proxmox task id.' );
		}

		$path    = '/nodes/' . rawurlencode( $node ) . '/tasks/' . rawurlencode( $upid ) . '/status';
		$started = time();
		$counted = 0;

		while ( true ) {
			$status = $this->request( 'GET', $path );

			if ( is_wp_error( $status ) ) {
				return $status;
			}

			if ( ! is_array( $status ) || ! isset( $status['status'] ) ) {
				return new \WP_Error( 'bad_response', 'Proxmox sent no task status.' );
			}

			if ( 'stopped' === $status['status'] ) {
				$exit = isset( $status['exitstatus'] ) && is_scalar( $status['exitstatus'] ) ? (string) $status['exitstatus'] : '';

				if ( 'OK' === $exit ) {
					return true;
				}

				return new \WP_Error( 'task_failed', 'Proxmox task failed: ' . mb_substr( wp_check_invalid_utf8( $exit, true ), 0, 200 ), array( 'exitstatus' => $exit ) );
			}

			if ( 'running' !== $status['status'] ) {
				return new \WP_Error( 'bad_response', 'Proxmox sent an unknown task status.' );
			}

			if ( max( $counted, time() - $started ) + 2 > $timeout ) {
				return new \WP_Error( 'task_pending', 'The Proxmox task is still running.' );
			}

			$this->pause( 2 );
			$counted += 2;
		}
	}

	/**
	 * The node a task runs on: the second field of the UPID.
	 *
	 * @param string $upid Task id.
	 */
	public static function upid_node( string $upid ): ?string {
		if ( 1 === preg_match( '/^UPID:([A-Za-z0-9][A-Za-z0-9.\-]*):/', $upid, $m ) ) {
			return $m[1];
		}

		return null;
	}
}
