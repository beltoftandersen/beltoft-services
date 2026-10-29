<?php
/**
 * The Firewall tab of a server page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Account\Servers;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs into the server page through its filters and actions (beltoft_services_server_page_tabs,
 * beltoft_services_server_tabs, beltoft_services_server_tab_pane, beltoft_services_account_assets), like AppTab. The
 * tab exists only for a server whose firewall the customer may manage (Firewall::editable()). The pane is a shell:
 * the rules come from the firewall route, and every text the page prints is this plugin's own.
 */
final class FirewallTab {

	public const SLUG   = 'firewall';
	public const HANDLE = 'beltoft-services-firewall-tab';

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
	 * Adds the tab after Access (before Reinstall), for a server whose firewall the customer manages.
	 *
	 * @param mixed $tabs        Tabs.
	 * @param mixed $resource_id Resource id.
	 * @return mixed
	 */
	public static function tabs( $tabs, $resource_id = 0 ) {
		$r = is_array( $tabs ) ? Resource::find( (int) $resource_id ) : null;

		if ( ! $r || ! Firewall::editable( $r ) ) {
			return $tabs;
		}

		$title = __( 'Firewall', 'beltoft-services' );
		$out   = array();
		$added = false;

		foreach ( $tabs as $slug => $label ) {
			if ( 'reinstall' === $slug && ! $added ) {
				$out[ self::SLUG ] = $title;
				$added             = true;
			}

			$out[ $slug ] = $label;

			if ( 'access' === $slug && ! $added ) {
				$out[ self::SLUG ] = $title;
				$added             = true;
			}
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
			'loading'       => __( 'Loading…', 'beltoft-services' ),
			'loadFailed'    => __( 'The firewall could not be read right now. Please reload the page.', 'beltoft-services' ),
			'open'          => __( 'Your server accepts connections on every port.', 'beltoft-services' ),
			'closed'        => __( 'Your server only accepts connections on the ports that were opened for it.', 'beltoft-services' ),
			'unknown'       => __( 'The firewall of this server has not been set up from here yet.', 'beltoft-services' ),
			'standardInfo'  => __( 'SSH (22), HTTP (80), HTTPS (443) and ping are open to everyone. Everything else is closed.', 'beltoft-services' ),
			'startStandard' => __( 'Use the standard rules', 'beltoft-services' ),
			'startOpen'     => __( 'Keep everything open', 'beltoft-services' ),
			'startOpenInfo' => __( 'Nothing changes on your server. You can close ports later.', 'beltoft-services' ),
			'on'            => __( 'Firewall: On', 'beltoft-services' ),
			'off'           => __( 'Firewall: Off', 'beltoft-services' ),
			'offWarning'    => __( 'Every port on your server can be reached from the internet.', 'beltoft-services' ),
			'turnOn'        => __( 'Turn on', 'beltoft-services' ),
			'turnOff'       => __( 'Turn off', 'beltoft-services' ),
			'offTitle'      => __( 'Turn the firewall off?', 'beltoft-services' ),
			'offBody'       => __( 'Every port on your server can then be reached from the internet. Your rules are kept, so you can turn the firewall on again.', 'beltoft-services' ),
			'resetTitle'    => __( 'Start again?', 'beltoft-services' ),
			'resetBody'     => __( 'Your rules are replaced by the standard rules: SSH, HTTP, HTTPS and ping, and the ports of your app.', 'beltoft-services' ),
			'reset'         => __( 'Start again', 'beltoft-services' ),
			'cancel'        => __( 'Cancel', 'beltoft-services' ),
			'remove'        => __( 'Remove', 'beltoft-services' ),
			'anyone'        => __( 'Anyone', 'beltoft-services' ),
			'forApp'        => __( 'Added for your app', 'beltoft-services' ),
			'noRules'       => __( 'No ports are open.', 'beltoft-services' ),
			'copyUfw'       => __( 'Copy the command for inside the server', 'beltoft-services' ),
			'copied'        => __( 'Copied.', 'beltoft-services' ),
			'apply'         => __( 'Apply changes', 'beltoft-services' ),
			'applying'      => __( 'Applying. This takes up to a minute.', 'beltoft-services' ),
			'saved'         => __( 'Saved. The change is in place.', 'beltoft-services' ),
			'failed'        => __( 'This did not finish. We have been told and will look into it.', 'beltoft-services' ),
			'unsaved'       => __( 'You have changes that are not applied yet.', 'beltoft-services' ),
			'badPort'       => __( 'A port is a number from 1 to 65535. A range is two numbers, like 8000-8100.', 'beltoft-services' ),
			'duplicate'     => __( 'That rule is already in the list.', 'beltoft-services' ),
			'tooMany'       => __( 'A server can have up to %d rules.', 'beltoft-services' ),
			'noAddress'     => __( 'Enter at least one address, or choose Anyone.', 'beltoft-services' ),
			'notIncluded'   => __( 'This does not include the address you are using now: %s', 'beltoft-services' ),
			'addMine'       => __( 'Add my address', 'beltoft-services' ),
			'noSsh'         => __( 'No rule lets you in over SSH from where you are now.', 'beltoft-services' ),
			'ackNoSsh'      => __( 'I understand that I will not be able to log in over SSH', 'beltoft-services' ),
			'busy'          => __( 'Your server is busy with another change. Please try again in a minute.', 'beltoft-services' ),
			'error'         => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
		);
	}

	/**
	 * Scripts and styles: only on the page of a server whose firewall the customer manages.
	 *
	 * @param mixed $parent   Handle of the generic account script.
	 * @param mixed $endpoint The endpoint being shown.
	 */
	public static function assets( $parent, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && Servers::slug() !== $endpoint ) ) {
			return;
		}

		$asked = Servers::requested();
		$r     = $asked['id'] > 0 ? self::resource_of_license( $asked['id'] ) : null;

		if ( ! $r || ! Firewall::editable( $r ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/firewall.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/firewall.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/firewall.js', BELTOFT_SERVICES_FILE ), array( $parent ), \BeltoftServices\Assets::version( 'assets/firewall.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesFirewall', array( 'strings' => self::strings() ) );
	}

	/**
	 * The resource of a license that belongs to the signed-in customer, or null.
	 *
	 * @param int $license_id License id.
	 */
	private static function resource_of_license( int $license_id ): ?Resource {
		$license = \BeltoftLicenses\Licenses::find( $license_id );

		if ( ! is_array( $license ) || (int) ( $license['user_id'] ?? 0 ) !== get_current_user_id() || get_current_user_id() <= 0 ) {
			return null;
		}

		return Resource::for_license( $license_id );
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

		if ( ! $r || ! Firewall::editable( $r ) ) {
			return;
		}

		( static function ( Resource $r ): void {
			$view = Firewall::view( $r );
			include BELTOFT_SERVICES_PATH . 'templates/account/firewall.php';
		} )( $r );
	}
}
