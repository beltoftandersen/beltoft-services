<?php
/**
 * Customer snapshots of a Proxmox VM: take, delete, roll back, expire.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Snapshot\Engine;
use BeltoftServices\Proxmox\Snapshot\PanelData;
use BeltoftServices\Proxmox\Snapshot\Policy;
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
	 * Is the switch in the settings on?
	 */
	public static function switched_on(): bool {
		return Policy::switched_on();
	}

	/**
	 * Does this server get snapshots? The switch is on, it is not an adopted machine, and every disk it has is
	 * qcow2 (an old server on raw volumes gets none). No Proxmox call: the boot volume's format was recorded when
	 * the VM was built, the extra disks' formats are in their volume names.
	 *
	 * @param Resource $r Resource.
	 */
	public static function enabled( Resource $r ): bool {
		return Policy::enabled( $r );
	}

	/**
	 * Could this server get snapshots, leaving aside what it is doing now? The switch is on, it is not an adopted
	 * machine, and every disk is qcow2. What the paid slots are sold on.
	 *
	 * @param Resource $r Resource.
	 */
	public static function eligible( Resource $r ): bool {
		return Policy::eligible( $r );
	}

	/**
	 * Is nothing else changing the server? It is ready and meant to be, with no reconciler operation or plan
	 * change pending, no restore marker and no reinstall or wipe under way. A take, a delete or a rollback never
	 * starts over any of these.
	 *
	 * @param Resource $r Resource.
	 */
	public static function idle( Resource $r ): bool {
		return Policy::idle( $r );
	}

	/**
	 * Whether the server has anything of snapshots in its meta (a list entry or a marker), whether or not the
	 * setting is on. What callers use to skip a Proxmox call when there is nothing to clean up.
	 *
	 * @param Resource $r Server resource.
	 */
	public static function tracked( Resource $r ): bool {
		return Policy::tracked( $r );
	}

	/**
	 * The snapshots in the list, oldest first.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries( Resource $r ): array {
		return Policy::entries( $r );
	}

	/**
	 * The entries that cover a volume.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $volid Volume id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function covering( Resource $r, string $volid ): array {
		return Policy::covering( $r, $volid );
	}

	/**
	 * What the reinstall form says about the snapshots (D8): they all go, with their number. Empty when there are
	 * none. The card (phase E) shows it; nothing else uses it.
	 *
	 * @param Resource $r Resource.
	 */
	public static function reinstall_warning( Resource $r ): string {
		return Policy::reinstall_warning( $r );
	}

	/**
	 * What the restore form says about the snapshots (D9): a restore replaces the server, so its snapshots go.
	 * Empty when there are none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function restore_warning( Resource $r ): string {
		return Policy::restore_warning( $r );
	}

	/**
	 * Slots bought on top of the free ones. The paid add-on answers through the filter; without it there are none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function paid_slots( Resource $r ): int {
		return Policy::paid_slots( $r );
	}

	/**
	 * Snapshots the server may hold: the free slots and the paid ones, never above the maximum.
	 *
	 * @param Resource $r Resource.
	 */
	public static function slots( Resource $r ): int {
		return Policy::slots( $r );
	}

	/**
	 * Snapshots that take a slot: every entry, also one that is being made or deleted.
	 *
	 * @param Resource $r Resource.
	 */
	public static function used( Resource $r ): int {
		return Policy::used( $r );
	}

	/**
	 * Days a snapshot taken now is kept: the longer paid time when the server has a paid slot.
	 *
	 * @param Resource $r Resource.
	 */
	public static function days_for( Resource $r ): int {
		return Policy::days_for( $r );
	}

	/**
	 * Is this id one of the server's snapshots?
	 *
	 * @param Resource $r  Resource.
	 * @param mixed    $id Id as sent.
	 */
	public static function owns( Resource $r, $id ): bool {
		return Policy::owns( $r, $id );
	}

	/**
	 * One snapshot of the list, or null.
	 *
	 * @param Resource $r  Resource.
	 * @param mixed    $id Id as sent.
	 * @return array<string, mixed>|null
	 */
	public static function entry( Resource $r, $id ): ?array {
		return Policy::entry( $r, $id );
	}

	/**
	 * The snapshot actions offered now. A step of an operation in flight stays offered (the retry of an action
	 * that is not offered any more is dropped by Jobs::act()).
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		return Policy::offered( $r );
	}

	/**
	 * The label as it will be kept: letters, digits, space, dash, underscore and dot, one line, at most
	 * MAX_LABEL characters. Nothing usable left (or nothing sent) gives a label made of the date.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function label_from( array $args ): string {
		return Policy::label_from( $args );
	}

	/**
	 * Why an action with these arguments cannot be accepted, in words for the customer; null when it can. No
	 * Proxmox call. Used by Actions::check() (the route answers at once, and act() asks again).
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function check( Resource $r, string $action, array $args ): ?string {
		return Policy::check( $r, $action, $args );
	}

	/**
	 * The drives of a VM configuration that hold a disk; a cd-rom, the cloud-init drive (made again from the
	 * configuration) and an empty drive are left out. The snapshot covers all of them, a `backup=0` extra disk too.
	 *
	 * @param array<string, mixed> $config VM configuration.
	 * @return array<string, string> Drive => volume id.
	 */
	public static function disks( array $config ): array {
		return Policy::disks( $config );
	}

	/**
	 * Free storage kept back for snapshots: the setting, or the reserve for extra disks when it is 0.
	 */
	public static function reserve_gb(): int {
		return Policy::reserve_gb();
	}

	/**
	 * The format of a volume, by its extension: qcow2, raw, or '' when it has neither.
	 *
	 * @param string $volid Volume id or name.
	 */
	public static function format_of( string $volid ): string {
		return Policy::format_of( $volid );
	}

	/**
	 * What the account panel shows. No Proxmox call.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null Null when the server gets no snapshots and has none.
	 */
	public static function panel( Resource $r ): ?array {
		return PanelData::panel( $r );
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
