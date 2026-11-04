<?php
/**
 * The state line of every My Account page: one line of a fixed height under the page's title row that says what the
 * page is for, and that shows what the page is doing (busy, done, failed) while a customer acts. A server or database
 * page has the line in its own head (next to the state), so it is not printed twice.
 *
 * The line is the element [data-bs-inline] that assets/status.js writes to.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the line before the account page, and knows the one-line description of each page.
 */
final class PageHead {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// After the Billing tab bar (10): the line goes above the tabs.
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'before_bricks_account' ), 11, 2 );
		add_action( 'woocommerce_account_content', array( __CLASS__, 'in_content' ), 1 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'echo_line' ), 1 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'echo_line' ), 1 );
	}

	/**
	 * The one-line description of the page being shown ('' when there is none).
	 */
	public static function description(): string {
		$endpoint = function_exists( 'WC' ) && WC()->query ? (string) WC()->query->get_current_endpoint() : '';

		// A page says nothing more than its title, except a "new" page, which says what to do there.
		$text = '';

		if ( ServerLinks::slug() === $endpoint && ServerLinks::is_new() ) {
			$text = __( 'Choose a plan and set up a new server', 'beltoft-services' );
		} elseif ( ServiceArea::get( Databases::class )->slug() === $endpoint && ServiceArea::get( Databases::class )->is_new() ) {
			$text = __( 'Choose an engine and set up a new database', 'beltoft-services' );
		}

		/**
		 * Filters the one-line description under the title of a My Account page.
		 *
		 * @param string $text     Description, plain text.
		 * @param string $endpoint The endpoint being shown, '' for the Overview.
		 */
		return (string) apply_filters( 'beltoft_services_page_description', $text, $endpoint );
	}

	/**
	 * The line itself: fixed height, description inside.
	 *
	 * @param string $text Description.
	 */
	public static function line( string $text ): string {
		return '<div class="bs-pagehead"><span class="bs-inline" data-bs-inline role="status" aria-live="polite"><span class="bs-inline__text">' . esc_html( $text ) . '</span></span></div>';
	}

	/**
	 * Before Bricks' account element: the line, unless the page printed its own in its head.
	 *
	 * @param mixed $html    Element HTML.
	 * @param mixed $element Element.
	 * @return mixed
	 */
	public static function before_bricks_account( $html, $element ) {
		if ( ! is_string( $html ) || ! is_object( $element ) || 'woocommerce-account-page-v2' !== ( $element->name ?? '' ) || str_contains( $html, 'data-bs-inline' ) ) {
			return $html;
		}

		return self::line( self::description() ) . $html;
	}

	/**
	 * Without Bricks: at the top of the account content (assets/status.js drops it when the page has its own).
	 */
	public static function in_content(): void {
		if ( defined( 'BRICKS_VERSION' ) ) {
			return;
		}

		echo self::line( self::description() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_html.
	}

	/**
	 * The empty line, on the cart and the checkout, where the add-on fields report.
	 */
	public static function echo_line(): void {
		echo self::line( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_html.
	}
}
