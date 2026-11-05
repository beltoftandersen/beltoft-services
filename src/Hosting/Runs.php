<?php
/**
 * Parts shared by the hosting provision and the hosting operations.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Steps;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Connecting to the node of a record, the container checks, power, limits and the agent jobs, written once for
 * Provision and Operations. The task polling, the error classification and the secret scrubbing are the
 * Proxmox\Steps trait (it is not changed; its two virtual machine methods are not used here, and verified() is
 * replaced by the container version below).
 */
trait Runs {

	use Steps {
		refuse_or_retry as private steps_refuse_or_retry;
	}

	/**
	 * Node record id.
	 *
	 * @var string
	 */
	protected $node_id = '';

	/**
	 * Node settings (secrets decrypted).
	 *
	 * @var array<string, mixed>
	 */
	protected $node = array();

	/**
	 * Agent client.
	 *
	 * @var Agent|null
	 */
	protected $agent = null;

	/**
	 * Connects to the node of the resource's hosting record: node settings, node API client, agent client.
	 * Everything that does not depend on the remote state is checked here; each failure is permanent.
	 */
	protected function connect(): ?StepResult {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record ) {
			return StepResult::fail( 'This resource has no hosting record yet' );
		}

		$node = Nodes::get( $record['node'] );

		if ( null === $node ) {
			return StepResult::fail( 'Unknown hosting node in the record: "' . mb_substr( sanitize_text_field( $record['node'] ), 0, 40 ) . '"' );
		}

		foreach ( array( 'api_url', 'token_id', 'token_secret', 'pve_node', 'agent_url', 'agent_key_id', 'agent_key', 'pool', 'storage', 'bridge', 'gateway', 'lan_cidr', 'db_host' ) as $key ) {
			if ( '' === (string) $node[ $key ] ) {
				return StepResult::fail( 'The hosting node setting "' . $key . '" is empty' );
			}
		}

		if ( (int) $node['template'] <= 0 ) {
			return StepResult::fail( 'The hosting node has no container template' );
		}

		$this->node_id = $record['node'];
		$this->node    = $node;
		$this->secrets = array( (string) $node['token_secret'], (string) $node['agent_key'] );

		$gate = Clients::gate( $this->node_id, $node, Clients::live_allowed() );

		if ( null !== $gate ) {
			return StepResult::retry( 0, $gate );
		}

		try {
			$this->client = Clients::for_node( $node );
			$this->agent  = Clients::agent_for( $node );
		} catch ( \Throwable $e ) {
			unset( $e );

			return StepResult::fail( 'The hosting clients could not be built for this node' );
		}

		$wait       = (int) Settings::get( 'task_wait' );
		$this->wait = $wait > 0 ? $wait : 25;

		return null;
	}

	/**
	 * The base path of the resource's container: /nodes/{node}/lxc/{vmid}.
	 *
	 * @param string $pve_node Node name as the container is on now.
	 */
	protected function lxc( string $pve_node ): string {
		$record = Records::for_resource( $this->r->id() );

		return '/nodes/' . rawurlencode( $pve_node ) . '/lxc/' . (int) ( $record['vmid'] ?? 0 );
	}

	/**
	 * The container, verified as ours (tag, range, record), with its node, config and state.
	 *
	 * @param array<string, mixed> $record Hosting record (kept for the signature of Steps::verified()).
	 * @param string               $step   Step name for messages.
	 * @return array<string, mixed>|StepResult
	 */
	protected function verified( array $record, string $step ) {
		$fresh = Records::for_resource( $this->r->id() );
		$v     = CtOwnership::verify( $this->client, $this->node, $fresh ? $fresh : $record, $this->r );

		return is_wp_error( $v ) ? $this->refuse_or_retry( $v, $step ) : $v;
	}

	/**
	 * Ownership failures are permanent, an absent container and client errors follow the usual rules.
	 *
	 * @param \WP_Error $e    Error.
	 * @param string    $step Step name.
	 */
	protected function refuse_or_retry( \WP_Error $e, string $step ): StepResult {
		if ( in_array( $e->get_error_code(), array( 'no_node', 'out_of_range', 'not_recorded', 'not_ours', 'is_template' ), true ) ) {
			return StepResult::fail( 'Ownership check failed: ' . $e->get_error_message() . ' (' . $step . ')' );
		}

		return $this->steps_refuse_or_retry( $e, $step );
	}

	/**
	 * Starts the container and waits for it to show as running.
	 *
	 * @param array<string, mixed> $v    Verified container.
	 * @param string               $step Step name for messages.
	 * @return true|StepResult
	 */
	protected function power_on( array $v, string $step ) {
		if ( 'running' === $v['status'] ) {
			return true;
		}

		$record = Records::for_resource( $this->r->id() ) ?? array();
		$done   = $this->run_task( $step, 'POST', $this->lxc( $v['node'] ) . '/status/start', array(), 'start' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$after = $this->verified( $record, $step );

		if ( $after instanceof StepResult ) {
			return $after;
		}

		if ( 'running' !== $after['status'] ) {
			return true === $done
				? $this->waiting( $step . ': waiting for the container to start', $this->task_budget( 'start' ) )
				: StepResult::retry( 0, $step . ': the start task failed (' . $done['failed'] . ')' );
		}

		return true;
	}

	/**
	 * Shuts the container down, then stops it when it still runs.
	 *
	 * @param array<string, mixed> $v    Verified container.
	 * @param string               $step Step name for messages.
	 * @return true|StepResult
	 */
	protected function power_off( array $v, string $step ) {
		if ( 'running' !== $v['status'] ) {
			return true;
		}

		$record = Records::for_resource( $this->r->id() ) ?? array();
		$base   = $this->lxc( $v['node'] );
		$done   = null;

		foreach ( array( 'shutdown', 'stop' ) as $phase ) {
			$done = 'shutdown' === $phase
				? $this->run_task( $step, 'POST', $base . '/status/shutdown', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), $phase )
				: $this->run_task( $step, 'POST', $base . '/status/stop', array(), $phase );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$after = $this->verified( $record, $step );

			if ( $after instanceof StepResult ) {
				return $after;
			}

			if ( 'running' !== $after['status'] ) {
				return true;
			}
		}

		return $this->still_running( $step . ': waiting for the container to stop', $done );
	}

	/**
	 * Makes the container's limits what the plan says: cores, memory, swap and the root disk (grown, never
	 * shrunk). Reads the config first and writes only what differs, so a retry sends nothing twice.
	 *
	 * @param array<string, mixed>           $v    Verified container.
	 * @param array<string, int|string|bool> $plan Typed plan.
	 * @param string                         $step Step name for messages.
	 * @return true|StepResult True when the container shows the limits.
	 */
	protected function apply_limits( array $v, array $plan, string $step ) {
		$base = $this->lxc( $v['node'] );
		$want = array(
			'cores'    => (int) $plan['cores'],
			'cpulimit' => (int) $plan['cores'],
			'cpuunits' => 100 * (int) $plan['cores'],
			'memory'   => (int) $plan['memory_mb'],
			'swap'     => (int) $plan['swap_mb'],
		);

		$diff = array();

		foreach ( $want as $key => $value ) {
			if ( (int) ( $v['config'][ $key ] ?? -1 ) !== $value ) {
				$diff[ $key ] = $value;
			}
		}

		if ( array() !== $diff ) {
			$sent = $this->put_config( $base, $v['config'], $diff, $step );

			if ( $sent instanceof StepResult ) {
				return $sent;
			}
		}

		$root = $this->grow_rootfs( $v, (int) $plan['disk_gb'], $step );

		if ( $root instanceof StepResult || ! isset( $v['config']['mp0'] ) ) {
			return $root;
		}

		// The dump mount follows the plan: a quarter of the disk, grown with it (never shrunk).
		return $this->grow_rootfs( $this->verified( Records::for_resource( $this->r->id() ) ?? array(), $step ), max( 1, intdiv( (int) $plan['disk_gb'], 4 ) ), $step, 'mp0' );
	}

	/**
	 * Grows the root disk to a size in GB when it is smaller. A bigger disk is left as it is.
	 *
	 * @param array<string, mixed> $v    Verified container.
	 * @param int                  $gb   Wanted size.
	 * @param string               $step Step name for messages.
	 * @param string               $disk rootfs, or mp0 (the dump mount).
	 * @return true|StepResult
	 */
	protected function grow_rootfs( $v, int $gb, string $step, string $disk = 'rootfs' ) {
		if ( $v instanceof StepResult ) {
			return $v;
		}

		$current = 1 === preg_match( '/size=(\d+)G/', (string) ( $v['config'][ $disk ] ?? '' ), $m ) ? (int) $m[1] : 0;

		if ( 0 === $current ) {
			return StepResult::fail( $step . ': the root disk size could not be read' );
		}

		if ( $current >= $gb ) {
			return true;
		}

		$done = $this->run_task(
			$step,
			'PUT',
			$this->lxc( $v['node'] ) . '/resize',
			array(
				'disk' => $disk,
				'size' => $gb . 'G',
			),
			'resize'
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$after = $this->verified( Records::for_resource( $this->r->id() ) ?? array(), $step );

		if ( $after instanceof StepResult ) {
			return $after;
		}

		$now = 1 === preg_match( '/size=(\d+)G/', (string) ( $after['config'][ $disk ] ?? '' ), $m ) ? (int) $m[1] : 0;

		return $now >= $gb ? true : StepResult::retry( 0, $step . ': the ' . ( 'mp0' === $disk ? 'dump mount' : 'root disk' ) . ' did not grow' . ( true === $done ? '' : ' (' . $done['failed'] . ')' ) );
	}

	/**
	 * One config PUT with the digest of the config just read.
	 *
	 * @param string               $base   Container path.
	 * @param array<string, mixed> $config Config as read (with its digest).
	 * @param array<string, mixed> $values Values to write.
	 * @param string               $step   Step name for messages.
	 * @return true|StepResult
	 */
	protected function put_config( string $base, array $config, array $values, string $step ) {
		$digest = $config['digest'] ?? '';

		if ( ! is_string( $digest ) || '' === $digest ) {
			return StepResult::retry( 0, $step . ': the container config has no digest, nothing was written' );
		}

		// The kill switch can be turned on while a run is going: nothing is written after that.
		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, $step . ': all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$answer = $this->client->request( 'PUT', $base . '/config', array_merge( $values, array( 'digest' => $digest ) ) );

		return is_wp_error( $answer ) ? $this->classify( $answer, $step ) : true;
	}

	/**
	 * Did the task a poll just collected belong to this step and phase? A resumed round must not start the same task
	 * again once it ended.
	 *
	 * @param string $step  Step name given to run_task().
	 * @param string $phase Phase given to run_task().
	 * @return bool|null True: it ended well. False: it failed. Null: no such task ended in this run.
	 */
	protected function just_finished( string $step, string $phase ): ?bool {
		$f = $this->finished;

		return is_array( $f ) && $f['step'] === $step && $f['phase'] === $phase ? (bool) $f['ok'] : null;
	}

	/**
	 * Runs one agent job and returns its result. The job id is the resource uuid, the name and a generation
	 * kept in the resource meta: the same id while the job is in flight (the agent returns the same job, so a
	 * retry never runs it twice), a new one after the job finished or failed (so a later pause or a retry after
	 * a failure is a new job).
	 *
	 * @param string               $name Name of the call inside the operation (keeps its own generation).
	 * @param string               $op   Agent operation.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|StepResult The job's result, or retry, wait or fail.
	 */
	protected function agent_call( string $name, string $op, array $args ) {
		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, $name . ': all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$jobs = (array) $this->r->get( 'agent_jobs' );
		$gen  = (int) ( $jobs[ $name ] ?? 0 );
		$id   = $this->r->get( 'uuid' ) . ':' . $name . ':' . $gen;
		$job  = $this->agent->submit( $op, $args, $id );

		if ( is_wp_error( $job ) ) {
			return $this->classify( $job, $name );
		}

		$status = (string) ( $job['status'] ?? '' );

		if ( in_array( $status, array( 'queued', 'running' ), true ) ) {
			return $this->waiting( $name . ': waiting for the node agent', 600 );
		}

		if ( ! in_array( $status, array( 'done', 'failed' ), true ) ) {
			return StepResult::retry( 0, $name . ': the node agent sent an unknown job status' );
		}

		$jobs[ $name ] = $gen + 1;

		if ( ! $this->r->set( array( 'agent_jobs' => $jobs ) ) ) {
			return StepResult::retry( 0, $name . ': the agent job state could not be stored' );
		}

		if ( 'failed' === $status ) {
			return StepResult::retry( 0, $name . ': the agent job failed: ' . $this->scrub( (string) ( $job['error'] ?? '' ), 200 ) );
		}

		$this->progressed = true;

		return is_array( $job['result'] ?? null ) ? $job['result'] : array();
	}
}
