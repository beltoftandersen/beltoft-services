<?php
/**
 * My Account → Billing → Subscriptions: the list and the page of one subscription.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Billing\SubscriptionNames;
use BeltoftServices\Usage\Account as UsageAccount;

defined( 'ABSPATH' ) || exit;

/**
 * The list (template templates/account/my-subscriptions.php, in the place of WooCommerce Subscriptions' own) leads with
 * the product names, shows live subscriptions first and folds the ended ones. The pay-as-you-go account is the card
 * above the list (UsageView), so it has no row. The page of one subscription gets a heading with the product names, a
 * note when it has ended, absolute dates, and for the pay-as-you-go account its usage in place of an empty totals table.
 */
final class SubscriptionPages {

	/**
	 * Statuses of a subscription that is over.
	 */
	public const ENDED = array( 'cancelled', 'expired', 'switched' );

	/**
	 * Whether a subscription page is being printed (dates are then shown in full).
	 *
	 * @var bool
	 */
	private static $on_page = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wc_get_template', array( __CLASS__, 'template' ), 10, 2 );
		add_action( 'woocommerce_account_view-subscription_endpoint', array( __CLASS__, 'head' ), 5 );
		add_filter( 'woocommerce_subscription_date_to_display', array( __CLASS__, 'full_date' ), 10, 3 );
	}

	/**
	 * Forgets the page state (tests).
	 */
	public static function reset(): void {
		self::$on_page = false;
	}

	/**
	 * Filter wc_get_template: the list template.
	 *
	 * @param mixed $located       Located template.
	 * @param mixed $template_name Name.
	 * @return mixed
	 */
	public static function template( $located, $template_name = '' ) {
		return 'myaccount/my-subscriptions.php' === $template_name ? BELTOFT_SERVICES_PATH . 'templates/account/my-subscriptions.php' : $located;
	}

	/**
	 * The customer's subscriptions for the list: live (newest first), ended (newest first). Trash is never in it, and
	 * neither is the pay-as-you-go account that the card above the list stands for.
	 *
	 * @param int $user_id Customer.
	 * @return array{live: array<int, \WC_Subscription>, ended: array<int, \WC_Subscription>}
	 */
	public static function groups( int $user_id ): array {
		$out  = array(
			'live'  => array(),
			'ended' => array(),
		);
		$card = $user_id > 0 ? UsageAccount::for_user( $user_id ) : null;
		$all  = function_exists( 'wcs_get_users_subscriptions' ) ? wcs_get_users_subscriptions( $user_id ) : array();

		foreach ( $all as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription || 'trash' === $subscription->get_status() || ( $card && $card->get_id() === $subscription->get_id() ) ) {
				continue;
			}

			$out[ in_array( $subscription->get_status(), self::ENDED, true ) ? 'ended' : 'live' ][] = $subscription;
		}

		foreach ( $out as $key => $list ) {
			usort(
				$list,
				static function ( \WC_Subscription $a, \WC_Subscription $b ): int {
					return array( $b->get_time( 'date_created', 'gmt' ), $b->get_id() ) <=> array( $a->get_time( 'date_created', 'gmt' ), $a->get_id() );
				}
			);
			$out[ $key ] = $list;
		}

		return $out;
	}

	/**
	 * The heading of a subscription page, its note, and for the pay-as-you-go account the usage.
	 *
	 * @param mixed $subscription_id Subscription id.
	 */
	public static function head( $subscription_id ): void {
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( absint( $subscription_id ) ) : null;

		if ( ! $subscription instanceof \WC_Subscription || ! current_user_can( 'view_order', $subscription->get_id() ) ) {
			return;
		}

		self::$on_page = true;
		$payg          = UsageAccount::is_account( $subscription );
		$names         = SubscriptionNames::names( $subscription );

		echo '<div class="bs-sub-head"><h2 class="bs-sub-head__title">' . esc_html( '' !== $names ? $names : __( 'Pay as you go account', 'beltoft-services' ) ) . '</h2>';
		/* translators: %s: subscription number. */
		echo '<p class="bs-sub-head__id">' . esc_html( sprintf( __( 'Subscription #%s', 'beltoft-services' ), $subscription->get_order_number() ) ) . '</p>';

		if ( in_array( $subscription->get_status(), self::ENDED, true ) ) {
			$when = (int) $subscription->get_time( 'end', 'gmt' );
			$when = $when > 0 ? $when : (int) $subscription->get_time( 'cancelled', 'gmt' );

			if ( $when > 0 ) {
				/* translators: %s: date. */
				$text = sprintf( __( 'This subscription ended on %s.', 'beltoft-services' ), wp_date( (string) get_option( 'date_format' ), $when ) );
			} else {
				$text = __( 'This subscription has ended.', 'beltoft-services' );
			}

			echo '<p class="bs-note">' . esc_html( $text ) . '</p>';
		}

		if ( $payg ) {
			echo '<p class="bs-note">' . esc_html__( 'Servers and other services billed by the hour are invoiced here once a month. The amount depends on use.', 'beltoft-services' ) . '</p>';
		}

		echo '</div>';

		if ( $payg ) {
			// Nothing to total: the amount is the usage.
			remove_action( 'woocommerce_subscription_totals_table', array( 'WCS_Template_Loader', 'get_subscription_totals_template' ) );
			echo UsageView::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		}
	}

	/**
	 * Filter woocommerce_subscription_date_to_display: a date within a week is "3 days ago" or "in 2 days" in
	 * WooCommerce Subscriptions; on a subscription page it is the date, like the others.
	 *
	 * @param mixed $text Text so far.
	 * @param mixed $type Date type.
	 * @param mixed $sub  Subscription.
	 * @return mixed
	 */
	public static function full_date( $text, $type = '', $sub = null ) {
		if ( ! self::$on_page || ! $sub instanceof \WC_Subscription || ! is_string( $type ) || ( 'next_payment' === $type && ! $sub->has_status( 'active' ) ) ) {
			return $text;
		}

		$time = (int) $sub->get_time( $type, 'gmt' );

		return $time > 0 && abs( $time - time() ) < WEEK_IN_SECONDS ? wp_date( (string) get_option( 'date_format' ), $time ) : $text;
	}
}
