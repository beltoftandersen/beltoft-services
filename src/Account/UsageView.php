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
	public static function card( bool $link = true ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$estimate = Estimate::for_user( get_current_user_id() );
		$account  = $estimate ? \BeltoftServices\Usage\Account::for_user( get_current_user_id() ) : null;

		if ( ! $estimate || ! $account ) {
			return '';
		}

		return self::render( $estimate, $link ? $account->get_view_order_url() : '', wcs_get_subscription_status_name( $account->get_status() ), SubscriptionPages::tone( $account->get_status() ), true );
	}

	/**
	 * Prints the template.
	 *
	 * @param array<string, mixed> $estimate Estimate.
	 * @param string               $view_url Address of the account's subscription ('' for the plain block).
	 * @param string               $state    Status word of the account (the card).
	 * @param string               $tone     Colour group of the status (the card).
	 * @param bool                 $card     The card, not the plain block.
	 */
	private static function render( array $estimate, string $view_url, string $state = '', string $tone = 'ok', bool $card = false ): string {
		Panel::style();
		self::$printed = true;

		ob_start();
		wc_get_template( 'usage.php', array(
				'estimate' => $estimate,
				'credit'   => \BeltoftServices\GiftCredit\GiftCredit::balance( get_current_user_id(), (string) $estimate['currency'] ),
				'view_url' => $view_url,
				'state'    => $state,
				'tone'     => $tone,
				'card'     => $card,
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
			// A service deleted this month still has hours on the bill, but its page is gone: plain text, no link.
			$url = $resource && $resource->is_live() ? OverviewData::follow_url( $resource->license() ) : '';

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
}
