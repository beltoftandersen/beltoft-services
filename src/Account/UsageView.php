<?php
/**
 * My Account → Billing: the "Usage this month" section of a pay-as-you-go customer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Usage\Estimate;

defined( 'ABSPATH' ) || exit;

/**
 * Printed on the first Billing page (Subscriptions), under the tab bar. A shortcode, [beltoft_services_usage],
 * places it elsewhere. Nothing is printed for a customer without a usage account or without usage yet. The
 * template is templates/account/usage.php (copy to yourtheme/beltoft-services/account/usage.php).
 */
final class UsageView {

	/**
	 * Whether it was printed in this request.
	 *
	 * @var bool
	 */
	private static $printed = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_shortcode( 'beltoft_services_usage', array( __CLASS__, 'shortcode' ) );
		// Before Billing's tab bar filter (10), which puts the bar in front of this section.
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'in_bricks' ), 9, 2 );
		add_action( 'woocommerce_account_content', array( __CLASS__, 'in_content' ), 6 );
	}

	/**
	 * Forgets what was printed (tests).
	 */
	public static function reset(): void {
		self::$printed = false;
		Estimate::reset();
	}

	/**
	 * The section's markup, '' when there is nothing to show.
	 */
	public static function html(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$estimate = Estimate::for_user( get_current_user_id() );

		if ( ! $estimate || ! $estimate['resources'] ) {
			return '';
		}

		Panel::style();
		self::$printed = true;

		ob_start();
		wc_get_template( 'usage.php', array( 'estimate' => $estimate ), 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

		return (string) ob_get_clean();
	}

	/**
	 * Shortcode.
	 */
	public static function shortcode(): string {
		return self::html();
	}

	/**
	 * Whether the page is the one the section prints on.
	 */
	private static function here(): bool {
		return ! self::$printed && 'subscriptions' === Billing::current_tab();
	}

	/**
	 * With Bricks: above the Account element (the tab bar goes above that).
	 *
	 * @param mixed $html    The element's markup.
	 * @param mixed $element The element.
	 * @return mixed
	 */
	public static function in_bricks( $html, $element ) {
		if ( ! is_string( $html ) || ! is_object( $element ) || 'woocommerce-account-page-v2' !== ( $element->name ?? '' ) || ! self::here() ) {
			return $html;
		}

		return self::html() . $html;
	}

	/**
	 * Without Bricks: at the top of the account content, after the tab bar.
	 */
	public static function in_content(): void {
		if ( defined( 'BRICKS_VERSION' ) || ! self::here() ) {
			return;
		}

		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}
}
