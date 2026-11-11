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
	 * function ( string $display = '' ): string that renders the price ('' for the plugin's own display, 'incl' or
	 * 'excl' for that tax display). Without a listener the result is exactly what the plugin printed before: wc_price() of the amount, or the
	 * callable's output.
	 *
	 * Arguments ($args): currency (code, default the current one), decimals, product (a \WC_Product that gives the
	 * tax rates), tax_class (a tax class slug, used when there is no product).
	 *
	 * The result passes through the filter beltoft_services_price_html ( string $html, ?float $amount, string $currency,
	 * string $context, callable $render ): $amount is null when $price was a callable, $context says where the price is
	 * printed (for example 'server-picker'), and $render ( string $display = '' ): string renders the same price in a
	 * tax display, so a listener can print both figures or the other one.
	 *
	 * @param float|callable       $price   Amount or renderer.
	 * @param string               $context Where it is printed.
	 * @param array<string, mixed> $args    Arguments.
	 */
	public static function html( $price, string $context, array $args = array() ): string {
		$is_render = $price instanceof \Closure || ( is_callable( $price ) && ! is_string( $price ) );
		$amount    = $is_render ? null : (float) $price;
		$currency  = isset( $args['currency'] ) && '' !== (string) $args['currency'] ? (string) $args['currency'] : (string) get_woocommerce_currency();

		$render = $is_render ? $price : function ( string $display = '' ) use ( $amount, $args ): string {
			$wc = array();

			if ( isset( $args['decimals'] ) ) {
				$wc['decimals'] = (int) $args['decimals'];
			}

			if ( isset( $args['currency'] ) && '' !== (string) $args['currency'] ) {
				$wc['currency'] = (string) $args['currency'];
			}

			return wc_price( 'incl' === $display ? self::including( (float) $amount, $args ) : (float) $amount, $wc );
		};

		return (string) apply_filters( 'beltoft_services_price_html', (string) $render(), $amount, $currency, $context, $render );
	}

	/**
	 * An amount excluding tax with its tax added, from the product's tax class or the tax class of the arguments. The amount is always
	 * excluding tax (whatever prices_include_tax says), so the rates are applied to it as they are and nothing is rounded to the shop's
	 * price decimals: the figure is formatted by wc_price() with the decimals the caller asked for.
	 *
	 * @param float                $amount Amount excluding tax.
	 * @param array<string, mixed> $args   Arguments of html().
	 */
	private static function including( float $amount, array $args ): float {
		if ( ! wc_tax_enabled() ) {
			return $amount;
		}

		if ( isset( $args['product'] ) && $args['product'] instanceof \WC_Product ) {
			if ( ! $args['product']->is_taxable() ) {
				return $amount;
			}

			$class = $args['product']->get_tax_class();
		} elseif ( isset( $args['tax_class'] ) ) {
			$class = (string) $args['tax_class'];
		} else {
			return $amount;
		}

		return round( $amount + array_sum( \WC_Tax::calc_tax( $amount, \WC_Tax::get_rates( $class ), false ) ), 8 );
	}
}
