<?php
/**
 * Failure alerts to the site admin.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the alert address when a resource fails, once per operation and error. The mail
 * carries only the error text the driver returned, never settings or secrets.
 */
final class Alerts {

	/**
	 * Option holding the last alert that could not be sent (no valid alert address), for the
	 * admin screen to show.
	 */
	public const PROBLEM_OPTION = 'beltoft_services_alert_problem';

	/**
	 * Alerts the admin about a failed resource, unless this operation's error was already sent.
	 * The alert is remembered (meta `alerted`) only once a mail was attempted, so a missing
	 * address does not swallow it: it is sent once the address is fixed.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $error Error text.
	 * @return bool Whether a mail was sent.
	 */
	public static function admin( Resource $r, string $error ): bool {
		$hash = md5( $r->get( 'op' ) . '|' . $error );

		if ( $hash === $r->get( 'alerted' ) ) {
			return false;
		}

		$to = (string) Settings::get( 'admin_email' );

		if ( ! is_email( $to ) ) {
			update_option(
				self::PROBLEM_OPTION,
				array(
					'time'     => time(),
					'resource' => $r->id(),
					'message'  => 'A service failure alert could not be sent: the alert address is not a valid email address.',
				),
				false
			);

			return false;
		}

		$license = $r->license();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$key     = (string) ( $license['license_key'] ?? '' );

		$subject = sprintf( '[%s] Service failed: %s', $site, $key );
		$lines   = array(
			sprintf( 'License: %d (%s)', (int) ( $license['id'] ?? 0 ), $key ),
			sprintf( 'Resource: %d', $r->id() ),
			sprintf( 'Driver: %s', $r->get( 'driver' ) ),
			sprintf( 'Operation: %s', $r->get( 'op' ) ),
			sprintf( 'State: %s', $r->get( 'state' ) ),
			sprintf( 'Attempts: %d', $r->get( 'attempts' ) ),
			sprintf( 'Error: %s', $error ),
		);

		$sent = (bool) wp_mail( $to, $subject, implode( "\n", $lines ) . "\n" );

		$r->set( array( 'alerted' => $hash ) );
		delete_option( self::PROBLEM_OPTION );

		return $sent;
	}
}
