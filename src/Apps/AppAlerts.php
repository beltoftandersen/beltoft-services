<?php
/** App-only failure alerts, separate from server failure alerts. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Resource;
use BeltoftServices\Alerts;

defined( 'ABSPATH' ) || exit;

final class AppAlerts {
	public static function failure( Resource $r, string $reason ): void {
		// The reason stays on the resource (the admin license box shows it), whether or not a mail goes out.
		$r->set( array( 'app_error' => mb_substr( wp_strip_all_tags( $reason ), 0, 300 ) ) );

		$hash = hash( 'sha256', $reason );
		if ( $hash === $r->get( 'app_alerted' ) ) {
			return;
		}
		$valid = '' !== Alerts::address();
		Alerts::notify(
			sprintf( 'App installation failed for server %d', $r->id() ),
			array(
				sprintf( 'Server resource: %d', $r->id() ),
				sprintf( 'App: %s', (string) $r->get( 'app_id' ) ),
				sprintf( 'Version: %s', (string) $r->get( 'app_version' ) ),
				sprintf( 'Reason: %s', $reason ),
			),
			$r,
			'',
			array( 'prefix' => false )
		);
		if ( $valid ) {
			$r->set( array( 'app_alerted' => $hash ) );
		}
	}

	/** The customer asked for a domain and it was dropped: the app goes on the server address. One mail per server. */
	public static function domain_dropped( Resource $r, string $domain, string $reason ): void {
		if ( '' !== (string) $r->get( 'app_domain_alerted' ) ) {
			return;
		}
		$valid = '' !== Alerts::address();
		Alerts::notify(
			sprintf( 'App domain dropped for server %d', $r->id() ),
			array(
				sprintf( 'Server resource: %d', $r->id() ),
				sprintf( 'App: %s', (string) $r->get( 'app_id' ) ),
				sprintf( 'Domain: %s', $domain ),
				sprintf( 'Reason: %s', $reason ),
				'The app is installed on the server address instead.',
			),
			$r,
			'',
			array( 'prefix' => false )
		);
		if ( $valid ) {
			$r->set( array( 'app_domain_alerted' => '1' ) );
		}
	}

	/** A record we made could not be checked or removed after all retries. Tell an admin once. */
	public static function domain_release_failed( Resource $r, string $domain, string $reason ): void {
		Alerts::notify(
			sprintf( 'App DNS record removal failed for server %d', $r->id() ),
			array(
				sprintf( 'Server resource: %d', $r->id() ),
				sprintf( 'Domain: %s', $domain ),
				sprintf( 'Reason: %s', $reason ),
			),
			$r,
			'',
			array( 'prefix' => false )
		);
	}
}
