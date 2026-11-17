<?php
/**
 * Pay as you go on the website: prices kept out of "from" prices and plan-card loops, and Bricks tags for the rates.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Brand;
use BeltoftServices\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * A pay-as-you-go variation has a price of 0 (the customer pays by the hour), so it must never be taken for a plan:
 *
 * - WooCommerce's visible children of a variable product (the source of "From" prices and the synced price) leave
 *   the pay-as-you-go variations out;
 * - a Bricks query loop over product variations leaves them out (a meta query: no `_beltoft_services_billing`).
 *
 * Bricks dynamic data tags give the offer next to a monthly variation (the current post in a loop, or `:ID`):
 *
 *   {beltoft_usage_line}    "Or €0.0226/hour, max €16.50/month, pay as you go", empty when there is no offer
 *   {beltoft_usage_hourly}  "€0.0226"
 *   {beltoft_usage_cap}     "€16.50" ("€15" for a whole amount)
 *
 * The figures are in the shop's tax display for the visitor's currency (including tax when the shop shows prices that way).
 *
 * The tags are empty for a variation without a pay-as-you-go sibling, so a text element can hide itself with the
 * condition "dynamic data is not empty".
 */
final class Catalog {

	/** Tag name => translated label. */
	private static function labels(): array {
		return array(
			'beltoft_usage_line' => __( 'Pay as you go: hourly price and monthly cap as one line', 'beltoft-services' ),
			'beltoft_usage_hourly' => __( 'Pay as you go: price per hour', 'beltoft-services' ),
			'beltoft_usage_cap' => __( 'Pay as you go: monthly cap', 'beltoft-services' ),
		);
	}

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_children', array( __CLASS__, 'visible_children' ), 20, 3 );
		add_filter( 'bricks/posts/query_vars', array( __CLASS__, 'query_vars' ), 20 );
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render' ), 20, 3 );
		// render_data passes the area of the content, not its context: plain there, as only a text element may take markup.
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render_data' ), 20, 2 );

		// The figures kept by value() are forgotten when what they come from changes (the same list the site's own tags use).
		foreach ( array( 'updated_post_meta', 'added_post_meta', 'deleted_post_meta', 'updated_option', 'added_option', 'deleted_option', 'clean_post_cache', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_after_product_object_save', 'woocommerce_tax_rate_added', 'woocommerce_tax_rate_updated', 'woocommerce_tax_rate_deleted', 'set_current_user', 'switch_blog' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget' ), 1, 0 );
		}
	}

	/**
	 * The figures worked out so far in this request: key => text.
	 *
	 * @var array<string, string>
	 */
	private static array $memo = array();

	/**
	 * Counts bumped by forget(): an answer worked out while data changed is not kept.
	 *
	 * @var int
	 */
	private static int $generation = 0;

	/**
	 * Forgets every figure (data it may come from changed). Tests call it between cases.
	 */
	public static function forget(): void {
		++self::$generation;
		self::$memo = array();
	}

	/**
	 * Everything the text of a tag depends on besides the tag and the variation: the shop and customer currency, the tax
	 * display in force (a forced one, a VAT-exempt customer, the shop's setting), the customer's tax address, the user, the
	 * locale and how a price is printed. Read when the figure is asked for, so a change inside one request is a different key.
	 */
	private static function state(): string {
		$customer = function_exists( 'WC' ) && WC() ? WC()->customer : null;
		$address  = '';

		if ( $customer instanceof \WC_Customer ) {
			$address = implode( '/', array( $customer->get_billing_country(), $customer->get_billing_state(), $customer->get_billing_postcode(), $customer->get_shipping_country(), $customer->get_shipping_state(), $customer->get_shipping_postcode(), $customer->get_is_vat_exempt() ? 'exempt' : '' ) );
		}

		$current = Currency::current();

		return implode(
			'|',
			array(
				Currency::base(),
				$current,
				get_woocommerce_currency(),
				get_woocommerce_currency_symbol( $current ),
				TaxNote::display(),
				wc_tax_enabled() ? 'tax' : 'notax',
				get_current_user_id(),
				determine_locale(),
				$address,
				wc_get_price_decimals(),
				get_woocommerce_price_format(),
			)
		);
	}

	/**
	 * The visible children (the ones prices are read from) without pay-as-you-go variations.
	 *
	 * @param mixed $children Child ids.
	 * @param mixed $product  Product.
	 * @param mixed $visible  Whether this is the visible list.
	 * @return mixed
	 */
	public static function visible_children( $children, $product = null, $visible = false ) {
		if ( ! $visible || ! is_array( $children ) ) {
			return $children;
		}

		return array_values(
			array_filter(
				$children,
				static fn( $id ) => Rates::KIND_HOURLY !== (string) get_post_meta( (int) $id, Rates::META_BILLING, true )
			)
		);
	}

	/**
	 * A Bricks loop over product variations never lists a pay-as-you-go variation.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( ! is_array( $vars ) || ! in_array( 'product_variation', (array) ( $vars['post_type'] ?? array() ), true ) ) {
			return $vars;
		}

		$vars['meta_query']   = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a small catalogue.
		// Only an hourly variation is hidden: a counted one is the plan the customer buys.
		$vars['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => Rates::META_BILLING,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Rates::META_BILLING,
				'value'   => Rates::KIND_COUNTED,
			),
		);

		return $vars;
	}

	/**
	 * The tags in the Bricks list.
	 *
	 * @param mixed $tags Tags.
	 * @return mixed
	 */
	public static function tags_list( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		foreach ( self::labels() as $name => $label ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => Brand::name(),
			);
		}

		return $tags;
	}

	/**
	 * The same for bricks/frontend/render_data, whose second argument is the area: the figure is the plain text in the display.
	 *
	 * @param mixed $content Content.
	 * @param mixed $post    Current post or area.
	 * @return mixed
	 */
	public static function render_data( $content, $post = null ) {
		return self::render( $content, is_object( $post ) ? $post : null, 'plain' );
	}

	/**
	 * Replaces the tags in a piece of content.
	 *
	 * @param mixed $content Content.
	 * @param mixed $post    Current post.
	 * @param mixed $context Bricks content context: 'text', 'link', 'image' or 'media'.
	 * @return mixed
	 */
	public static function render( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{beltoft_usage_' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/\{(beltoft_usage_(?:line|hourly|cap))(?::(\d+))?\}/',
			static function ( $m ) use ( $post, $context ) {
				$id = ! empty( $m[2] ) ? (int) $m[2] : ( isset( $post->ID ) ? (int) $post->ID : 0 );

				$tag  = $m[1];
				$text = esc_html( self::value( $tag, $id ) );

				// Only text content takes markup. In an attribute, a link or an image field the figure is the plain text in the shop's display.
				if ( '' === $text || 'text' !== $context ) {
					return $text;
				}

				// The figure is in the shop's tax display. The html passes beltoft_services_price_html, so a site can show both
				// figures; the default is exactly the escaped text. Nothing here is cached by the plugin: a page cache must vary
				// by currency and tax display, as it does for the prices themselves.
				$html = TaxNote::html( static fn( string $display = '' ): string => esc_html( self::value( $tag, $id ) ), 'usage-tag' );

				return $html === $text ? $text : wp_kses_post( $html );
			},
			$content
		);
	}

	/**
	 * The text of one tag for a variation ('' when it has no pay-as-you-go offer).
	 *
	 * @param string $tag Tag name without braces.
	 * @param int    $id  Variation id.
	 */
	public static function value( string $tag, int $id ): string {
		// A page prints the same figure many times, and every one costs a product load, its sibling, a tax figure and a conversion.
		$slot = $tag . '/' . $id . '/' . self::state();

		if ( array_key_exists( $slot, self::$memo ) ) {
			return self::$memo[ $slot ];
		}

		$generation = self::$generation;
		$text       = self::compute( $tag, $id );

		// Data that changed while the figure was worked out may have changed it: not kept then.
		if ( $generation === self::$generation ) {
			if ( count( self::$memo ) >= 2000 ) {
				self::$memo = array();
			}

			self::$memo[ $slot ] = $text;
		}

		return $text;
	}

	/**
	 * The text of one tag, worked out.
	 *
	 * @param string $tag Tag name without braces.
	 * @param int    $id  Variation id.
	 */
	private static function compute( string $tag, int $id ): string {
		$v = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		if ( ! $v instanceof \WC_Product_Variation ) {
			return '';
		}

		$rates = Rates::for_plan( $v, false ); // A sold-out plan keeps its price on the page.

		if ( null === $rates ) {
			return '';
		}

		switch ( $tag ) {
			case 'beltoft_usage_hourly':
				return Rates::money( $rates['hourly'], 4, '', $v );
			case 'beltoft_usage_cap':
				return Rates::cap_money( $rates['cap'], $v );
			default:
				return Rates::line( $v );
		}
	}
}
