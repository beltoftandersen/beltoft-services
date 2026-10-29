<?php
/**
 * The Servers tab of My Account: the list, and one page per server with its own tabs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Product;
use BeltoftServices\Progress;
use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Beltoft Licenses prints one card per license on the Servers tab (My Account, endpoint `vps`).
 * This class takes over that one template call (WooCommerce's wc_get_template filter) and prints
 * instead:
 *
 *  - `/my-account/vps/`: one row per server (name, address, state, renewal and the CPU, memory
 *    and disk bars from the status the 15-minute power job already kept; no Proxmox call);
 *  - `/my-account/vps/<license id>/` and `/my-account/vps/<license id>/<tab>/`: the server's page.
 *    The tab is one of snapshots, backups, disks, access and reinstall; anything else is the
 *    overview. Every tab has its own address, so a link in an email or the Back button opens the
 *    right one. The page is the service panel in its page layout (see Panel::render()).
 *
 * A license that is not a server (an extra disk that is not folded into its server, say) is
 * still printed with the default card below the rows. The id must be one of the customer's own
 * server licenses: any other value shows the list with a notice.
 */
final class Servers {

	/**
	 * The tabs of a server page after the overview, by address part.
	 */
	public const TABS = array( 'snapshots', 'backups', 'disks', 'access', 'reinstall' );

	/**
	 * The page being printed (license row, requested tab), or null outside a server page.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $page = null;

	/**
	 * The default licenses template, kept while this class prints the list.
	 *
	 * @var string
	 */
	private static $default = '';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wc_get_template', array( __CLASS__, 'template' ), 10, 3 );
	}

	/**
	 * The endpoint the tab lives on.
	 */
	public static function slug(): string {
		/**
		 * Filters the My Account endpoint of the Servers tab (the slug of the tab declared by Tabs).
		 *
		 * @param string $slug Endpoint, default vps.
		 */
		$slug = sanitize_key( (string) apply_filters( 'beltoft_services_servers_slug', 'vps' ) );

		return '' !== $slug ? $slug : 'vps';
	}

	/**
	 * The address of a server's page or of one of its tabs.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $tab     Tab, or '' for the overview.
	 */
	public static function url( array $license, string $tab = '' ): string {
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		$value = (string) (int) $license['id'] . ( in_array( $tab, self::TABS, true ) ? '/' . $tab : '' );

		return wc_get_endpoint_url( self::slug(), $value, wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * The server page being printed, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function page(): ?array {
		return self::$page;
	}

	/**
	 * What the address after the endpoint says: the license id and the tab.
	 *
	 * @return array{id: int, tab: string}
	 */
	public static function requested(): array {
		global $wp;

		$value = is_object( $wp ) && isset( $wp->query_vars[ self::slug() ] ) ? (string) $wp->query_vars[ self::slug() ] : '';
		$parts = explode( '/', trim( sanitize_text_field( $value ), '/' ) );
		$id    = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
		$tab   = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';

		return array(
			'id'  => $id,
			'tab' => in_array( $tab, self::TABS, true ) ? $tab : '',
		);
	}

	/**
	 * Listener of wc_get_template: the licenses template on the Servers tab becomes the list or the page.
	 *
	 * @param mixed $located  Path of the template WooCommerce found.
	 * @param mixed $name     Template name.
	 * @param mixed $args     Template arguments.
	 * @return mixed
	 */
	public static function template( $located, $name = '', $args = array() ) {
		if ( 'myaccount/licenses.php' !== $name || ! is_array( $args ) || ! isset( $args['licenses'] ) || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::slug() ) ) {
			return $located;
		}

		self::$default = is_string( $located ) ? $located : '';

		return wc_locate_template( 'servers.php', 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );
	}

	/**
	 * Prints the Servers tab for the licenses the customer has on it. Called by the template.
	 *
	 * @param array<int, array<string, mixed>> $views     Display values of the licenses on the tab (BeltoftLicenses View::license()).
	 * @param string                           $empty     Text for an empty tab.
	 */
	public static function render( array $views, string $empty = '' ): void {
		$servers = array();
		$others  = array();

		foreach ( $views as $view ) {
			if ( is_array( $view ) && isset( $view['license'] ) && self::is_server( $view['license'] ) ) {
				$servers[ (int) $view['id'] ] = $view;
			} else {
				$others[] = $view;
			}
		}

		$asked = self::requested();

		if ( $asked['id'] > 0 && isset( $servers[ $asked['id'] ] ) ) {
			self::detail( $servers[ $asked['id'] ], $asked['tab'] );

			return;
		}

		if ( $asked['id'] > 0 ) {
			echo '<p class="beltoft-services-notice" role="status">' . esc_html__( 'That server was not found. Here are your servers.', 'beltoft-services' ) . '</p>';
		}

		self::list( $servers, $others, $empty );
	}

	/**
	 * Is the license a server (a virtual machine), not an extra disk or a snapshot pack?
	 *
	 * @param mixed $license License row.
	 */
	public static function is_server( $license ): bool {
		if ( ! is_array( $license ) || empty( $license['id'] ) ) {
			return false;
		}

		$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );

		return $driver && 'vm' === $driver->kind();
	}

	/**
	 * The list: rows, then the cards of anything else on the tab.
	 *
	 * @param array<int, array<string, mixed>> $servers Views of the server licenses.
	 * @param array<int, array<string, mixed>> $others  Views of the other licenses on the tab.
	 * @param string                           $empty   Text for an empty tab.
	 */
	private static function list( array $servers, array $others, string $empty ): void {
		$rows = self::rows( $servers );

		if ( $rows ) {
			include self::file( 'servers-list.php' );
		}

		if ( $others && '' !== self::$default && is_readable( self::$default ) ) {
			$licenses    = $others;
			$account_url = wc_get_account_endpoint_url( self::slug() );
			$empty       = '';
			include self::$default;
		} elseif ( ! $rows && ! $others ) {
			echo '<p class="beltoft-licenses__empty">' . esc_html( '' !== $empty ? $empty : __( 'You have no servers yet.', 'beltoft-services' ) ) . '</p>';
		}
	}

	/**
	 * One server's page.
	 *
	 * @param array<string, mixed> $view License view.
	 * @param string               $tab  Requested tab.
	 */
	private static function detail( array $view, string $tab ): void {
		self::$page = array(
			'license' => $view['license'],
			'tab'     => $tab,
		);

		try {
			$list = self::url_list();
			include self::file( 'server.php' );
		} finally {
			self::$page = null;
		}
	}

	/**
	 * The address of the list.
	 */
	public static function url_list(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::slug() ) : '';
	}

	/**
	 * A template of this plugin, or the theme's copy of it.
	 *
	 * @param string $name File name in templates/account.
	 */
	private static function file( string $name ): string {
		return wc_locate_template( $name, 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );
	}

	/**
	 * The renewal sentence of a license ("Renews 29/11/2026", "Ends 29/11/2026"), or '' when nothing is known.
	 * From the subscription's own dates; one object load.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function renewal( array $license ): string {
		$id = (int) ( $license['subscription_id'] ?? 0 );

		if ( $id <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$subscription = wcs_get_subscription( $id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		$format = get_option( 'date_format' );

		if ( $subscription->has_status( 'pending-cancel' ) ) {
			$end = (int) $subscription->get_time( 'end', 'site' );

			/* translators: %s: date the subscription ends */
			return $end > 0 ? sprintf( __( 'Ends %s', 'beltoft-services' ), date_i18n( $format, $end ) ) : '';
		}

		$next = (int) $subscription->get_time( 'next_payment', 'site' );

		/* translators: %s: date of the next payment */
		return $next > 0 && $subscription->has_status( 'active' ) ? sprintf( __( 'Renews %s', 'beltoft-services' ), date_i18n( $format, $next ) ) : '';
	}

	/**
	 * The rows of the list: plain values, ready to print. Reads only what the site already holds
	 * (resource meta, the cached status, the guest disk reading, the subscription): no Proxmox call.
	 *
	 * @param array<int, array<string, mixed>> $views License views of servers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( array $views ): array {
		$rows = array();

		foreach ( $views as $view ) {
			$license  = $view['license'];
			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource ) {
				continue;
			}

			$rows[] = self::row( $view, $license, $resource );
		}

		return $rows;
	}

	/**
	 * One row.
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param array<string, mixed> $license  License row.
	 * @param Resource             $resource Resource.
	 * @return array<string, mixed>
	 */
	private static function row( array $view, array $license, Resource $resource ): array {
		$state  = (string) $resource->get( 'state' );
		$group  = Panel::group( $state );
		$status = Operations::cached_status( $resource->id() );
		$power  = null === $status ? '' : (string) $status['power'];
		$busy   = (bool) Progress::build( $resource )['active'];
		$plan   = (array) $resource->get( 'plan' );
		$ip     = (string) $resource->get( 'ip' );
		$title  = (string) ( $view['product'] ?? '' );
		$kind   = \BeltoftLicenses\Account\View::product_name( $license );

		if ( $busy ) {
			$text = __( 'Working', 'beltoft-services' );
			$tone = 'busy';
		} elseif ( 'ready' === $group && ! $resource->on_hold() && 'running' === $power ) {
			$text = __( 'Running', 'beltoft-services' );
			$tone = 'ok';
		} elseif ( 'ready' === $group && ! $resource->on_hold() && 'stopped' === $power ) {
			$text = __( 'Stopped', 'beltoft-services' );
			$tone = 'off';
		} else {
			$text = Panel::label( $state, $resource );
			$tone = $resource->on_hold() || 'failed' === $group ? 'bad' : ( 'ready' === $group ? 'ok' : ( 'getting-ready' === $group ? 'busy' : 'off' ) );
		}

		$bars = array();

		if ( 'ready' === $group && ! $busy && null !== $status && 'running' === $power ) {
			$bars[] = array(
				'label'   => __( 'CPU', 'beltoft-services' ),
				'percent' => max( 0, min( 100, (int) round( (float) $status['cpu'] * 100 ) ) ),
			);

			$memory = (int) ( $plan['memory_mb'] ?? 0 ) * 1048576;

			if ( $memory > 0 ) {
				$bars[] = array(
					'label'   => __( 'Memory', 'beltoft-services' ),
					'percent' => max( 0, min( 100, (int) round( (int) $status['mem'] / $memory * 100 ) ) ),
				);
			}

			$disk = GuestDisk::cached( $resource );

			if ( null !== $disk ) {
				$bars[] = array(
					'label'   => __( 'Disk', 'beltoft-services' ),
					'percent' => max( 0, min( 100, (int) round( $disk['used'] / $disk['total'] * 100 ) ) ),
				);
			}
		}

		$parts = array();

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts[] = $ip;
		}

		if ( '' !== $kind && $kind !== $title ) {
			$parts[] = $kind;
		}

		$os = self::os_label( $resource );

		if ( '' !== $os ) {
			$parts[] = $os;
		}

		return array(
			'id'      => $resource->id(),
			'url'     => self::url( $license ),
			'name'    => $title,
			'details' => implode( ' · ', $parts ),
			'state'   => $text,
			'tone'    => $tone,
			'renews'  => self::renewal( $license ),
			'bars'    => $bars,
		);
	}

	/**
	 * The operating system's label: the customer's choice, else the plan's template.
	 *
	 * @param Resource $r Resource.
	 */
	private static function os_label( Resource $r ): string {
		$record = VmRecords::for_resource( $r->id() );

		if ( null === $record ) {
			return '';
		}

		$plan   = (array) $r->get( 'plan' );
		$chosen = (int) $r->get( OsField::META );
		$chosen = $chosen > 0 ? $chosen : (int) ( $plan['template'] ?? 0 );
		$list   = OsField::options_for( (string) $record['cluster'] );

		return (string) ( $list[ $chosen ] ?? '' );
	}
}
