<?php
/** Customer and admin domain details. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Panels {
	public static function register(): void {
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ) );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 2 );
	}
	public static function assets( $parent ): void {
		wp_enqueue_script( 'beltoft-services-domain', plugins_url( 'assets/domain.js', BELTOFT_SERVICES_FILE ), is_string( $parent ) ? array( $parent ) : array(), BELTOFT_SERVICES_VERSION, true );
	}
	public static function buttons( $buttons, $r ) {
		if ( is_array( $buttons ) && $r instanceof Resource && in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ) {
			unset( $buttons['set_records'], $buttons['set_nameservers'] );
		}
		return $buttons;
	}
	public static function account( Resource $r ): void {
		$domain = (string) $r->get( 'domain' );
		$dns_only = 'dns' === $r->get( 'driver' );
		$zone = $dns_only || 'custom' !== $r->get( 'ns_mode' ) ? Clients::dns()->zone( $domain ) : null;
		$rrsets = is_array( $zone ) ? (array) ( $zone['rrsets'] ?? array() ) : array();
		include BELTOFT_SERVICES_PATH . 'templates/account/domain.php';
	}
	public static function admin( Resource $r ): void {
		$details = array_filter( array(
			'Domain' => $r->get( 'domain' ),
			'Registrar id' => $r->get( 'remote_id' ),
			'Registrar status' => $r->get( 'registrar_status' ),
			'Expires' => $r->get( 'expires_at' ),
			'Delegation' => $r->get( 'delegation' ),
			'Delegation checked' => $r->get( 'delegation_checked' ) ? wp_date( 'Y-m-d H:i', (int) $r->get( 'delegation_checked' ) ) : '',
			'Last sync' => $r->get( 'last_sync' ) ? wp_date( 'Y-m-d H:i', (int) $r->get( 'last_sync' ) ) : '',
		) );
		foreach ( $details as $label => $value ) { printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( (string) $value ) ); }
		$subscription = (int) ( $r->license()['subscription_id'] ?? 0 );
		if ( $subscription ) { printf( '<p><a href="%s">%s</a></p>', esc_url( get_edit_post_link( $subscription ) ), esc_html__( 'Subscription', 'beltoft-services' ) ); }
	}
}
