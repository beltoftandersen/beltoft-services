<?php
/**
 * One Billing item in the My Account menu and a tab bar over the billing pages.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriptions, orders, payment methods and addresses are four WooCommerce pages. When the setting is on,
 * the menu shows one "Billing" item for them and each of those pages (and their sub-pages: a subscription,
 * an order, adding a card, an address) gets the same tab bar on top. Nothing about the pages themselves
 * changes: their addresses, templates and payment gateway screens stay as they are. Off by default, so a site
 * that does not turn it on is unaffected.
 */
final class Billing {

	/**
	 * Settings key.
	 */
	public const SETTING = 'account_billing';

	/**
	 * The menu keys the grouping takes out of the menu.
	 */
	private const GROUPED = array( 'orders', 'subscriptions', 'payment-methods', 'edit-address' );

	/**
	 * The menu list WooCommerce built before the grouping, for the tab bar (which pages the customer has).
	 *
	 * @var array<string, string>|null
	 */
	private static $raw = null;

	/**
	 * Whether the tab bar was already printed in this request.
	 *
	 * @var bool
	 */
	private static $printed = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 99 );
		add_filter( 'woocommerce_account_menu_item_classes', array( __CLASS__, 'classes' ), 10, 2 );
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'before_bricks_account' ), 10, 2 );
		add_action( 'woocommerce_account_content', array( __CLASS__, 'in_content' ), 5 );
		add_shortcode( 'beltoft_billing_tabs', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Forgets what was built (tests, and a request that prints twice).
	 */
	public static function reset(): void {
		self::$raw     = null;
		self::$printed = false;
	}

	/**
	 * Whether the Billing grouping is on.
	 */
	public static function enabled(): bool {
		/**
		 * Whether My Account groups the billing pages under one Billing item with a tab bar.
		 *
		 * @param bool $on The setting, default off.
		 */
		return (bool) apply_filters( 'beltoft_services_billing_enabled', (bool) Settings::get( self::SETTING ) );
	}

	/**
	 * The My Account endpoints that belong to each tab: the page itself and its sub-pages.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function sets(): array {
		$sets = array(
			'subscriptions'   => array( 'subscriptions', 'view-subscription', 'subscription-payment-method' ),
			'orders'          => array( 'orders', 'view-order' ),
			'payment-methods' => array( 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method' ),
			'edit-address'    => array( 'edit-address' ),
		);

		/**
		 * Filters which My Account endpoints belong to each Billing tab (tab endpoint => its endpoints).
		 *
		 * @param array<string, array<int, string>> $sets Sets.
		 */
		$sets = apply_filters( 'beltoft_services_billing_sets', $sets );

		return is_array( $sets ) ? $sets : array();
	}

	/**
	 * The My Account endpoint being shown, or ''.
	 */
	private static function current_endpoint(): string {
		return function_exists( 'WC' ) && WC()->query ? (string) WC()->query->get_current_endpoint() : '';
	}

	/**
	 * Which tab the current page belongs to, or '' when it is not a billing page.
	 */
	public static function current_tab(): string {
		$endpoint = self::current_endpoint();

		foreach ( self::sets() as $tab => $endpoints ) {
			if ( in_array( $endpoint, $endpoints, true ) ) {
				return (string) $tab;
			}
		}

		return '';
	}

	/**
	 * The menu: the four billing items become one. It takes the place of the first of them and points at
	 * Subscriptions, or at Orders when the customer has no subscriptions.
	 *
	 * @param mixed $items Menu items, key => label.
	 * @return mixed
	 */
	public static function menu( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		if ( null === self::$raw ) {
			self::$raw = $items;
		}

		if ( self::enabled() ) {
			$items = self::group( $items );
		}

		return self::arrange( $items );
	}

	/**
	 * The four billing items as one, in the place of the first of them.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	private static function group( array $items ): array {
		$key = self::menu_key( $items );

		if ( '' === $key ) {
			return $items;
		}

		$out  = array();
		$done = false;

		foreach ( $items as $slug => $label ) {
			if ( in_array( $slug, self::GROUPED, true ) ) {
				if ( ! $done ) {
					$out[ $key ] = self::label();
					$done        = true;
				}

				continue;
			}

			$out[ $slug ] = $label;
		}

		return $out;
	}

	/**
	 * Lets a site set the menu's order and labels. Both filters return nothing by default and then the menu is
	 * left as it is. Items the order does not name keep their place after the named ones, and "Log out" stays last.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	private static function arrange( array $items ): array {
		/**
		 * The order of the My Account menu, as a list of endpoint keys. Empty keeps WooCommerce's order.
		 *
		 * @param array<int, string> $order Endpoint keys.
		 */
		$order = apply_filters( 'beltoft_services_account_menu_order', array() );

		/**
		 * Labels for My Account menu items, endpoint key => label. Empty keeps the labels as they are.
		 *
		 * @param array<string, string> $labels Labels.
		 */
		$labels = apply_filters( 'beltoft_services_account_menu_labels', array() );

		if ( is_array( $order ) && $order ) {
			$sorted = array();

			foreach ( $order as $slug ) {
				if ( isset( $items[ $slug ] ) ) {
					$sorted[ $slug ] = $items[ $slug ];
				}
			}

			$logout = array();

			if ( isset( $items['customer-logout'] ) && ! isset( $sorted['customer-logout'] ) ) {
				$logout = array( 'customer-logout' => $items['customer-logout'] );
				unset( $items['customer-logout'] );
			}

			$items = $sorted + $items + $logout;

			if ( isset( $sorted['customer-logout'] ) ) {
				$last = array( 'customer-logout' => $items['customer-logout'] );
				unset( $items['customer-logout'] );
				$items += $last;
			}
		}

		foreach ( is_array( $labels ) ? $labels : array() as $slug => $label ) {
			if ( isset( $items[ $slug ] ) && is_string( $label ) && '' !== $label ) {
				$items[ $slug ] = $label;
			}
		}

		return $items;
	}

	/**
	 * The menu key the Billing item keeps: Subscriptions when listed, else Orders, else ''.
	 *
	 * @param array<string, string> $items Menu items.
	 */
	private static function menu_key( array $items ): string {
		foreach ( array( 'subscriptions', 'orders' ) as $key ) {
			if ( isset( $items[ $key ] ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * The Billing item stays marked on every billing page.
	 *
	 * @param mixed  $classes  Classes of the menu item.
	 * @param string $endpoint The item's endpoint.
	 * @return mixed
	 */
	public static function classes( $classes, $endpoint ) {
		if ( ! is_array( $classes ) || ! self::enabled() ) {
			return $classes;
		}

		$raw = self::raw();
		$key = self::menu_key( $raw );

		if ( $endpoint === $key && '' !== self::current_tab() && ! in_array( 'is-active', $classes, true ) ) {
			$classes[] = 'is-active';
		}

		return $classes;
	}

	/**
	 * The menu as WooCommerce lists it for this customer, before the grouping.
	 *
	 * @return array<string, string>
	 */
	private static function raw(): array {
		if ( null === self::$raw && function_exists( 'wc_get_account_menu_items' ) ) {
			wc_get_account_menu_items();
		}

		return is_array( self::$raw ) ? self::$raw : array();
	}

	/**
	 * The menu's label.
	 */
	private static function label(): string {
		/**
		 * The label of the Billing menu item.
		 *
		 * @param string $label Label.
		 */
		return (string) apply_filters( 'beltoft_services_billing_label', __( 'Billing', 'beltoft-services' ) );
	}

	/**
	 * The tabs: only the pages the customer has (WooCommerce lists them in the menu), and always the one shown.
	 *
	 * @return array<int, array{endpoint: string, label: string, url: string, current: bool}>
	 */
	public static function tabs(): array {
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return array();
		}

		$labels = array(
			'subscriptions'   => __( 'Subscriptions', 'beltoft-services' ),
			'orders'          => __( 'Orders and invoices', 'beltoft-services' ),
			'payment-methods' => __( 'Payment methods', 'beltoft-services' ),
			'edit-address'    => __( 'Addresses', 'beltoft-services' ),
		);

		/**
		 * Filters the labels of the Billing tabs (tab endpoint => label).
		 *
		 * @param array<string, string> $labels Labels.
		 */
		$labels  = (array) apply_filters( 'beltoft_services_billing_tab_labels', $labels );
		$raw     = self::raw();
		$current = self::current_tab();
		$tabs    = array();

		foreach ( $labels as $endpoint => $label ) {
			if ( ! isset( $raw[ $endpoint ] ) && $endpoint !== $current ) {
				continue;
			}

			$tabs[] = array(
				'endpoint' => (string) $endpoint,
				'label'    => (string) $label,
				'url'      => wc_get_account_endpoint_url( (string) $endpoint ),
				'current'  => $endpoint === $current,
			);
		}

		return $tabs;
	}

	/**
	 * The tab bar's markup, '' outside the billing pages, for a visitor, or when the grouping is off.
	 */
	public static function html(): string {
		if ( ! self::enabled() || ! is_user_logged_in() || '' === self::current_tab() ) {
			return '';
		}

		$tabs = self::tabs();

		if ( count( $tabs ) < 2 ) {
			return '';
		}

		Panel::style();
		self::$printed = true;

		$out = '<nav class="beltoft-services-billing-tabs" aria-label="' . esc_attr( self::label() ) . '"><ul>';

		foreach ( $tabs as $tab ) {
			$out .= '<li><a href="' . esc_url( $tab['url'] ) . '"' . ( $tab['current'] ? ' aria-current="page" class="is-active"' : '' ) . '>' . esc_html( $tab['label'] ) . '</a></li>';
		}

		return $out . '</ul></nav>';
	}

	/**
	 * Shortcode [beltoft_billing_tabs]: for a site that places the bar itself.
	 */
	public static function shortcode(): string {
		return self::$printed ? '' : self::html();
	}

	/**
	 * Whether the bar prints itself.
	 */
	private static function auto(): bool {
		/**
		 * Whether the tab bar prints itself on the billing pages. A site that places the shortcode returns false.
		 *
		 * @param bool $auto Default true.
		 */
		return (bool) apply_filters( 'beltoft_services_billing_tabs_auto', true );
	}

	/**
	 * With Bricks: the bar goes above the Account element, so it shows for every endpoint.
	 *
	 * @param mixed $html    The element's markup.
	 * @param mixed $element The element.
	 * @return mixed
	 */
	public static function before_bricks_account( $html, $element ) {
		if ( ! is_string( $html ) || ! is_object( $element ) || 'woocommerce-account-page-v2' !== ( $element->name ?? '' ) || self::$printed || ! self::auto() ) {
			return $html;
		}

		return self::html() . $html;
	}

	/**
	 * Without Bricks: the bar goes at the top of the account content.
	 */
	public static function in_content(): void {
		if ( defined( 'BRICKS_VERSION' ) || self::$printed || ! self::auto() ) {
			return;
		}

		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaping above.
	}
}
