<?php
/**
 * Daily wipe of stored VM passwords whose reveal window has passed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Jobs;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\SshKeyField;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Daily wipe of stored VM passwords whose reveal window has passed.
 */
final class PasswordJobs {

	/**
	 * Wipes the stored VM passwords whose reveal window (Access::REVEAL_DAYS) has passed, read or
	 * not. Runs once a day, from the sweep. A password of a reset that is not on the VM yet is left alone.
	 */
	public static function purge_passwords(): void {
		if ( false !== get_transient( ProxmoxJobs::PURGE_RAN ) || Settings::get( 'pause_all' ) ) {
			return;
		}

		set_transient( ProxmoxJobs::PURGE_RAN, 1, DAY_IN_SECONDS );

		$driver = Registry::get( SshKeyField::DRIVER );

		if ( ! $driver ) {
			return;
		}

		foreach ( Resource::live_ids( $driver->kind() ) as $id ) {
			$resource = Resource::find( $id );

			if ( ! $resource || SshKeyField::DRIVER !== $resource->get( 'driver' ) ) {
				continue;
			}

			$stored = $resource->get( Access::META_REVEAL );

			if ( ! is_string( $stored ) || '' === $stored || (int) $resource->get( Access::META_UNTIL ) >= time() ) {
				continue;
			}

			$resource->locked(
				static function () use ( $resource ): bool {
					$stored = $resource->get( Access::META_REVEAL );

					// Read again under the lock: a reveal or a reset may have changed it.
					if ( ! is_string( $stored ) || '' === $stored || (int) $resource->get( Access::META_UNTIL ) >= time() || $resource->get( Access::META_RESET ) ) {
						return false;
					}

					return Access::purge( $resource );
				}
			);
		}
	}
}
