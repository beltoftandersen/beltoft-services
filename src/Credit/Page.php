<?php
/**
 * My Account: the credit page (balance, history, top-up form).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Currency;
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
		$user_id  = get_current_user_id();
		// The credit is in one currency, the customer's own; top-ups are offered in it, and only on a page that shows it.
		// With no credit and no currency fixed for them the customer tops up in the currency of the page they are on.
		$currency = Balance::account_currency( $user_id );
		$currency = '' !== $currency ? $currency : Currency::current();
		$here     = Currency::current() === $currency;

		return array(
			'balance'  => Balance::get( $user_id ),
			'promo'    => Balance::promo_left( $user_id ),
			'history'  => self::merged( Balance::history( $user_id, 40 ), 20 ),
			'amounts'  => $here ? Topup::amounts( $currency ) : array(),
			'min'      => Topup::min( $currency ),
			'max'      => Topup::max( $currency ),
			'available' => $here && Topup::available( $currency ),
			'currency' => $currency,
			'reserve'  => self::reserve( $user_id, $currency ),
		);
	}

	/**
	 * The reservation of this month's usage, when it is in the currency the page shows.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $currency Currency of the page.
	 * @return array<string, mixed>|null
	 */
	private static function reserve( int $user_id, string $currency ): ?array {
		$reserve = Reserve::for_user( $user_id );

		return $reserve && $reserve['currency'] === $currency ? $reserve : null;
	}

	/**
	 * The page's markup.
	 */
	public static function html(): string {
		if ( ! is_user_logged_in() || ! Config::enabled() || Config::gift() ) {
			return '';
		}

		Panel::style();
		wp_enqueue_script( 'beltoft-services-credit', plugins_url( 'assets/credit.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Status::HANDLE ), \BeltoftServices\Assets::version( 'assets/credit.js' ), true );
		wp_localize_script( 'beltoft-services-credit', 'beltoftCredit', array( 'empty' => __( 'Enter your coupon code.', 'beltoft-services' ) ) );

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
	 * The history with the two rows of one order (the promotional and the paid part) as one line: the sum, the newest balance.
	 *
	 * @param array<int, array<string, mixed>> $rows  Ledger rows, newest first.
	 * @param int                              $limit Lines.
	 * @return array<int, array<string, mixed>>
	 */
	public static function merged( array $rows, int $limit ): array {
		$out  = array();
		$at   = array();
		$pair = array(
			Balance::INVOICE    => 'use',
			Balance::PROMO_USE  => 'use',
			Balance::ADJUST     => 'back',
			Balance::PROMO_BACK => 'back',
		);

		foreach ( $rows as $row ) {
			$part = $pair[ $row['kind'] ] ?? '';
			$key  = '' !== $part && in_array( $row['ref_type'], array( Invoice::REF_TYPE, Invoice::REF_RETURN ), true ) ? $part . '|' . $row['ref_type'] . '|' . $row['ref_id'] : '';

			if ( '' !== $key && isset( $at[ $key ] ) ) {
				$out[ $at[ $key ] ]['amount_cents'] = (string) ( (int) $out[ $at[ $key ] ]['amount_cents'] + (int) $row['amount_cents'] );

				continue;
			}

			if ( '' !== $key ) {
				$at[ $key ] = count( $out );
			}

			if ( Balance::PROMO_USE === $row['kind'] ) {
				$row['kind'] = Balance::INVOICE;
			} elseif ( Balance::PROMO_BACK === $row['kind'] ) {
				$row['kind'] = Balance::ADJUST;
			}

			$out[] = $row;
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * The order a "used on invoice" row paid, when it is the customer's own; else null.
	 *
	 * @param array<string, mixed> $row Ledger row.
	 */
	public static function row_order( array $row ): ?\WC_Order {
		if ( Balance::INVOICE !== (string) $row['kind'] || Invoice::REF_TYPE !== (string) $row['ref_type'] || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( (int) $row['ref_id'] );

		return $order instanceof \WC_Order && (int) $order->get_customer_id() === (int) $row['user_id'] ? $order : null;
	}

	/**
	 * What an order was for: the names of its products as the shop shows them now (so in the page's language), two at
	 * most and then how many more.
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

	/**
	 * One history row's "What" as markup: for credit used on an order, the order number links to the order and the products
	 * follow; every other row is row_label(), escaped.
	 *
	 * @param array<string, mixed> $row Ledger row.
	 */
	public static function row_html( array $row ): string {
		$order = self::row_order( $row );

		if ( null === $order ) {
			return esc_html( self::row_label( $row ) );
		}

		/* translators: %s: order number. */
		$link  = '<a href="' . esc_url( $order->get_view_order_url() ) . '">' . esc_html( sprintf( _x( '#%s', 'order number in the credit history', 'beltoft-services' ), $order->get_order_number() ) ) . '</a>';
		$names = self::order_names( $order );

		return '' === $names
			/* translators: %s: order number, as a link. */
			? sprintf( esc_html__( 'Used on invoice %s', 'beltoft-services' ), $link )
			/* translators: 1: order number, as a link, 2: what the order was for. */
			: sprintf( esc_html__( 'Used on invoice %1$s: %2$s', 'beltoft-services' ), $link, esc_html( $names ) );
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
			Balance::PROMO_USE  => __( 'Used on invoice', 'beltoft-services' ),
			Balance::PROMO_BACK => __( 'Adjustment', 'beltoft-services' ),
		);

		return $labels[ $kind ] ?? $kind;
	}
}
