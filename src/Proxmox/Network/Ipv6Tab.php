<?php
/**
 * The IPv6 tab of a server page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Proxmox\Vm\ServerTabHooks;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs into the server page like NetworkTab. The tab exists only for a server that can have IPv6 switched
 * (Ipv6Switch::applies(): a cluster with an IPv6 prefix). The buttons post the customer actions ipv6_add and
 * ipv6_remove after a question that says plainly that the server restarts once.
 */
final class Ipv6Tab {

	use ServerTabHooks;

	public const SLUG   = 'ipv6';
	public const HANDLE = 'beltoft-services-ipv6-tab';

	/**
	 * Adds the tab before Reinstall.
	 *
	 * @param mixed $tabs        Tabs.
	 * @param mixed $resource_id Resource id.
	 * @return mixed
	 */
	public static function tabs( $tabs, $resource_id = 0 ) {
		$r = is_array( $tabs ) ? Resource::find( (int) $resource_id ) : null;

		if ( ! $r || ! Ipv6Switch::applies( $r ) ) {
			return $tabs;
		}

		$title = __( 'IPv6', 'beltoft-services' );
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
			'working' => __( 'Working on it. This takes a few minutes.', 'beltoft-services' ),
			'failed'  => __( 'This did not finish. We have been told and will look into it.', 'beltoft-services' ),
			'error'   => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
		);
	}

	/**
	 * Scripts: only on the page of a server that can have IPv6 switched.
	 *
	 * @param mixed $parent   Handle of the generic account script.
	 * @param mixed $endpoint The endpoint being shown.
	 */
	public static function assets( $parent, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && ServerLinks::slug() !== $endpoint ) ) {
			return;
		}

		$asked   = ServerLinks::requested();
		$license = $asked['id'] > 0 ? \BeltoftLicenses\Licenses::find( $asked['id'] ) : null;
		$r       = is_array( $license ) && (int) ( $license['user_id'] ?? 0 ) === get_current_user_id() && get_current_user_id() > 0 ? Resource::for_license( (int) $license['id'] ) : null;

		if ( ! $r || ! Ipv6Switch::applies( $r ) ) {
			return;
		}

		wp_enqueue_style( NetworkTab::HANDLE, plugins_url( 'assets/network-tab.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/network-tab.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/ipv6-tab.js', BELTOFT_SERVICES_FILE ), array( $parent, 'beltoft-services-status', \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/ipv6-tab.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesIpv6', array( 'strings' => self::strings() ) );
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

		if ( ! $r || ! Ipv6Switch::applies( $r ) ) {
			return;
		}

		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/ipv6.php';
		} )( Ipv6Switch::view( $r ) );
	}
}
