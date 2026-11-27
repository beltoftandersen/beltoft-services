<?php
/** A database's backups come with the Managed add-on. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Managed;

use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the filter `beltoft_services_backup_days` for a database: backups only while the Managed add-on counts (ready, active
 * licence) or the database holds the grandfather meta `backups_included` (stamped by the owner on a database bought before the
 * add-on existed). A server is untouched: its backups stay the plan's.
 */
final class ManagedBackups {

	/** Meta of a database that keeps its backups without the add-on. */
	public const INCLUDED = 'backups_included';

	public static function register(): void {
		add_filter( 'beltoft_services_backup_days', array( __CLASS__, 'days' ), 10, 2 );
	}

	/**
	 * @param mixed $days Days of the plan.
	 * @param mixed $r    The resource.
	 * @return mixed
	 */
	public static function days( $days, $r ) {
		if ( ! $r instanceof Resource || \BeltoftServices\Proxmox\Db\DbDriver::ID !== (string) $r->get( 'driver' ) ) {
			return $days;
		}

		if ( ! ManagedMeta::is_managed( $r ) && true !== $r->get( self::INCLUDED ) ) {
			return 0;
		}

		return (int) $days > 0 ? (int) $days : Backups::DB_DEFAULT_DAYS;
	}
}
