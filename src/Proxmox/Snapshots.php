<?php
/**
 * Customer snapshots of a Proxmox VM: take, delete, roll back, expire.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

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

	use Steps {
		poll_task as private poll_any_task;
	}
	use Prepares;
	use RestoreSteps;

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
	 * Cluster settings (token secret decrypted).
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * Set while a disk delete drops the snapshots that cover its volume (see poll_task()).
	 *
	 * @var bool
	 */
	private bool $dropping = false;

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	// -- Rules -----------------------------------------------------------------------------------

	/**
	 * The format of a volume, by its extension: qcow2, raw, or '' when it has neither.
	 *
	 * @param string $volid Volume id or name.
	 */
	public static function format_of( string $volid ): string {
		return 1 === preg_match( '/\.(raw|qcow2)$/', $volid, $m ) ? $m[1] : '';
	}

	/**
	 * Is the switch in the settings on?
	 */
	public static function switched_on(): bool {
		return (bool) Settings::get( 'snapshots_enabled' );
	}

	/**
	 * Does this server get snapshots? The switch is on, it is not an adopted machine, and every disk it has is
	 * qcow2 (an old server on raw volumes gets none). No Proxmox call: the boot volume's format was recorded when
	 * the VM was built, the extra disks' formats are in their volume names.
	 *
	 * @param Resource $r Resource.
	 */
	public static function enabled( Resource $r ): bool {
		return self::eligible( $r ) && self::idle( $r );
	}

	/**
	 * Could this server get snapshots, leaving aside what it is doing now? The switch is on, it is not an adopted
	 * machine, and every disk is qcow2. What the paid slots are sold on.
	 *
	 * @param Resource $r Resource.
	 */
	public static function eligible( Resource $r ): bool {
		if ( ! self::switched_on() || true === $r->get( 'linked' ) || SshKeyField::DRIVER !== $r->get( 'driver' ) || null === VmRecords::for_resource( $r->id() ) ) {
			return false;
		}

		if ( 'qcow2' !== $r->get( 'boot_format' ) ) {
			return false;
		}

		foreach ( DiskMeta::for_server( $r ) as $disk ) {
			$name = (string) $disk->get( 'volname' );

			if ( '' !== $name && 'qcow2' !== self::format_of( $name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Is nothing else changing the server? It is ready and meant to be, with no reconciler operation or plan
	 * change pending, no restore marker and no reinstall or wipe under way. A take, a delete or a rollback never
	 * starts over any of these.
	 *
	 * @param Resource $r Resource.
	 */
	public static function idle( Resource $r ): bool {
		return 'ready' === $r->get( 'state' )
			&& 'ready' === $r->get( 'desired' )
			&& '' === (string) $r->get( 'op' )
			&& ! $r->get( 'plan_dirty' )
			&& ! is_array( $r->get( 'restore' ) )
			&& ! $r->get( 'wipe' );
	}

	/**
	 * Whether the server has anything of snapshots in its meta (a list entry or a marker), whether or not the
	 * setting is on. What callers use to skip a Proxmox call when there is nothing to clean up.
	 *
	 * @param Resource $r Server resource.
	 */
	public static function tracked( Resource $r ): bool {
		return array() !== self::entries( $r ) || array() !== (array) $r->get( self::OP ) || is_array( $r->get( self::ROLLBACK ) );
	}

	/**
	 * The snapshots in the list, oldest first.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries( Resource $r ): array {
		$out = array();

		foreach ( (array) $r->get( self::LIST ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'], $entry['snap'] ) ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The entries that cover a volume.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $volid Volume id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function covering( Resource $r, string $volid ): array {
		return array_values(
			array_filter(
				self::entries( $r ),
				static fn( $e ) => in_array( $volid, (array) ( $e['covers'] ?? array() ), true )
			)
		);
	}

	/**
	 * What the reinstall form says about the snapshots (D8): they all go, with their number. Empty when there are
	 * none. The card (phase E) shows it; nothing else uses it.
	 *
	 * @param Resource $r Resource.
	 */
	public static function reinstall_warning( Resource $r ): string {
		$count = count( self::entries( $r ) );

		if ( 0 === $count ) {
			return '';
		}

		/* translators: %d: number of snapshots */
		return sprintf( _n( 'Reinstalling deletes %d snapshot of this server.', 'Reinstalling deletes all %d snapshots of this server.', $count, 'beltoft-services' ), $count );
	}

	/**
	 * What the restore form says about the snapshots (D9): a restore replaces the server, so its snapshots go.
	 * Empty when there are none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function restore_warning( Resource $r ): string {
		return array() === self::entries( $r ) ? '' : __( 'Restoring a backup deletes the snapshots of this server.', 'beltoft-services' );
	}

	/**
	 * Is a take open (marker and entry both there) that has not outlived its age limit?
	 *
	 * @param Resource $r Resource.
	 */
	private static function take_open( Resource $r ): bool {
		$op = (array) $r->get( self::OP );

		return 'take' === ( $op['op'] ?? '' ) && null !== self::entry( $r, $op['id'] ?? null ) && ! self::stale( $op );
	}

	/**
	 * Has the operation been open longer than OP_MAX_AGE?
	 *
	 * @param array<string, mixed> $op Marker.
	 */
	private static function stale( array $op ): bool {
		return 'take' === ( $op['op'] ?? '' ) && (int) ( $op['at'] ?? 0 ) > 0 && time() - (int) $op['at'] > self::OP_MAX_AGE;
	}

	/**
	 * Slots bought on top of the free ones. The paid add-on answers through the filter; without it there are none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function paid_slots( Resource $r ): int {
		return max( 0, (int) apply_filters( 'beltoft_services_snapshot_paid_slots', 0, $r ) );
	}

	/**
	 * Snapshots the server may hold: the free slots and the paid ones, never above the maximum.
	 *
	 * @param Resource $r Resource.
	 */
	public static function slots( Resource $r ): int {
		$free = max( 0, (int) Settings::get( 'snapshot_free_slots' ) );

		return min( max( 1, (int) Settings::get( 'snapshot_max_slots' ) ), $free + self::paid_slots( $r ) );
	}

	/**
	 * Snapshots that take a slot: every entry, also one that is being made or deleted.
	 *
	 * @param Resource $r Resource.
	 */
	public static function used( Resource $r ): int {
		return count( self::entries( $r ) );
	}

	/**
	 * Days a snapshot taken now is kept: the longer paid time when the server has a paid slot.
	 *
	 * @param Resource $r Resource.
	 */
	public static function days_for( Resource $r ): int {
		if ( self::paid_slots( $r ) > 0 ) {
			$own = SnapshotMeta::days_for( $r ); // A pack's own period wins over the setting.

			return max( 1, $own > 0 ? $own : (int) Settings::get( 'snapshot_paid_days' ) );
		}

		return max( 1, (int) Settings::get( 'snapshot_free_days' ) );
	}

	/**
	 * Is this id one of the server's snapshots?
	 *
	 * @param Resource $r  Resource.
	 * @param mixed    $id Id as sent.
	 */
	public static function owns( Resource $r, $id ): bool {
		return null !== self::entry( $r, $id );
	}

	/**
	 * One snapshot of the list, or null.
	 *
	 * @param Resource $r  Resource.
	 * @param mixed    $id Id as sent.
	 * @return array<string, mixed>|null
	 */
	public static function entry( Resource $r, $id ): ?array {
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{8}$/', $id ) ) {
			return null;
		}

		foreach ( self::entries( $r ) as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * What the account panel shows. No Proxmox call.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null Null when the server gets no snapshots and has none.
	 */
	public static function panel( Resource $r ): ?array {
		$entries = self::entries( $r );
		$enabled = self::enabled( $r );

		if ( ! $enabled && ! $entries ) {
			return null;
		}

		return array(
			'enabled'  => $enabled,
			'slots'    => self::slots( $r ),
			'used'     => count( $entries ),
			'days'     => self::days_for( $r ),
			'rolling'  => is_array( $r->get( self::ROLLBACK ) ),
			'entries'  => array_map(
				static fn( $e ) => array(
					'id'         => (string) $e['id'],
					'label'      => (string) ( $e['label'] ?? '' ),
					'taken_at'   => (int) ( $e['taken_at'] ?? 0 ),
					'expires_at' => (int) ( $e['expires_at'] ?? 0 ),
					'consistent' => ! empty( $e['consistent'] ),
					'state'      => (string) ( $e['state'] ?? 'ok' ),
				),
				$entries
			),
		);
	}

	/**
	 * The snapshot actions offered now. A step of an operation in flight stays offered (the retry of an action
	 * that is not offered any more is dropped by Jobs::act()).
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		$out     = array();
		$entries = self::entries( $r );
		$op      = (array) $r->get( self::OP );
		$ready   = array_filter( $entries, static fn( $e ) => 'ok' === ( $e['state'] ?? 'ok' ) );
		$on      = self::enabled( $r );

		if ( ( $on && count( $entries ) < self::slots( $r ) ) || 'take' === ( $op['op'] ?? '' ) ) {
			$out['snapshot_take'] = __( 'Take a snapshot', 'beltoft-services' );
		}

		if ( $entries && true !== $r->get( 'linked' ) ) {
			$out['snapshot_delete'] = __( 'Delete a snapshot', 'beltoft-services' );
		}

		if ( ( $on && $ready ) || is_array( $r->get( self::ROLLBACK ) ) ) {
			$out['snapshot_rollback'] = __( 'Roll back to a snapshot', 'beltoft-services' );
		}

		return $out;
	}

	/**
	 * The label as it will be kept: letters, digits, space, dash, underscore and dot, one line, at most
	 * MAX_LABEL characters. Nothing usable left (or nothing sent) gives a label made of the date.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function label_from( array $args ): string {
		$label = isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : '';
		$label = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^A-Za-z0-9 _.\-]/', '', $label ) ) );
		$label = trim( substr( $label, 0, self::MAX_LABEL ) );

		return '' === $label ? 'Snapshot ' . gmdate( 'Y-m-d H:i' ) : $label;
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
		$op      = (array) $r->get( self::OP );
		$rolling = (array) $r->get( self::ROLLBACK );

		// A rollback that has begun is finished whatever happens to the switch or a disk meanwhile: the server is off.
		if ( 'snapshot_rollback' === $action && isset( $args['id'] ) && ( $rolling['id'] ?? '' ) === $args['id'] ) {
			return null;
		}

		// A take that is under way is resumed by the next take, whatever label it carries; the op owns the entry.
		if ( 'snapshot_take' === $action && 'take' === ( $op['op'] ?? '' ) && null !== self::entry( $r, $op['id'] ?? null ) ) {
			return null;
		}

		// Nothing else starts while a rollback or a take is open (a take past its age limit is closed by the next run).
		if ( $rolling || self::take_open( $r ) ) {
			return __( 'Another snapshot action is still running on this server. Try again in a few minutes.', 'beltoft-services' );
		}

		if ( 'snapshot_delete' === $action ) {
			$entry = self::entry( $r, $args['id'] ?? null );

			// An entry that is still being made is the take's; one that is being deleted may be asked again.
			return null !== $entry && in_array( $entry['state'] ?? 'ok', array( 'ok', 'deleting' ), true ) ? null : __( 'Choose one of your snapshots.', 'beltoft-services' );
		}

		if ( ! self::enabled( $r ) ) {
			return __( 'Snapshots are not available for this server.', 'beltoft-services' );
		}

		if ( 'snapshot_take' === $action ) {
			if ( self::used( $r ) >= self::slots( $r ) ) {
				return __( 'All your snapshot slots are in use. Delete a snapshot first.', 'beltoft-services' );
			}

			foreach ( self::entries( $r ) as $entry ) {
				if ( 0 === strcasecmp( (string) ( $entry['label'] ?? '' ), self::label_from( $args ) ) ) {
					return __( 'You already have a snapshot with that name.', 'beltoft-services' );
				}
			}

			return null;
		}

		$entry = self::entry( $r, $args['id'] ?? null );

		if ( null === $entry || 'ok' !== ( $entry['state'] ?? 'ok' ) ) {
			return __( 'Choose one of your snapshots.', 'beltoft-services' );
		}

		if ( self::CONFIRM_ROLLBACK !== ( $args['confirm'] ?? '' ) ) {
			/* translators: %s: the word to type, ROLLBACK. */
			return sprintf( __( 'Type %s to confirm. Rolling back replaces everything on the server with the snapshot.', 'beltoft-services' ), self::CONFIRM_ROLLBACK );
		}

		return Actions::disk_block( $r, 'rollback' );
	}

	/**
	 * The drives of a VM configuration that hold a disk; a cd-rom, the cloud-init drive (made again from the
	 * configuration) and an empty drive are left out. The snapshot covers all of them, a `backup=0` extra disk too.
	 *
	 * @param array<string, mixed> $config VM configuration.
	 * @return array<string, string> Drive => volume id.
	 */
	public static function disks( array $config ): array {
		$out = array();

		foreach ( $config as $key => $value ) {
			if ( 1 !== preg_match( '/^(?:scsi|virtio|sata|ide)\d+$|^(?:efidisk0|tpmstate0)$/', (string) $key ) || ! is_string( $value ) || false !== strpos( ',' . $value . ',', ',media=cdrom,' ) || false !== strpos( explode( ',', $value )[0], 'cloudinit' ) || 'none' === $value ) {
				continue;
			}

			$out[ (string) $key ] = explode( ',', $value )[0];
		}

		return $out;
	}

	/**
	 * Free storage kept back for snapshots: the setting, or the reserve for extra disks when it is 0.
	 */
	public static function reserve_gb(): int {
		$own = (int) Settings::get( 'snapshot_reserve_gb' );

		return $own > 0 ? $own : max( 0, (int) Settings::get( 'disk_reserve_gb' ) );
	}

	// -- Run -------------------------------------------------------------------------------------

	/**
	 * Runs one snapshot action (called by Actions::run() and, for the internal ones, by the driver, inside
	 * Jobs::act()'s lock).
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function run( string $action, array $args ): StepResult {
		$result = $this->dispatch( $action, $args );

		$this->note( $result );

		return $result;
	}

	/**
	 * Keeps the last problem for the admin panel (meta `snapshot_error`): set by a failure or a refusal, cleared
	 * by an action that succeeded; a retry leaves it as it is. Written only when it changes.
	 *
	 * @param StepResult $result Outcome of an action.
	 */
	private function note( StepResult $result ): void {
		$now = (string) $this->r->get( self::ERROR );

		if ( $result->is_retry() ) {
			return; // A retry or a wait is not a problem to show, and it must not hide one.
		}

		$text = $result->is_failed() ? $result->error() : ( 1 === preg_match( '/\brefused\b/i', $result->event() ) ? $result->event() : '' );
		$next = $this->scrub( $text, 200 );

		if ( $now !== $next ) {
			$this->r->set( array( self::ERROR => '' === $next ? null : $next ) );
		}
	}

	/**
	 * One action.
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	private function dispatch( string $action, array $args ): StepResult {
		$began = $this->begin( $action, true );

		if ( $began instanceof StepResult ) {
			return $began;
		}

		list( $record, $v ) = $began;

		$closed = $this->close_stale( $record, $v );

		if ( null !== $closed ) {
			return $closed;
		}

		switch ( $action ) {
			case 'snapshot_take':
				return $this->take( $record, $v, $args );
			case 'snapshot_delete':
				return $this->delete( $record, $v, (string) ( $args['id'] ?? '' ) );
			case 'snapshot_rollback':
				return $this->rollback( $record, $v, (string) ( $args['id'] ?? '' ) );
			case 'snapshot_expire':
				return $this->expire( $record, $v );
			case 'snapshot_purge':
				return $this->purge( $record, $v );
			case 'snapshot_sync':
				return $this->sync( $record, $v );
			default:
				return StepResult::fail( 'This action is not available' );
		}
	}

	/**
	 * Deletes every snapshot of the list. For a reinstall or a restore, which end them anyway. Done when none is
	 * left; a retry while a delete runs or is refused. It reads the remote list again every time, so the caller
	 * may call it again after its own task poll.
	 */
	public function purge_all(): StepResult {
		$began = $this->begin( 'snapshot_purge', false );

		return $began instanceof StepResult ? $began : $this->purge( $began[0], $began[1] );
	}

	/**
	 * Deletes the snapshots that cover a volume, before the volume goes. Done when none is left.
	 *
	 * @param string $volid Volume id.
	 */
	public function drop_covering( string $volid ): StepResult {
		$this->dropping = true;
		$began          = $this->begin( 'snapshot_drop', true );
		$this->dropping = false;

		if ( $began instanceof StepResult ) {
			return $began;
		}

		list( $record, $v ) = $began;

		foreach ( self::entries( $this->r ) as $entry ) {
			if ( in_array( $volid, (array) ( $entry['covers'] ?? array() ), true ) ) {
				$done = $this->delete( $record, $v, (string) $entry['id'] );

				if ( ! $done->is_done() ) {
					return $done;
				}
			}
		}

		return null === $this->r->get( self::DROP_WAITS ) || $this->r->set( array( self::DROP_WAITS => null ) ) ? StepResult::done() : StepResult::retry( 0, 'snapshot: the wait count could not be cleared' );
	}

	/**
	 * Forgets the whole list (the VM's snapshots went with a restore over it), then reads the remote list again.
	 */
	public function forget_all(): StepResult {
		$began = $this->begin( 'snapshot_sync', false );

		if ( $began instanceof StepResult ) {
			return $began;
		}

		if ( ! $this->r->set( array( self::LIST => array(), self::OP => null, self::ROLLBACK => null ) ) ) {
			return StepResult::retry( 0, 'snapshot: the list could not be stored' );
		}

		return $this->sync( $began[0], $began[1] );
	}

	/**
	 * The record and the verified VM, or the answer of the round.
	 *
	 * @param string $step Step name for messages.
	 * @param bool   $poll Poll the stored task first.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|StepResult
	 */
	private function begin( string $step, bool $poll ) {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'VM record missing; check Proxmox manually' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		if ( $poll ) {
			$early = $this->poll_task();

			if ( null !== $early ) {
				return $early;
			}
		}

		$v = $this->verified( $record, $step );

		return $v instanceof StepResult ? $v : array( $record, $v );
	}

	/**
	 * Polls the task in the shared `task` slot only when a snapshot flow owns it: a task of step `snapshot`, or
	 * any task while a rollback is open (its stop and its disk steps use the slot too). A task of another job is
	 * never polled or cleared here: that job's own run does it, and this one waits for it.
	 */
	protected function poll_task(): ?StepResult {
		$task = $this->r->get( 'task' );

		if ( ! is_array( $task ) || empty( $task['upid'] ) || ! is_string( $task['upid'] ) ) {
			return null;
		}

		if ( 'snapshot' !== (string) ( $task['step'] ?? '' ) && ! is_array( $this->r->get( self::ROLLBACK ) ) ) {
			if ( $this->dropping ) {
				// A billing-driven disk delete never waits for good on a leftover task: a few rounds, then it goes on
				// (Proxmox's own VM lock serialises, and a refusal is a retry).
				$waits = (int) $this->r->get( self::DROP_WAITS ) + 1;

				if ( $waits > self::DROP_WAIT_ROUNDS ) {
					return null;
				}

				return $this->r->set( array( self::DROP_WAITS => $waits ) )
					? StepResult::retry( 0, 'snapshot: waiting for another task on the server to end' )
					: StepResult::retry( 0, 'snapshot: the wait could not be counted' );
			}

			return StepResult::retry( 0, 'snapshot: waiting for another task on the server to end' );
		}

		return $this->poll_any_task();
	}

	/**
	 * The VM's path.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private static function base( array $record, array $v ): string {
		return '/nodes/' . rawurlencode( $v['node'] ) . '/qemu/' . $record['vmid'];
	}

	/**
	 * The snapshots Proxmox lists for the VM, by name (the `current` line is not one).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, array<string, mixed>>|StepResult
	 */
	private function remote( array $record, array $v ) {
		$list = $this->client->request( 'GET', self::base( $record, $v ) . '/snapshot' );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'snapshot list' );
		}

		$out = array();

		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) && 'current' !== $item['name'] ) {
				$out[ $item['name'] ] = $item;
			}
		}

		return $out;
	}

	/**
	 * Nothing runs on the VM that a snapshot would collide with: no lock, no backup under way. A retry otherwise.
	 *
	 * @param array<string, mixed> $v Verified VM.
	 * @param string               $what Name for the message.
	 */
	private function quiet( array $v, string $what ): ?StepResult {
		$running = ( new Backups( $this->r ) )->collect_running();

		if ( null !== $running ) {
			return StepResult::retry( 0, $what . ': ' . $running->error() );
		}

		if ( '' !== (string) $v['lock'] ) {
			return StepResult::retry( 0, $what . ': the server is locked (' . sanitize_key( (string) $v['lock'] ) . ')' );
		}

		return null;
	}

	/**
	 * The host-space guard: the storage is read now, and a take needs at least the reserve free. Not readable is
	 * not enough: it fails closed.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function space( array $record, array $v ): ?StepResult {
		$storage = (string) $this->cluster['storage'];

		if ( ! DiskCapacity::refresh( (string) $record['cluster'], (string) $v['node'], $storage, $this->client ) ) {
			return StepResult::retry( 0, 'snapshot: the free space of the storage could not be read' );
		}

		$avail = DiskCapacity::available( (string) $record['cluster'], (string) $v['node'], $storage );

		if ( null === $avail || $avail < self::reserve_gb() * 1073741824 ) {
			$text = sprintf( 'Snapshot refused: the storage %s has less than %d GB free', $storage, self::reserve_gb() );
			Alerts::admin( $this->r, $text );

			return StepResult::done( array(), $text );
		}

		return null;
	}

	/**
	 * Stores the list and the operation marker together.
	 *
	 * @param array<int, array<string, mixed>>|null $list List, or null to leave it.
	 * @param array<string, mixed>|null             $op   Marker; null clears it.
	 * @param array<string, mixed>                  $more More meta.
	 */
	private function save( ?array $list, ?array $op, array $more = array() ): bool {
		$meta = array( self::OP => $op ) + $more;

		if ( null !== $list ) {
			$meta[ self::LIST ] = array_values( $list );
		}

		return $this->r->set( $meta );
	}

	/**
	 * The marker of the operation in flight, or null. A delete leaves it alone: a take that is still under way
	 * keeps its marker.
	 *
	 * @return array<string, mixed>|null
	 */
	private function open_op(): ?array {
		$op = $this->r->get( self::OP );

		return is_array( $op ) && $op ? $op : null;
	}

	/**
	 * The list with one entry changed or, with null, removed.
	 *
	 * @param string                    $id    Id.
	 * @param array<string, mixed>|null $patch Values to set.
	 * @return array<int, array<string, mixed>>
	 */
	private function with( string $id, ?array $patch ): array {
		$out = array();

		foreach ( self::entries( $this->r ) as $entry ) {
			if ( $entry['id'] !== $id ) {
				$out[] = $entry;
			} elseif ( null !== $patch ) {
				$out[] = array_merge( $entry, $patch );
			}
		}

		return $out;
	}

	// -- Take ------------------------------------------------------------------------------------

	/**
	 * A take that has been open longer than OP_MAX_AGE (it ran out of retries, or its job was lost) is closed
	 * here, by whichever action or sync comes next: the entry stays as `ok` when Proxmox lists the snapshot,
	 * and goes when it does not. The admin is told once, because the marker goes with it.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return StepResult|null Null when nothing was stale or it is closed; a retry otherwise.
	 */
	private function close_stale( array $record, array $v ): ?StepResult {
		$op = $this->open_op();

		if ( null === $op || ! self::stale( $op ) ) {
			return null;
		}

		$entry = self::entry( $this->r, $op['id'] ?? null );

		if ( null === $entry ) {
			return $this->save( null, null ) ? null : StepResult::retry( 0, 'snapshot: the old request could not be closed' );
		}

		$remote = $this->remote( $record, $v );

		if ( $remote instanceof StepResult ) {
			return $remote;
		}

		$kept = isset( $remote[ $entry['snap'] ] );
		$list = $kept ? $this->with( (string) $entry['id'], array( 'state' => 'ok' ) ) : $this->with( (string) $entry['id'], null );

		if ( ! $this->save( $list, null ) ) {
			return StepResult::retry( 0, 'snapshot: the old request could not be closed' );
		}

		Alerts::admin( $this->r, 'A snapshot request ("' . $this->scrub( (string) $entry['label'], 60 ) . '") ran out of time without finishing. ' . ( $kept ? 'Proxmox lists the snapshot, so it was kept.' : 'Proxmox does not list it, so the entry was dropped.' ) );

		return null;
	}

	/**
	 * Takes a snapshot. The entry is written down (`creating`, with the volumes it will cover) before Proxmox is
	 * asked; the Proxmox task is waited for (bounded); what Proxmox lists afterwards decides. A failure removes
	 * the entry again and tells the admin.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param array<string, mixed> $args   Arguments.
	 */
	private function take( array $record, array $v, array $args ): StepResult {
		$op = (array) $this->r->get( self::OP );

		if ( 'take' !== ( $op['op'] ?? '' ) || null === self::entry( $this->r, $op['id'] ?? null ) ) {
			$started = $this->take_begin( $record, $v, $args );

			if ( $started instanceof StepResult ) {
				return $started;
			}

			$op = $started;
		}

		$entry = self::entry( $this->r, $op['id'] );

		if ( null === $entry ) {
			return StepResult::done( array( self::OP => null ), 'Snapshot: the entry is gone' );
		}

		$remote = $this->remote( $record, $v );

		if ( $remote instanceof StepResult ) {
			return $remote;
		}

		if ( ! isset( $remote[ $entry['snap'] ] ) ) {
			// The task of an earlier round has ended and left no snapshot: it failed.
			if ( 'sent' === ( $op['phase'] ?? '' ) && $this->finished && 'snapshot' === $this->finished['step'] && 'take' === $this->finished['phase'] ) {
				return $this->take_failed( $entry, (string) $this->finished['exit'] );
			}

			$quiet = $this->quiet( $v, 'snapshot' );

			if ( null !== $quiet ) {
				return $quiet;
			}

			$op['phase'] = 'sent';

			if ( ! $this->save( null, $op ) ) {
				return StepResult::retry( 0, 'snapshot: progress could not be stored' );
			}

			$done = $this->run_task(
				'snapshot',
				'POST',
				self::base( $record, $v ) . '/snapshot',
				array(
					'snapname'    => $entry['snap'],
					'description' => (string) $entry['label'],
					'vmstate'     => 0,
				),
				'take'
			);

			if ( $done instanceof StepResult ) {
				return $done->is_failed() ? $this->take_failed( $entry, $done->error() ) : $done;
			}

			$remote = $this->remote( $record, $v );

			if ( $remote instanceof StepResult ) {
				return $remote;
			}

			if ( ! isset( $remote[ $entry['snap'] ] ) ) {
				return $this->take_failed( $entry, true === $done ? 'OK' : (string) $done['failed'] );
			}
		}

		if ( ! $this->save( $this->with( $entry['id'], array( 'state' => 'ok' ) ), null ) ) {
			return StepResult::retry( 0, 'snapshot: the result could not be stored' );
		}

		return StepResult::done( array(), 'Customer took the snapshot "' . $this->scrub( (string) $entry['label'], 60 ) . '"' );
	}

	/**
	 * The guards of a new take and the first write: the entry and the marker. A StepResult is the round's answer.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param array<string, mixed> $args   Arguments.
	 * @return array<string, mixed>|StepResult The marker.
	 */
	private function take_begin( array $record, array $v, array $args ) {
		if ( self::used( $this->r ) >= self::slots( $this->r ) ) {
			return StepResult::done( array(), 'Snapshot refused: no slot is free' );
		}

		$quiet = $this->quiet( $v, 'snapshot' );

		if ( null !== $quiet ) {
			return $quiet;
		}

		$covers = self::disks( (array) $v['config'] );

		foreach ( $covers as $volid ) {
			if ( 'qcow2' !== self::format_of( $volid ) ) {
				$text = 'Snapshot refused: the volume ' . $this->scrub( $volid, 120 ) . ' is not qcow2';
				Alerts::admin( $this->r, $text );

				return StepResult::done( array(), $text );
			}
		}

		if ( ! $covers ) {
			return StepResult::done( array(), 'Snapshot refused: the server has no disk' );
		}

		$guard = $this->space( $record, $v );

		if ( null !== $guard ) {
			return $guard;
		}

		$id    = bin2hex( random_bytes( 4 ) );
		$now   = time();
		$entry = array(
			'id'         => $id,
			'snap'       => 'bs' . bin2hex( random_bytes( 5 ) ),
			'label'      => self::label_from( $args ),
			'taken_at'   => $now,
			'expires_at' => $now + self::days_for( $this->r ) * DAY_IN_SECONDS,
			'covers'     => array_values( $covers ),
			'consistent' => 'running' === $v['status'] && Actions::agent_ok( $this->r ),
			'paid'       => self::paid_slots( $this->r ) > 0,
			'state'      => 'creating',
		);
		$op    = array(
			'op'    => 'take',
			'id'    => $id,
			'phase' => 'creating',
			'at'    => $now,
		);

		if ( ! $this->save( array_merge( self::entries( $this->r ), array( $entry ) ), $op ) ) {
			return StepResult::retry( 0, 'snapshot: the request could not be stored' );
		}

		return $op;
	}

	/**
	 * A take that did not give a snapshot: the entry goes, the admin is told.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param string               $exit  What Proxmox answered (admin text).
	 */
	private function take_failed( array $entry, string $exit ): StepResult {
		$text = 'Snapshot failed (' . $this->scrub( $exit, 160 ) . ')';

		if ( ! $this->save( $this->with( (string) $entry['id'], null ), null ) ) {
			return StepResult::retry( 0, 'snapshot: the failure could not be stored' );
		}

		return StepResult::fail( $text, array( self::OP => null ) );
	}

	// -- Delete ----------------------------------------------------------------------------------

	/**
	 * Deletes one snapshot: marked `deleting`, deleted, and dropped from the list once Proxmox no longer lists it
	 * (also when it was gone already).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $id     Snapshot id.
	 */
	private function delete( array $record, array $v, string $id ): StepResult {
		$entry = self::entry( $this->r, $id );

		if ( null === $entry ) {
			return StepResult::done( array(), 'Snapshot delete: it was already gone' );
		}

		$remote = $this->remote( $record, $v );

		if ( $remote instanceof StepResult ) {
			return $remote;
		}

		if ( isset( $remote[ $entry['snap'] ] ) ) {
			$quiet = $this->quiet( $v, 'snapshot delete' );

			if ( null !== $quiet ) {
				return $quiet;
			}

			if ( ! $this->save( $this->with( $id, array( 'state' => 'deleting' ) ), $this->open_op() ) ) {
				return StepResult::retry( 0, 'snapshot delete: progress could not be stored' );
			}

			$done = $this->run_task( 'snapshot', 'DELETE', self::base( $record, $v ) . '/snapshot/' . rawurlencode( (string) $entry['snap'] ), array(), 'delete' );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$remote = $this->remote( $record, $v );

			if ( $remote instanceof StepResult ) {
				return $remote;
			}

			if ( isset( $remote[ $entry['snap'] ] ) ) {
				return StepResult::retry( 0, 'snapshot delete: the snapshot is still listed (' . ( true === $done ? 'OK' : $this->scrub( (string) $done['failed'], 120 ) ) . ')' );
			}
		}

		if ( ! $this->save( $this->with( $id, null ), $this->open_op() ) ) {
			return StepResult::retry( 0, 'snapshot delete: the result could not be stored' );
		}

		return StepResult::done( array(), 'Snapshot "' . $this->scrub( (string) $entry['label'], 60 ) . '" deleted' );
	}

	// -- Roll back -------------------------------------------------------------------------------

	/**
	 * Rolls the server back to one snapshot: off, rolled back, the extra disks attached and grown again, the plan
	 * queued again (it starts the VM when it was running). Every round starts from the remote state and the marker
	 * in meta `snapshot_rollback` ({id, phase, was_running}), so a retry never rolls back twice. The password and
	 * the SSH key are the snapshot's again. A failed rollback leaves the server as it is (started again when it
	 * ran) and tells the admin.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $id     Snapshot id.
	 */
	private function rollback( array $record, array $v, string $id ): StepResult {
		$marker = $this->r->get( self::ROLLBACK );
		$marker = is_array( $marker ) && ( $marker['id'] ?? '' ) === $id ? $marker : array();
		$phase  = (string) ( $marker['phase'] ?? '' );
		$entry  = self::entry( $this->r, $id );

		if ( '' === $phase ) {
			if ( null === $entry || 'ok' !== ( $entry['state'] ?? 'ok' ) ) {
				return StepResult::done( array( self::ROLLBACK => null ), 'Rollback refused: that snapshot is not available' );
			}

			if ( null !== Actions::disk_block( $this->r, 'rollback' ) ) {
				return StepResult::done( array( self::ROLLBACK => null ), 'Rollback refused: an extra disk is detached or needs attention; the server was not touched' );
			}

			$remote = $this->remote( $record, $v );

			if ( $remote instanceof StepResult ) {
				return $remote;
			}

			if ( ! isset( $remote[ $entry['snap'] ] ) ) {
				$this->save( $this->with( $id, null ), $this->open_op() );

				return StepResult::done( array( self::ROLLBACK => null ), 'Rollback refused: the snapshot is not on the server any more' );
			}

			$quiet = $this->quiet( $v, 'rollback' );

			if ( null !== $quiet ) {
				return $quiet;
			}

			$marker = array(
				'id'          => $id,
				'phase'       => 'stopping',
				'was_running' => 'running' === $v['status'],
				'at'          => time(),
			);

			if ( ! $this->r->set( array( self::ROLLBACK => $marker ) ) ) {
				return StepResult::retry( 0, 'rollback: progress could not be stored' );
			}

			$phase = 'stopping';
		}

		if ( null === $entry ) {
			return $this->rollback_failed( $record, $v, $marker, 'the snapshot left the list while it ran' );
		}

		if ( 'stopping' === $phase ) {
			$off = ( new Operations( $this->r ) )->pause();

			if ( ! $off->is_done() ) {
				return $off;
			}

			$again = $this->verified( $record, 'rollback' );

			if ( $again instanceof StepResult ) {
				return $again;
			}

			if ( 'running' === $again['status'] ) {
				return StepResult::retry( 0, 'rollback: the server did not switch off' );
			}

			$marker['phase'] = 'rolling';

			if ( ! $this->r->set( array( self::ROLLBACK => $marker ) ) ) {
				return StepResult::retry( 0, 'rollback: progress could not be stored' );
			}

			$phase = 'rolling';
			$v     = $again;
		}

		if ( 'rolling' === $phase ) {
			$ended = $this->finished && 'snapshot' === $this->finished['step'] && 'rollback' === $this->finished['phase'];

			// The rollback task of an earlier round has ended: that decides, not another rollback.
			if ( $ended ) {
				$failed = ! $this->finished['ok'] && 0 !== strpos( (string) $this->finished['exit'], 'WARNINGS' );
				$why    = (string) $this->finished['exit'];
			} else {
				$done   = $this->run_task( 'snapshot', 'POST', self::base( $record, $v ) . '/snapshot/' . rawurlencode( (string) $entry['snap'] ) . '/rollback', array(), 'rollback' );

				if ( $done instanceof StepResult ) {
					return $done->is_failed() ? $this->rollback_failed( $record, $v, $marker, $done->error() ) : $done;
				}

				$failed = true !== $done && 0 !== strpos( (string) $done['failed'], 'WARNINGS' );
				$why    = true === $done ? 'OK' : (string) $done['failed'];
			}

			if ( $failed ) {
				return $this->rollback_failed( $record, $v, $marker, $why );
			}

			$marker['phase'] = 'rolled';

			if ( ! $this->r->set( array( self::ROLLBACK => $marker ) ) ) {
				return StepResult::retry( 0, 'rollback: progress could not be stored' );
			}

			$phase = 'rolled';
		}

		$planned = $this->reapply_plan( $record, 'rollback', ! empty( $marker['was_running'] ) );

		if ( null !== $planned ) {
			return $planned;
		}

		return StepResult::done( array( self::ROLLBACK => null ), 'Server rolled back to the snapshot "' . $this->scrub( (string) $entry['label'], 60 ) . '" of ' . gmdate( 'Y-m-d H:i', (int) $entry['taken_at'] ) . ' UTC' );
	}

	/**
	 * A rollback that ended badly: a server that ran is started again (best effort), the customer gets one fixed
	 * note, the admin the failure.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param array<string, mixed> $marker The marker.
	 * @param string               $exit   Exit status (admin text).
	 */
	private function rollback_failed( array $record, array $v, array $marker, string $exit ): StepResult {
		if ( ! empty( $marker['was_running'] ) && 'running' !== $v['status'] ) {
			$upid = $this->client->task( 'POST', self::base( $record, $v ) . '/status/start' );

			if ( is_string( $upid ) ) {
				$this->client->wait_task( $upid, $this->wait );
			}
		}

		// Whatever the failed rollback left, the paid plan is applied again (it starts the server when it ran).
		if ( true === $this->r->change_plan( (array) $this->r->get( 'plan' ), 'Plan re-applied after a failed rollback' ) ) {
			Jobs::enqueue( $this->r->id() );
		}

		$this->tell_customer( 'rollback' );

		return StepResult::fail( 'Rollback failed (' . $this->scrub( $exit, 160 ) . '); the server was left as it was and may need a manual check', array( self::ROLLBACK => null ) );
	}

	// -- Expire, purge, sync ---------------------------------------------------------------------

	/**
	 * Deletes the snapshots that are due (their time is up, or a delete was left half done), one after the other,
	 * then reads the remote list. A round that cannot finish is a retry, and goes on at the same place.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function expire( array $record, array $v ): StepResult {
		$now     = time();
		$expired = array();

		foreach ( self::entries( $this->r ) as $entry ) {
			if ( SnapshotExpiry::is_due( $entry, $now ) ) {
				// Their time is up (not the state: a delete that took several rounds is "deleting" by then).
				$lapsed = (int) ( $entry['expires_at'] ?? 0 ) <= $now;
				$done   = $this->delete( $record, $v, (string) $entry['id'] );

				if ( ! $done->is_done() ) {
					return $done;
				}

				if ( $lapsed ) {
					$expired[] = (string) $entry['id'];
				}
			}
		}

		if ( $expired ) {
			// One mail for the round.
			SnapshotExpiry::notify( $this->r, 'expired', md5( implode( ',', $expired ) ), array( 'snapshot_count' => count( $expired ) ) );
		}

		return $this->sync( $record, $v );
	}

	/**
	 * Deletes every snapshot of the list.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function purge( array $record, array $v ): StepResult {
		foreach ( self::entries( $this->r ) as $entry ) {
			$done = $this->delete( $record, $v, (string) $entry['id'] );

			if ( ! $done->is_done() ) {
				return $done;
			}
		}

		return StepResult::done();
	}

	/**
	 * Reads the remote list: an entry Proxmox no longer lists is dropped (not one that is being made), and a
	 * `bs` snapshot that is not in the list is left alone and reported to the admin.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function sync( array $record, array $v ): StepResult {
		$remote = $this->remote( $record, $v );

		if ( $remote instanceof StepResult ) {
			return $remote;
		}

		$op     = (array) $this->r->get( self::OP );
		$kept   = array();
		$known  = array();
		$change = false;

		foreach ( self::entries( $this->r ) as $entry ) {
			$known[ $entry['snap'] ] = true;

			// What is in flight is decided by its own action.
			if ( ! isset( $remote[ $entry['snap'] ] ) && ( $op['id'] ?? '' ) !== $entry['id'] ) {
				$change = true;
				continue;
			}

			$kept[] = $entry;
		}

		if ( $change && ! $this->save( $kept, $this->open_op() ) ) {
			return StepResult::retry( 0, 'snapshot: the list could not be stored' );
		}

		foreach ( array_keys( $remote ) as $name ) {
			if ( 1 === preg_match( '/^bs[a-f0-9]{10}$/', (string) $name ) && ! isset( $known[ $name ] ) ) {
				Alerts::admin( $this->r, 'The server has a snapshot named ' . $name . ' that is not in the plugin\'s list. It was left alone.' );
			}
		}

		return StepResult::done();
	}
}
