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
	 * Option holding the alerts that could not be sent (no valid alert address), keyed by
	 * resource id, for the admin screen to show.
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
			$problems = self::problems();

			$problems[ $r->id() ] = array(
				'time'     => time(),
				'resource' => $r->id(),
				'message'  => 'A service failure alert could not be sent: the alert address is not a valid email address.',
			);

			update_option( self::PROBLEM_OPTION, $problems, false );

			return false;
		}

		$license = $r->license();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$key     = (string) ( $license['license_key'] ?? '' );

		$subject = sprintf( '[%s] Service failed: %s', $site, self::mask( $key ) );
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
		self::clear_problem( $r->id() );

		return $sent;
	}

	/**
	 * Alerts the admin that a service looks abused (sustained high load): its own subject and
	 * body, not the failed-service format. Deduped like admin(): the text carries the date, so
	 * it repeats at most daily. Call it under the resource lock (it stores the dedupe hash in meta).
	 *
	 * @param Resource $r        Resource.
	 * @param string   $measured What was measured (threshold, duration, since, date).
	 * @param string   $mode     The abuse mode (report or pause).
	 * @return bool Whether a mail was sent.
	 */
	public static function abuse( Resource $r, string $measured, string $mode ): bool {
		$hash = md5( 'abuse|' . $measured );

		if ( $hash === $r->get( 'alerted' ) ) {
			return false;
		}

		$to = (string) Settings::get( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$license = $r->license();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$key     = (string) ( $license['license_key'] ?? '' );

		$lines = array(
			sprintf( 'License: %d (%s)', (int) ( $license['id'] ?? 0 ), $key ),
			sprintf( 'Resource: %d', $r->id() ),
			sprintf( 'Driver: %s', $r->get( 'driver' ) ),
			sprintf( 'State: %s', $r->get( 'state' ) ),
			sprintf( 'Measured: %s', $measured ),
			'pause' === $mode ? 'Mode: pause. The service was asked to pause; a renewal asks for it again.' : 'Mode: report only. Nothing was changed.',
		);

		$sent = (bool) wp_mail( $to, sprintf( '[%s] Abuse suspected: %s', $site, self::mask( $key ) ), implode( "\n", $lines ) . "\n" );

		$r->set( array( 'alerted' => $hash ) );

		return $sent;
	}

	/**
	 * Alerts the admin once per hold that a service on breach hold has passed its days and was
	 * not deleted (deletion is off or report only). The hold's date is stored in meta
	 * `breach_alerted` under the resource lock, after the mail is attempted.
	 *
	 * @param Resource $r    Resource.
	 * @param int      $days Breach days.
	 * @param string   $mode Deletion mode (off or report).
	 * @return bool Whether a mail was sent.
	 */
	public static function breach_due( Resource $r, int $days, string $mode ): bool {
		$breach_at = $r->get( 'breach_at' );
		$to        = (string) Settings::get( 'admin_email' );

		if ( null === $breach_at || (int) $r->get( 'breach_alerted' ) === $breach_at || ! is_email( $to ) ) {
			return false;
		}

		$license = $r->license();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$key     = (string) ( $license['license_key'] ?? '' );
		$lines   = array(
			sprintf( 'License: %d (%s)', (int) ( $license['id'] ?? 0 ), $key ),
			sprintf( 'Resource: %d', $r->id() ),
			sprintf( 'Driver: %s', $r->get( 'driver' ) ),
			sprintf( 'On breach hold since: %s UTC (%d days)', gmdate( 'Y-m-d H:i', $breach_at ), $days ),
			sprintf( 'Deletion mode: %s, so nothing was deleted. Delete the service by hand, or turn deletion on.', $mode ),
		);

		$sent = (bool) wp_mail( $to, sprintf( '[%s] Breach hold period over: %s', $site, self::mask( $key ) ), implode( "\n", $lines ) . "\n" );

		$r->locked(
			static function () use ( $r, $breach_at ): bool {
				return $breach_at === $r->get( 'breach_at' ) && $r->set( array( 'breach_alerted' => $breach_at ) );
			}
		);

		return $sent;
	}

	/**
	 * Alerts the admin that a license's service could not be created (there is no resource to
	 * hang the alert on). Sent once per call: a creation happens once per license event.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $error   Error text.
	 * @return bool Whether a mail was sent.
	 */
	public static function license( array $license, string $error ): bool {
		$to = (string) Settings::get( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$key  = (string) ( $license['license_key'] ?? '' );

		$subject = sprintf( '[%s] Service not created: %s', $site, self::mask( $key ) );
		$lines   = array(
			sprintf( 'License: %d (%s)', (int) ( $license['id'] ?? 0 ), $key ),
			sprintf( 'Product: %d', (int) ( $license['product_id'] ?? 0 ) ),
			sprintf( 'Error: %s', $error ),
		);

		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Alerts the admin that a write for the resource failed, at most once an hour per
	 * resource (transient `beltoft_services_not_stored_<id>`, and once per request), so a
	 * broken database does not flood the inbox.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $error Error text.
	 * @return bool Whether an alert was attempted now (false while throttled).
	 */
	public static function write_failed( Resource $r, string $error ): bool {
		static $alerted = array();

		$transient = 'beltoft_services_not_stored_' . $r->id();

		if ( isset( $alerted[ $r->id() ] ) || false !== get_transient( $transient ) ) {
			return false;
		}

		$alerted[ $r->id() ] = true;
		set_transient( $transient, 1, HOUR_IN_SECONDS );

		self::admin( $r, $error );

		return true;
	}

	/**
	 * A license key for a mail subject: only its last four characters ("…ABCD"). Subjects show
	 * in inbox lists and notifications; the body carries the full key for the admin.
	 *
	 * @param string $key License key.
	 */
	public static function mask( string $key ): string {
		return '' === $key ? '' : '…' . substr( $key, -4 );
	}

	/**
	 * Alerts that could not be sent, keyed by resource id.
	 *
	 * @return array<int, array{time: int, resource: int, message: string}>
	 */
	public static function problems(): array {
		$problems = get_option( self::PROBLEM_OPTION, array() );

		return is_array( $problems ) ? $problems : array();
	}

	/**
	 * Forgets the unsent alert of one resource (sent since, or the resource was closed).
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function clear_problem( int $resource_id ): void {
		$problems = self::problems();

		if ( ! isset( $problems[ $resource_id ] ) ) {
			return;
		}

		unset( $problems[ $resource_id ] );

		if ( $problems ) {
			update_option( self::PROBLEM_OPTION, $problems, false );
		} else {
			delete_option( self::PROBLEM_OPTION );
		}
	}
}
