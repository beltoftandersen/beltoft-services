<?php
/**
 * Backups, restores, undo and the getting-started checklist of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Backups, restores, undo and the getting-started checklist of a hosting account. Reached through the HostingActions facade; results are arrays and a refusal is a WP_Error.
 */
final class CareActions {

	use Base;

	/**
	 * The backups the account has (the stored listing), the safety point and the last restore.
	 *
	 * @return array<string, mixed>
	 */
	public function backups(): array {
		$safety = HostingRestore::safety( Resource::find( $this->r->id() ) ?? $this->r );

		return array(
			'enabled' => HostingBackups::enabled( $this->r ),
			'backups' => HostingBackups::stored( $this->r ),
			'running' => ( new HostingBackups( $this->r ) )->active() || HostingRestore::active( $this->r ),
			'safety'  => null === $safety ? null : array( 'at' => $safety['at'], 'expires' => $safety['expires'] ),
			'last'    => $this->r->get( 'restore_last' ),
		);
	}

	/**
	 * Back up now. A change of the shared controller (the action `backup_now`): the request checks the rules and stores the round, the
	 * job drives it (HostingBackups::step()) as far as the node's tasks take it.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function backup_now() {
		return $this->queued_only() ?? $this->locked( fn() => $this->backup_now_locked() );
	}

	/**
	 * backup_now with the site lock held.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function backup_now_locked() {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$round = new HostingBackups( Resource::find( $this->r->id() ) ?? $this->r );

		if ( $this->in_job() ) {
			// One run of the round: a poll or a retry ends this run only, a done or failed result ends the change.
			throw new TaskPending( $round->active() ? $round->signal( $round->step() ) : StepResult::done( array(), 'Backup finished' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		$checked = $round->prepare_manual();

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$r = Resource::find( $this->r->id() ) ?? $this->r;
		$this->defer_undo( static fn() => ( new HostingBackups( $r ) )->undo_manual() );
		$this->defer_with( $checked );
		$this->defer_now( 'backup' );

		return $checked;
	}

	/**
	 * Deletes one backup of the account's list. A change of the shared controller (the action `backup_delete`): the request checks the
	 * rules, the job deletes exactly that volume (HostingBackups::delete_one()). One backup at a time, never the restore safety copy.
	 *
	 * @param string $volid Backup.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_backup( string $volid ) {
		return $this->queued_only() ?? $this->locked( fn() => $this->delete_backup_locked( $volid ) );
	}

	/**
	 * delete_backup with the site lock held.
	 *
	 * @param string $volid Backup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function delete_backup_locked( string $volid ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$round = new HostingBackups( Resource::find( $this->r->id() ) ?? $this->r );

		if ( $this->in_job() ) {
			throw new TaskPending( $round->delete_one( $volid ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		$checked = $round->delete_check( $volid );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// The floor counts only a request that could be queued. A delete leaves nothing behind to put right if the queueing fails (no round is stored).
		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:hosting-backup-delete:' . $this->r->id(), 10, HOUR_IN_SECONDS ) ) {
			return $this->error( 'rate_limited', __( 'Too many requests. Please wait a while and try again.', 'beltoft-services' ) );
		}

		$this->defer_with( array( 'started' => true ) );
		$this->defer_now( 'backup_delete' );
	}

	/**
	 * Starts a restore. A change of the shared controller (the action `restore_backup`): the request checks it and stores the round,
	 * the job drives it (HostingRestore::step()), waiting for the node's tasks with the shared poll budget.
	 *
	 * @param string $scope account, files or database.
	 * @param string $volid Backup.
	 * @param string $slug  Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore( string $scope, string $volid, string $slug = '' ) {
		return $this->queued_only() ?? $this->locked( fn() => $this->restore_locked( 'restore', $scope, $volid, $slug ) );
	}

	/**
	 * Goes back to the safety point (the action `restore_undo`).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo() {
		return $this->queued_only() ?? $this->locked( fn() => $this->restore_locked( 'undo', '', '', '' ) );
	}

	/**
	 * A restore or an undo with the site lock held.
	 *
	 * @param string $what  restore or undo.
	 * @param string $scope account, files or database.
	 * @param string $volid Backup.
	 * @param string $slug  Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function restore_locked( string $what, string $scope, string $volid, string $slug ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$round = new HostingRestore( Resource::find( $this->r->id() ) ?? $this->r );

		if ( $this->in_job() ) {
			throw new TaskPending( HostingRestore::active( Resource::find( $this->r->id() ) ?? $this->r ) ? $round->step() : StepResult::done( array(), 'Restore finished' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		$checked = 'undo' === $what ? $round->prepare_undo() : $round->prepare( $scope, $volid, $slug );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$r = Resource::find( $this->r->id() ) ?? $this->r;
		$this->defer_undo( static fn() => ( new HostingRestore( $r ) )->undo_prepare() );
		$this->defer_with( $checked, 'undo' === $what ? array() : array( $scope, $volid, $slug ) );
		$this->defer_now( 'restore' );

		return $checked;
	}

	/**
	 * Ticks a getting-started item the customer does by hand, or hides the list.
	 *
	 * @param string $item build, backups, or '' with $dismiss.
	 * @param bool   $dismiss Hide the list.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function checklist( string $item, bool $dismiss = false ) {
		$fresh = Resource::find( $this->r->id() ) ?? $this->r;

		if ( $dismiss ) {
			return $fresh->set( array( 'checklist_dismissed' => 1 ) ) ? array( 'dismissed' => true ) : $this->error( 'failed', __( 'That could not be saved. Try again.', 'beltoft-services' ) );
		}

		if ( ! in_array( $item, HostingAccount::MANUAL_ITEMS, true ) ) {
			return $this->error( 'invalid', __( 'Unknown item.', 'beltoft-services' ) );
		}

		$done = array_values( array_unique( array_merge( array_map( 'strval', (array) $fresh->get( 'checklist' ) ), array( $item ) ) ) );

		return $fresh->set( array( 'checklist' => $done ) ) ? array( 'done' => $item ) : $this->error( 'failed', __( 'That could not be saved. Try again.', 'beltoft-services' ) );
	}
}
