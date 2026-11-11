<?php
/**
 * The tax note and the one helper that prints a catalogue price.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Follows WooCommerce's tax display options and reads no other plugin.
 *
 * html() is the one place this plugin prints a catalogue price as HTML (a plan, a domain, a disk, a pay as you go
 * rate or cap, an add-on difference). A site's listener on beltoft_services_price_html may show both figures
 * of it, or the other tax display. Money that is an invoice, an order, a credit balance, a statement or a
 * checkout total is not a catalogue price and never goes through html().
 */
final class TaxNote {

	/** @var string Display forced for the duration of a render ('' for the shop's own). */
	private static string $forced = '';

	/**
	 * Runs a callback with a tax display in force (a price listener renders the other display through this).
	 *
	 * @param string   $display 'incl' or 'excl'.
	 * @param callable $fn      Callback.
	 * @return mixed
	 */
	public static function in( string $display, callable $fn ) {
		if ( ! in_array( $display, array( 'incl', 'excl' ), true ) ) {
			return $fn();
		}

		$before       = self::$forced;
		self::$forced = $display;

		try {
			return $fn();
		} finally {
			self::$forced = $before;
		}
	}

	/**
	 * The display a catalogue price is shown in: a forced one, else the shop's. 'excl' when the shop charges no tax.
	 *
	 * @param string $context 'shop' or 'cart'.
	 */
	public static function display( string $context = 'shop' ): string {
		if ( '' !== self::$forced && wc_tax_enabled() ) {
			return self::$forced;
		}

		return 'incl' === self::mode( $context ) ? 'incl' : 'excl';
	}

	/**
	 * "incl. VAT" or "excl. VAT" for the display in force. Unlike note() it never says nothing: a shop with no tax
	 * says "excl. VAT", as the plugin always did.
	 */
	public static function label(): string {
		return 'incl' === self::display() ? __( 'incl. VAT', 'beltoft-services' ) : __( 'excl. VAT', 'beltoft-services' );
	}

	/**
	 * The catalogue figure for an amount excluding tax: the amount with its tax added when the display in force is
	 * including, from the product's tax class (a non-taxable product keeps the amount) or a given tax class. Nothing is
	 * rounded here; the caller formats with the decimals it wants. Without a product and without a class the amount is kept.
	 *
	 * @param float            $excl      Amount excluding tax.
	 * @param \WC_Product|null $product   Product the amount belongs to.
	 * @param string|null      $tax_class Tax class slug, used when there is no product.
	 */
	public static function figure( float $excl, ?\WC_Product $product = null, ?string $tax_class = null ): float {
		if ( 'incl' !== self::display() ) {
			return $excl;
		}

		if ( $product instanceof \WC_Product ) {
			if ( ! $product->is_taxable() ) {
				return $excl;
			}

			$class = $product->get_tax_class();
		} elseif ( null !== $tax_class ) {
			$class = $tax_class;
		} else {
			return $excl;
		}

		return round( $excl + array_sum( \WC_Tax::calc_tax( $excl, \WC_Tax::get_rates( $class ), false ) ), 8 );
	}

	/**
	 * An amount as plain text, the way the shop shows prices ("€16.50", "€0.0226"): wc_price() without its markup. No tax is added.
	 *
	 * @param float    $amount   Amount.
	 * @param int|null $decimals Decimals, null for the shop's price decimals.
	 * @param string   $currency Currency code, '' for the current one.
	 * @param bool     $nbsp     Spaces in it become non-breaking, so an amount never wraps inside (Rates::money() does; some labels never did).
	 */
	public static function plain( float $amount, ?int $decimals = 2, string $currency = '', bool $nbsp = true ): string {
		$args = array();

		if ( null !== $decimals ) {
			$args['decimals'] = $decimals;
		}

		if ( '' !== $currency ) {
			$args['currency'] = $currency;
		}

		$text = html_entity_decode( wp_strip_all_tags( wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );

		return $nbsp ? str_replace( ' ', "\xC2\xA0", trim( $text ) ) : $text;
	}

	/**
	 * A catalogue figure as plain text in the display in force: for a label, an attribute or a script, where HTML does not go.
	 *
	 * @param float            $excl     Amount excluding tax.
	 * @param int|null         $decimals Decimals, null for the shop's price decimals.
	 * @param \WC_Product|null $product  Product the amount belongs to (null: shown as given).
	 * @param string           $currency Currency code, '' for the current one.
	 * @param bool             $nbsp     See plain().
	 */
	public static function text( float $excl, ?int $decimals = 2, ?\WC_Product $product = null, string $currency = '', bool $nbsp = true ): string {
		return self::plain( self::figure( $excl, $product ), $decimals, $currency, $nbsp );
	}

	/**
	 * Runs a callback in the display of the cart (woocommerce_tax_display_cart): for prices printed in the cart and the order summary.
	 *
	 * @param callable $fn Callback.
	 * @return mixed
	 */
	public static function in_cart( callable $fn ) {
		return self::in( self::display( 'cart' ), $fn );
	}

	/**
	 * Whether a catalogue figure of this product is shown including tax: the display in force is including and the amount belongs to a
	 * product. Without a product the amount is shown as given (excluding tax) and the sentence around it says so.
	 *
	 * @param \WC_Product|null $product Product the amount belongs to.
	 */
	public static function incl_for( ?\WC_Product $product ): bool {
		return null !== $product && 'incl' === self::display();
	}

	/**
	 * Whether prices are shown including or excluding tax: 'incl', 'excl', or '' when the shop charges no tax.
	 *
	 * @param string $context 'shop' or 'cart'.
	 */
	public static function mode( string $context = 'shop' ): string {
		if ( ! wc_tax_enabled() ) {
			return '';
		}

		$value = (string) get_option( 'cart' === $context ? 'woocommerce_tax_display_cart' : 'woocommerce_tax_display_shop' );

		return 'incl' === $value ? 'incl' : 'excl';
	}

	/**
	 * The words that go next to a price: "incl. VAT" or "excl. VAT", empty when the shop charges no tax.
	 *
	 * @param string $context 'shop' or 'cart'.
	 */
	public static function note( string $context = 'shop' ): string {
		$mode = self::mode( $context );

		if ( 'incl' === $mode ) {
			return __( 'incl. VAT', 'beltoft-services' );
		}

		return 'excl' === $mode ? __( 'excl. VAT', 'beltoft-services' ) : '';
	}

	/**
	 * The figure to print for a product, in the shop's display setting.
	 *
	 * @param \WC_Product $product Product.
	 * @param float|null  $excl    An amount excluding tax that belongs to the product (a cap, a difference), or null for its price.
	 */
	public static function price( \WC_Product $product, ?float $excl = null ): float {
		return (float) ( null === $excl ? wc_get_price_to_display( $product ) : wc_get_price_to_display( $product, array( 'price' => $excl ) ) );
	}

	/**
	 * A catalogue price as HTML.
	 *
	 * $price is either the amount as this plugin prints it today (a float, excluding tax) or a callable
	 * function ( string $display = '' ): string that renders the price ('' for the shop's display, 'incl' or 'excl' for that
	 * display). Without a listener the result is the shop's display of it: wc_price() of the amount, or the callable's output.
	 * With the display excluding tax, or no tax context, that is exactly what the plugin printed before.
	 *
	 * Arguments ($args): currency (code, default the current one), decimals, product (a \WC_Product that gives the tax
	 * class), tax_class (a tax class slug, used when there is no product), plain (true: the figure is plain text escaped
	 * with esc_html(), the way text printed in a page was before, not wc_price() markup).
	 *
	 * The result passes through the filter beltoft_services_price_html ( string $html, ?float $amount, string $currency,
	 * string $context, callable $render ): $amount is null when $price was a callable, $context says where the price is
	 * printed (for example 'server-picker'), and $render ( string $display = '' ): string renders the same price in a
	 * tax display, so a listener can print both figures or the other one. A caller outputs the result with wp_kses_post().
	 *
	 * @param float|callable       $price   Amount or renderer.
	 * @param string               $context Where it is printed.
	 * @param array<string, mixed> $args    Arguments.
	 */
	public static function html( $price, string $context, array $args = array() ): string {
		$is_render = $price instanceof \Closure || ( is_callable( $price ) && ! is_string( $price ) );
		$amount    = $is_render ? null : (float) $price;
		$currency  = isset( $args['currency'] ) && '' !== (string) $args['currency'] ? (string) $args['currency'] : (string) get_woocommerce_currency();

		$render = $is_render ? static function ( string $display = '' ) use ( $price ): string {
			return (string) self::in( $display, static fn() => $price( $display ) );
		} : function ( string $display = '' ) use ( $amount, $args ): string {
			return self::in(
				$display,
				static function () use ( $amount, $args ): string {
					$product = isset( $args['product'] ) && $args['product'] instanceof \WC_Product ? $args['product'] : null;
					$figure  = self::figure( (float) $amount, $product, isset( $args['tax_class'] ) ? (string) $args['tax_class'] : null );
					$code    = isset( $args['currency'] ) ? (string) $args['currency'] : '';

					if ( ! empty( $args['plain'] ) ) {
						return esc_html( self::plain( $figure, isset( $args['decimals'] ) ? (int) $args['decimals'] : null, $code, false ) );
					}

					$wc = array();

					if ( isset( $args['decimals'] ) ) {
						$wc['decimals'] = (int) $args['decimals'];
					}

					if ( '' !== $code ) {
						$wc['currency'] = $code;
					}

					return wc_price( $figure, $wc );
				}
			);
		};

		return (string) apply_filters( 'beltoft_services_price_html', (string) $render(), $amount, $currency, $context, $render );
	}
}
