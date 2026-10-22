<?php
/**
 * Pause, resume, delete, plan changes and status of a Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything after provisioning. Each call runs under the resource lock, re-reads the remote
 * state before it acts, keeps a Proxmox task's UPID in meta `task` while it waits (bounded by the
 * `task_wait` setting) and asks to be run again when the task is still running.
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>):
 * /nodes/{node}/qemu/{vmid}/status/shutdown (graceful, `timeout`), .../status/stop ("pulling the
 * power plug", only when a shutdown left the VM running), .../status/start, .../status/current,
 * DELETE /nodes/{node}/qemu/{vmid} (`purge`, `destroy-unreferenced-disks`), PUT .../config
 * (`cores`, `memory`, `digest`), PUT .../resize, /cluster/nextid (the id is "gone" when it is free).
 *
 * Every write goes through Ownership::verify() first: the VMID in the customer range, recorded
 * for this resource in this plugin's table, and the tag line in the VM description. A VM that is
 * not listed is never taken as deleted: only a free id on /cluster/nextid says so.
 */
final class Operations {

	use Steps;

	/**
	 * Transient holding the last status of a VM (one hour).
	 */
	public const STATUS_PREFIX = 'beltoft_services_proxmox_status_';

	/**
	 * Cluster settings (token secret decrypted).
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	// -- Pause -----------------------------------------------------------------------------------

	/**
	 * Graceful shutdown, then stop only if it is still running. A half-built resource makes no call.
	 */
	public function pause(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );
		$task   = $this->r->get( 'task' );
		$steps  = (array) $this->r->get( 'steps' );

		if ( null === $record ) {
			return $this->record_gone() ?? StepResult::done( array( 'task' => null ) );
		}

		$pending = is_array( $task ) && ! empty( $task['upid'] );

		if ( ! in_array( 'clone', $steps, true ) && ! $pending ) {
			return StepResult::done(); // Nothing was built: nothing to switch off.
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		// A clone (or any other task) that is still running is waited for, never paused over.
		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		if ( ! in_array( 'clone', $steps, true ) ) {
			return StepResult::done( array( 'task' => null ) );
		}

		$out = $this->power_or_delete( $record, 'pause', false );

		return $out instanceof StepResult ? $out : StepResult::done( array( 'task' => null ) );
	}

	/**
	 * No VM record although the clone (or more) was recorded as done: a record was lost, and a VM
	 * may still run on an address nothing holds. Never "done", never a release.
	 */
	private function record_gone(): ?StepResult {
		if ( array() === array_intersect( array( 'clone', 'ready' ), (array) $this->r->get( 'steps' ) ) ) {
			return null;
		}

		return StepResult::fail( 'VM record missing; check Proxmox manually' );
	}

	// -- Resume ----------------------------------------------------------------------------------

	/**
	 * Starts the VM (the driver continues provisioning when it was never finished).
	 */
	public function resume(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'resume: no VM is recorded for this service' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, 'resume' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$started = $this->start_if_stopped( $record, $v, 'resume' );

		return $started instanceof StepResult ? $started : StepResult::done( array( 'task' => null ) );
	}

	// -- Delete ----------------------------------------------------------------------------------

	/**
	 * Shuts down, destroys, confirms the id is free, gives back the address and the record.
	 */
	public function delete(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return $this->record_gone() ?? $this->cleanup( 'No virtual machine was ever recorded' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		if ( ! in_array( 'clone', (array) $this->r->get( 'steps' ), true ) ) {
			$half = $this->half_built( $record );

			if ( null !== $half ) {
				return $half;
			}
		}

		$out = $this->power_or_delete( $record, 'delete', true );

		if ( $out instanceof StepResult ) {
			return $out;
		}

		return $this->cleanup( 'The virtual machine was deleted' );
	}

	/**
	 * A resource whose clone never completed: no VM of ours exists, or one does and the normal
	 * path deletes it. A VM at the id that is not ours is left alone and the record is dropped.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null Null: carry on with the full path.
	 */
	private function half_built( array $record ): ?StepResult {
		$list = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'delete' );
		}

		if ( null === Ownership::entry( $list, $record['vmid'] ) ) {
			$free = Ownership::is_free( $this->client, $record['vmid'] );

			if ( true === $free ) {
				return $this->cleanup( 'Nothing was built' );
			}

			return StepResult::retry( 0, 'delete: the id is not listed and not confirmed free' );
		}

		$v = Ownership::verify( $this->client, $record, $this->r );

		if ( ! is_wp_error( $v ) ) {
			return null; // Ours, though the step was not recorded: delete it.
		}

		if ( in_array( $v->get_error_code(), array( 'not_ours', 'is_template' ), true ) ) {
			return $this->cleanup( 'The VMID belongs to another machine, which was not touched' );
		}

		return $this->refuse_or_retry( $v, 'delete' );
	}

	/**
	 * Gives back the address, forgets the VMID, wipes any unread password.
	 *
	 * @param string $why Reason (admin text).
	 */
	private function cleanup( string $why ): StepResult {
		IpPool::release( $this->r->id() );
		VmRecords::remove( $this->r->id() );
		Access::purge( $this->r );

		return StepResult::done( array( 'task' => null ), $why );
	}

	// -- Plan change -----------------------------------------------------------------------------

	/**
	 * Applies cores and memory, grows the disk; never shrinks it. A VM that ran is started again.
	 */
	public function update_plan(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'update: no VM is recorded for this service' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$plan = (array) $this->r->get( 'plan' );
		$want = array(
			'cores'  => (int) ( $plan['cores'] ?? 0 ),
			'memory' => (int) ( $plan['memory_mb'] ?? 0 ),
			'disk'   => (int) ( $plan['disk_gb'] ?? 0 ),
		);

		if ( $want['cores'] <= 0 || $want['memory'] <= 0 || $want['disk'] <= 0 ) {
			return StepResult::fail( 'update: the plan needs positive cores, memory and disk' );
		}

		$v = $this->verified( $record, 'update' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$was_running = (bool) $this->r->get( 'was_running' );
		$result      = $this->change( $record, $v, $want, $was_running );

		// A permanent failure after the shutdown must not leave a customer's VM switched off.
		if ( $result->is_failed() && $this->r->get( 'was_running' ) ) {
			$this->restore( $record );
		}

		return $result;
	}

	/**
	 * One round of the plan change.
	 *
	 * @param array<string, mixed> $record      VM record.
	 * @param array<string, mixed> $v           Verified VM.
	 * @param array<string, int>   $want        cores, memory, disk (GB).
	 * @param bool                 $was_running Whether the VM ran before the change began.
	 */
	private function change( array $record, array $v, array $want, bool $was_running ): StepResult {
		$config  = $v['config'];
		$disk_id = (string) $this->cluster['boot_disk'];
		$size    = Provision::gigabytes( (string) ( $config[ $disk_id ] ?? '' ) );

		if ( null === $size ) {
			return StepResult::fail( 'update: the boot disk ' . $disk_id . ' was not found on the VM' );
		}

		$have   = (int) ceil( $size );
		$shrink = $want['disk'] < $have;
		$grow   = $want['disk'] > $size;
		$sizing = (int) ( $config['cores'] ?? 0 ) !== $want['cores'] || (int) ( $config['memory'] ?? 0 ) !== $want['memory'];

		if ( $shrink ) {
			Alerts::admin( $this->r, sprintf( 'Plan disk (%d GB) is smaller than the VM disk (%d GB); the disk was not shrunk', $want['disk'], $have ) );
		}

		$keep = array( 'disk_gb' => $shrink ? $have : max( $have, $want['disk'] ) );

		if ( ! $sizing && ! $grow ) {
			if ( $was_running ) {
				$started = $this->start_if_stopped( $record, $v, 'update' );

				if ( $started instanceof StepResult ) {
					return $started;
				}
			}

			return StepResult::done( $keep + array( 'was_running' => null ) );
		}

		if ( 'running' === $v['status'] && ! $was_running ) {
			if ( ! $this->r->set( array( 'was_running' => true ) ) ) {
				return StepResult::retry( 0, 'update: progress could not be stored' );
			}

			$was_running = true;
		}

		$stopped = $this->power_or_delete( $record, 'update', false );

		if ( $stopped instanceof StepResult ) {
			return $stopped;
		}

		$v    = $stopped['v'];
		$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];

		if ( $sizing ) {
			$params = array(
				'cores'  => $want['cores'],
				'memory' => $want['memory'],
			);

			if ( ! empty( $v['config']['digest'] ) ) {
				$params['digest'] = (string) $v['config']['digest'];
			}

			$answer = $this->client->request( 'PUT', $base . '/config', $params );

			if ( is_wp_error( $answer ) ) {
				return $this->classify( $answer, 'update' );
			}

			$this->progressed = true; // The new cores and memory are written.
		}

		if ( $grow ) {
			$done = $this->run_task(
				'update',
				'PUT',
				$base . '/resize',
				array(
					'disk' => $disk_id,
					'size' => $want['disk'] . 'G',
				)
			);

			if ( $done instanceof StepResult ) {
				return $done;
			}
		}

		// What the VM is now decides; a task that ended with warnings is not a failure.
		$again = $this->verified( $record, 'update' );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		$now = Provision::gigabytes( (string) ( $again['config'][ $disk_id ] ?? '' ) );

		if ( (int) ( $again['config']['cores'] ?? 0 ) !== $want['cores'] || (int) ( $again['config']['memory'] ?? 0 ) !== $want['memory'] || null === $now || $now < $want['disk'] - 0.001 ) {
			return StepResult::retry( 0, 'update: the VM does not have the new plan yet' );
		}

		if ( $was_running ) {
			$started = $this->start_if_stopped( $record, $again, 'update' );

			if ( $started instanceof StepResult ) {
				return $started;
			}
		}

		return StepResult::done( $keep + array( 'disk_gb' => max( $have, $want['disk'] ), 'was_running' => null ) );
	}

	/**
	 * Best effort: start a VM the change switched off, then leave it to the admin.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function restore( array $record ): void {
		$v = Ownership::verify( $this->client, $record, $this->r );

		if ( ! is_wp_error( $v ) && 'running' !== $v['status'] ) {
			$upid = $this->client->task( 'POST', '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/status/start' );

			if ( is_string( $upid ) ) {
				$this->client->wait_task( $upid, $this->wait );
			}
		}

		$after = Ownership::verify( $this->client, $record, $this->r );

		if ( ! is_wp_error( $after ) && 'running' === $after['status'] ) {
			$this->r->set( array( 'was_running' => null ) );
		}
	}

	/**
	 * Listener of beltoft_services_state_changed: an update that ran out of attempts must not leave
	 * the VM it switched off switched off. Runs under the resource lock of the failing job.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'failed' !== $new || ! $r instanceof Resource || SshKeyField::DRIVER !== $r->get( 'driver' ) || 'update_plan' !== $r->get( 'failed_op' ) || ! $r->get( 'was_running' ) ) {
			return;
		}

		$record = VmRecords::for_resource( $r->id() );
		$self   = new self( $r );

		if ( null !== $record && null === $self->prepare( $record ) ) {
			$self->restore( $record );
		}
	}

	// -- Status ----------------------------------------------------------------------------------

	/**
	 * Power, CPU load and memory from status/current. `cpu` is PVE's own number: a fraction of the
	 * VM's CPUs in use (0 to 1 over all of them, so 0.5 is half), not a percent. Kept for an hour in a transient (not in meta:
	 * a write outside the resource lock could race the reconciler).
	 *
	 * @return array{power: string, cpu: float, mem: int}|null
	 */
	public function status(): ?array {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record || null !== $this->prepare( $record ) ) {
			return null;
		}

		$v = Ownership::verify( $this->client, $record, $this->r );

		if ( is_wp_error( $v ) ) {
			return null;
		}

		$now = $this->client->request( 'GET', '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/status/current' );

		if ( is_wp_error( $now ) || ! is_array( $now ) ) {
			return null;
		}

		$status = array(
			'power' => (string) ( $now['status'] ?? '' ),
			'cpu'   => (float) ( $now['cpu'] ?? 0 ),
			'mem'   => (int) ( $now['mem'] ?? 0 ),
		);

		set_transient( self::STATUS_PREFIX . $this->r->id(), $status, HOUR_IN_SECONDS );

		return $status;
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * Builds the client of the record's cluster. Failures are permanent.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function prepare( array $record ): ?StepResult {
		$cluster = Clusters::get( (string) $record['cluster'] );

		if ( null === $cluster ) {
			return StepResult::fail( 'The cluster of this VM is not configured' );
		}

		$this->cluster = $cluster;
		$this->secrets = array( (string) $cluster['token_secret'] );

		try {
			$this->client = Clients::for( $cluster );
		} catch ( \Throwable $e ) {
			unset( $e );

			return StepResult::fail( 'The Proxmox client could not be built for this cluster' );
		}

		$wait       = (int) Settings::get( 'task_wait' );
		$this->wait = $wait > 0 ? $wait : 25;

		return null;
	}

	/**
	 * Starts a stopped VM and waits (bounded) for the task; what the VM is afterwards decides.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $step   Step name.
	 * @return true|StepResult True when it runs.
	 */
	private function start_if_stopped( array $record, array $v, string $step ) {
		if ( 'running' === $v['status'] ) {
			return true;
		}

		$done = $this->run_task( $step, 'POST', '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'] . '/status/start' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$again = $this->verified( $record, $step );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		return 'running' === $again['status'] ? true : StepResult::retry( 0, $step . ': the start task did not leave the VM running (' . ( true === $done ? 'OK' : $done['failed'] ) . ')' );
	}

	/**
	 * Brings the VM to "stopped" (shutdown, then stop only if still running) and, when asked,
	 * destroys it. Every round starts from a verified read.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param string               $step   Step name.
	 * @param bool                 $delete Destroy it once stopped.
	 * @return StepResult|array{v?: array<string, mixed>, gone?: bool} retry/fail, or the stopped VM, or gone (the id is confirmed free).
	 */
	private function power_or_delete( array $record, string $step, bool $delete ) {
		for ( $round = 0; $round < 5; $round++ ) {
			$fresh = VmRecords::for_resource( $this->r->id() );
			$v     = Ownership::verify( $this->client, $fresh ? $fresh : $record, $this->r );

			if ( is_wp_error( $v ) && 'absent' === $v->get_error_code() && $delete ) {
				if ( true === Ownership::is_free( $this->client, $record['vmid'] ) ) {
					return array( 'gone' => true );
				}

				return StepResult::retry( 0, $step . ': the VM is not listed and its id is not confirmed free' );
			}

			if ( is_wp_error( $v ) ) {
				if ( in_array( $v->get_error_code(), array( 'no_cluster', 'out_of_range', 'not_recorded', 'not_ours', 'is_template' ), true ) ) {
					return StepResult::fail( 'Ownership check failed: ' . $this->scrub( $v->get_error_message(), 200 ) . ' (' . $step . ')' );
				}

				return 'absent' === $v->get_error_code() ? StepResult::retry( 0, $step . ': ' . $v->get_error_message() ) : $this->classify( $v, $step );
			}

			$base = '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];

			if ( 'running' === $v['status'] ) {
				$phase = ( $this->finished && $step === $this->finished['step'] && 'shutdown' === $this->finished['phase'] ) ? 'stop' : 'shutdown';
				$done  = 'shutdown' === $phase
					? $this->run_task( $step, 'POST', $base . '/status/shutdown', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), $phase )
					: $this->run_task( $step, 'POST', $base . '/status/stop', array(), $phase );
			} elseif ( $delete ) {
				$phase = 'delete';
				$done  = $this->run_task(
					$step,
					'DELETE',
					$base,
					array(
						'purge'                      => 1,
						'destroy-unreferenced-disks' => 1,
					),
					$phase
				);
			} else {
				return array( 'v' => $v );
			}

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$this->finished = array(
				'step'  => $step,
				'phase' => $phase,
				'ok'    => true === $done,
				'exit'  => true === $done ? 'OK' : (string) $done['failed'],
			);
		}

		return StepResult::retry( 0, $step . ': the VM did not reach the wanted state' );
	}
}
