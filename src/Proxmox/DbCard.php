<?php
/**
 * The customer's card of a managed database (the driver's part of the license panel).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Everything printed is the plugin's own text or a value it checked; the password is never part of the page:
 * a button asks for it once.
 */
final class DbCard {

	public const HANDLE = 'beltoft-services-db';

	/**
	 * The card's script and style, loaded when a card is printed (no other page loads them).
	 */
	private static function assets(): void {
		$parent = \BeltoftServices\Account\Panel::HANDLE;

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/db.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/db.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/db.js', BELTOFT_SERVICES_FILE ), array( $parent ), \BeltoftServices\Assets::version( 'assets/db.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesDb', array(
			'strings' => array(
				'gone'    => __( 'The password has already been shown or is no longer available.', 'beltoft-services' ),
				'failed'  => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
				'working' => __( 'Making a new password. This can take a minute.', 'beltoft-services' ),
				'limit'   => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
				'once'    => __( 'The password is shown one time only. Copy it now and keep it safe.', 'beltoft-services' ),
			),
		) );
	}

	/**
	 * What the card shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function view( Resource $r ): array {
		$state   = (string) $r->get( 'state' );
		$db      = (string) $r->get( 'db_state' );
		$address = (string) $r->get( 'db_address' );
		$ready   = 'ready' === $state && 'ready' === $db && false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$plan    = (array) $r->get( 'plan' );
		$base    = DbGuest::ROLE . ':PASSWORD@' . $address . ':' . DbGuest::PORT . '/' . DbGuest::DATABASE;
		$kind    = $ready ? 'ready' : ( 'paused' === $state ? 'paused' : ( 'failed' === $state ? 'failed' : 'building' ) );

		return array(
			'kind'        => $kind,
			'host'        => $ready ? $address : '',
			'port'        => DbGuest::PORT,
			'database'    => DbGuest::DATABASE,
			'user'        => DbGuest::ROLE,
			'conn_verify' => $ready ? 'postgresql://' . $base . '?sslmode=verify-full&sslrootcert=ca.crt' : '',
			'conn_require' => $ready ? 'postgresql://' . $base . '?sslmode=require' : '',
			'fingerprint' => $ready && 1 === preg_match( '/^[0-9A-F:]{95}$/D', (string) $r->get( 'db_fingerprint' ) ) ? (string) $r->get( 'db_fingerprint' ) : '',
			'ca_url'      => $ready && DbGuest::pem( (string) $r->get( 'db_ca_pem' ) ) ? DbRoutes::ca_url( $r->id() ) : '',
			'can_reveal'  => $ready && DbRoutes::revealable( $r ),
			'can_reset'   => $ready && $r->accepts_actions() && array() !== DbActions::offered( $r ),
			'resetting'   => '' !== (string) $r->get( 'db_reset_request' ),
			'confirm'     => DbActions::CONFIRM,
			'version'     => (int) ( $plan['major'] ?? 0 ),
			'size'        => sprintf(
				/* translators: 1: number of vCPUs, 2: memory in GB, 3: disk in GB */
				__( '%1$d vCPU, %2$s GB memory, %3$d GB disk', 'beltoft-services' ),
				(int) ( $plan['cores'] ?? 0 ),
				(string) round( (int) ( $plan['memory_mb'] ?? 0 ) / 1024, 1 ),
				(int) ( $plan['disk_gb'] ?? 0 )
			),
		);
	}

	public static function render( Resource $r ): void {
		self::assets();

		$view = self::view( $r );

		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/db.php';
		} )( $view );
	}
}
