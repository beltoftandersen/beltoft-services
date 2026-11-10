<?php
/**
 * The runner of customer snapshot actions: take, delete, roll back, expire, purge and sync.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Snapshot;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\Pve\DiskCapacity;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Vm\Provision;
use BeltoftServices\Proxmox\Vm\RestoreSteps;
use BeltoftServices\Proxmox\SnapshotExpiry;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one snapshot action on one server resource, under the lock the caller holds. Snapshots is the facade the
 * rest of the plugin calls; the rules sit in Policy. The meta keys and the phase names are the constants of
 * Snapshots and are persisted, so they never change here.
 */
final class Engine {

	use Steps {
		poll_task as private poll_any_task;
	}
	use Prepares;
	use RestoreSteps;

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
		$now = (string) $this->r->get( Snapshots::ERROR );

		if ( $result->is_retry() ) {
			return; // A retry or a wait is not a problem to show, and it must not hide one.
		}

		$text = $result->is_failed() ? $result->error() : ( 1 === preg_match( '/\brefused\b/i', $result->event() ) ? $result->event() : '' );
		$next = $this->scrub( $text, 200 );

		if ( $now !== $next ) {
			$this->r->set( array( Snapshots::ERROR => '' === $next ? null : $next ) );
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

		foreach ( Policy::entries( $this->r ) as $entry ) {
			if ( in_array( $volid, (array) ( $entry['covers'] ?? array() ), true ) ) {
				$done = $this->delete( $record, $v, (string) $entry['id'] );

				if ( ! $done->is_done() ) {
					return $done;
				}
			}
		}

		return null === $this->r->get( Snapshots::DROP_WAITS ) || $this->r->set( array( Snapshots::DROP_WAITS => null ) ) ? StepResult::done() : StepResult::retry( 0, 'snapshot: the wait count could not be cleared' );
	}

	/**
	 * Forgets the whole list (the VM's snapshots went with a restore over it), then reads the remote list again.
	 */
	public function forget_all(): StepResult {
		$began = $this->begin( 'snapshot_sync', false );

		if ( $began instanceof StepResult ) {
			return $began;
		}

		if ( ! $this->r->set( array( Snapshots::LIST => array(), Snapshots::OP => null, Snapshots::ROLLBACK => null ) ) ) {
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

		if ( 'snapshot' !== (string) ( $task['step'] ?? '' ) && ! is_array( $this->r->get( Snapshots::ROLLBACK ) ) ) {
			if ( $this->dropping ) {
				// A billing-driven disk delete never waits for good on a leftover task: a few rounds, then it goes on
				// (Proxmox's own VM lock serialises, and a refusal is a retry).
				$waits = (int) $this->r->get( Snapshots::DROP_WAITS ) + 1;

				if ( $waits > Snapshots::DROP_WAIT_ROUNDS ) {
					return null;
				}

				return $this->r->set( array( Snapshots::DROP_WAITS => $waits ) )
					? StepResult::retry( 0, 'snapshot: waiting for another task on the server to end' )
					: StepResult::retry( 0, 'snapshot: the wait could not be counted' );
			}

			return $this->waiting( 'snapshot: waiting for another task on the server to end', $this->task_budget( '' ) );
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

		if ( null === $avail || $avail < Policy::reserve_gb() * 1073741824 ) {
			$text = sprintf( 'Snapshot refused: the storage %s has less than %d GB free', $storage, Policy::reserve_gb() );
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
		$meta = array( Snapshots::OP => $op ) + $more;

		if ( null !== $list ) {
			$meta[ Snapshots::LIST ] = array_values( $list );
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
		$op = $this->r->get( Snapshots::OP );

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

		foreach ( Policy::entries( $this->r ) as $entry ) {
			if ( $entry['id'] !== $id ) {
				$out[] = $entry;
			} elseif ( null !== $patch ) {
				$out[] = array_merge( $entry, $patch );
			}
		}

		return $out;
	}

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

		if ( null === $op || ! Policy::stale( $op ) ) {
			return null;
		}

		$entry = Policy::entry( $this->r, $op['id'] ?? null );

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
		$op = (array) $this->r->get( Snapshots::OP );

		if ( 'take' !== ( $op['op'] ?? '' ) || null === Policy::entry( $this->r, $op['id'] ?? null ) ) {
			$started = $this->take_begin( $record, $v, $args );

			if ( $started instanceof StepResult ) {
				return $started;
			}

			$op = $started;
		}

		$entry = Policy::entry( $this->r, $op['id'] );

		if ( null === $entry ) {
			return StepResult::done( array( Snapshots::OP => null ), 'Snapshot: the entry is gone' );
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
		if ( Policy::used( $this->r ) >= Policy::slots( $this->r ) ) {
			return StepResult::done( array(), 'Snapshot refused: no slot is free' );
		}

		$quiet = $this->quiet( $v, 'snapshot' );

		if ( null !== $quiet ) {
			return $quiet;
		}

		$covers = Policy::disks( (array) $v['config'] );

		foreach ( $covers as $volid ) {
			if ( 'qcow2' !== Policy::format_of( $volid ) ) {
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

		// The agent may have come up after the build: ask, so the snapshot is marked as Proxmox really takes it.
		if ( 'running' === $v['status'] && ! Actions::agent_ok( $this->r ) && Provision::agent_enabled( (string) ( $v['config']['agent'] ?? '' ) ) && ! is_wp_error( $this->client->request( 'POST', self::base( $record, $v ) . '/agent/ping' ) ) ) {
			$this->r->set( array( 'agent' => 'ok', 'agent_checked_at' => null ) );
		}

		$id    = bin2hex( random_bytes( 4 ) );
		$now   = time();
		$entry = array(
			'id'         => $id,
			'snap'       => 'bs' . bin2hex( random_bytes( 5 ) ),
			'label'      => Policy::label_from( $args ),
			'taken_at'   => $now,
			'expires_at' => $now + Policy::days_for( $this->r ) * DAY_IN_SECONDS,
			'covers'     => array_values( $covers ),
			'consistent' => 'running' === $v['status'] && Actions::agent_ok( $this->r ),
			'paid'       => Policy::paid_slots( $this->r ) > 0,
			'state'      => 'creating',
		);
		$op    = array(
			'op'    => 'take',
			'id'    => $id,
			'phase' => 'creating',
			'at'    => $now,
		);

		if ( ! $this->save( array_merge( Policy::entries( $this->r ), array( $entry ) ), $op ) ) {
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

		return StepResult::fail( $text, array( Snapshots::OP => null ) );
	}

	/**
	 * Deletes one snapshot: marked `deleting`, deleted, and dropped from the list once Proxmox no longer lists it
	 * (also when it was gone already).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param string               $id     Snapshot id.
	 */
	private function delete( array $record, array $v, string $id ): StepResult {
		$entry = Policy::entry( $this->r, $id );

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
		$marker = $this->r->get( Snapshots::ROLLBACK );
		$marker = is_array( $marker ) && ( $marker['id'] ?? '' ) === $id ? $marker : array();
		$phase  = (string) ( $marker['phase'] ?? '' );
		$entry  = Policy::entry( $this->r, $id );

		if ( '' === $phase ) {
			if ( null === $entry || 'ok' !== ( $entry['state'] ?? 'ok' ) ) {
				return StepResult::done( array( Snapshots::ROLLBACK => null ), 'Rollback refused: that snapshot is not available' );
			}

			if ( null !== Actions::disk_block( $this->r, 'rollback' ) ) {
				return StepResult::done( array( Snapshots::ROLLBACK => null ), 'Rollback refused: an extra disk is detached or needs attention; the server was not touched' );
			}

			$remote = $this->remote( $record, $v );

			if ( $remote instanceof StepResult ) {
				return $remote;
			}

			if ( ! isset( $remote[ $entry['snap'] ] ) ) {
				$this->save( $this->with( $id, null ), $this->open_op() );

				return StepResult::done( array( Snapshots::ROLLBACK => null ), 'Rollback refused: the snapshot is not on the server any more' );
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

			if ( ! $this->r->set( array( Snapshots::ROLLBACK => $marker ) ) ) {
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
				return $this->still_running( 'rollback: the server did not switch off' );
			}

			$marker['phase'] = 'rolling';

			if ( ! $this->r->set( array( Snapshots::ROLLBACK => $marker ) ) ) {
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

			if ( ! $this->r->set( array( Snapshots::ROLLBACK => $marker ) ) ) {
				return StepResult::retry( 0, 'rollback: progress could not be stored' );
			}

			$phase = 'rolled';
		}

		$planned = $this->reapply_plan( $record, 'rollback', ! empty( $marker['was_running'] ) );

		if ( null !== $planned ) {
			return $planned;
		}

		return StepResult::done( array( Snapshots::ROLLBACK => null ), 'Server rolled back to the snapshot "' . $this->scrub( (string) $entry['label'], 60 ) . '" of ' . gmdate( 'Y-m-d H:i', (int) $entry['taken_at'] ) . ' UTC' );
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

		return StepResult::fail( 'Rollback failed (' . $this->scrub( $exit, 160 ) . '); the server was left as it was and may need a manual check', array( Snapshots::ROLLBACK => null ) );
	}

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

		foreach ( Policy::entries( $this->r ) as $entry ) {
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
		foreach ( Policy::entries( $this->r ) as $entry ) {
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

		$op     = (array) $this->r->get( Snapshots::OP );
		$kept   = array();
		$known  = array();
		$change = false;

		foreach ( Policy::entries( $this->r ) as $entry ) {
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
			if ( 1 === preg_match( '/^bs[a-f0-9]{10}$/D', (string) $name ) && ! isset( $known[ $name ] ) ) {
				Alerts::admin( $this->r, 'The server has a snapshot named ' . $name . ' that is not in the plugin\'s list. It was left alone.' );
			}
		}

		return StepResult::done();
	}
}
