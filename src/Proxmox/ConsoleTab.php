<?php
/** Console tab for an eligible customer server. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Account\Servers;
use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class ConsoleTab {
	public const SLUG = 'console';
	public const HANDLE = 'beltoft-services-console';

	public static function register(): void {
		add_filter( 'beltoft_services_server_page_tabs', array( __CLASS__, 'slugs' ) );
		add_filter( 'beltoft_services_server_tabs', array( __CLASS__, 'tabs' ), 30, 2 );
		add_action( 'beltoft_services_server_tab_pane', array( __CLASS__, 'pane' ), 10, 2 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 25, 2 );
	}

	public static function slugs( $slugs ) {
		return is_array( $slugs ) ? array_merge( $slugs, array( self::SLUG ) ) : $slugs;
	}

	public static function tabs( $tabs, $resource_id = 0 ) {
		$r = is_array( $tabs ) ? Resource::find( (int) $resource_id ) : null;
		if ( ! $r || ! Console::eligible( $r ) ) {
			return $tabs;
		}
		$out = array();
		foreach ( $tabs as $slug => $title ) {
			$out[ $slug ] = $title;
			if ( 'overview' === $slug ) {
				$out[ self::SLUG ] = __( 'Console', 'beltoft-services' );
			}
		}
		return isset( $out[ self::SLUG ] ) ? $out : $tabs + array( self::SLUG => __( 'Console', 'beltoft-services' ) );
	}

	public static function pane( $slug, $resource_id = 0 ): void {
		if ( self::SLUG !== $slug ) {
			return;
		}
		$r = Resource::find( (int) $resource_id );
		if ( ! $r || ! Console::eligible( $r ) ) {
			echo '<p class="description">' . esc_html__( 'The console is not available while the server is stopped or paused.', 'beltoft-services' ) . '</p>';
			return;
		}
		include BELTOFT_SERVICES_PATH . 'templates/account/console.php';
	}

	public static function assets( $parent, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && Servers::slug() !== $endpoint ) ) {
			return;
		}
		$asked = Servers::requested();
		$r = $asked['id'] > 0 ? self::resource_for_license( $asked['id'] ) : null;
		if ( ! $r || ! Console::eligible( $r ) ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/console.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/console.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/console.js', BELTOFT_SERVICES_FILE ), array( $parent ), \BeltoftServices\Assets::version( 'assets/console.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesConsole', array(
			'rest' => esc_url_raw( rest_url( Routes::NAMESPACE . '/resources/' . $r->id() . '/console' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'novnc' => plugins_url( 'assets/novnc/core/rfb.js', BELTOFT_SERVICES_FILE ),
			'strings' => array(
				'open' => __( 'Open console', 'beltoft-services' ),
				'connecting' => __( 'Connecting', 'beltoft-services' ),
				'connected' => __( 'Connected', 'beltoft-services' ),
				'disconnected' => __( 'The console has closed. Open it again to continue.', 'beltoft-services' ),
				'stopped' => __( 'The console is not available while the server is stopped or paused.', 'beltoft-services' ),
				'busy' => __( 'A console is already being opened. Please wait a moment.', 'beltoft-services' ),
				'limit' => __( 'Too many attempts. Please wait a few minutes and try again.', 'beltoft-services' ),
				'failed' => __( 'The console is not available right now.', 'beltoft-services' ),
				'help' => __( 'Use the console when you cannot log in with SSH. It shows the same screen as a monitor on the server.', 'beltoft-services' ),
				'keyboard' => __( 'Keyboard', 'beltoft-services' ),
				/* translators: %d: number of characters typed into the console */
				'typed' => __( 'Typed %d characters. Press Enter in the field below, or in the console, to send Enter.', 'beltoft-services' ),
				/* translators: %d: number of characters sent to the console */
				'sent' => __( 'Sent %d characters and Enter.', 'beltoft-services' ),
				'fullscreen' => __( 'Full screen', 'beltoft-services' ),
				'cad' => __( 'Ctrl+Alt+Del', 'beltoft-services' ),
				'disconnect' => __( 'Disconnect', 'beltoft-services' ),
			)
		) );
	}

	private static function resource_for_license( int $license_id ): ?Resource {
		$license = \BeltoftLicenses\Licenses::find( $license_id );
		if ( ! is_array( $license ) || get_current_user_id() <= 0 || (int) ( $license['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		return Resource::for_license( $license_id );
	}
}
