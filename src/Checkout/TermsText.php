<?php
/**
 * The terms box of the classic checkout shows the text of the terms page itself.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce prints the post content of the terms page in the checkout. A page built with Bricks keeps its text in the Bricks
 * content and leaves the post content as it was when the page was last saved by hand, so customers accepted an older text than the
 * page shows. This takes over the box (the action `woocommerce_checkout_terms_and_conditions` at priority 30): it prints the text
 * elements of the Bricks content of the terms page WooCommerce names (`wc_terms_and_conditions_page_id()`, which a language plugin
 * answers with the page of the visitor's language), sanitized with wp_kses_post(). A page without Bricks text keeps WooCommerce's own box.
 */
final class TermsText {

	/** Hooks. */
	public static function register(): void {
		add_action( 'woocommerce_checkout_terms_and_conditions', array( __CLASS__, 'swap' ), 1 );
	}

	/** Replaces WooCommerce's box for a terms page that has Bricks text. */
	public static function swap(): void {
		if ( '' === self::html() ) {
			return;
		}

		remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30 );
		add_action( 'woocommerce_checkout_terms_and_conditions', array( __CLASS__, 'render' ), 30 );
	}

	/** The box, in WooCommerce's markup. */
	public static function render(): void {
		$html = self::html();

		if ( '' !== $html ) {
			echo '<div class="woocommerce-terms-and-conditions" style="display: none; max-height: 200px; overflow: auto;">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() in html().
		}
	}

	/**
	 * The text of the terms page as the page shows it: its Bricks text elements in order. Empty when the page is not published, is
	 * not a Bricks page or has no text.
	 */
	public static function html(): string {
		$id = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;

		if ( $id < 1 || 'publish' !== get_post_status( $id ) ) {
			return '';
		}

		$elements = get_post_meta( $id, '_bricks_page_content_2', true );
		$text     = '';

		foreach ( is_array( $elements ) ? $elements : array() as $element ) {
			if ( is_array( $element ) && 'text' === ( $element['name'] ?? '' ) && is_string( $element['settings']['text'] ?? null ) ) {
				$text .= $element['settings']['text'];
			}
		}

		return '' === trim( $text ) ? '' : wc_format_content( wp_kses_post( $text ) );
	}
}
