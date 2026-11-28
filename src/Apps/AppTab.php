<?php
/** The App tab of a server page. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Proxmox\Vm\ServerTabHooks;
use BeltoftServices\Domains\Domains;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Resource;
use BeltoftServices\Domains\ZoneAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs into the server page through its filters and actions (beltoft_services_server_page_tabs,
 * beltoft_services_server_tabs, beltoft_services_server_tab_pane); no other tab is touched. The tab
 * exists only for a server that was ordered with an app. Everything it prints is the plugin's own
 * text: the guest only ever supplies a step code, which is matched against the app's fixed list.
 */
final class AppTab {

	use ServerTabHooks {
		register as register_tab;
	}

	public const SLUG   = 'app';
	public const HANDLE = 'beltoft-services-app-tab';

	public const LOGIN_ID = 'beltoft-app-login';

	/**
	 * Hooks: the tab (the shared ones), the ready box on the overview, and the lines that say where the login is.
	 */
	public static function register(): void {
		self::register_tab();
		add_action( 'beltoft_services_server_overview_top', array( __CLASS__, 'ready_box' ), 10, 1 );
		add_filter( 'beltoft_services_thankyou_row', array( __CLASS__, 'thankyou_row' ), 15, 2 );
		add_filter( 'beltoft_services_email_details', array( __CLASS__, 'email_details' ), 20, 3 );
	}

	/**
	 * The words for every key an app lists under credentials. A key without a word here is shown as a plain password.
	 *
	 * @return array<string, string>
	 */
	public static function credential_labels(): array {
		return array(
			'admin_user'      => __( 'Username', 'beltoft-services' ),
			'admin_password'  => __( 'Password', 'beltoft-services' ),
			'root_email'      => __( 'Email', 'beltoft-services' ),
			'root_password'   => __( 'Password', 'beltoft-services' ),
			'encryption_key'  => __( 'Encryption key', 'beltoft-services' ),
			'master_password' => __( 'Master password (only for database management)', 'beltoft-services' ),
		);
	}

	/**
	 * The box at the top of the overview of a server whose app is ready: open it, and where the login is.
	 *
	 * @param mixed $resource_id Resource id.
	 */
	public static function ready_box( $resource_id = 0 ): void {
		$r    = Resource::find( (int) $resource_id );
		$view = $r && '' !== (string) $r->get( 'app_id' ) ? self::view( $r ) : null;

		if ( null === $view || 'ready' !== $view['kind'] || ! $r->license() ) {
			return;
		}

		$login_url = ServerLinks::url( $r->license(), self::SLUG ) . '#' . self::LOGIN_ID;
		include BELTOFT_SERVICES_PATH . 'templates/account/app-ready.php';
	}

	/**
	 * A server with an app: its row on the order received page leads to the App tab, where the login is.
	 *
	 * @param mixed $row     Row.
	 * @param mixed $r       Resource.
	 */
	public static function thankyou_row( $row, $r = null ) {
		if ( ! is_array( $row ) || ! $r instanceof Resource || '' === (string) $r->get( 'app_id' ) || ! $r->license() || 'vm' !== ( $row['kind'] ?? '' ) ) {
			return $row;
		}

		$row['url']        = ServerLinks::url( $r->license(), self::SLUG ) . '#' . self::LOGIN_ID;
		$row['open_label'] = __( 'Go to your app and login', 'beltoft-services' );

		return $row;
	}

	/**
	 * The ready email of an app server says where the login is.
	 *
	 * @param mixed $details  Lines so far.
	 * @param mixed $email_id Email id.
	 * @param mixed $r        Resource.
	 */
	public static function email_details( $details, $email_id = '', $r = null ) {
		if ( ! is_array( $details ) || 'beltoft_services_ready' !== $email_id || ! $r instanceof Resource || '' === (string) $r->get( 'app_id' ) || 'vm' !== self::kind_of( $r ) ) {
			return $details;
		}

		$details[ __( 'Login', 'beltoft-services' ) ] = __( 'Your login is on the server page in My Account, under App.', 'beltoft-services' );

		return $details;
	}

	private static function kind_of( Resource $r ): string {
		return \BeltoftServices\Proxmox\Vm\ProxmoxDriver::ID === (string) $r->get( 'driver' ) ? 'vm' : '';
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

		if ( ! $r || ( ! (array) $r->get( 'app_domain_left' ) && '' === (string) $r->get( 'app_id' ) ) || ( '' !== (string) $r->get( 'app_id' ) && null === Apps::definition( (string) $r->get( 'app_id' ) ) ) ) {
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
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && ServerLinks::slug() !== $endpoint ) ) {
			return;
		}

		$asked = ServerLinks::requested();
		$r     = $asked['id'] > 0 ? self::resource_of_license( $asked['id'] ) : null;

		if ( ! $r || '' === (string) $r->get( 'app_id' ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/app-tab.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/app-tab.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/app-tab.js', BELTOFT_SERVICES_FILE ), array( $parent, 'beltoft-services-status', \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/app-tab.js' ), true );
		AppDnsCheck::assets( $parent );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesApp',
			array(
				'strings' => array(
					'gone'   => __( 'The login has already been shown or is no longer available.', 'beltoft-services' ),
					'failed' => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'revealing' => __( 'Showing the login…', 'beltoft-services' ),
					'revealed' => __( 'The login is shown below, one time only. Copy it now.', 'beltoft-services' ),
					'retrying' => __( 'Starting again…', 'beltoft-services' ),
					'limit'  => __( 'You can try again three times an hour. Please wait and try again later.', 'beltoft-services' ),
					'pass'   => __( 'Password', 'beltoft-services' ),
				),
				'labels'  => self::credential_labels(),
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
			'download'         => __( 'Downloading the installer', 'beltoft-services' ),
			'os'               => __( 'Checking the system', 'beltoft-services' ),
			'packages'         => __( 'Installing packages', 'beltoft-services' ),
			'docker'           => __( 'Installing Docker', 'beltoft-services' ),
			'database'         => __( 'Setting up the database', 'beltoft-services' ),
			'wordpress'        => __( 'Installing WordPress', 'beltoft-services' ),
			'web'              => __( 'Setting up the web server', 'beltoft-services' ),
			'coolify'          => __( 'Installing Coolify', 'beltoft-services' ),
			'n8n'              => __( 'Setting up n8n', 'beltoft-services' ),
			'odoo'             => __( 'Setting up Odoo', 'beltoft-services' ),
			'credentials'      => __( 'Saving the login', 'beltoft-services' ),
			'nextcloud'        => __( 'Setting up Nextcloud', 'beltoft-services' ),
			'easyappointments' => __( 'Setting up Easy!Appointments', 'beltoft-services' ),
			'plesk'            => __( 'Installing Plesk', 'beltoft-services' ),
			'licence'          => __( 'Applying the licence', 'beltoft-services' ),
			'firewall'         => __( 'Setting up the firewall', 'beltoft-services' ),
			'verify'           => __( 'Checking the install', 'beltoft-services' ),
			'install'          => __( 'Finishing', 'beltoft-services' ),
			'complete'         => __( 'Done', 'beltoft-services' ),
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
		$ip6    = \BeltoftServices\Proxmox\Vm\Panels::ip6( $r );
		// A server with IPv6 only is opened by its IPv6 address, which a URL writes in brackets.
		$host   = '' !== $domain ? $domain : ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : ( '' !== $ip6 ? '[' . $ip6 . ']' : '' ) );

		if ( '' === $host || ( ! isset( $open['path'] ) && ! isset( $open['port'] ) ) ) {
			return '';
		}

		$path = isset( $open['path'] ) && is_string( $open['path'] ) && 1 === preg_match( '#^/[A-Za-z0-9/_.-]*$#D', $open['path'] ) ? $open['path'] : '/';
		$port = isset( $open['port'] ) ? (int) $open['port'] : 0;

		$https = '' !== $domain && 0 === $port || ( $port > 0 && 'https' === ( $open['scheme'] ?? '' ) );

		return ( $https ? 'https://' : 'http://' ) . $host . ( $port > 0 ? ':' . $port : '' ) . $path;
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

		// A name on the customer's own DNS holds the install until it points at the server.
		$own_name  = (string) $r->get( 'app_domain_own' );
		$own_state = '' === $own_name ? '' : (string) $r->get( 'app_domain_dns' );
		$own_wait  = AppOwnDomain::waiting( $r );

		// A name that worked and does not any more is not the first wait and not the "installed on the address" end: the box says so.
		if ( AppOwnDomain::lost( $r ) ) {
			$own_state = 'lost';
		}

		if ( $own_wait && 'waiting' === $kind && 'ready' === $server ) {
			$kind = 'dns';
		}

		$own_since = AppOwnDomain::deadline( $r );
		$installed = (int) $r->get( 'app_installed_at' );
		$domain    = (string) $r->get( 'app_domain' );
		$reveal    = is_string( $r->get( 'app_reveal' ) ) && '' !== $r->get( 'app_reveal' ) && (int) $r->get( 'app_reveal_until' ) > time();
		// A version of another operating system (Plesk on AlmaLinux next to the Ubuntu one) is not an update.
		$newer     = null !== $current && '' !== $vid && $current['version_id'] !== $vid && ( null === $full || array() !== array_intersect( (array) $current['os'], (array) $full['os'] ) ) ? $current : null;
		$retired   = null !== $full && null === Apps::version( $id, $vid );
		$steps_n   = count( $order );
		$position  = false === $at ? 0 : min( $steps_n, (int) $at + 1 );
		$domain_error = (string) $r->get( 'app_domain_error' );
		$domain_reason = match ( $domain_error ) {
			'The name already has a DNS record.' => __( 'The name already has a DNS record.', 'beltoft-services' ),
			'DNS hosting is paused for this domain.' => __( 'DNS hosting is paused for this domain.', 'beltoft-services' ),
			default => '',
		};
		$left = (array) $r->get( 'app_domain_left' );
		$left_url = '';
		$user = (int) ( $r->license()['user_id'] ?? 0 );
		if ( '' !== (string) ( $left['zone'] ?? '' ) && $user > 0 ) {
			foreach ( \BeltoftLicenses\Licenses::where( 'user_id', $user ) as $license ) {
				$domain_resource = Resource::for_license( (int) $license['id'] );
				if ( $domain_resource && strtolower( (string) $domain_resource->get( 'domain' ) ) === $left['zone'] && ZoneAccess::editable( $domain_resource, $user ) ) {
					$left_url = ServiceArea::get( Domains::class )->url( $license, 'dns' );
					break;
				}
			}
		}

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
			'own'             => array(
				'name'     => $own_name,
				'state'    => $own_state,
				'ip'       => AppOwnDomain::address( $r ),
				'reason'   => (string) $r->get( 'app_domain_dns_reason' ),
				'deadline' => $own_since > 0 ? wp_date( get_option( 'date_format' ), $own_since ) : '',
				'days'     => \BeltoftServices\Domains\DnsWatchSettings::wait_days( AppDnsWatch::ID ),
			),
			'www'             => AppWww::view( $r ),
			'domain_support'  => ! empty( $app['domain'] ),
			'change'          => self::change_view( $r, $app ),
			'domain_wanted'   => (string) $r->get( 'app_domain_want' ),
			'domain_failed'   => '' !== (string) $r->get( 'app_domain_error' ),
			'domain_reason'   => $domain_reason,
			'domain_left'     => (string) ( $left['name'] ?? '' ),
			'domain_left_url' => $left_url,
			'licence'         => AppLicence::view( $r ),
			'can_retry'       => AppInstall::can_retry( $r ),
			'can_reveal'      => 'ready' === $kind && $reveal && $r->accepts_actions(),
			'getting_started' => (string) ( $app['getting_started'] ?? '' ),
			'docs_url'        => (string) ( $app['docs_url'] ?? '' ),
			'newer'           => null === $newer ? '' : (string) $newer['label'],
			'retired'         => $retired,
			'support_url'     => (string) apply_filters( 'beltoft_services_support_url', home_url( '/support/' ) ),
		);
	}

	/**
	 * What the Domain block shows: AppDomainChange::view() with every key present, the www name of an own name, and the days of the wait.
	 *
	 * @param array<string, mixed> $app Definition.
	 * @return array<string, mixed>
	 */
	public static function change_view( Resource $r, array $app ): array {
		$out = array(
			'supported' => false,
			'why'       => '',
			'state'     => '',
			'mode'      => '',
			'name'      => '',
			'reason'    => '',
			'ip'        => '',
			'deadline'  => '',
			'zones'     => array(),
			'current'   => '',
		);

		if ( empty( $app['domain'] ) ) {
			return $out;
		}

		$out = array_merge( $out, array_intersect_key( AppDomainChange::view( $r ), $out ) );

		// The date the wait ends: a time stamp from the flow, or already a date.
		$out['deadline'] = is_numeric( $out['deadline'] ) ? ( (int) $out['deadline'] > 0 ? wp_date( get_option( 'date_format' ), (int) $out['deadline'] ) : '' ) : (string) $out['deadline'];
		$out['www']      = 'own' === $out['mode'] && '' !== (string) $out['name'] ? AppWww::name_for( $r, (string) $out['name'] ) : '';
		$out['days']     = \BeltoftServices\Domains\DnsWatchSettings::wait_days( AppDnsWatch::ID );
		$out['minutes']  = (int) round( \BeltoftServices\Domains\DnsWatchSettings::look_seconds( AppDnsWatch::ID ) / MINUTE_IN_SECONDS );
		$changed         = (int) $r->get( 'app_domain_changed_at' );
		$out['changed']  = $changed > 0 && time() - $changed < 7 * DAY_IN_SECONDS && '' === (string) $out['state'] ? wp_date( get_option( 'date_format' ), $changed ) : '';

		return $out;
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
			$left = $r ? (array) $r->get( 'app_domain_left' ) : array();
			if ( '' !== (string) ( $left['name'] ?? '' ) && '' === (string) $r->get( 'app_id' ) ) {
				$user = (int) ( $r->license()['user_id'] ?? 0 );
				$url = '';
				foreach ( \BeltoftLicenses\Licenses::where( 'user_id', $user ) as $license ) {
					$domain = Resource::for_license( (int) $license['id'] );
					if ( $domain && strtolower( (string) $domain->get( 'domain' ) ) === (string) ( $left['zone'] ?? '' ) && ZoneAccess::editable( $domain, $user ) ) {
						$url = ServiceArea::get( Domains::class )->url( $license, 'dns' );
						break;
					}
				}
				echo '<p class="description" data-app-domain-left>';
				/* translators: %s: old domain name. */
				printf( esc_html__( 'The DNS record for %s was not made by us, so it was left in place.', 'beltoft-services' ), esc_html( (string) $left['name'] ) );
				if ( '' !== $url ) {
					echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'You can remove it in DNS records.', 'beltoft-services' ) . '</a>';
				}
				echo '</p>';
			}
			return;
		}

		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/app.php';
		} )( $view );
	}
}
