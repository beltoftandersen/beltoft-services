<?php
/**
 * The daily mail usage check.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Once a day every ready email domain is read through the mail server: a mailbox that is at or above the alert
 * percentage (setting mail_quota_alert_percent) is reported to the admin, once, until it drops below it again. Meta
 * mail_usage_alerted holds the mailboxes already reported. The job reads the mail server only; nothing is changed there.
 * It also wipes the stored mailbox passwords that nobody revealed in time (MailAccess::purge_expired).
 */
final class MailJobs {

	public const HOOK = 'beltoft_services_mail_usage';
	public const CRON = '15 5 * * *';

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily run, once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON, array(), HOUR_IN_SECONDS );
	}

	/**
	 * The daily run.
	 */
	public static function run(): void {
		foreach ( Resource::live_ids( MailDriver::KIND ) as $id ) {
			$r = Resource::find( $id );

			if ( $r && array() !== (array) $r->get( MailAccess::META ) ) {
				MailAccess::purge_expired( $r );
			}

			if ( $r && 'ready' === $r->get( 'state' ) ) {
				self::check( $r );
			}
		}
	}

	/**
	 * One domain.
	 *
	 * @param Resource $r Resource.
	 * @return string[] The mailboxes that are over the threshold now.
	 */
	public static function check( Resource $r ): array {
		$rows = MailClients::for()->list_mailboxes( (string) $r->get( MailDomain::META_DOMAIN ) );

		if ( is_wp_error( $rows ) ) {
			return array();
		}

		$percent = max( 1, min( 100, (int) MailSettings::get( 'mail_quota_alert_percent' ) ) );
		$over    = array();
		$lines   = array();

		$system = MailGuard::system_address( $r );

		foreach ( $rows as $row ) {
			// The hidden website mail mailbox is ours: the customer is not told about its size and has no way to free it.
			if ( '' !== $system && strtolower( (string) ( $row['username'] ?? '' ) ) === $system ) {
				continue;
			}

			$quota = (int) ( $row['quota'] ?? 0 );
			$used  = (int) ( $row['quota_used'] ?? 0 );

			if ( $quota > 0 && $used * 100 >= $quota * $percent ) {
				$email          = strtolower( (string) ( $row['username'] ?? '' ) );
				$over[]         = $email;
				$lines[ $email ] = sprintf( '%s: %d percent of %d MB', $email, (int) floor( $used * 100 / $quota ), (int) round( $quota / 1048576 ) );
			}
		}

		$known = array_map( 'strval', (array) $r->get( 'mail_usage_alerted' ) );
		$new   = array_values( array_diff( $over, $known ) );

		if ( array() !== $new ) {
			Alerts::mail_usage( $r, array_values( array_intersect_key( $lines, array_flip( $new ) ) ) );
		}

		if ( $over !== $known ) {
			$r->locked(
				static function () use ( $r, $over ): bool {
					return $r->set( array( 'mail_usage_alerted' => $over ) );
				}
			);
		}

		return $over;
	}
}
