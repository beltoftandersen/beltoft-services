<?php
/**
 * The Proxmox features' hooks into the core's extension points.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

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
		add_filter( 'beltoft_services_default_retention', array( ProxmoxDriver::class, 'default_retention' ), 10, 2 );
		add_filter( 'beltoft_services_default_retention', array( DiskDriver::class, 'default_retention' ), 10, 2 );
		add_filter( 'beltoft_services_default_retention', array( AppLicenceDriver::class, 'default_retention' ), 10, 2 );
		add_action( 'beltoft_services_state_changed', array( Access::class, 'on_state_changed' ), 10, 3 );
		add_action( 'beltoft_services_state_changed', array( Operations::class, 'on_state_changed' ), 10, 3 );
		add_filter( 'beltoft_services_park_action', array( __CLASS__, 'park_action' ), 10, 3 );
		add_filter( 'beltoft_services_failure_alert_lines', array( DiskDriver::class, 'failure_alert_lines' ), 10, 3 );
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
