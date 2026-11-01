<?php
/**
 * How a pay-as-you-go server reads in the order summary, the cart, the mini cart and the order views.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Display only: real prices and totals are never touched. The server line shows its hourly rate and its monthly
 * cap instead of 0.00, the hidden "Pay as you go account" line is not listed, the billing note is said once
 * under the items at checkout, and "Total" reads "Due today" when a pay-as-you-go cart costs nothing now.
 */
final class Summary {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_cart_item_visible', array( __CLASS__, 'visible' ), 20, 2 );
		add_filter( 'woocommerce_checkout_cart_item_visible', array( __CLASS__, 'visible' ), 20, 2 );
		add_filter( 'woocommerce_widget_cart_item_visible', array( __CLASS__, 'visible' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_price' ), 100, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'cart_subtotal' ), 100, 2 );
		add_filter( 'woocommerce_order_item_visible', array( __CLASS__, 'order_visible' ), 20, 2 );
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( __CLASS__, 'order_subtotal' ), 100, 2 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'checkout_summary' ), 30, 1 );
	}

	/**
	 * Is this the hidden account product?
	 *
	 * @param int $product_id Product id.
	 */
	private static function is_account( int $product_id ): bool {
		$id = (int) get_option( Account::OPTION_PRODUCT );

		return $id > 0 && $id === $product_id;
	}

	/**
	 * The account line is not listed.
	 *
	 * @param mixed $visible Whether the line is shown.
	 * @param mixed $item    Cart item.
	 */
	public static function visible( $visible, $item = array() ) {
		return is_array( $item ) && self::is_account( (int) ( $item['product_id'] ?? 0 ) ) ? false : $visible;
	}

	/**
	 * Order views (thank-you page, My Account order) do not list the account line either.
	 *
	 * @param mixed $visible Whether the line is shown.
	 * @param mixed $item    Order item.
	 */
	public static function order_visible( $visible, $item = null ) {
		return $item instanceof \WC_Order_Item_Product && self::is_account( (int) $item->get_product_id() ) ? false : $visible;
	}

	/**
	 * "€0.0829 / hour" under the name of a pay-as-you-go server.
	 *
	 * @param mixed $price Price html.
	 * @param mixed $item  Cart item.
	 */
	public static function cart_price( $price, $item = array() ) {
		$rates = is_array( $item ) && ! isset( $item['subscription_renewal'] ) ? Rates::for_usage( $item['data'] ?? null ) : null;

		if ( null === $rates ) {
			return $price;
		}

		/* translators: %s: price per hour such as €0.0829 */
		return esc_html( sprintf( __( '%s / hour', 'beltoft-services' ), Rates::money( $rates['hourly'], 4 ) ) );
	}

	/**
	 * "max €55.00 / month" where the line's amount is.
	 *
	 * @param mixed $subtotal Subtotal html.
	 * @param mixed $item     Cart item.
	 */
	public static function cart_subtotal( $subtotal, $item = array() ) {
		$rates = is_array( $item ) && ! isset( $item['subscription_renewal'] ) ? Rates::for_usage( $item['data'] ?? null ) : null;

		return null === $rates ? $subtotal : self::cap_text( $rates['cap'] );
	}

	/**
	 * The same on an order line.
	 *
	 * @param mixed $subtotal Subtotal html.
	 * @param mixed $item     Order item.
	 */
	public static function order_subtotal( $subtotal, $item = null ) {
		$rates = $item instanceof \WC_Order_Item_Product ? Rates::for_usage( $item->get_product() ) : null;

		return null === $rates ? $subtotal : self::rate_and_cap( $rates );
	}

	/**
	 * "max €55.00 / month".
	 *
	 * @param float $cap Cap.
	 */
	private static function cap_text( float $cap ): string {
		/* translators: %s: monthly cap such as €55.00 */
		return esc_html( sprintf( __( 'max %s / month', 'beltoft-services' ), Rates::money( $cap ) ) );
	}

	/**
	 * "€0.0829 / hour, max €55.00 / month".
	 *
	 * @param array{hourly: float, cap: float} $rates Rates.
	 */
	private static function rate_and_cap( array $rates ): string {
		/* translators: 1: price per hour such as €0.0829, 2: monthly cap such as €55.00 */
		return esc_html( sprintf( __( '%1$s / hour, max %2$s / month', 'beltoft-services' ), Rates::money( $rates['hourly'], 4 ), Rates::money( $rates['cap'] ) ) );
	}

	/**
	 * The one note under the items.
	 *
	 * @param int $user_id Customer.
	 */
	private static function note( int $user_id ): string {
		$saved = $user_id > 0 && null !== Account::for_user( $user_id, Account::ESTABLISHED );

		/* translators: 1: how pay as you go is billed, 2: what happens to the card */
		return sprintf( __( '%1$s. %2$s', 'beltoft-services' ), \BeltoftServices\LineNames::usage_note(), $saved ? __( 'Your saved card is charged after each month.', 'beltoft-services' ) : __( 'Your card is saved now and charged after each month.', 'beltoft-services' ) );
	}

	/**
	 * Is the checkout (also while WooCommerce refreshes it) showing the order summary?
	 */
	public static function at_checkout(): bool {
		return defined( 'WOOCOMMERCE_CHECKOUT' ) || ( function_exists( 'is_checkout' ) && is_checkout() );
	}

	/**
	 * The Bricks order summary of a cart with a pay-as-you-go server: the note once under the items, and "Due today".
	 *
	 * @param mixed $content Rendered html.
	 */
	public static function checkout_summary( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, 'bricks-woo-checkout-order-summary' ) || ! function_exists( 'WC' ) || ! WC()->cart || Cart::usage_units( WC()->cart ) < 1 ) {
			return $content;
		}

		$note = '<p class="beltoft-services-payg-note" style="margin:12px 0 16px;font-size:14px;line-height:1.5">' . esc_html( self::note( get_current_user_id() ) ) . '</p>';
		$from = (int) strpos( $content, 'bricks-woo-checkout-order-summary' );
		$out  = substr( $content, 0, $from ) . (string) preg_replace( '/(<div id="brxe-[a-z0-9]+" class="brxe-divider horizontal">)/', $note . '$1', substr( $content, $from ), 1 );

		if ( (float) WC()->cart->get_total( 'edit' ) <= 0 ) {
			$out = substr( $out, 0, $from ) . (string) preg_replace( '/(<div id="brxe-[a-z0-9]+" class="brxe-text-basic">)Total(<\/div>)/', '${1}' . esc_html__( 'Due today', 'beltoft-services' ) . '${2}', substr( $out, $from ), 1 );
		}

		return $out;
	}
}
