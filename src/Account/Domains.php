<?php
/**
 * The Domains tab of My Account: the list, one page per domain with its own tabs, and the add page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsClaimsFeature;
use BeltoftServices\Domains\DnsClaimPanel;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Beltoft Licenses prints one card per license on the Domains tab (endpoint `domains`). This class takes over
 * that one template call, the way Servers does for the Servers tab, and prints instead:
 *
 *  - `/my-account/domains/`: one row per domain and DNS hosting service, then the open DNS hosting claims;
 *  - `/my-account/domains/<license id>/` and `/my-account/domains/<license id>/<tab>/`: the domain's page, one
 *    address per tab (dns, nameservers, transfer, danger; anything else is the overview);
 *  - `/my-account/domains/add/`: adding a domain for DNS hosting.
 *
 * An id that is not one of the customer's own domains shows the list with a notice.
 */
final class Domains {

	/**
	 * The tabs of a domain page after the overview, by address part.
	 */
	public const TABS = array( 'dns', 'nameservers', 'transfer', 'danger' );

	/**
	 * The value after the endpoint that is the add page.
	 */
	public const ADD_VALUE = 'add';

	/**
	 * The page being printed (license row, requested tab, resource), or null outside a domain page.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $page = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wc_get_template', array( __CLASS__, 'template' ), 10, 3 );
	}

	/**
	 * The tab addresses a domain page accepts: the built-in ones, plus any an extension adds.
	 *
	 * @return array<int, string>
	 */
	public static function tabs(): array {
		/**
		 * Filters the tab addresses of a domain page. An extension that adds a tab (see the filter
		 * beltoft_services_domain_tabs) adds its slug here so the tab has its own address.
		 *
		 * @param array<int, string> $slugs Slugs after the overview.
		 */
		$slugs = apply_filters( 'beltoft_services_domain_page_tabs', self::TABS );

		return array_values( array_filter( is_array( $slugs ) ? $slugs : self::TABS, 'is_string' ) );
	}

	/**
	 * The endpoint the tab lives on.
	 */
	public static function slug(): string {
		/**
		 * Filters the My Account endpoint of the Domains tab (the slug of the tab declared by Tabs).
		 *
		 * @param string $slug Endpoint, default domains.
		 */
		$slug = sanitize_key( (string) apply_filters( 'beltoft_services_domains_slug', 'domains' ) );

		return '' !== $slug ? $slug : 'domains';
	}

	/**
	 * The address of a domain's page or of one of its tabs.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $tab     Tab, or '' for the overview.
	 */
	public static function url( array $license, string $tab = '' ): string {
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		$value = (string) (int) $license['id'] . ( in_array( $tab, self::tabs(), true ) ? '/' . $tab : '' );

		return wc_get_endpoint_url( self::slug(), $value, wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * The address of the add page.
	 */
	public static function url_add(): string {
		return function_exists( 'wc_get_endpoint_url' ) ? wc_get_endpoint_url( self::slug(), self::ADD_VALUE, wc_get_page_permalink( 'myaccount' ) ) : '';
	}

	/**
	 * The address of the list.
	 */
	public static function url_list(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::slug() ) : '';
	}

	/**
	 * The site's page for registering a new domain (setting domain_search_url), or '' when none is set.
	 */
	public static function url_register(): string {
		$url = trim( (string) Settings::get( 'domain_search_url' ) );

		// A path on this site, or a full address; a scheme-less //host is neither.
		if ( '' === $url || 0 === strpos( $url, '//' ) ) {
			return '';
		}

		return esc_url_raw( 0 === strpos( $url, '/' ) ? home_url( $url ) : $url );
	}

	/**
	 * The domain page being printed, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function page(): ?array {
		return self::$page;
	}

	/**
	 * What the address after the endpoint says: the license id, the tab, and whether it is the add page.
	 *
	 * @return array{id: int, tab: string, add: bool}
	 */
	public static function requested(): array {
		global $wp;

		$value = is_object( $wp ) && isset( $wp->query_vars[ self::slug() ] ) ? (string) $wp->query_vars[ self::slug() ] : '';
		$parts = explode( '/', trim( sanitize_text_field( $value ), '/' ) );
		$id    = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
		$tab   = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';

		return array(
			'id'  => $id,
			'tab' => in_array( $tab, self::tabs(), true ) ? $tab : '',
			'add' => self::ADD_VALUE === $parts[0],
		);
	}

	/**
	 * Is the license a domain or DNS hosting service?
	 *
	 * @param mixed $license License row.
	 */
	public static function is_domain( $license ): bool {
		if ( ! is_array( $license ) || empty( $license['id'] ) ) {
			return false;
		}

		$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );

		return $driver && in_array( $driver->kind(), array( 'domain', 'dns' ), true );
	}

	/**
	 * Listener of wc_get_template: the licenses template on the Domains tab becomes the list or the page.
	 *
	 * @param mixed $located Path of the template WooCommerce found.
	 * @param mixed $name    Template name.
	 * @param mixed $args    Template arguments.
	 * @return mixed
	 */
	public static function template( $located, $name = '', $args = array() ) {
		if ( 'myaccount/licenses.php' !== $name || ! is_array( $args ) || ! isset( $args['licenses'] ) || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::slug() ) ) {
			return $located;
		}

		return wc_locate_template( 'domains.php', 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );
	}

	/**
	 * Prints the Domains tab for the licenses the customer has on it. Called by the template.
	 *
	 * @param array<int, array<string, mixed>> $views License views (BeltoftLicenses View::license()).
	 * @param string                           $empty Text for an empty tab.
	 */
	public static function render( array $views, string $empty = '' ): void {
		$asked = self::requested();

		if ( $asked['add'] ) {
			self::add();

			return;
		}

		$domains   = array();
		$resources = array();

		foreach ( $views as $view ) {
			if ( ! is_array( $view ) || ! isset( $view['license'] ) || ! self::is_domain( $view['license'] ) ) {
				continue;
			}

			$resource = Resource::for_license( (int) $view['license']['id'] );

			// A DNS service folded into a registration is history: not listed, not opened.
			if ( ! $resource || $resource->folded_into() > 0 ) {
				continue;
			}

			$domains[ (int) $view['license']['id'] ]   = $view;
			$resources[ (int) $view['license']['id'] ] = $resource;
		}

		if ( $asked['id'] > 0 && isset( $domains[ $asked['id'] ] ) ) {
			self::detail( $domains[ $asked['id'] ], $asked['tab'], $resources[ $asked['id'] ] );

			return;
		}

		if ( $asked['id'] > 0 ) {
			echo '<p class="beltoft-services-notice" role="status">' . esc_html__( 'That domain was not found. Here are your domains.', 'beltoft-services' ) . '</p>';
		}

		self::list( $domains, $resources, $empty );
	}

	/**
	 * The rows of the list: plain values, ready to print, the same as the Overview's.
	 *
	 * @param array<int, array<string, mixed>> $views     Domain views by license id.
	 * @param array<int, Resource>             $resources Their resources by license id.
	 * @return array<int, array<string, string>>
	 */
	public static function rows( array $views, array $resources ): array {
		$rows = array();

		foreach ( $views as $license_id => $view ) {
			if ( isset( $resources[ $license_id ] ) ) {
				$rows[] = DomainRows::row( $view, $resources[ $license_id ] );
			}
		}

		return $rows;
	}

	/**
	 * Rows for the customer's open DNS hosting claims, first in the list. Empty when the feature is off.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array<string, string>>
	 */
	public static function claim_rows( int $user_id ): array {
		if ( $user_id <= 0 || ! DnsClaimsFeature::enabled() || ! DnsSchema::ready() ) {
			return array();
		}

		$rows = array();

		foreach ( DnsClaims::for_user( $user_id ) as $claim ) {
			if ( ! in_array( $claim['status'], array( 'pending', 'checking', 'verified' ), true ) ) {
				continue;
			}

			$rows[] = array(
				'id'     => 'claim-' . (int) $claim['id'],
				'url'    => self::url_add(),
				'name'   => (string) $claim['domain'],
				'sub'    => __( 'DNS hosting', 'beltoft-services' ),
				'state'  => 'verified' === $claim['status'] ? __( 'Verified, finish adding', 'beltoft-services' ) : __( 'Waiting for verification', 'beltoft-services' ),
				'tone'   => 'busy',
				'renews' => '',
			);
		}

		return $rows;
	}

	/**
	 * The header buttons: label => address. Add a domain needs DNS hosting claims on; Register a domain needs a
	 * search page address in the settings.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	public static function buttons(): array {
		$buttons = array();

		if ( DnsClaimsFeature::enabled() ) {
			$buttons[] = array(
				'label' => __( 'Add a domain', 'beltoft-services' ),
				'url'   => self::url_add(),
			);
		}

		$register = self::url_register();

		if ( '' !== $register ) {
			$buttons[] = array(
				'label' => __( 'Register a domain', 'beltoft-services' ),
				'url'   => $register,
			);
		}

		return $buttons;
	}

	/**
	 * The list: header buttons, claim rows, domain rows, or a plain line when there is nothing.
	 *
	 * @param array<int, array<string, mixed>> $views     Domain views by license id.
	 * @param array<int, Resource>             $resources Resources by license id.
	 * @param string                           $empty     Text for an empty tab.
	 */
	private static function list( array $views, array $resources, string $empty ): void {
		$rows    = array_merge( self::claim_rows( get_current_user_id() ), self::rows( $views, $resources ) );
		$buttons = self::buttons();
		$empty   = '' !== $empty ? $empty : __( 'You have no domains yet.', 'beltoft-services' );

		include self::file( 'domains-list.php' );
	}

	/**
	 * The add page: a back link and the DNS hosting claim section.
	 */
	private static function add(): void {
		echo '<div class="beltoft-services-server beltoft-services-domains-add">';
		echo '<p class="beltoft-services-server__back"><a href="' . esc_url( self::url_list() ) . '">&lsaquo; ' . esc_html__( 'All domains', 'beltoft-services' ) . '</a></p>';
		DnsClaimPanel::render();
		echo '</div>';
	}

	/**
	 * One domain's page.
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param string               $tab      Requested tab.
	 * @param Resource             $resource The domain's resource.
	 */
	private static function detail( array $view, string $tab, Resource $resource ): void {
		self::$page = array(
			'license'  => $view['license'],
			'tab'      => $tab,
			'resource' => $resource,
		);

		try {
			echo '<div class="beltoft-services-server beltoft-services-domain-page">';
			echo '<p class="beltoft-services-server__back"><a href="' . esc_url( self::url_list() ) . '">&lsaquo; ' . esc_html__( 'All domains', 'beltoft-services' ) . '</a></p>';
			Panel::render( $view['license'], $view );
			echo '</div>';
		} finally {
			self::$page = null;
		}
	}

	/**
	 * A template of this plugin, or the theme's copy of it.
	 *
	 * @param string $name File name in templates/account.
	 */
	public static function file( string $name ): string {
		return wc_locate_template( $name, 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );
	}
}
