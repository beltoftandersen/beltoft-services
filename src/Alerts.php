<?php
/**
 * Failure alerts to the site admin.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the alert address when a resource fails, once per distinct error. The mail carries
 * only the error text the driver returned, never settings or secrets.
 */
final class Alerts {

	/**
	 * Alerts the admin about a failed resource, unless this error was already sent.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $error Error text.
	 * @return bool Whether a mail was sent.
	 */
	public static function admin( Resource $r, string $error ): bool {
		$hash = md5( $error );

		if ( $hash === $r->get( 'alerted' ) ) {
			return false;
		}

		// Store first: a resource that cannot remember the alert would mail on every run.
		if ( ! $r->set( array( 'alerted' => $hash ) ) ) {
			return false;
		}

		$to = (string) Settings::get( 'admin_email' );

		if ( ! is_email( $to ) ) {
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
			sprintf( 'State: %s', $r->get( 'state' ) ),
			sprintf( 'Attempts: %d', $r->get( 'attempts' ) ),
			sprintf( 'Error: %s', $error ),
		);

		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ) . "\n" );
	}
}
