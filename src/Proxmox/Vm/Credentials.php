<?php
/**
 * Stored first-login credentials of databases and apps: wiped when nobody revealed them in time, and when the service is deleted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Db\DbJob;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The VM password has its own purge (PasswordJobs::purge_passwords, Access::on_state_changed); this does the same for the
 * database login (db_reveal) and the app login (app_reveal), which are encrypted but would otherwise stay for ever.
 */
final class Credentials {

	public const RAN = 'beltoft_services_credentials_purge_ran';

	/**
	 * Resource meta that holds a stored login: secret key => its expiry key.
	 */
	private const FIELDS = array(
		DbJob::REVEAL => DbJob::UNTIL,
		'app_reveal'  => 'app_reveal_until',
	);

	public static function register(): void {
		add_action( Jobs::SWEEP, array( __CLASS__, 'purge_all_expired' ), 25 );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
	}

	/**
	 * Daily: wipes every stored login whose reveal window has passed.
	 */
	public static function purge_all_expired(): void {
		if ( false !== get_transient( self::RAN ) || Settings::get( 'pause_all' ) ) {
			return;
		}

		set_transient( self::RAN, 1, DAY_IN_SECONDS );

		foreach ( array( 'vm', 'db' ) as $kind ) {
			foreach ( Resource::live_ids( $kind ) as $id ) {
				$r = Resource::find( $id );

				if ( $r ) {
					self::purge_expired( $r );
				}
			}
		}
	}

	/**
	 * Wipes the expired logins of one resource, under its lock.
	 *
	 * @param Resource $r Resource.
	 * @return bool Whether something was wiped.
	 */
	public static function purge_expired( Resource $r ): bool {
		$wiped = false;

		$r->locked(
			static function () use ( $r, &$wiped ): bool {
				$wipe = array();

				// Read again under the lock: a reveal or a reinstall may have changed it.
				foreach ( self::FIELDS as $secret => $until ) {
					$stored = $r->get( $secret );

					if ( is_string( $stored ) && '' !== $stored && (int) $r->get( $until ) < time() ) {
						$wipe[ $secret ] = '';
						$wipe[ $until ]  = 0;
					}
				}

				if ( array() === $wipe ) {
					return true;
				}

				$wiped = $r->set( $wipe );

				return $wiped;
			}
		);

		return $wiped;
	}

	/**
	 * Listener of beltoft_services_state_changed: a deleted service keeps no login.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'deleted' !== $new || ! $r instanceof Resource ) {
			return;
		}

		$wipe = array();

		foreach ( self::FIELDS as $secret => $until ) {
			if ( '' !== (string) $r->get( $secret ) ) {
				$wipe[ $secret ] = '';
				$wipe[ $until ]  = 0;
			}
		}

		if ( array() !== $wipe ) {
			$r->set( $wipe );
		}
	}
}
