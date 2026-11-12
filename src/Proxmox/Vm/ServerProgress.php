<?php
/** The steps and titles of a server's and a disk's work, for the progress. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * What Progress shows while a server is reinstalled, restored or rolled back to a snapshot, and how a
 * server and an extra disk word an update, a pause and a build. Only fixed texts.
 */
final class ServerProgress {

	/**
	 * Hooks the server's words and steps into Progress.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_progress_title', array( __CLASS__, 'title' ), 10, 3 );
		add_filter( 'beltoft_services_progress_work', array( __CLASS__, 'work' ), 10, 4 );
	}

	/**
	 * The title of an update, a pause or a build of a server or a disk.
	 *
	 * @param mixed  $title  The generic title.
	 * @param string $kind   update, pause or build (any other kind of work).
	 * @param string $driver Driver id.
	 * @return mixed
	 */
	public static function title( $title, $kind, $driver ) {
		if ( DiskDriver::ID === $driver ) {
			if ( 'update' === $kind ) {
				return __( 'Making your disk larger', 'beltoft-services' );
			}

			return 'pause' === $kind ? __( 'Detaching your disk', 'beltoft-services' ) : __( 'Adding your disk', 'beltoft-services' );
		}

		if ( ProxmoxDriver::ID === $driver && ! in_array( $kind, array( 'update', 'pause' ), true ) ) {
			return __( 'Setting up your server', 'beltoft-services' );
		}

		return $title;
	}

	/**
	 * The steps of a reinstall, a restore and a rollback of a server, with the ones done.
	 *
	 * @param mixed    $work   Steps found so far.
	 * @param Resource $r      Resource.
	 * @param string   $kind   Kind.
	 * @param string   $driver Driver id.
	 * @return mixed
	 */
	public static function work( $work, $r, $kind, $driver ) {
		if ( ProxmoxDriver::ID !== $driver || ! $r instanceof Resource ) {
			return $work;
		}

		if ( 'reinstall' === $kind ) {
			$labels = self::steps( 'reinstall' );

			return array( 'labels' => $labels, 'done' => array_merge( array_intersect( (array) $r->get( 'steps' ), array_keys( $labels ) ), $r->get( 'wipe' ) ? array() : array( 'wipe' ) ) );
		}

		if ( 'restore' === $kind ) {
			$labels = self::steps( 'restore' );
			$state  = $r->get( 'restore' );
			$phase  = is_array( $state ) ? (string) ( $state['phase'] ?? '' ) : '';
			$order  = array( 'restored' => 1, 'planned' => 2 );

			// The phase of the restore also stands for the phase of the work (never queued or failed), as it always did.
			return array( 'labels' => $labels, 'done' => array_slice( array_keys( $labels ), 0, $order[ $phase ] ?? 0 ), 'phase' => $phase );
		}

		if ( 'rollback' === $kind ) {
			$labels = self::steps( 'rollback' );
			$state  = $r->get( 'snapshot_rollback' );
			// No marker: the rollback itself is over and only its plan change is left.
			$mark = is_array( $state ) ? (string) ( $state['phase'] ?? '' ) : 'rolled';

			return array( 'labels' => $labels, 'done' => array_slice( array_keys( $labels ), 0, 'rolled' === $mark ? 1 : 0 ) );
		}

		return $work;
	}

	/**
	 * The steps of a server reinstall and restore.
	 *
	 * @param string $kind reinstall, restore or rollback.
	 * @return array<string, string>
	 */
	private static function steps( string $kind ): array {
		if ( 'rollback' === $kind ) {
			return array(
				'rollback' => __( 'Going back to the snapshot', 'beltoft-services' ),
				'plan'     => __( 'Applying your plan', 'beltoft-services' ),
				'start'    => __( 'Starting the server', 'beltoft-services' ),
			);
		}

		if ( 'restore' === $kind ) {
			return array(
				'restore' => __( 'Restoring the backup', 'beltoft-services' ),
				'plan'    => __( 'Applying your plan', 'beltoft-services' ),
				'start'   => __( 'Starting the server', 'beltoft-services' ),
			);
		}

		return array(
			'wipe'      => __( 'Erasing the old system', 'beltoft-services' ),
			'clone'     => __( 'Installing the system', 'beltoft-services' ),
			'configure' => __( 'Applying your settings', 'beltoft-services' ),
			'start'     => __( 'Starting the server', 'beltoft-services' ),
			'ready'     => __( 'Final checks', 'beltoft-services' ),
		);
	}
}
