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
 * Printed as the Pay as you go card on the Subscriptions list (not on the page of one subscription: the pay-as-you-go
 * account's own page prints the block), under the tab bar. A shortcode, [beltoft_services_usage],
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

		return self::render( $estimate, '' );
	}

	/**
	 * The Pay as you go card at the top of the subscription list: usage, credit, next invoice and a link to the account's
	 * subscription. '' for a customer without a pay-as-you-go account.
	 */
	public static function card(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$estimate = Estimate::for_user( get_current_user_id() );
		$account  = $estimate ? \BeltoftServices\Usage\Account::for_user( get_current_user_id() ) : null;

		if ( ! $estimate || ! $account ) {
			return '';
		}

		return self::render( $estimate, $account->get_view_order_url(), wcs_get_subscription_status_name( $account->get_status() ), SubscriptionPages::tone( $account->get_status() ) );
	}

	/**
	 * Prints the template.
	 *
	 * @param array<string, mixed> $estimate Estimate.
	 * @param string               $view_url Address of the account's subscription ('' for the plain block).
	 * @param string               $state    Status word of the account (the card).
	 * @param string               $tone     Colour group of the status (the card).
	 */
	private static function render( array $estimate, string $view_url, string $state = '', string $tone = 'ok' ): string {
		Panel::style();
		self::$printed = true;

		ob_start();
		wc_get_template( 'usage.php', array(
				'estimate' => $estimate,
				'credit'   => \BeltoftServices\GiftCredit\GiftCredit::balance( get_current_user_id(), (string) $estimate['currency'] ),
				'view_url' => $view_url,
				'state'    => $state,
				'tone'     => $tone,
				'links'    => self::links( $estimate ),
			), 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

		return (string) ob_get_clean();
	}

	/**
	 * The page of each service in the estimate (resource id => address).
	 *
	 * @param array<string, mixed> $estimate Estimate.
	 * @return array<int, string>
	 */
	private static function links( array $estimate ): array {
		$links = array();

		foreach ( array_keys( (array) $estimate['resources'] ) as $id ) {
			$resource = \BeltoftServices\Resource::find( (int) $id );
			$url      = $resource ? OverviewData::follow_url( $resource->license() ) : '';

			if ( '' !== $url ) {
				$links[ (int) $id ] = $url;
			}
		}

		return $links;
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
		return ! self::$printed && 'subscriptions' === ( function_exists( 'WC' ) && WC()->query ? (string) WC()->query->get_current_endpoint() : '' );
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

		return self::card() . $html;
	}

	/**
	 * Without Bricks: at the top of the account content, after the tab bar.
	 */
	public static function in_content(): void {
		if ( defined( 'BRICKS_VERSION' ) || ! self::here() ) {
			return;
		}

		echo self::card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}
}
