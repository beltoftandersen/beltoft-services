<?php
/**
 * Pay as you go on the website: prices kept out of "from" prices and plan-card loops, and Bricks tags for the rates.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

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
 *   {beltoft_usage_line}    "or EUR 0.0226/hour, max EUR 16.50/month pay as you go", empty when there is no offer
 *   {beltoft_usage_hourly}  "EUR 0.0226"
 *   {beltoft_usage_cap}     "EUR 16.50"
 *
 * The tags are empty for a variation without a pay-as-you-go sibling, so a text element can hide itself with the
 * condition "dynamic data is not empty".
 */
final class Catalog {

	public const TAGS = array(
		'beltoft_usage_line'   => 'Pay as you go: hourly price and monthly cap as one line',
		'beltoft_usage_hourly' => 'Pay as you go: price per hour',
		'beltoft_usage_cap'    => 'Pay as you go: monthly cap',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_children', array( __CLASS__, 'visible_children' ), 20, 3 );
		add_filter( 'bricks/posts/query_vars', array( __CLASS__, 'query_vars' ), 20 );
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render' ), 20, 2 );
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
				static fn( $id ) => 'usage' !== (string) get_post_meta( (int) $id, Rates::META_BILLING, true )
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
		$vars['meta_query'][] = array(
			'key'     => Rates::META_BILLING,
			'compare' => 'NOT EXISTS',
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

		foreach ( self::TAGS as $name => $label ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => 'Beltoft',
			);
		}

		return $tags;
	}

	/**
	 * Replaces the tags in a piece of content.
	 *
	 * @param mixed $content Content.
	 * @param mixed $post    Current post.
	 * @return mixed
	 */
	public static function render( $content, $post = null ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{beltoft_usage_' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/\{(beltoft_usage_(?:line|hourly|cap))(?::(\d+))?\}/',
			static function ( $m ) use ( $post ) {
				$id = ! empty( $m[2] ) ? (int) $m[2] : ( isset( $post->ID ) ? (int) $post->ID : 0 );

				return esc_html( self::value( $m[1], $id ) );
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
		$v = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		if ( ! $v instanceof \WC_Product_Variation ) {
			return '';
		}

		$rates = Rates::for_plan( $v );

		if ( null === $rates ) {
			return '';
		}

		switch ( $tag ) {
			case 'beltoft_usage_hourly':
				return Rates::money( $rates['hourly'], 4 );
			case 'beltoft_usage_cap':
				return Rates::money( $rates['cap'] );
			default:
				return Rates::line( $v );
		}
	}
}
