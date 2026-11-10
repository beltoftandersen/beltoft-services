<?php
/**
 * Backups, restores, undo and the getting-started checklist of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
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
	 * Back up now.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function backup_now() {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : ( new HostingBackups( $this->r ) )->now();
	}

	/**
	 * Starts a restore.
	 *
	 * @param string $scope account, files or database.
	 * @param string $volid Backup.
	 * @param string $slug  Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore( string $scope, string $volid, string $slug = '' ) {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : ( new HostingRestore( $this->r ) )->start( $scope, $volid, $slug );
	}

	/**
	 * Goes back to the safety point.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo() {
		$ready = $this->ready();

		return is_wp_error( $ready ) ? $ready : ( new HostingRestore( $this->r ) )->undo();
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
