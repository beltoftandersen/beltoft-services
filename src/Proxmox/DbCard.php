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
				'restoring' => __( 'Restoring the database. This can take several minutes.', 'beltoft-services' ),
				'applying'  => __( 'Applying the change. This can take a minute.', 'beltoft-services' ),
				'applied'   => __( 'The change is applied.', 'beltoft-services' ),
				'notapplied' => __( 'The change could not be applied. We have been told. Please try again later.', 'beltoft-services' ),
				'noip'      => __( 'Your current address could not be read.', 'beltoft-services' ),
				'confirmremove' => __( 'Remove this address from the list?', 'beltoft-services' ),
				'nobackup'  => __( 'Choose a backup.', 'beltoft-services' ),
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
		$engine  = DbEngines::for_resource( $r );
		$restore = 'ready' === $state && ( is_array( $r->get( 'restore' ) ) || DbRestore::waiting( $r ) );
		$kind    = $restore ? 'restoring' : ( $ready ? 'ready' : ( 'paused' === $state ? 'paused' : ( 'failed' === $state ? 'failed' : 'building' ) ) );
		$offered = DbActions::offered( $r );
		$backups = self::backups( $r, $ready && ! $restore && $r->accepts_actions() && array_key_exists( DbActions::RESTORE, $offered ) );
		$access  = DbAccess::view( $r );
		$public  = $ready && '' !== $access['address'] ? $engine->connections( $access['address'] ) : array();

		return array(
			'kind'        => $kind,
			'cost'        => \BeltoftServices\Usage\Estimate::cost_line( $r->id() ),
			'host'        => $ready ? $address : '',
			'engine'      => $engine->label( $plan ),
			'engine_id'   => $engine->id(),
			'limits'      => $engine->limits(),
			'ca_hint'     => $engine->ca_hint(),
			'port'        => $engine->port(),
			'database'    => $engine->database(),
			'user'        => $engine->login(),
			'connections' => $ready ? $engine->connections( $address ) : array(),
			'connection_note' => $engine->connection_note(),
			'fingerprint' => $ready && 1 === preg_match( '/^[0-9A-F:]{95}$/D', (string) $r->get( 'db_fingerprint' ) ) ? (string) $r->get( 'db_fingerprint' ) : '',
			'ca_url'      => $ready && DbGuest::pem( (string) $r->get( 'db_ca_pem' ) ) ? DbRoutes::ca_url( $r->id() ) : '',
			'can_reveal'  => $ready && DbRoutes::revealable( $r ),
			'can_reset'   => $ready && $r->accepts_actions() && array_key_exists( DbActions::RESET, $offered ),
			'resetting'   => '' !== (string) $r->get( 'db_reset_request' ),
			'confirm'     => DbActions::CONFIRM,
			'backups'     => $backups,
			'access'      => $access,
			'public_connections' => $public,
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

	/**
	 * The backups and the nightly dump, from stored data only (no Proxmox call): whether backups run, the days kept,
	 * the list, the last good backup, the last nightly dump (from the guest's health file) and whether a restore may start.
	 *
	 * @param Resource $r       Database.
	 * @param bool     $offered Whether a restore may be started now.
	 * @return array<string, mixed>
	 */
	public static function backups( Resource $r, bool $offered ): array {
		$enabled = Backups::enabled( $r );
		$view    = Backups::listing( $r );
		$dump    = (int) $r->get( 'db_last_dump' );
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return array(
			'enabled'     => $enabled,
			'days'        => Backups::days( $r ),
			'list'        => $view['list'],
			'last_ok'     => $view['last_ok'],
			'last_dump'   => $dump > 0 ? wp_date( $format, $dump ) : '',
			'can_restore' => $enabled && $offered && array() !== $view['list'],
			'confirm'     => Actions::CONFIRM_RESTORE,
		);
	}

	public static function render( Resource $r ): void {
		self::assets();

		$view = self::view( $r );
		$page = \BeltoftServices\Account\ServiceArea::page();
		$area = $page && ( $page['area'] ?? null ) instanceof \BeltoftServices\Account\Databases ? $page['area'] : null;
		$nav  = $area ? $area->nav() : array();
		$tab  = $area && '' !== (string) $page['tab'] ? (string) $page['tab'] : 'overview';
		$link = static fn( string $slug ): string => $area && $page ? $area->url( $page['license'], 'overview' === $slug ? '' : $slug ) : '';

		( static function ( array $view, array $nav, string $active, callable $link ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/db.php';
		} )( $view, $nav, $tab, $link );
	}
}
