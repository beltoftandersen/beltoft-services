<?php
/**
 * The customer deletes one of their own backups of a server or a database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Backup;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Vm\ActionsRun;
use BeltoftServices\Proxmox\Pve\DeletesVolumes;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * action `backup_delete`, built by Actions::run() after the VM is verified as ours, under the resource lock. The request side
 * (Actions::check) has already refused a restore or a backup in progress, a linked server and a volume id of the wrong shape;
 * this class proves the volume against the live listing (Backups::list), deletes exactly that volume
 * (`DELETE /nodes/{node}/storage/{storage}/content/{volid}`, the call Backups::purge already makes), and judges the result by
 * reading the listing again, never by the error text. The stored `backup_list` is rewritten only after the volume is gone.
 * Idempotent: a volume that is not listed counts as deleted. No resumable state of its own: a task still running is kept in
 * the resource's `task` meta and polled by Actions::run() first.
 */
final class BackupDelete {

	use Steps;
	use Prepares;
	use ActionsRun;
	use DeletesVolumes;

	/**
	 * Deletes one backup.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM (Steps::verified).
	 * @param string               $volid  Volume id (shape already checked by the request).
	 */
	public function delete( array $record, array $v, string $volid ): StepResult {
		$backups = new Backups( $this->r );
		$list    = $backups->list();

		// A VM that cannot be proved ours is never touched.
		if ( is_wp_error( $list ) && 'absent' === $list->get_error_code() ) {
			return StepResult::done( array(), 'Backup delete: the machine is not listed, nothing was deleted' );
		}

		if ( is_wp_error( $list ) ) {
			return StepResult::retry( 60, 'backup delete: ' . $this->text( $list ) );
		}

		$item = self::find( $list, $volid );

		if ( null === $item ) {
			$backups->remember( $list );

			return StepResult::done( array( Jobs::TEXT => __( 'The backup was deleted.', 'beltoft-services' ) ), 'Backup delete: it was already gone' );
		}

		$storage = (string) $this->cluster['backup_storage'];
		$path    = '/nodes/' . rawurlencode( (string) $v['node'] ) . '/storage/' . rawurlencode( $storage ) . '/content/' . rawurlencode( $volid );
		$sent    = $this->send_volume_delete( $path, 'backup delete', 'backup_delete' );

		// Paused, still running or not stored: that is this run's answer (the next run polls the task and reads the listing again).
		if ( $sent instanceof StepResult ) {
			return $sent;
		}

		return $this->judge( $backups, $volid, $item, $sent );
	}

	/**
	 * The item of a listing with this volume id.
	 *
	 * @param array<int, array{volid: string, ctime: int, size: int}> $list  Listing.
	 * @param string                                                   $volid Volume id.
	 * @return array{volid: string, ctime: int, size: int}|null
	 */
	private static function find( array $list, string $volid ): ?array {
		foreach ( $list as $item ) {
			if ( $item['volid'] === $volid ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Reads the listing again and decides: the volume is gone (success, whatever the task said), or it is still there (the node
	 * refused: expected failure for the customer, no mail to staff), or the node could not be asked (retry).
	 *
	 * @param Backups                                         $backups Backups of the server.
	 * @param string                                          $volid   Volume id.
	 * @param array{volid: string, ctime: int, size: int}     $item    The listed backup.
	 * @param true|\WP_Error                                  $sent    What the delete returned.
	 */
	private function judge( Backups $backups, string $volid, array $item, $sent ): StepResult {
		// A transport trouble or a node that is busy is tried again; a refusal (4xx, or the 500 Proxmox uses for "no") is judged by the listing.
		if ( is_wp_error( $sent ) && $this->volume_delete_transient( $sent ) ) {
			return StepResult::retry( 60, 'backup delete: ' . $this->text( $sent ) );
		}

		$list = $backups->list();

		if ( is_wp_error( $list ) ) {
			return StepResult::retry( 60, 'backup delete: ' . $this->text( $list ) );
		}

		if ( null !== self::find( $list, $volid ) ) {
			$why = is_wp_error( $sent ) ? $this->text( $sent ) : 'the volume is still listed after the delete';

			return StepResult::fail( 'backup delete: ' . $why, array( Jobs::TEXT => __( 'That backup could not be deleted. It may be protected. Contact support.', 'beltoft-services' ), Jobs::EXPECTED => true ) );
		}

		if ( ! $backups->remember( $list ) ) {
			return StepResult::retry( 60, 'backup delete: the new list could not be stored' );
		}

		return StepResult::done(
			array( Jobs::TEXT => __( 'The backup was deleted.', 'beltoft-services' ) ),
			'Backup of ' . gmdate( 'Y-m-d H:i', (int) $item['ctime'] ) . ' UTC deleted at the customer\'s request'
		);
	}
}
