<?php
/** Ends every add-on of a server when the server is deleted, once for all kinds. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Addon;

use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class AddonCascade {
	/** Hooks. */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 10, 3 );
	}

	/**
	 * A deleted server ends its disks, snapshot packs and app licences: each is moved to deleted (silently; the kind's
	 * own follow-up says what the customer and the admin are told) and then closed.
	 *
	 * @param mixed  $resource Resource.
	 * @param string $old      Old state (unused).
	 * @param string $new      New state.
	 */
	public static function state_changed( $resource, $old, $new ): void {
		unset( $old );

		if ( ! $resource instanceof Resource || 'deleted' !== $new || ProxmoxDriver::ID !== $resource->get( 'driver' ) ) {
			return;
		}

		foreach ( Addon::all() as $a ) {
			foreach ( AddonLink::for_server( $a, $resource ) as $addon ) {
				if ( $a->skips_deleted && 'deleted' === $addon->get( 'state' ) ) {
					continue;
				}

				if ( $addon->transition( 'deleted', $a->parent_deleted_event ) ) {
					( $a->after_parent_deleted )( $addon );
				}
			}
		}
	}
}
