<?php
/**
 * Customer snapshots of a Proxmox VM: take, delete, roll back, expire.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Snapshot;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Disk-only qcow2 snapshots (no `vmstate`) that the plugin owns. A Proxmox snapshot is named `bs` and ten hex
 * characters; the customer's label lives in meta, next to the time it was taken, when it expires and the volumes
 * it covers. A customer only ever sends an id of this list, never a Proxmox name, and a snapshot that is not in
 * the list is never touched. Every action runs from Jobs::act() under the resource lock, verifies the VM as ours
 * first and re-reads the remote state before it acts, so a retry never repeats a step.
 *
 * Meta on the server resource: `snapshots` (list of {id, snap, label, taken_at, expires_at, covers, consistent, paid,
 * state: ok|creating|deleting}), `snapshot_op` ({op: take|delete, id, phase, at}, the crash-safe marker of the
 * operation in flight) and `snapshot_rollback` ({id, phase, was_running, at}, for the progress of a rollback).
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>), all under
 * /nodes/{node}/qemu/{vmid}: POST snapshot (`snapname`, `description`, `vmstate`; VM.Snapshot), GET snapshot,
 * DELETE snapshot/{snapname} (VM.Snapshot), POST snapshot/{snapname}/rollback (VM.Snapshot.Rollback), and the
 * storage status for the free-space guard.
 *
 * Proxmox leaves the VM stopped after a rollback, and a disk that was grown after the snapshot goes back to its
 * old size (live results of 2026-10-28). So a rollback stops the VM, rolls back, attaches the extra disks that
 * became unusedN, grows every extra disk to the size it was paid for, and queues the plan again, which grows the
 * boot disk, applies cores and memory and starts the VM when it was running (RestoreSteps::reapply_plan).
 *
 * Texts that reach the customer are fixed strings; a Proxmox error only ever goes to the admin alert, scrubbed.
 */
final class Snapshots {

	/**
	 * Meta: the snapshots of the server.
	 */
	public const LIST = 'snapshots';

	/**
	 * Meta: the operation in flight.
	 */
	public const OP = 'snapshot_op';

	/**
	 * Meta: the rollback in flight.
	 */
	public const ROLLBACK = 'snapshot_rollback';

	/**
	 * Rounds a disk delete waits for a task of another job, and the meta that counts them.
	 */
	public const DROP_WAITS       = 'snapshot_drop_waits';
	public const DROP_WAIT_ROUNDS = 5;

	/**
	 * Meta: the last problem of a snapshot action, for the admin.
	 */
	public const ERROR = 'snapshot_error';

	/**
	 * The customer's actions.
	 */
	public const ACTIONS = array( 'snapshot_take', 'snapshot_delete', 'snapshot_rollback' );

	/**
	 * The actions queued by the plugin itself, never offered to a customer (Drivers\InternalActions).
	 */
	public const INTERNAL = array( 'snapshot_expire', 'snapshot_purge', 'snapshot_sync' );

	/**
	 * Seconds a take may stay open. Past that the next action closes it (see close_stale()).
	 */
	public const OP_MAX_AGE = 2 * HOUR_IN_SECONDS;

	/**
	 * The word a customer types to confirm a rollback.
	 */
	public const CONFIRM_ROLLBACK = 'ROLLBACK';

	/**
	 * Longest label.
	 */
	public const MAX_LABEL = 40;

	/**
	 * The runner behind the instance methods.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->engine = new Engine( $r );
	}

	/**
	 * Runs one snapshot action (called by Actions::run() and, for the internal ones, by the driver, inside
	 * Jobs::act()'s lock).
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function run( string $action, array $args ): StepResult {
		return $this->engine->run( $action, $args );
	}

	/**
	 * Deletes every snapshot of the list. For a reinstall or a restore, which end them anyway. Done when none is
	 * left; a retry while a delete runs or is refused. It reads the remote list again every time, so the caller
	 * may call it again after its own task poll.
	 */
	public function purge_all(): StepResult {
		return $this->engine->purge_all();
	}

	/**
	 * Deletes the snapshots that cover a volume, before the volume goes. Done when none is left.
	 *
	 * @param string $volid Volume id.
	 */
	public function drop_covering( string $volid ): StepResult {
		return $this->engine->drop_covering( $volid );
	}

	/**
	 * Forgets the whole list (the VM's snapshots went with a restore over it), then reads the remote list again.
	 */
	public function forget_all(): StepResult {
		return $this->engine->forget_all();
	}
}
