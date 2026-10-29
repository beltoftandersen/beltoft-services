<?php
/** The App tab of a server page. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Account\Servers;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs into the server page through its filters and actions (beltoft_services_server_page_tabs,
 * beltoft_services_server_tabs, beltoft_services_server_tab_pane); no other tab is touched. The tab
 * exists only for a server that was ordered with an app. Everything it prints is the plugin's own
 * text: the guest only ever supplies a step code, which is matched against the app's fixed list.
 */
final class AppTab {

	public const SLUG   = 'app';
	public const HANDLE = 'beltoft-services-app-tab';

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
	 * Adds the tab after the overview, for a server with an app.
	 *
	 * @param mixed $tabs        Tabs.
	 * @param mixed $resource_id Resource id.
	 * @return mixed
	 */
	public static function tabs( $tabs, $resource_id = 0 ) {
		$r = is_array( $tabs ) ? Resource::find( (int) $resource_id ) : null;

		if ( ! $r || '' === (string) $r->get( 'app_id' ) || null === Apps::definition( (string) $r->get( 'app_id' ) ) ) {
			return $tabs;
		}

		$out = array();

		foreach ( $tabs as $slug => $title ) {
			$out[ $slug ] = $title;

			if ( 'overview' === $slug ) {
				$out[ self::SLUG ] = __( 'App', 'beltoft-services' );
			}
		}

		return isset( $out[ self::SLUG ] ) ? $out : $tabs + array( self::SLUG => __( 'App', 'beltoft-services' ) );
	}

	/**
	 * Scripts and styles: only on the page of a server that has an app.
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

		if ( ! $r || '' === (string) $r->get( 'app_id' ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/app-tab.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/app-tab.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/app-tab.js', BELTOFT_SERVICES_FILE ), array( $parent ), \BeltoftServices\Assets::version( 'assets/app-tab.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesApp',
			array(
				'strings' => array(
					'gone'   => __( 'The login has already been shown or is no longer available.', 'beltoft-services' ),
					'failed' => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'user'   => __( 'Username', 'beltoft-services' ),
					'email'  => __( 'Email', 'beltoft-services' ),
					'pass'   => __( 'Password', 'beltoft-services' ),
				),
			)
		);
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

	// -- The pane ------------------------------------------------------------------------------

	/**
	 * The words for a step code the guest may report. Anything else is shown as a plain "Installing".
	 *
	 * @return array<string, string>
	 */
	public static function step_labels(): array {
		return array(
			'download'    => __( 'Downloading the installer', 'beltoft-services' ),
			'os'          => __( 'Checking the system', 'beltoft-services' ),
			'packages'    => __( 'Installing packages', 'beltoft-services' ),
			'docker'      => __( 'Installing Docker', 'beltoft-services' ),
			'database'    => __( 'Setting up the database', 'beltoft-services' ),
			'wordpress'   => __( 'Installing WordPress', 'beltoft-services' ),
			'web'         => __( 'Setting up the web server', 'beltoft-services' ),
			'coolify'     => __( 'Installing Coolify', 'beltoft-services' ),
			'credentials' => __( 'Saving the login', 'beltoft-services' ),
			'firewall'    => __( 'Setting up the firewall', 'beltoft-services' ),
			'verify'      => __( 'Checking the install', 'beltoft-services' ),
			'install'     => __( 'Finishing', 'beltoft-services' ),
			'complete'    => __( 'Done', 'beltoft-services' ),
		);
	}

	/**
	 * The address the app is reached at, built here from the stored domain or address and the app's own path or port.
	 *
	 * @param array<string, mixed> $app Definition.
	 */
	public static function url( Resource $r, array $app ): string {
		$open   = (array) ( $app['open'] ?? array() );
		$domain = (string) $r->get( 'app_domain' );
		$ip     = (string) $r->get( 'ip' );
		$host   = '' !== $domain ? $domain : ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '' );

		if ( '' === $host || ( ! isset( $open['path'] ) && ! isset( $open['port'] ) ) ) {
			return '';
		}

		$path = isset( $open['path'] ) && is_string( $open['path'] ) && 1 === preg_match( '#^/[A-Za-z0-9/_.-]*$#', $open['path'] ) ? $open['path'] : '/';
		$port = isset( $open['port'] ) ? (int) $open['port'] : 0;

		return ( '' !== $domain && 0 === $port ? 'https://' : 'http://' ) . $host . ( $port > 0 ? ':' . $port : '' ) . $path;
	}

	/**
	 * What the pane shows.
	 *
	 * @return array<string, mixed>|null Null when the server has no app.
	 */
	public static function view( Resource $r ): ?array {
		$id  = (string) $r->get( 'app_id' );
		$app = '' === $id ? null : Apps::definition( $id );

		if ( null === $app ) {
			return null;
		}

		$vid     = (string) $r->get( 'app_version' );
		$full    = Apps::version( $id, $vid, true );
		$current = Apps::current( $id );
		$state   = (string) $r->get( 'app_state' );
		$server  = (string) $r->get( 'state' );
		$labels  = self::step_labels();
		$steps   = array();
		$step    = (string) $r->get( 'app_step' );
		$at      = array_search( $step, (array) $app['steps'], true );
		$order   = array_values( array_diff( (array) $app['steps'], array( 'complete' ) ) );

		foreach ( $order as $i => $code ) {
			$steps[] = array(
				'label'  => $labels[ $code ] ?? __( 'Installing', 'beltoft-services' ),
				'status' => 'installing' === $state && false !== $at ? ( $i < $at ? 'done' : ( $i === $at ? 'current' : 'todo' ) ) : 'todo',
			);
		}

		if ( 'ready' === $state ) {
			$kind = 'ready';
		} elseif ( 'failed' === $state ) {
			$kind = 'failed';
		} elseif ( 'unknown' === $state ) {
			$kind = 'slow';
		} elseif ( 'installing' === $state ) {
			$kind = 'installing';
		} else {
			$kind = 'waiting';
		}

		$installed = (int) $r->get( 'app_installed_at' );
		$domain    = (string) $r->get( 'app_domain' );
		$reveal    = is_string( $r->get( 'app_reveal' ) ) && '' !== $r->get( 'app_reveal' ) && (int) $r->get( 'app_reveal_until' ) > time();
		$newer     = null !== $current && '' !== $vid && $current['version_id'] !== $vid ? $current : null;
		$retired   = null !== $full && null === Apps::version( $id, $vid );
		$steps_n   = count( $order );
		$position  = false === $at ? 0 : min( $steps_n, (int) $at + 1 );

		return array(
			'resource_id'     => $r->id(),
			'name'            => (string) $app['name'],
			'version'         => null === $full ? $vid : (string) $full['label'],
			'upstream'        => (string) ( $full['upstream'] ?? '' ),
			'installed'       => 'ready' === $kind && $installed > 0 ? wp_date( get_option( 'date_format' ), $installed ) : '',
			'kind'            => $kind,
			'server_ready'    => 'ready' === $server,
			'steps'           => $steps,
			'position'        => $position,
			'total'           => $steps_n,
			'step_label'      => $labels[ $step ] ?? '',
			'url'             => 'ready' === $kind ? self::url( $r, $app ) : '',
			'secure'          => '' !== $domain,
			'domain'          => $domain,
			'domain_wanted'   => (string) $r->get( 'app_domain_want' ),
			'domain_failed'   => '' !== (string) $r->get( 'app_domain_error' ),
			'can_reveal'      => 'ready' === $kind && $reveal && $r->accepts_actions(),
			'getting_started' => (string) ( $app['getting_started'] ?? '' ),
			'docs_url'        => (string) ( $app['docs_url'] ?? '' ),
			'newer'           => null === $newer ? '' : (string) $newer['label'],
			'retired'         => $retired,
			'support_url'     => (string) apply_filters( 'beltoft_services_support_url', home_url( '/support/' ) ),
		);
	}

	/**
	 * Prints the pane of the App tab.
	 *
	 * @param mixed $slug        Tab slug.
	 * @param mixed $resource_id Resource id.
	 */
	public static function pane( $slug, $resource_id = 0 ): void {
		if ( self::SLUG !== $slug ) {
			return;
		}

		$r    = Resource::find( (int) $resource_id );
		$view = $r ? self::view( $r ) : null;

		if ( null === $view ) {
			return;
		}

		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/app.php';
		} )( $view );
	}
}
