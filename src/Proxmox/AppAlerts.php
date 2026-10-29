<?php
/** App-only failure alerts, separate from server failure alerts. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class AppAlerts {
	public static function failure( Resource $r, string $reason ): void {
		$hash = hash( 'sha256', $reason );
		if ( $hash === $r->get( 'app_alerted' ) ) {
			return;
		}
		$to = (string) Settings::get( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = sprintf( 'App installation failed for server %d', $r->id() );
		$body = sprintf( "Server resource: %d\nApp: %s\nVersion: %s\nReason: %s\n", $r->id(), (string) $r->get( 'app_id' ), (string) $r->get( 'app_version' ), $reason );
		wp_mail( $to, $subject, $body );
		$r->set( array( 'app_alerted' => $hash ) );
	}

	/** The customer asked for a domain and it was dropped: the app goes on the server address. One mail per server. */
	public static function domain_dropped( Resource $r, string $domain, string $reason ): void {
		if ( '' !== (string) $r->get( 'app_domain_alerted' ) ) {
			return;
		}
		$to = (string) Settings::get( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = sprintf( 'App domain dropped for server %d', $r->id() );
		$body = sprintf( "Server resource: %d\nApp: %s\nDomain: %s\nReason: %s\nThe app is installed on the server address instead.\n", $r->id(), (string) $r->get( 'app_id' ), $domain, $reason );
		wp_mail( $to, $subject, $body );
		$r->set( array( 'app_domain_alerted' => '1' ) );
	}

	/** A record we made could not be checked or removed after all retries. Tell an admin once. */
	public static function domain_release_failed( Resource $r, string $domain, string $reason ): void {
		$to = (string) Settings::get( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		wp_mail( $to, sprintf( 'App DNS record removal failed for server %d', $r->id() ), sprintf( "Server resource: %d\nDomain: %s\nReason: %s\n", $r->id(), $domain, $reason ) );
	}
}
