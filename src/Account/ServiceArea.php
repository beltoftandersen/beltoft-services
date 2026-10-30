<?php
/**
 * A My Account area for one kind of service: a list of rows, and one page per service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The shared part of the Databases and Apps areas. Like Servers and Domains, an area takes over the licenses
 * template of Beltoft Licenses on its own endpoint (WooCommerce's wc_get_template filter) and prints:
 *
 *  - `/my-account/<slug>/`: one row per service (templates/account/area-list.php);
 *  - `/my-account/<slug>/<license id>/` and `/my-account/<slug>/<license id>/<tab>/`: the service's page, the
 *    service panel in its page layout (see Panel::render()), with the tabs the area names.
 *
 * An id that is not one of the customer's own services of the kind shows the list with a notice. A subclass names
 * the endpoint, the driver kinds, the texts and the row; it prints nothing itself.
 */
abstract class ServiceArea {

	/**
	 * The areas by class name, built once.
	 *
	 * @var array<string, ServiceArea>
	 */
	private static $areas = array();

	/**
	 * The area whose template call is being answered.
	 *
	 * @var ServiceArea|null
	 */
	private static $active = null;

	/**
	 * The page being printed (license row, requested tab, resource, area), or null outside a service page.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $page = null;

	/**
	 * Every area, in tab order.
	 *
	 * @return array<int, ServiceArea>
	 */
	public static function all(): array {
		return array( self::get( Databases::class ), self::get( Apps::class ) );
	}

	/**
	 * One area.
	 *
	 * @param string $class Class name.
	 */
	public static function get( string $class ): ServiceArea {
		if ( ! isset( self::$areas[ $class ] ) ) {
			self::$areas[ $class ] = new $class();
		}

		return self::$areas[ $class ];
	}

	/**
	 * Hooks of every area.
	 */
	public static function register(): void {
		add_filter( 'wc_get_template', array( __CLASS__, 'template' ), 10, 3 );
		add_filter( 'beltoft_services_account_page_head', array( __CLASS__, 'head' ), 10, 5 );
	}

	/**
	 * The service page being printed, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function page(): ?array {
		return self::$page;
	}

	/**
	 * The area a My Account endpoint belongs to, or null.
	 *
	 * @param string $slug Endpoint.
	 */
	public static function for_slug( string $slug ): ?ServiceArea {
		foreach ( self::all() as $area ) {
			if ( $area->slug() === $slug ) {
				return $area;
			}
		}

		return null;
	}

	/**
	 * The default endpoint.
	 */
	abstract protected function default_slug(): string;

	/**
	 * The driver kinds that belong to the area.
	 *
	 * @return array<int, string>
	 */
	abstract protected function kinds(): array;

	/**
	 * The tab label and the heading.
	 */
	abstract public function label(): string;

	/**
	 * Text of an empty list.
	 */
	abstract protected function empty_text(): string;

	/**
	 * The "back to the list" link text.
	 */
	abstract public function all_label(): string;

	/**
	 * The notice for an unknown id.
	 */
	abstract protected function not_found_text(): string;

	/**
	 * The row of a service: id, url, name, details, state, tone (ok, busy, off, bad), extra (a third column), action (label and url, or empty).
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 * @return array<string, mixed>
	 */
	abstract public function row( array $view, Resource $resource ): array;

	/**
	 * The buttons of the action bar over the list: label and url, the last one the main one. None by default.
	 *
	 * @return array<int, array<string, string>>
	 */
	protected function buttons(): array {
		return array();
	}

	/**
	 * Prints a list's action bar (templates/account/list-actions.php).
	 *
	 * @param array<int, array<string, string>> $buttons Label and url of each button.
	 * @param bool                              $start   True under an empty line, false over the rows.
	 */
	public static function actions( array $buttons, bool $start ): void {
		include self::file( 'list-actions.php' );
	}

	/**
	 * The tabs after the overview: address part => title. Empty for a page without tabs.
	 *
	 * @return array<string, string>
	 */
	protected function page_tabs(): array {
		return array();
	}

	/**
	 * The filter prefix of the area, for example databases.
	 */
	abstract protected function key(): string;

	/**
	 * The endpoint the tab lives on.
	 */
	public function slug(): string {
		/**
		 * Filters the My Account endpoint of a service area (the slug of the tab declared by Tabs). The filter is
		 * beltoft_services_databases_slug or beltoft_services_apps_slug.
		 *
		 * @param string $slug Endpoint.
		 */
		$slug = sanitize_key( (string) apply_filters( 'beltoft_services_' . $this->key() . '_slug', $this->default_slug() ) );

		return '' !== $slug ? $slug : $this->default_slug();
	}

	/**
	 * The My Account tab declaration for Beltoft Licenses (see Tabs).
	 *
	 * @return array<string, mixed>
	 */
	public function group(): array {
		return array(
			'slug'  => $this->slug(),
			'label' => $this->label(),
			'empty' => $this->empty_text(),
			'match' => fn( $license ): bool => $this->is_member( $license ),
		);
	}

	/**
	 * Does the license's product use a driver of this area's kinds?
	 *
	 * @param mixed $license License row.
	 */
	public function is_member( $license ): bool {
		if ( ! is_array( $license ) || empty( $license['id'] ) ) {
			return false;
		}

		$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );

		return $driver && in_array( $driver->kind(), $this->kinds(), true );
	}

	/**
	 * The tab addresses a page of this area accepts.
	 *
	 * @return array<int, string>
	 */
	public function tabs(): array {
		/**
		 * Filters the tab addresses of a service page of an area (beltoft_services_databases_page_tabs).
		 *
		 * @param array<int, string> $slugs Slugs after the overview.
		 */
		$slugs = apply_filters( 'beltoft_services_' . $this->key() . '_page_tabs', array_keys( $this->page_tabs() ) );

		return array_values( array_filter( is_array( $slugs ) ? $slugs : array(), 'is_string' ) );
	}

	/**
	 * The tabs of a page for the navigation: slug ('overview' first) => title. Empty when the area has no tabs.
	 *
	 * @return array<string, string>
	 */
	public function nav(): array {
		$titles = $this->page_tabs();

		if ( ! $titles ) {
			return array();
		}

		$out = array( 'overview' => __( 'Overview', 'beltoft-services' ) );

		foreach ( $this->tabs() as $slug ) {
			$out[ $slug ] = $titles[ $slug ] ?? $slug;
		}

		return $out;
	}

	/**
	 * The address of a service's page or of one of its tabs.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $tab     Tab, or '' for the overview.
	 */
	public function url( array $license, string $tab = '' ): string {
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		$value = (string) (int) $license['id'] . ( in_array( $tab, $this->tabs(), true ) ? '/' . $tab : '' );

		return wc_get_endpoint_url( $this->slug(), $value, wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * The address of the list.
	 */
	public function url_list(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( $this->slug() ) : '';
	}

	/**
	 * What the address after the endpoint says: the license id and the tab.
	 *
	 * @return array{id: int, tab: string}
	 */
	public function requested(): array {
		global $wp;

		$value = is_object( $wp ) && isset( $wp->query_vars[ $this->slug() ] ) ? (string) $wp->query_vars[ $this->slug() ] : '';
		$parts = explode( '/', trim( sanitize_text_field( $value ), '/' ) );
		$id    = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
		$tab   = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';

		return array(
			'id'  => $id,
			'tab' => in_array( $tab, $this->tabs(), true ) ? $tab : '',
		);
	}

	/**
	 * Listener of wc_get_template: the licenses template on an area's endpoint becomes the list or the page.
	 *
	 * @param mixed $located Path of the template WooCommerce found.
	 * @param mixed $name    Template name.
	 * @param mixed $args    Template arguments.
	 * @return mixed
	 */
	public static function template( $located, $name = '', $args = array() ) {
		if ( 'myaccount/licenses.php' !== $name || ! is_array( $args ) || ! isset( $args['licenses'] ) || ! function_exists( 'is_wc_endpoint_url' ) ) {
			return $located;
		}

		foreach ( self::all() as $area ) {
			if ( is_wc_endpoint_url( $area->slug() ) ) {
				self::$active = $area;

				return self::file( 'area.php' );
			}
		}

		return $located;
	}

	/**
	 * Prints the tab of the area the template call was for. Called by templates/account/area.php.
	 *
	 * @param array<int, array<string, mixed>> $views License views.
	 * @param string                           $empty Text for an empty tab.
	 */
	public static function render_active( array $views, string $empty = '' ): void {
		if ( self::$active ) {
			self::$active->render( $views, $empty );
		}
	}

	/**
	 * Prints the list or the page for the licenses the customer has on the tab.
	 *
	 * @param array<int, array<string, mixed>> $views License views (BeltoftLicenses View::license()).
	 * @param string                           $empty Text for an empty tab.
	 */
	public function render( array $views, string $empty = '' ): void {
		$members   = array();
		$resources = array();

		foreach ( $views as $view ) {
			if ( ! is_array( $view ) || ! isset( $view['license'] ) || ! $this->is_member( $view['license'] ) ) {
				continue;
			}

			$resource = Resource::for_license( (int) $view['license']['id'] );

			if ( ! $resource ) {
				continue;
			}

			$id               = (int) $view['license']['id'];
			$members[ $id ]   = $view;
			$resources[ $id ] = $resource;
		}

		$asked = $this->requested();

		if ( $asked['id'] > 0 && isset( $members[ $asked['id'] ] ) ) {
			$this->detail( $members[ $asked['id'] ], $asked['tab'], $resources[ $asked['id'] ] );

			return;
		}

		if ( $asked['id'] > 0 ) {
			echo '<p class="beltoft-services-notice" role="status">' . esc_html( $this->not_found_text() ) . '</p>';
		}

		$rows    = $this->rows( $members, $resources );
		$buttons = $this->buttons();
		$empty   = '' !== $empty ? $empty : $this->empty_text();

		include self::file( 'area-list.php' );
	}

	/**
	 * The rows: plain values, ready to print. Reads only what the site already holds.
	 *
	 * @param array<int, array<string, mixed>> $views     Views by license id.
	 * @param array<int, Resource>             $resources Resources by license id.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( array $views, array $resources ): array {
		$rows = array();

		foreach ( $views as $id => $view ) {
			if ( isset( $resources[ $id ] ) ) {
				$rows[] = $this->row( $view, $resources[ $id ] );
			}
		}

		return $rows;
	}

	/**
	 * One service's page.
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param string               $tab      Requested tab.
	 * @param Resource             $resource The service's resource.
	 */
	private function detail( array $view, string $tab, Resource $resource ): void {
		self::$page = array(
			'license'  => $view['license'],
			'tab'      => $tab,
			'resource' => $resource,
			'area'     => $this,
		);

		try {
			echo '<div class="beltoft-services-server beltoft-services-area-page beltoft-services-area-page--' . esc_attr( $this->key() ) . '">';
			echo '<p class="beltoft-services-server__back"><a href="' . esc_url( $this->url_list() ) . '">&lsaquo; ' . esc_html( $this->all_label() ) . '</a></p>';
			Panel::render( $view['license'], $view );
			echo '</div>';
		} finally {
			self::$page = null;
		}
	}

	/**
	 * The state text and the tone of a service, the same words as the server rows.
	 *
	 * @param Resource $resource Resource.
	 * @return array{0: string, 1: string} Text, tone (ok, busy, off, bad).
	 */
	public static function state( Resource $resource ): array {
		$state = (string) $resource->get( 'state' );
		$group = Panel::group( $state, $resource );

		if ( Progress::build( $resource )['active'] ) {
			return array( __( 'Working', 'beltoft-services' ), 'busy' );
		}

		$tone = $resource->on_hold() || 'failed' === $group ? 'bad' : ( 'ready' === $group ? 'ok' : ( 'getting-ready' === $group ? 'busy' : 'off' ) );

		return array( Panel::label( $state, $resource ), $tone );
	}

	/**
	 * Listener of beltoft_services_account_page_head: the head of a service page in an area, the service's name as the title.
	 *
	 * @param mixed $handled  Whether another listener printed the head.
	 * @param mixed $resource Resource.
	 * @param mixed $license  License row.
	 * @param mixed $view     Display values of the license.
	 * @param mixed $page     The page being printed.
	 * @return mixed
	 */
	public static function head( $handled, $resource, $license = array(), $view = array(), $page = array() ) {
		unset( $license );

		if ( $handled || ! $resource instanceof Resource || ! self::$page || ! is_array( $page ) || ! is_array( $view ) ) {
			return $handled;
		}

		$state = (string) $resource->get( 'state' );
		$group = Panel::group( $state, $resource );
		$vars  = array(
			'title'        => (string) ( $view['product'] ?? '' ),
			'group'        => $group,
			'badge'        => Panel::label( $state, $resource ),
			'message'      => Panel::message( $state, $resource ),
			'show_message' => 'ready' !== $group,
			'kind'         => (string) self::$page['area']->label_of( $resource ),
			'renews'       => Servers::renewal( self::$page['license'] ),
		);

		( static function ( array $vars ): void {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, a fixed list built above.
			extract( $vars, EXTR_SKIP );
			include self::file( 'domain-head.php' );
		} )( $vars );

		return true;
	}

	/**
	 * The small text next to the title of a page (the kind of service), default the driver's label.
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		$driver = Registry::get( (string) $resource->get( 'driver' ) );

		return $driver ? $driver->label() : '';
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
