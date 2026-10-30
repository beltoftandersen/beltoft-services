<?php
/**
 * The Network tab of a server page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Account\Servers;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs into the server page through its filters and actions, like AppTab and FirewallTab. The tab exists
 * only for a server that can have the private network (PrivateAttach::eligible()). The buttons post the
 * customer actions private_attach and private_detach; every text is the plugin's own.
 */
final class NetworkTab {

	public const SLUG   = 'network';
	public const HANDLE = 'beltoft-services-network-tab';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_server_page_tabs', array( __CLASS__, 'slugs' ) );
		add_filter( 'beltoft_services_server_tabs', array( __CLASS__, 'tabs' ), 10, 2 );
		add_action( 'beltoft_services_server_tab_pane', array( __CLASS__, 'pane' ), 10, 2 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 20, 2 );
	}

	/**
	 * The address part of the tab.
	 *
	 * @param mixed $slugs Slugs.
	 * @return mixed
	 */
	public static function slugs( $slugs ) {
		return is_array( $slugs ) ? array_merge( $slugs, array( self::SLUG ) ) : $slugs;
	}

	/**
	 * Adds the tab before Reinstall (after the Firewall tab when there is one).
	 *
	 * @param mixed $tabs        Tabs.
	 * @param mixed $resource_id Resource id.
	 * @return mixed
	 */
	public static function tabs( $tabs, $resource_id = 0 ) {
		$r = is_array( $tabs ) ? Resource::find( (int) $resource_id ) : null;

		if ( ! $r || ! PrivateAttach::eligible( $r ) ) {
			return $tabs;
		}

		$title = __( 'Network', 'beltoft-services' );
		$out   = array();
		$added = false;

		foreach ( $tabs as $slug => $label ) {
			if ( 'reinstall' === $slug && ! $added ) {
				$out[ self::SLUG ] = $title;
				$added             = true;
			}

			$out[ $slug ] = $label;
		}

		return $added ? $out : $tabs + array( self::SLUG => $title );
	}

	/**
	 * The words the script prints.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'attachTitle' => __( 'Attach the private network?', 'beltoft-services' ),
			'attachBody'  => __( 'Your server restarts once. It is offline for about a minute.', 'beltoft-services' ),
			'attach'      => __( 'Attach', 'beltoft-services' ),
			'detachTitle' => __( 'Detach the private network?', 'beltoft-services' ),
			'detachBody'  => __( 'Your server restarts once and loses its private address. Anything that uses that address stops working.', 'beltoft-services' ),
			'detach'      => __( 'Detach', 'beltoft-services' ),
			'working'     => __( 'Working on it. This takes a few minutes.', 'beltoft-services' ),
			'done'        => __( 'Done.', 'beltoft-services' ),
			'failed'      => __( 'This did not finish. We have been told and will look into it.', 'beltoft-services' ),
			'error'       => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
		);
	}

	/**
	 * Scripts and styles: only on the page of a server that can have the private network.
	 *
	 * @param mixed $parent   Handle of the generic account script.
	 * @param mixed $endpoint The endpoint being shown.
	 */
	public static function assets( $parent, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && Servers::slug() !== $endpoint ) ) {
			return;
		}

		$asked   = Servers::requested();
		$license = $asked['id'] > 0 ? \BeltoftLicenses\Licenses::find( $asked['id'] ) : null;
		$r       = is_array( $license ) && (int) ( $license['user_id'] ?? 0 ) === get_current_user_id() && get_current_user_id() > 0 ? Resource::for_license( (int) $license['id'] ) : null;

		if ( ! $r || ! PrivateAttach::eligible( $r ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/network-tab.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/network-tab.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/network-tab.js', BELTOFT_SERVICES_FILE ), array( $parent ), \BeltoftServices\Assets::version( 'assets/network-tab.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesNetwork', array( 'strings' => self::strings() ) );
	}

	/**
	 * Prints the pane.
	 *
	 * @param mixed $slug        Tab slug.
	 * @param mixed $resource_id Resource id.
	 */
	public static function pane( $slug, $resource_id = 0 ): void {
		if ( self::SLUG !== $slug ) {
			return;
		}

		$r = Resource::find( (int) $resource_id );

		if ( ! $r || ! PrivateAttach::eligible( $r ) ) {
			return;
		}

		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/network.php';
		} )( PrivateAttach::view( $r ) );
	}
}
