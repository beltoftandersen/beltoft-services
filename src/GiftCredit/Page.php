<?php
/**
 * My Account: the credit page, drawn from the add-on's wallet.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\GiftCredit;

use Bgca\Account;
use BeltoftServices\Account\Billing;
use BeltoftServices\Account\Panel;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Minimum;

defined( 'ABSPATH' ) || exit;

/**
 * A My Account endpoint, "credit" (the address the old credit page had, so links keep working), only while gift credit is on. With the
 * Billing grouping on it is a tab of Billing, otherwise a menu item before "Log out". The page shows the add-on's wallet (balance, paid and
 * free credit, history, the top-up form and the form that adds a gift card code, both handled by the add-on and returning here) and the
 * estimate of this month's pay as you go usage. Template: templates/account/gift-credit.php (copy to
 * yourtheme/beltoft-services/account/gift-credit.php).
 */
final class Page {

	public const ENDPOINT = 'credit';

	/**
	 * Option holding whether the rewrite rules were flushed for the endpoint.
	 */
	public const OPTION_FLUSH = 'beltoft_services_credit_flushed';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 50 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'ungroup' ), 100 );
		// The gift card plugin's own "Gift Cards" page shows the same cards: the credit page is the one place for them.
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'no_plugin_page' ), 110 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ), 5 );
		add_filter( 'beltoft_services_billing_sets', array( __CLASS__, 'billing_set' ) );
		add_filter( 'beltoft_services_billing_tab_labels', array( __CLASS__, 'billing_label' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	/**
	 * The page's address.
	 */
	public static function url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/' );
	}

	/**
	 * Answer for the add-on's filter bgca_account_url: its forms return to this page.
	 *
	 * @param mixed $url Default.
	 * @return mixed
	 */
	public static function url_filter( $url ) {
		return GiftCredit::active() ? self::url() : $url;
	}

	/**
	 * Registers the endpoint (always, so the rules exist; the page itself needs the switch).
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[ self::ENDPOINT ] = self::ENDPOINT;
		}

		return $vars;
	}

	/**
	 * Flushes the rewrite rules once when the endpoint first matters.
	 */
	public static function maybe_flush(): void {
		if ( GiftCredit::active() && 'yes' !== get_option( self::OPTION_FLUSH ) ) {
			update_option( self::OPTION_FLUSH, 'yes', false );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * The menu item.
	 *
	 * @param mixed $items Items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		if ( ! is_array( $items ) || ! GiftCredit::active() ) {
			return $items;
		}

		$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'Credit', 'beltoft-services' );

		return $items + $logout;
	}

	/**
	 * With the Billing grouping on, the credit page is a Billing tab, so its menu item goes (after Billing has read the menu).
	 *
	 * @param mixed $items Items.
	 * @return mixed
	 */
	public static function ungroup( $items ) {
		if ( is_array( $items ) && GiftCredit::active() && Billing::enabled() ) {
			unset( $items[ self::ENDPOINT ] );
		}

		return $items;
	}

	/**
	 * The page belongs to the Billing tab "credit".
	 *
	 * @param mixed $sets Sets.
	 * @return mixed
	 */
	public static function billing_set( $sets ) {
		if ( is_array( $sets ) && GiftCredit::active() ) {
			$sets[ self::ENDPOINT ] = array( self::ENDPOINT );
		}

		return $sets;
	}

	/**
	 * Billing tab label.
	 *
	 * @param mixed $labels Labels.
	 * @return mixed
	 */
	public static function billing_label( $labels ) {
		if ( is_array( $labels ) && GiftCredit::active() ) {
			$labels[ self::ENDPOINT ] = __( 'Credit', 'beltoft-services' );
		}

		return $labels;
	}

	/**
	 * Page title.
	 */
	public static function title(): string {
		return __( 'Credit', 'beltoft-services' );
	}

	/**
	 * What the page shows: the add-on's data and the usage estimate.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	public static function data( int $user_id ): array {
		$data            = Account::data( $user_id );
		$data['reserve'] = self::reserve( $user_id, (string) $data['currency'], (int) $data['totals']['total'] );
		// The top-up is offered on a page of the account's currency only (the add-on checks it).
		return $data;
	}

	/**
	 * The reservation of this month's usage: display only, by the rule the usage invoice will use.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $currency Currency of the page.
	 * @param int    $balance  What the cards hold in it, cents.
	 * @return array{usage: int, left: int, rest: int, carried: bool}|null Null with no credit in the usage account's currency or no usage.
	 */
	private static function reserve( int $user_id, string $currency, int $balance ): ?array {
		$estimate = Estimate::for_user( $user_id );

		if ( ! $estimate || ! $estimate['resources'] || (string) $estimate['currency'] !== $currency ) {
			return null;
		}

		$usage = Estimate::invoice_cents( $estimate );

		if ( $balance < 1 || $usage < 1 ) {
			return null;
		}

		$carried = Minimum::carries( $usage, $user_id, $currency );
		$used    = $carried ? 0 : min( $balance, $usage );

		return array(
			'usage'   => $usage,
			'left'    => $balance - $used,
			'rest'    => $carried ? 0 : $usage - $used,
			'carried' => $carried,
		);
	}

	/**
	 * The page's markup.
	 */
	public static function html(): string {
		if ( ! is_user_logged_in() || ! GiftCredit::active() ) {
			return '';
		}

		Panel::style();
		wp_enqueue_script( \BeltoftServices\Status::HANDLE );

		ob_start();
		wc_get_template( 'gift-credit.php', array( 'credit' => self::data( get_current_user_id() ) ), 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

		return (string) ob_get_clean();
	}

	/**
	 * Endpoint output.
	 */
	public static function render(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}

	/**
	 * Takes the gift card plugin's own My Account item out of the menu while gift credit is on.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function no_plugin_page( $items ) {
		if ( is_array( $items ) && GiftCredit::active() ) {
			unset( $items['gift-cards'] );
		}

		return $items;
	}

	/**
	 * What an order was for: its products as the shop names them now (so in the page's language), two at most, then how many more.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function order_names( \WC_Order $order ): string {
		$names = array();

		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$name    = trim( wp_strip_all_tags( $product ? $product->get_name() : (string) $item->get_name() ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		$names = array_values( array_unique( $names ) );

		if ( count( $names ) > 2 ) {
			$more = count( $names ) - 2;
			/* translators: 1: two item names, 2: how many more items. */
			return sprintf( _n( '%1$s and %2$d more', '%1$s and %2$d more', $more, 'beltoft-services' ), implode( ', ', array_slice( $names, 0, 2 ) ), $more );
		}

		return implode( ', ', $names );
	}
}
