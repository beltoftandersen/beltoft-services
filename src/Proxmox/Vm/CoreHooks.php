<?php
/**
 * The Proxmox features' hooks into the core's extension points.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Proxmox\AppLicenceDriver;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Disk\DiskGuide;
use BeltoftServices\Proxmox\PrivateAttach;
use BeltoftServices\Proxmox\SnapshotDriver;
use BeltoftServices\Proxmox\SnapshotMeta;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Core (Jobs, Alerts, Product, Reconciler) knows nothing of Proxmox: it exposes filters and actions, and the
 * Proxmox drivers attach here: default retention, state_changed clean-up, parking of half-done network actions
 * and the paid-disk lines of the failure mail.
 */
final class CoreHooks {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 2 );
		add_filter( 'beltoft_services_default_retention', array( ProxmoxDriver::class, 'default_retention' ), 10, 2 );
		add_filter( 'beltoft_services_default_retention', array( DiskDriver::class, 'default_retention' ), 10, 2 );
		add_filter( 'beltoft_services_default_retention', array( AppLicenceDriver::class, 'default_retention' ), 10, 2 );
		add_action( 'beltoft_services_state_changed', array( Access::class, 'on_state_changed' ), 10, 3 );
		add_action( 'beltoft_services_state_changed', array( Operations::class, 'on_state_changed' ), 10, 3 );
		add_filter( 'beltoft_services_park_action', array( __CLASS__, 'park_action' ), 10, 3 );
		add_filter( 'beltoft_services_failure_alert_lines', array( DiskDriver::class, 'failure_alert_lines' ), 10, 3 );
		add_filter( 'beltoft_services_thankyou_row', array( __CLASS__, 'thankyou_row' ), 10, 3 );
	}

	/**
	 * The thank-you row: the server name and the card address of every service, and the keys of a snapshot pack and
	 * an extra disk (where to find them).
	 *
	 * @param mixed $row     The row so far.
	 * @param mixed $r       Resource.
	 * @param mixed $license Licence row.
	 * @return mixed
	 */
	public static function thankyou_row( $row, $r, $license ) {
		if ( ! is_array( $row ) || ! $r instanceof Resource || ! is_array( $license ) ) {
			return $row;
		}

		$row['name'] = ServerName::display( $r, $license );
		$row['url']  = DiskGuide::card_url( $r );

		if ( SnapshotDriver::ID === (string) $r->get( 'driver' ) ) {
			$row['url']      = DiskGuide::server_url( $r );
			$row['snapshot'] = array(
				'slots'      => SnapshotMeta::slots_of( $r ),
				'server_url' => $row['url'],
			);
		}

		if ( DiskDriver::ID === (string) $r->get( 'driver' ) ) {
			$row['disk'] = array(
				'server_url' => DiskGuide::server_url( $r ),
				'guide_url'  => DiskGuide::guide_url( $r ),
			);
		}

		return $row;
	}

	/**
	 * Adds the Proxmox drivers to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( ! is_array( $drivers ) ) {
			return $drivers;
		}

		if ( ! isset( $drivers[ ProxmoxDriver::ID ] ) ) {
			$drivers[ ProxmoxDriver::ID ] = new ProxmoxDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-db' ] ) ) {
			$drivers[ 'proxmox-db' ] = new DbDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-disk' ] ) ) {
			$drivers[ 'proxmox-disk' ] = new DiskDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-snapshots' ] ) ) {
			$drivers[ 'proxmox-snapshots' ] = new SnapshotDriver();
		}
		if ( ! isset( $drivers[ 'proxmox-app' ] ) ) {
			$drivers[ 'proxmox-app' ] = new AppLicenceDriver();
		}

		return $drivers;
	}

	/**
	 * A private network attach or detach whose work is half done waits instead of being dropped.
	 *
	 * @param mixed $parkable Earlier answer.
	 * @param mixed $action   Action name.
	 * @param mixed $r        Resource.
	 */
	public static function park_action( $parkable, $action, $r ): bool {
		if ( $parkable ) {
			return true;
		}

		return $r instanceof Resource && in_array( $action, PrivateAttach::ACTIONS, true ) && PrivateAttach::in_flight( $r );
	}
}
