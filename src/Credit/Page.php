<?php
/**
 * My Account: the credit page (balance, history, top-up form).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Account\Billing;
use BeltoftServices\Account\Panel;
use BeltoftServices\Usage\Estimate;

defined( 'ABSPATH' ) || exit;

/**
 * A My Account endpoint, "credit", only while account credit is on. With the Billing grouping on it is a tab of
 * Billing (Billing's filters), otherwise a menu item before "Log out". The template is templates/account/credit.php
 * (copy to yourtheme/beltoft-services/account/credit.php).
 */
final class Page {

	public const ENDPOINT = 'credit';

	/**
	 * Option holding what the rewrite rules were last flushed for.
	 */
	public const OPTION_FLUSH = 'beltoft_services_credit_flushed';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 50 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'ungroup' ), 100 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
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
		if ( 'yes' !== get_option( self::OPTION_FLUSH ) ) {
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
		if ( ! is_array( $items ) || ! Config::enabled() ) {
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
		if ( is_array( $items ) && Billing::enabled() ) {
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
		if ( is_array( $sets ) && Config::enabled() ) {
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
		if ( is_array( $labels ) && Config::enabled() ) {
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
	 * What the page shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$user_id = get_current_user_id();

		return array(
			'balance'  => Balance::get( $user_id ),
			'history'  => Balance::history( $user_id, 20 ),
			'amounts'  => Topup::amounts(),
			'min'      => Topup::min(),
			'max'      => Topup::max(),
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * The page's markup.
	 */
	public static function html(): string {
		if ( ! is_user_logged_in() || ! Config::enabled() ) {
			return '';
		}

		Panel::style();

		ob_start();
		wc_get_template( 'credit.php', array( 'credit' => self::data() ), 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

		return (string) ob_get_clean();
	}

	/**
	 * Endpoint output.
	 */
	public static function render(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}

	/**
	 * A signed amount in cents for people: "+€25.00", "-€1.20".
	 *
	 * @param int    $cents    Cents.
	 * @param string $currency Currency.
	 * @param bool   $sign     Put + before a positive amount.
	 */
	public static function money( int $cents, string $currency, bool $sign = false ): string {
		$text = Estimate::money( abs( $cents ) / 100, $currency );

		return ( $cents < 0 ? '-' : ( $sign && $cents > 0 ? '+' : '' ) ) . $text;
	}

	/**
	 * The words for one history row: "Credit added: Welcome credit", "Credit correction: ...", else the note or the kind.
	 *
	 * @param array<string, mixed> $row Ledger row.
	 */
	public static function row_label( array $row ): string {
		$note = (string) $row['note'];

		if ( Balance::PROMO === $row['kind'] ) {
			return '' !== $note ? sprintf( /* translators: %s: reason. */ __( 'Credit added: %s', 'beltoft-services' ), $note ) : self::kind_label( Balance::PROMO );
		}

		if ( Balance::ADJUST === $row['kind'] && 'admin' === $row['ref_type'] ) {
			return sprintf( /* translators: %s: reason. */ __( 'Credit correction: %s', 'beltoft-services' ), $note );
		}

		return '' !== $note ? $note : self::kind_label( (string) $row['kind'] );
	}

	/**
	 * Words for a ledger kind.
	 *
	 * @param string $kind Kind.
	 */
	public static function kind_label( string $kind ): string {
		$labels = array(
			Balance::TOPUP      => __( 'Top-up', 'beltoft-services' ),
			Balance::INVOICE    => __( 'Used on invoice', 'beltoft-services' ),
			Balance::PROMO      => __( 'Credit added', 'beltoft-services' ),
			Balance::REFUND     => __( 'Refund', 'beltoft-services' ),
			Balance::CHARGEBACK => __( 'Chargeback', 'beltoft-services' ),
			Balance::ADJUST     => __( 'Adjustment', 'beltoft-services' ),
		);

		return $labels[ $kind ] ?? $kind;
	}
}
