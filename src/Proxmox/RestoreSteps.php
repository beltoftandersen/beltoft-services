<?php
/**
 * What a restore from a backup and a rollback to a snapshot have in common once the VM's disks are back.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Used by Actions (restore) and Snapshots (rollback). The using class has the Steps trait and a $cluster
 * property (the cluster settings), and is run under the resource lock.
 */
trait RestoreSteps {

	/**
	 * The extra disks are not part of the backup: a forced restore keeps their volumes as unusedN entries.
	 * Each one that was attached goes back to its own slot, while the server is still off. One that cannot
	 * (volume missing, slot taken) is failed with one admin alert and left alone; the restore goes on.
	 * A rollback needs the same for a disk bought after the snapshot. Null: done.
	 */
	private function restore_disks(): ?StepResult {
		foreach ( DiskMeta::for_server( $this->r ) as $disk ) {
			$result = ( new DiskSteps( $disk ) )->reattach_after_restore( $this->r );

			if ( $result->is_done() ) {
				continue;
			}

			if ( ! $result->is_failed() ) {
				return $result;
			}

			$disk->set( array( 'last_error' => $result->error() ) );
			$disk->transition( 'failed', 'Extra disk could not be attached again after the restore: ' . $result->error() );
			Alerts::admin( $disk, 'After the restore of the server the extra disk could not be attached again: ' . $result->error() . ' The volume was left alone.' );
		}

		return null;
	}

	/**
	 * A rollback gives every disk back the size it had when the snapshot was taken: each extra disk is grown to
	 * the size it was paid for (the boot disk is grown by the plan change that follows). A disk that cannot be
	 * grown is failed with one admin alert and left alone. Null: done.
	 */
	private function regrow_disks(): ?StepResult {
		foreach ( DiskMeta::for_server( $this->r ) as $disk ) {
			$result = ( new DiskSteps( $disk ) )->regrow_after_rollback();

			if ( $result->is_done() ) {
				continue;
			}

			if ( ! $result->is_failed() ) {
				return $result;
			}

			$disk->set( array( 'last_error' => $result->error() ) );
			$disk->transition( 'failed', 'Extra disk could not be grown again after the rollback: ' . $result->error() );
			Alerts::admin( $disk, 'After the rollback of the server the extra disk could not be grown to its size again: ' . $result->error() );
		}

		return null;
	}

	/**
	 * The second half of a restore or a rollback: the tag is in the restored config, the disk size is recorded,
	 * the extra disks are attached (and for a rollback grown) again, and the plan is queued again. The queued
	 * plan change applies cores, memory and the boot disk size to the stopped VM and starts it when `was_running`
	 * says so. Null: done.
	 *
	 * @param array<string, mixed> $record      VM record.
	 * @param string               $what        restore or rollback (names the meta marker and the texts).
	 * @param bool                 $was_running Start the VM afterwards.
	 */
	private function reapply_plan( array $record, string $what, bool $was_running ): ?StepResult {
		$marker = 'rollback' === $what ? Snapshots::ROLLBACK : 'restore';
		$v      = $this->verified( $record, $what );

		if ( $v instanceof StepResult ) {
			if ( $v->is_failed() ) {
				$this->tell_customer( $what );
			}

			return $v->is_failed() ? StepResult::fail( $v->error() . ( 'rollback' === $what ? '; the server could not be proved ours after the rollback' : '; the restored server could not be proved ours' ), array( $marker => null ) ) : $v;
		}

		$size = Provision::gigabytes( (string) ( $v['config'][ (string) $this->cluster['boot_disk'] ] ?? '' ) );

		if ( null === $size ) {
			return StepResult::fail( ucfirst( $what ) . ': the boot disk was not found on the ' . ( 'rollback' === $what ? 'server' : 'restored server' ), array( $marker => null ) );
		}

		// The queued plan change owns the one start (was_running): it applies the plan to the stopped VM and starts it, so a VM that has only just booted is never shut down again.
		$disks = $this->restore_disks();

		if ( null !== $disks ) {
			return $disks;
		}

		if ( 'rollback' === $what ) {
			$grown = $this->regrow_disks();

			if ( null !== $grown ) {
				return $grown;
			}

			/**
			 * The VM is stopped with the snapshot's configuration, its extra disks attached and grown again, and
			 * the plan change (cores, memory, boot disk, start) is not queued yet. This is where whatever else the
			 * snapshot's configuration lost has to be put back, for example a private network card that was added
			 * after the snapshot. It may run again on a retry, so it must be idempotent. Return a StepResult to
			 * retry or fail the rollback, null to go on.
			 *
			 * @param null|StepResult $result Null.
			 * @param \BeltoftServices\Resource $resource Server resource.
			 * @param array<string, mixed> $record VM record.
			 */
			$hooked = apply_filters( 'beltoft_services_snapshot_rolled_back', null, $this->r, $record );

			if ( $hooked instanceof StepResult ) {
				return $hooked;
			}
		}

		// A managed database keeps its private address and its plugin-owned firewall, whatever the backup held.
		if ( DbActions::is_db( $this->r ) ) {
			$db = $this->reapply_database( $record, $v );

			if ( null !== $db ) {
				return $db;
			}
		}

		// A restored or rolled back VM may come back without its firewall file: the dashboard's rules are written again from the stored ones.
		if ( Firewall::managed( $this->r ) && true !== $this->r->get( Firewall::LOCKED ) ) {
			$done = ( new Firewall( $this->r, $this->client, $this->cluster ) )->ensure( $record, $v );

			if ( $done instanceof StepResult ) {
				return $done;
			}
		}

		// The backup or snapshot carries the VM name it had then: the server's own name is written back, in one call.
		$named = $this->reapply_name( $record );

		if ( null !== $named ) {
			return $named;
		}

		// A database's plan disk is its data disk: the boot disk's size is not recorded as the plan's.
		$sized = DbActions::is_db( $this->r ) ? array() : array( 'disk_gb' => (int) ceil( $size ) );

		if ( ! $this->r->set( $sized + array( 'was_running' => $was_running ? true : null ) + ( 'rollback' === $what ? array( 'snapshot_rolled_at' => time() ) : array() ) ) ) {
			return StepResult::retry( 0, $what . ': progress could not be stored' );
		}

		$stored = $this->r->change_plan( (array) $this->r->get( 'plan' ), 'rollback' === $what ? 'Plan re-applied after rollback' : 'Plan re-applied after restore' );

		if ( true !== $stored ) {
			return StepResult::retry( 0, $what . ': the plan could not be queued again' );
		}

		Jobs::enqueue( $this->r->id() );

		return null;
	}

	/**
	 * A restored managed database: its private network card and address are the ones the service holds (a backup of
	 * the same server has them; a mismatch is written back and the step goes round again), and the plugin-owned
	 * firewall is written again (idempotent). Null: done.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private function reapply_database( array $record, array $v ): ?StepResult {
		$net = PrivateNets::for_resource( $this->r->id() );

		if ( null === $net ) {
			return StepResult::fail( 'Restore: the private network of this database is not recorded', array( 'restore' => null ) );
		}

		$wanted = PrivateNic::params( $net, $net['address'], (string) ( $this->cluster['private_bridge'] ?? '' ) );

		if ( is_wp_error( $wanted ) ) {
			return StepResult::fail( 'Restore: ' . $wanted->get_error_message(), array( 'restore' => null ) );
		}

		if ( ! PrivateNic::matches( (array) $v['config'], $wanted ) ) {
			$params = $wanted;

			if ( ! empty( $v['config']['digest'] ) ) {
				$params['digest'] = (string) $v['config']['digest'];
			}

			$put = $this->client->request( 'PUT', '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . (int) $record['vmid'] . '/config', $params );

			if ( is_wp_error( $put ) ) {
				return $this->classify( $put, 'restore' );
			}

			return StepResult::retry( 0, 'restore: the private network card was put back' );
		}

		$done = ( new Firewall( $this->r, $this->client, $this->cluster ) )->ensure_database( $record, $v );

		return $done instanceof StepResult ? $done : null;
	}

	/**
	 * After a restore or rollback: the VM gets the name the customer chose (or vm-<uuid>) again, when the archive
	 * or snapshot restored another one. The config is read again (the extra disks changed its digest). Idempotent.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function reapply_name( array $record ): ?StepResult {
		$v = $this->verified( $record, 'name' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$failed = $this->put_name( self::base( $record, $v ), Provision::vm_name( $this->r ), (array) $v['config'] );

		if ( null !== $failed ) {
			return $failed;
		}

		// The server is rebuilt from the archive and starts fresh: no hostname change is waiting for a reboot.
		if ( null !== $this->r->get( ServerName::PENDING ) && ! $this->r->set( array( ServerName::PENDING => null, ServerName::PENDING_AT => null ) ) ) {
			return StepResult::retry( 0, 'name: the notice could not be cleared' );
		}

		return null;
	}

	/**
	 * A restore or a rollback ended badly: the customer gets one short, fixed note (no Proxmox text). The
	 * server may be off and the admin has been alerted.
	 *
	 * @param string $what restore or rollback.
	 */
	private function tell_customer( string $what = 'restore' ): void {
		\BeltoftServices\Emails\Emails::send( 'beltoft_services_notice', $this->r, array( 'notice_kind' => 'rollback' === $what ? 'rollback_failed' : 'restore_failed' ) );
	}
}
