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
}
