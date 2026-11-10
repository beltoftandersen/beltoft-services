<?php
/**
 * The restore of a Proxmox VM from a backup.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Snapshot\Snapshots;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The restore from a backup, with the steps it shares with a snapshot rollback (RestoreSteps). Built by Actions::run() after the VM is verified, and run under the resource lock.
 */
final class Restore {

	use Steps;
	use Prepares;
	use RestoreSteps;

	/**
	 * Cluster settings (token secret decrypted), as Actions prepared them.
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r     Resource.
	 * @param Actions  $owner The Actions run that prepared the client and polled the stored task.
	 */
	public function __construct( Resource $r, Actions $owner ) {
		$this->r = $r;
		$state = $owner->state();

		$this->client    = $state['client'];
		$this->cluster   = $state['cluster'];
		$this->secrets   = $state['secrets'];
		$this->wait      = $state['wait'];
		$this->finished  = $state['finished'];
	}

	/**
	 * Restores the whole server to one backup: off, restored over the same VMID (`force`), the
	 * tag checked in the restored config, the plan re-applied (the backup may predate an upgrade:
	 * cores, memory and a larger disk come back, a disk is never shrunk), and the VM, still off, is
	 * started by that plan change (meta was_running), once. Every round
	 * starts from the remote state and the marker in meta `restore` ({volid, phase, ctime}), so
	 * a retry never restores twice. The customer is told that the password and the SSH key are
	 * the backup's. A failed restore may have destroyed the VM: it is left as it is and the
	 * admin is alerted.
	 *
	 * @internal Public only so Actions can call it; not an API.
	 * @param array<string, mixed> $record VM record.
	 * @param string               $volid  Backup volume id (shape already checked).
	 */
	public function restore( array $record, string $volid ): StepResult {
		$state = $this->r->get( 'restore' );
		$state = is_array( $state ) && ( $state['volid'] ?? '' ) === $volid ? $state : array();
		$phase = (string) ( $state['phase'] ?? '' );

		// The restore task of an earlier round has ended: that decides, not another restore.
		if ( '' === $phase && $this->finished && 'restore' === $this->finished['phase'] ) {
			if ( ! $this->finished['ok'] && ! self::accepted_exit( (string) $this->finished['exit'] ) ) {
				return $this->restore_failed( (string) $this->finished['exit'] );
			}

			// The backup's time was not kept while the task ran: read it from the listing again.
			$ctime = 0;
			$list  = ( new Backups( $this->r ) )->list();

			foreach ( is_array( $list ) ? $list : array() as $backup ) {
				if ( $backup['volid'] === $volid ) {
					$ctime = (int) $backup['ctime'];
				}
			}

			$state = array(
				'volid' => $volid,
				'ctime' => $ctime,
			);
			$phase = 'task';
		}

		if ( '' === $phase && null !== Actions::disk_block( $this->r ) ) {
			// Checked again here: a disk paused after the request must stop the restore before the VM is touched.
			return StepResult::fail( 'Restore refused: an extra disk is detached or needs attention; the server was not touched', array( 'restore' => null ) );
		}

		if ( '' === $phase ) {
			$ran = $this->restore_run( $record, $volid );

			if ( $ran instanceof StepResult ) {
				return $ran;
			}

			$state = array(
				'volid' => $volid,
				'ctime' => $ran,
			);
			$phase = 'task';
		}

		if ( 'task' === $phase ) {
			$state['phase'] = 'restored';

			if ( ! $this->r->set( array( 'restore' => $state ) ) ) {
				return StepResult::retry( 0, 'restore: progress could not be stored' );
			}

			$phase = 'restored';
		}

		if ( 'restored' === $phase ) {
			// The restore recreated the VM: its snapshots are gone (D9). Nothing is asked of Proxmox when none were listed.
			$forgot = Snapshots::tracked( $this->r ) ? ( new Snapshots( $this->r ) )->forget_all() : null;

			if ( null !== $forgot && ! $forgot->is_done() ) {
				return $forgot;
			}

			$planned = $this->restore_plan( $record );

			if ( null !== $planned ) {
				return $planned;
			}

			$state['phase'] = 'planned';

			if ( ! $this->r->set( array( 'restore' => $state ) ) ) {
				return StepResult::retry( 0, 'restore: progress could not be stored' );
			}
		}

		return StepResult::done(
			array( 'restore' => null ),
			(int) ( $state['ctime'] ?? 0 ) > 0 ? 'Server restored from the backup of ' . gmdate( 'Y-m-d H:i', (int) $state['ctime'] ) . ' UTC' : 'Server restored from a backup'
		);
	}

	/**
	 * The first half: the backup is listed for this server, the VM is off, the restore task ran.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param string               $volid  Backup volume id.
	 * @return int|StepResult The backup's time, or the round's answer.
	 */
	private function restore_run( array $record, string $volid ) {
		$list = ( new Backups( $this->r ) )->list();

		if ( is_wp_error( $list ) ) {
			return StepResult::retry( 0, 'restore: ' . $this->scrub( $list->get_error_message(), 200 ) );
		}

		$ctime = null;

		foreach ( $list as $backup ) {
			if ( $backup['volid'] === $volid ) {
				$ctime = (int) $backup['ctime'];
			}
		}

		if ( null === $ctime ) {
			return StepResult::done( array( 'restore' => null ), 'Restore refused: that backup is not available for this server' );
		}

		// A backup in progress holds the VM (lock=backup): wait for it before anything is switched off.
		$running = ( new Backups( $this->r ) )->collect_running();

		if ( null !== $running ) {
			return StepResult::retry( 0, 'restore: ' . $running->error() );
		}

		$locked = $this->verified( $record, 'restore' );

		if ( $locked instanceof StepResult ) {
			return $locked;
		}

		if ( '' !== (string) $locked['lock'] ) {
			return StepResult::retry( 0, 'restore: the server is locked (' . sanitize_key( (string) $locked['lock'] ) . ')' );
		}

		$off = ( new Operations( $this->r ) )->pause();

		if ( ! $off->is_done() ) {
			return $off;
		}

		$v = $this->verified( $record, 'restore' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' === $v['status'] ) {
			return $this->still_running( 'restore: the server did not switch off' );
		}

		$done = $this->run_task(
			'act',
			'POST',
			'/nodes/' . rawurlencode( $v['node'] ) . '/qemu',
			array(
				'vmid'    => $record['vmid'],
				'archive' => $volid,
				'force'   => 1,
				'storage' => (string) $this->cluster['storage'],
			),
			'restore'
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		return true === $done || self::accepted_exit( $done['failed'] ) ? $ctime : $this->restore_failed( $done['failed'] );
	}

	/**
	 * Did the task end with warnings only?
	 *
	 * @param string $exit Exit status.
	 */
	private static function accepted_exit( string $exit ): bool {
		return 0 === strpos( $exit, 'WARNINGS' );
	}

	/**
	 * A restore that ended badly.
	 *
	 * @param string $exit Exit status (admin text).
	 */
	private function restore_failed( string $exit ): StepResult {
		$this->tell_customer();

		return StepResult::fail( 'Restore failed (' . $this->scrub( $exit, 160 ) . '); the server was left switched off and may need a manual check', array( 'restore' => null ) );
	}

	/**
	 * The second half: the tag is in the restored config, the disk size is recorded, and the plan
	 * is queued again (RestoreSteps::reapply_plan). Null: done.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function restore_plan( array $record ): ?StepResult {
		$pool = $this->restore_pool( $record );

		if ( null !== $pool ) {
			return $pool;
		}

		return $this->reapply_plan( $record, 'restore', true );
	}

	/**
	 * A forced restore recreates the VM, and whether it stays in the cluster's pool is not known
	 * for sure. The token sees only the pool, so a VM that left it would vanish for the plugin: the
	 * membership is read and the VM put back when it is missing. Null: it is in the pool (or the
	 * cluster has none).
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function restore_pool( array $record ): ?StepResult {
		$pool = (string) $this->cluster['pool'];

		if ( '' === $pool ) {
			return null;
		}

		$by_id = 'pools_id' === $this->cluster['pool_api'];
		$read  = $this->client->request( 'GET', $by_id ? '/pools/' . rawurlencode( $pool ) : '/pools', $by_id ? array() : array( 'poolid' => $pool ) );

		if ( is_wp_error( $read ) ) {
			return $this->classify( $read, 'restore' );
		}

		$members = array();

		// PVE answers the pool itself (pools_id) or a list of pools (pools).
		foreach ( is_array( $read ) ? ( isset( $read['members'] ) ? array( $read ) : $read ) : array() as $one ) {
			if ( is_array( $one ) && ( $by_id || ( $one['poolid'] ?? $pool ) === $pool ) ) {
				foreach ( (array) ( $one['members'] ?? array() ) as $member ) {
					$members[] = is_array( $member ) ? (int) ( $member['vmid'] ?? 0 ) : (int) $member;
				}
			}
		}

		if ( in_array( (int) $record['vmid'], $members, true ) ) {
			return null;
		}

		$put = $this->client->request(
			'PUT',
			$by_id ? '/pools/' . rawurlencode( $pool ) : '/pools',
			$by_id ? array( 'vms' => (string) $record['vmid'] ) : array(
				'poolid' => $pool,
				'vms'    => (string) $record['vmid'],
			)
		);

		if ( is_wp_error( $put ) ) {
			$text = 'Restore: the server could not be put back into the pool "' . $pool . '": ' . $this->text( $put );
			Alerts::admin( $this->r, $text );
			$this->tell_customer();

			return StepResult::fail( $text, array( 'restore' => null ) );
		}

		return null;
	}

	/**
	 * The VM's path (RestoreSteps asks for it by this name).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private static function base( array $record, array $v ): string {
		return Actions::base( $record, $v );
	}
}
