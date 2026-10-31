<?php
/**
 * The Servers tab of My Account: the list, and one page per server with its own tabs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Progress;
use BeltoftServices\Proxmox\DiskDriver;
use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\SnapshotDriver;
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
 * An extra disk or a snapshot pack whose server is in the list is not printed on the list: it lives
 * on the server's Disks and Snapshots tabs, and its own address (/vps/<its license id>/) redirects
 * there (see redirect()). One without a server in the list is printed as a plain card below the rows,
 * and any other license on the tab with the default card. The id must be one of the customer's own
 * server licenses: any other value shows the list with a notice.
 */
final class Servers {

	/**
	 * The tabs of a server page after the overview, by address part.
	 */
	public const TABS = array( 'snapshots', 'backups', 'disks', 'access', 'reinstall' );

	/**
	 * The value after the endpoint that is the New server page (the picker).
	 */
	public const NEW_VALUE = 'new';

	/**
	 * The tab addresses a server page accepts: the built-in ones, plus any an extension adds.
	 *
	 * @return array<int, string>
	 */
	public static function tabs(): array {
		/**
		 * Filters the tab addresses of a server page. An extension that adds a tab (see the filter
		 * beltoft_services_server_tabs) adds its slug here so the tab has its own address.
		 *
		 * @param array<int, string> $slugs Slugs after the overview.
		 */
		$slugs = apply_filters( 'beltoft_services_server_page_tabs', self::TABS );

		return array_values( array_filter( is_array( $slugs ) ? $slugs : self::TABS, 'is_string' ) );
	}

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
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ) );
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

		$value = Slugs::value( $license, in_array( $tab, self::tabs(), true ) ? $tab : '' );

		return wc_get_endpoint_url( self::slug(), $value, wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * The address of the New server page (the picker).
	 *
	 * @param int $plan Variation id to start with, 0 for none.
	 */
	public static function url_new( int $plan = 0 ): string {
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		$url = wc_get_endpoint_url( self::slug(), self::NEW_VALUE, wc_get_page_permalink( 'myaccount' ) );

		return $plan > 0 ? add_query_arg( 'plan', $plan, $url ) : $url;
	}

	/**
	 * Is the address the New server page (the value after the endpoint is "new")?
	 */
	public static function is_new(): bool {
		global $wp;

		$value = is_object( $wp ) && isset( $wp->query_vars[ self::slug() ] ) ? (string) $wp->query_vars[ self::slug() ] : '';
		$parts = explode( '/', trim( sanitize_text_field( $value ), '/' ) );

		return self::NEW_VALUE === $parts[0];
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
	 * What the address after the endpoint says: the license id (from the server's slug, or the number itself) and the tab.
	 *
	 * @return array{id: int, tab: string}
	 */
	public static function requested(): array {
		$asked = Slugs::parse( self::slug(), 'servers' );

		return array(
			'id'  => $asked['id'],
			'tab' => in_array( $asked['second'], self::tabs(), true ) ? $asked['second'] : '',
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
		if ( self::is_new() ) {
			ServerPicker::render();

			return;
		}

		$servers   = array();
		$resources = array();
		$others    = array();

		foreach ( $views as $view ) {
			if ( is_array( $view ) && isset( $view['license'] ) && self::is_server( $view['license'] ) ) {
				$id = (int) $view['id'];

				$servers[ $id ] = $view;

				// One lookup per server: the rows and the page take the resource from here.
				$resource = Resource::for_license( (int) $view['license']['id'] );

				if ( $resource ) {
					$resources[ $id ] = $resource;
				}
			} else {
				$others[] = $view;
			}
		}

		// An add-on whose server is in the list is shown on that server's page, not here.
		$others = array_values(
			array_filter(
				$others,
				static function ( $view ) use ( $servers ): bool {
					$parent = self::parent_of( $view );

					return null === $parent || 0 === $parent['license_id'] || ! isset( $servers[ $parent['license_id'] ] );
				}
			)
		);

		$asked = self::requested();

		if ( $asked['id'] > 0 && isset( $servers[ $asked['id'] ] ) ) {
			self::detail( $servers[ $asked['id'] ], $asked['tab'], $resources[ $asked['id'] ] ?? null );

			return;
		}

		if ( $asked['id'] > 0 || Slugs::unknown( Slugs::parse( self::slug(), 'servers' ) ) ) {
			echo '<p class="beltoft-services-notice" role="status">' . esc_html__( 'That server was not found. Here are your servers.', 'beltoft-services' ) . '</p>';
		}

		self::list( $servers, $others, $empty, $resources );
	}

	/**
	 * What an extra disk or a snapshot pack is, from its view (see parent_of_license()); null for any other license.
	 *
	 * @param mixed $view License view.
	 * @return array{license_id: int, tab: string, resource: Resource}|null
	 */
	private static function parent_of( $view ): ?array {
		if ( ! is_array( $view ) || empty( $view['license']['id'] ) ) {
			return null;
		}

		return self::parent_of_license( (int) $view['license']['id'] );
	}

	/**
	 * What an extra disk or a snapshot pack license is: the license id of its server (0 when none is linked), the tab it
	 * lives on, and its resource. Null for any other license.
	 *
	 * @param int $license_id Add-on license id.
	 * @return array{license_id: int, tab: string, resource: Resource}|null
	 */
	public static function parent_of_license( int $license_id ): ?array {
		$resource = Resource::for_license( $license_id );

		if ( ! $resource ) {
			return null;
		}

		$driver = (string) $resource->get( 'driver' );

		if ( DiskDriver::ID !== $driver && SnapshotDriver::ID !== $driver && \BeltoftServices\Proxmox\AppLicenceDriver::ID !== $driver ) {
			return null;
		}

		return array(
			'license_id' => max( 0, (int) $resource->get( 'server_license' ) ),
			'tab'        => DiskDriver::ID === $driver ? 'disks' : ( SnapshotDriver::ID === $driver ? 'snapshots' : \BeltoftServices\Proxmox\AppTab::SLUG ),
			'resource'   => $resource,
		);
	}

	/**
	 * Early on a My Account address: the page of an extra disk or a snapshot pack (its own license id) goes to the
	 * server's Disks or Snapshots tab.
	 */
	public static function redirect(): void {
		$target = self::redirect_target();

		if ( '' !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}

		// A server's page by license id goes to its slug address.
		$target = Slugs::redirect_target( self::slug(), 'servers', array( __CLASS__, 'url' ) );

		if ( '' !== $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * Where the requested address should go instead, or '' when it stays. Only for the customer's own add-on and the
	 * server it is on.
	 */
	public static function redirect_target(): string {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::slug() ) || ! is_user_logged_in() ) {
			return '';
		}

		$asked = self::requested();

		if ( $asked['id'] < 1 ) {
			return '';
		}

		$addon = Licenses::find( $asked['id'] );

		if ( ! $addon || (int) ( $addon['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return '';
		}

		$parent = self::parent_of_license( $asked['id'] );
		$server = $parent && $parent['license_id'] > 0 ? Licenses::find( $parent['license_id'] ) : null;

		if ( ! $parent || ! $server || (int) ( $server['user_id'] ?? 0 ) !== get_current_user_id() || ! self::is_server( $server ) ) {
			return '';
		}

		$anchor = 'disks' === $parent['tab'] ? '#' . \BeltoftServices\Proxmox\DiskGuide::anchor( $parent['resource']->id() ) : '';

		return self::url( $server, $parent['tab'] ) . $anchor;
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
	 * @param array<int, Resource>             $resources Resources of the servers, by license view id.
	 */
	private static function list( array $servers, array $others, string $empty, array $resources = array() ): void {
		$rows    = self::rows( $servers, $resources );
		$buttons = array(
			array(
				'label' => __( 'Create a server', 'beltoft-services' ),
				'url'   => self::url_new(),
			),
		);

		if ( $rows ) {
			ServiceArea::actions( $buttons, false );
			\BeltoftServices\Proxmox\LiveUsage::enqueue();
			include self::file( 'servers-list.php' );
		}

		$addons = array();
		$rest   = array();

		foreach ( $others as $other ) {
			// An add-on whose server is gone (an ended disk or snapshot pack) is a compact row like the others, not a
			// full license box: its state, and the subscription link.
			$card = self::addon_card( $other ) ?? self::ended_card( $other );

			if ( $card ) {
				$addons[] = $card;
			} else {
				$rest[] = $other;
			}
		}

		if ( $addons ) {
			include self::file( 'addons.php' );
		}

		$others = $rest;

		if ( $others && '' !== self::$default && is_readable( self::$default ) ) {
			$licenses    = $others;
			$account_url = wc_get_account_endpoint_url( self::slug() );
			$empty       = '';
			include self::$default;
		} elseif ( ! $rows && ! $others && ! $addons ) {
			echo '<p class="beltoft-licenses__empty">' . esc_html( '' !== $empty ? $empty : __( 'You have no servers yet.', 'beltoft-services' ) ) . '</p>';
			ServiceArea::actions( $buttons, true );
		}
	}

	/**
	 * One server's page.
	 *
	 * @param array<string, mixed> $view License view.
	 * @param string               $tab  Requested tab.
	 * @param Resource|null        $resource The server's resource, when already loaded.
	 */
	private static function detail( array $view, string $tab, ?Resource $resource = null ): void {
		self::$page = array(
			'license'  => $view['license'],
			'tab'      => $tab,
			'resource' => $resource,
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
	 * @param array<int, array<string, mixed>> $views     License views of servers.
	 * @param array<int, Resource>             $resources Resources already loaded, by license view id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( array $views, array $resources = array() ): array {
		$rows = array();

		foreach ( $views as $view ) {
			$license  = $view['license'];
			$resource = $resources[ (int) ( $view['id'] ?? 0 ) ] ?? Resource::for_license( (int) $license['id'] );

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
		$group  = Panel::group( $state, $resource );
		$status = Operations::cached_status( $resource->id() );
		$power  = null === $status ? '' : (string) $status['power'];
		$busy   = (bool) Progress::build( $resource )['active'];
		$plan   = (array) $resource->get( 'plan' );
		$ip     = (string) $resource->get( 'ip' );
		$title  = (string) ( $view['product'] ?? '' );
		$kind   = \BeltoftLicenses\Account\View::product_name( $license );

		$app = $resource->on_hold() ? '' : \BeltoftServices\Proxmox\AppProgress::line( $resource );

		if ( '' !== $app ) {
			// The server is up but its app is not done: the row says so, never "Running" or "Ready".
			$text = $app;
			$tone = 'failed' === \BeltoftServices\Proxmox\AppProgress::state( $resource ) ? 'bad' : 'busy';
		} elseif ( $busy ) {
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
				'key'     => 'cpu',
				'label'   => __( 'CPU', 'beltoft-services' ),
				'percent' => max( 0, min( 100, (int) round( (float) $status['cpu'] * 100 ) ) ),
			);

			$memory = (int) ( $plan['memory_mb'] ?? 0 ) * 1048576;
			// The guest's own figure (MemAvailable) when a fresh reading is kept: PVE counts page cache as used.
			$guest = \BeltoftServices\Proxmox\LiveUsage::cached_memory( $resource->id() );

			if ( null !== $guest ) {
				$bars[] = array(
					'key'     => 'mem',
					'label'   => __( 'Memory', 'beltoft-services' ),
					'percent' => $guest,
				);
			} elseif ( $memory > 0 ) {
				$bars[] = array(
					'key'     => 'mem',
					'label'   => __( 'Memory', 'beltoft-services' ),
					'percent' => max( 0, min( 100, (int) round( (int) $status['mem'] / $memory * 100 ) ) ),
				);
			}

			$disk = GuestDisk::cached( $resource );

			if ( null !== $disk ) {
				$bars[] = array(
					'key'     => 'disk',
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
			'note'    => \BeltoftServices\Proxmox\Quarantine::active( $resource ) ? __( 'Outgoing internet traffic is blocked', 'beltoft-services' ) : '',
			'bars'    => $bars,
		);
	}

	/**
	 * A plain card for an extra disk or a snapshot pack whose server is not in the list: its name, a status in plain
	 * text and what its driver prints. Null for anything else.
	 *
	 * @param mixed $view License view.
	 * @return array{name: string, state: string, body: string}|null
	 */
	private static function addon_card( $view ): ?array {
		$parent = self::parent_of( $view );

		if ( ! $parent ) {
			return null;
		}

		$resource = $parent['resource'];
		$license  = $view['license'];
		$driver   = Registry::get( (string) $resource->get( 'driver' ) );
		$owner    = ! empty( $license['user_id'] ) && (int) $license['user_id'] === get_current_user_id();
		$body     = '';

		if ( $driver && $owner ) {
			ob_start();
			$driver->render_account( $resource );
			$body = (string) ob_get_clean();
		}

		return array(
			'name'  => (string) ( $view['product'] ?? '' ),
			'state' => Panel::label( (string) $resource->get( 'state' ), $resource ),
			'body'  => $body,
		);
	}

	/**
	 * A compact row for a license on this tab that has no server to sit under (an ended add-on): its name, its
	 * state (with the date when the license gives one) and the subscription link.
	 *
	 * @param mixed $view License view.
	 * @return array{name: string, state: string, body: string}|null
	 */
	private static function ended_card( $view ): ?array {
		if ( ! is_array( $view ) || '' === (string) ( $view['product'] ?? '' ) ) {
			return null;
		}

		$label  = (string) ( $view['status_label'] ?? '' );
		$expiry = trim( (string) ( $view['expiry'] ?? '' ) );
		$state  = '' !== $label && 0 === strpos( $expiry, $label ) ? $expiry : $label;
		$url    = (string) ( $view['subscription'] ?? '' );

		return array(
			'name'  => (string) $view['product'],
			'state' => $state,
			'body'  => '' !== $url ? '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage subscription', 'beltoft-services' ) . '</a></p>' : '',
		);
	}

	/**
	 * The operating system's label: the customer's choice, else the plan's template.
	 *
	 * @param Resource $r Resource.
	 */
	public static function os_label( Resource $r ): string {
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
