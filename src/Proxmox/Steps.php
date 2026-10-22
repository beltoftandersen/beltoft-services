<?php
/**
 * Parts shared by the Proxmox operations that run as driver steps.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Task polling, bounded task waits, error classification and scrubbing, written once for
 * Provision and Operations. The using class sets $r, $client, $secrets and $wait.
 */
trait Steps {
	/**
	 * Resource.
	 *
	 * @var Resource
	 */
	protected $r;

	/**
	 * Client of the cluster.
	 *
	 * @var Client
	 */
	protected $client;

	/**
	 * Values removed from every error text.
	 *
	 * @var string[]
	 */
	protected $secrets = array();

	/**
	 * Seconds to wait for one task.
	 *
	 * @var int
	 */
	protected $wait = 25;

	/**
	 * The task that finished since the last run: step, phase, ok, exit.
	 *
	 * @var array<string, mixed>|null
	 */
	protected $finished = null;

	/**
	 * A task stored by an earlier run is polled first. Still running: that is this run's answer.
	 * Finished (well or not): the steps go on and read the remote state, which is what counts.
	 */
	protected function poll_task(): ?StepResult {
		$task = $this->r->get( 'task' );

		if ( ! is_array( $task ) || empty( $task['upid'] ) || ! is_string( $task['upid'] ) ) {
			return null;
		}

		$step   = (string) ( $task['step'] ?? '' );
		$result = $this->client->wait_task( $task['upid'], $this->wait );

		if ( is_wp_error( $result ) && 'task_pending' === $result->get_error_code() ) {
			return StepResult::retry( 0, $step . ': waiting for the Proxmox task' );
		}

		if ( is_wp_error( $result ) && 'task_failed' !== $result->get_error_code() ) {
			return StepResult::retry( 0, $step . ': ' . $this->text( $result ) );
		}

		$this->finished = array(
			'step'  => $step,
			'phase' => (string) ( $task['phase'] ?? '' ),
			'ok'    => true === $result,
			'exit'  => is_wp_error( $result ) ? $this->exit_status( $result ) : 'OK',
		);

		if ( ! $this->r->set( array( 'task' => null ) ) ) {
			return StepResult::retry( 0, $step . ': the finished task could not be cleared' );
		}

		return null;
	}

	/**
	 * The VM, verified as ours (tag, range, record), with its current node, config and state.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param string               $step   Step name for messages.
	 * @return array<string, mixed>|StepResult
	 */
	protected function verified( array $record, string $step ) {
		$fresh = VmRecords::for_resource( $this->r->id() );
		$v     = Ownership::verify( $this->client, $fresh ? $fresh : $record, $this->r );

		return is_wp_error( $v ) ? $this->refuse_or_retry( $v, $step ) : $v;
	}

	/**
	 * Ownership failures are permanent, an absent VM and client errors follow the usual rules.
	 *
	 * @param \WP_Error $e    Error.
	 * @param string    $step Step name.
	 */
	protected function refuse_or_retry( \WP_Error $e, string $step ): StepResult {
		if ( in_array( $e->get_error_code(), array( 'no_cluster', 'out_of_range', 'not_recorded', 'not_ours', 'is_template' ), true ) ) {
			return StepResult::fail( $step . ': refused, ' . $e->get_error_message() );
		}

		if ( 'absent' === $e->get_error_code() ) {
			return StepResult::retry( 0, $step . ': ' . $e->get_error_message() );
		}

		return $this->classify( $e, $step );
	}

	/**
	 * Calls a task endpoint, stores its UPID before waiting, waits the bounded time.
	 *
	 * @param string               $step   Step name.
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 * @param string               $phase  Sub-step name kept with the task.
	 * @return true|array{failed: string}|StepResult True: ended OK. failed: the exit status (read the state before believing it). StepResult: retry or fail.
	 */
	protected function run_task( string $step, string $method, string $path, array $params = array(), string $phase = '' ) {
		$upid = $this->client->task( $method, $path, $params );

		if ( is_wp_error( $upid ) ) {
			return $this->classify( $upid, $step );
		}

		$node = 1 === preg_match( '/^UPID:([A-Za-z0-9][A-Za-z0-9.\-]*):/', $upid, $m ) ? $m[1] : '';

		$task = array(
			'step'  => $step,
			'phase' => $phase,
			'upid'  => $upid,
			'node'  => $node,
		);

		if ( ! $this->r->set( array( 'task' => $task ) ) ) {
			return StepResult::retry( 0, $step . ': the task id could not be stored' );
		}

		$result = $this->client->wait_task( $upid, $this->wait );

		if ( is_wp_error( $result ) && 'task_pending' === $result->get_error_code() ) {
			return StepResult::retry( 0, $step . ': waiting for the Proxmox task' );
		}

		if ( is_wp_error( $result ) && 'task_failed' !== $result->get_error_code() ) {
			return StepResult::retry( 0, $step . ': ' . $this->text( $result ) );
		}

		if ( ! $this->r->set( array( 'task' => null ) ) ) {
			return StepResult::retry( 0, $step . ': the finished task could not be cleared' );
		}

		return true === $result ? true : array( 'failed' => $this->exit_status( $result ) );
	}

	/**
	 * Turns a client error into the step's answer: a refusal (4xx other than 408 and 429) fails,
	 * everything else is tried again.
	 *
	 * @param \WP_Error $e    Error.
	 * @param string    $step Step name.
	 */
	protected function classify( \WP_Error $e, string $step ): StepResult {
		$data   = $e->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$text   = $step . ': ' . $this->text( $e );

		if ( $status >= 400 && $status < 500 && ! in_array( $status, array( 408, 429 ), true ) ) {
			return StepResult::fail( $text );
		}

		return StepResult::retry( 0, $text );
	}

	/**
	 * An error as admin text: the code and message, secrets removed, one line, short. Never for customers.
	 *
	 * @param \WP_Error $e Error.
	 */
	protected function text( \WP_Error $e ): string {
		return $this->scrub( $e->get_error_code() . ': ' . $e->get_error_message(), 300 );
	}

	/**
	 * Admin text: secrets removed, one line, short.
	 *
	 * @param string $text   Text.
	 * @param int    $length Most characters.
	 */
	protected function scrub( string $text, int $length ): string {
		$secrets = array_filter( $this->secrets, static fn( $s ) => strlen( (string) $s ) >= 4 );
		usort( $secrets, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$text = str_replace( $secrets, '***', wp_check_invalid_utf8( $text, true ) );

		return mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ), 0, $length );
	}

	/**
	 * The exit status of a failed task.
	 *
	 * @param \WP_Error|true $result Result of wait_task().
	 */
	protected function exit_status( $result ): string {
		if ( ! is_wp_error( $result ) ) {
			return 'OK';
		}

		$data = $result->get_error_data();

		return $this->scrub( is_array( $data ) && isset( $data['exitstatus'] ) && is_scalar( $data['exitstatus'] ) ? (string) $data['exitstatus'] : $result->get_error_message(), 200 );
	}
}
