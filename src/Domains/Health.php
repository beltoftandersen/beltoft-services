<?php
/** Registrar funds and DNS health checks. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Health {
	public const LOW_ALERT = 'beltoft_services_domains_low_balance_alert';

	public static function balance(): float|\WP_Error {
		$reply = Clients::registrar()->request( 'GET', '/resellers' );
		if ( is_wp_error( $reply ) ) { return $reply; }
		if ( ! is_numeric( $reply['balance'] ?? null ) || 'EUR' !== strtoupper( (string) ( $reply['currency'] ?? 'EUR' ) ) ) { return new \WP_Error( 'bad_balance', 'Registrar did not return a EUR balance.' ); }
		$balance = (float) $reply['balance'];
		if ( $balance < (float) Settings::get( 'domain_low_balance', 50 ) ) {
			$today = gmdate( 'Y-m-d' );
			if ( $today !== get_option( self::LOW_ALERT, '' ) ) {
				$to = (string) Settings::get( 'admin_email' );
				if ( is_email( $to ) ) {
					wp_mail( $to, __( 'Domain registrar balance is low', 'beltoft-services' ), sprintf( 'Registrar balance: %.2f EUR', $balance ) );
					update_option( self::LOW_ALERT, $today, false );
				}
			}
		}
		return $balance;
	}
}
