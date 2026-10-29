<?php
/**
 * Parts shared by the Proxmox operations that run as driver steps.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

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
	 * Whether this run got further: a step completed or a Proxmox task ended.
	 *
	 * @var bool
	 */
	protected $progressed = false;

	/**
	 * Seconds to wait for a task now: the task wait, never more than what is left of the run's budget (the
	 * resource lock is held for the run, so a run stays within about one budget).
	 */
	protected function task_wait(): int {
		return max( 0, min( $this->wait, Jobs::time_left() ) );
	}

	/**
	 * The budget of a wait for a task: a shutdown or a stop gets the shutdown timeout plus a minute, anything
	 * else (clone, resize, rollback, snapshot) 30 minutes. Filter `beltoft_services_poll_budget` (seconds, phase).
	 *
	 * @param string $phase Phase kept with the task.
	 */
	protected function task_budget( string $phase ): int {
		$budget = in_array( $phase, array( 'shutdown', 'stop' ), true ) ? max( 10, (int) Settings::get( 'shutdown_timeout' ) ) + 60 : 1800;

		/**
		 * Seconds a wait for a Proxmox task may last before it counts as an error.
		 *
		 * @param int    $budget Seconds.
		 * @param string $phase  Phase: shutdown, stop or another.
		 */
		return max( 1, (int) apply_filters( 'beltoft_services_poll_budget', $budget, $phase ) );
	}

	/**
	 * Was a power task of this kind (`stop`: shutdown or stop; `start`) sent and ended OK in the last minute? The
	 * status can show the old state for a while after it, and a second one would be a second write: the step waits.
	 *
	 * @param string $what stop or start.
	 */
	protected function sent_recently( string $what ): bool {
		$sent = $this->r->get( 'power_sent' );

		return is_array( $sent ) && ( $sent['what'] ?? '' ) === $what && time() - (int) ( $sent['at'] ?? 0 ) < 60;
	}

	/**
	 * A task that ended OK: a shutdown, stop or start is remembered for a minute (see sent_recently()).
	 *
	 * @param string $phase Phase kept with the task.
	 */
	protected function note_ended( string $phase ): void {
		if ( in_array( $phase, array( 'shutdown', 'stop' ), true ) ) {
			$this->mark_sent( 'stop' );
		} elseif ( 'start' === $phase ) {
			$this->mark_sent( 'start' );
		}
	}

	/**
	 * Remembers a power task that ended OK (or forgets it).
	 *
	 * @param string|null $what stop, start, or null to forget.
	 */
	protected function mark_sent( ?string $what ): void {
		if ( null === $what && ! is_array( $this->r->get( 'power_sent' ) ) ) {
			return;
		}

		$this->r->set( array( 'power_sent' => null === $what ? null : array( 'what' => $what, 'at' => time() ) ) );
	}

	/**
	 * A wait for something that still runs: no error, no counted attempt (see StepResult::poll()).
	 *
	 * @param string $why    What it waits for.
	 * @param int    $budget Seconds.
	 */
	protected function waiting( string $why, int $budget ): StepResult {
		return StepResult::poll( 5, $why, $budget );
	}

	/**
	 * Flags a retry as progress when this run advanced (never for merely polling a task that still runs).
	 *
	 * @param StepResult $result Result of the run.
	 */
	public function signal( StepResult $result ): StepResult {
		return $this->progressed ? $result->with_progress() : $result;
	}

	/**
	 * Does a finished task count as progress? Only one that ended OK or with warnings (the steps
	 * still re-read the state before believing it). A task that failed never does: a step that
	 * keeps failing must run into the retry budget instead of looping.
	 *
	 * @param \WP_Error|true $result Result of wait_task().
	 */
	protected static function accepted( $result ): bool {
		if ( true === $result ) {
			return true;
		}

		$data = is_wp_error( $result ) ? $result->get_error_data() : null;
		$exit = is_array( $data ) && isset( $data['exitstatus'] ) && is_scalar( $data['exitstatus'] ) ? (string) $data['exitstatus'] : '';

		return 0 === strpos( $exit, 'WARNINGS' );
	}

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
		$result = $this->client->wait_task( $task['upid'], $this->task_wait() );

		if ( is_wp_error( $result ) && 'task_pending' === $result->get_error_code() ) {
			return $this->waiting( $step . ': waiting for the Proxmox task', $this->task_budget( (string) ( $task['phase'] ?? '' ) ) );
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

		$this->progressed = $this->progressed || self::accepted( $result );

		if ( true === $result ) {
			$this->note_ended( (string) ( $task['phase'] ?? '' ) );
		}

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
	 * The VM read again after a power task, until it shows the wanted power state. A task that ended OK can be
	 * followed by a status that still shows the old state for a moment (a VM that is still shutting down, a start
	 * that is not listed yet, Proxmox refreshes the status every few seconds): the VM is read again every 2 seconds
	 * for up to 20 seconds (filter `beltoft_services_power_settle`, seconds), never past the run's budget, so that
	 * lag is not taken for a failure. Filter `beltoft_services_proxmox_pause` (callable, seconds) replaces the
	 * sleep, for tests; the settle time counts the seconds asked for, not the clock.
	 *
	 * @param array<string, mixed> $record  VM record.
	 * @param string               $step    Step name for messages.
	 * @param bool                 $running True to wait for a running VM, false for a stopped one.
	 * @return array<string, mixed>|StepResult The last read (it may still show the old state), or retry/fail.
	 */
	protected function reread_power( array $record, string $step, bool $running ) {
		$v = $this->verified( $record, $step );

		/**
		 * Seconds a VM is read again for after a power task.
		 *
		 * @param int $seconds Default 20.
		 */
		$settle = max( 0, (int) apply_filters( 'beltoft_services_power_settle', 20 ) );
		$waited = 0;

		while ( is_array( $v ) && $running !== ( 'running' === $v['status'] ) && $waited + 2 <= $settle && Jobs::time_left() >= 2 ) {
			$pause = apply_filters( 'beltoft_services_proxmox_pause', null );

			if ( is_callable( $pause ) ) {
				$pause( 2 );
			} else {
				sleep( 2 );
			}

			$waited += 2;

			// One cheap call per look; the full verified read only once the VM shows the wanted state (or when
			// the live status cannot be read).
			$live = $this->client->request( 'GET', '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . (int) ( $record['vmid'] ?? 0 ) . '/status/current' );

			if ( is_array( $live ) && isset( $live['status'] ) && $running !== ( 'running' === $live['status'] ) ) {
				continue;
			}

			$v = $this->verified( $record, $step );
		}

		return $v;
	}

	/**
	 * Ownership failures are permanent, an absent VM and client errors follow the usual rules.
	 *
	 * @param \WP_Error $e    Error.
	 * @param string    $step Step name.
	 */
	protected function refuse_or_retry( \WP_Error $e, string $step ): StepResult {
		if ( in_array( $e->get_error_code(), array( 'no_cluster', 'out_of_range', 'not_recorded', 'not_ours', 'is_template' ), true ) ) {
			return StepResult::fail( 'Ownership check failed: ' . $e->get_error_message() . ' (' . $step . ')' );
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

		$result = $this->client->wait_task( $upid, $this->task_wait() );

		if ( is_wp_error( $result ) && 'task_pending' === $result->get_error_code() ) {
			return $this->waiting( $step . ': waiting for the Proxmox task', $this->task_budget( $phase ) );
		}

		if ( is_wp_error( $result ) && 'task_failed' !== $result->get_error_code() ) {
			return StepResult::retry( 0, $step . ': ' . $this->text( $result ) );
		}

		$this->progressed = $this->progressed || self::accepted( $result );

		if ( true === $result ) {
			$this->note_ended( $phase );
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
