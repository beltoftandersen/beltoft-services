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
 * mail_usage_alerted holds the mailboxes already reported. The job reads only; nothing is changed on the server.
 */
final class MailJobs {

	public const HOOK = 'beltoft_services_mail_usage';
	public const CRON = '15 5 * * *';

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily run, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time() + HOUR_IN_SECONDS, self::CRON, self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * The daily run.
	 */
	public static function run(): void {
		foreach ( Resource::live_ids( MailDriver::KIND ) as $id ) {
			$r = Resource::find( $id );

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

		foreach ( $rows as $row ) {
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
