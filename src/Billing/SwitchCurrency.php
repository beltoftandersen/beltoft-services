<?php
/**
 * A subscription is switched in its own currency.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

use BeltoftServices\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Plan switches, backup and snapshot changes, extra disks, public addresses and app licences all go through a WooCommerce Subscriptions
 * switch, whose prices come from the cart. The plugin writes no price itself there, so the one thing to keep is that the cart is in the
 * subscription's currency: a switch of a subscription in another currency than the cart is refused (never a figure of one currency charged as the other).
 */
final class SwitchCurrency {

	/** @var bool One notice per request. */
	private static bool $noticed = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', array( __CLASS__, 'can_switch' ), 5, 3 );
	}

	/**
	 * Refuses a switch when the cart is not in the subscription's currency.
	 *
	 * @param mixed $can          Whether the item can be switched.
	 * @param mixed $item         The line item.
	 * @param mixed $subscription The subscription.
	 * @return mixed
	 */
	public static function can_switch( $can, $item = null, $subscription = null ) {
		if ( ! $can || ! $subscription instanceof \WC_Subscription ) {
			return $can;
		}

		$code = Currency::clean( $subscription->get_currency() );

		if ( '' === $code || $code === Currency::current() ) {
			return $can;
		}

		if ( ! self::$noticed && function_exists( 'wc_add_notice' ) ) {
			self::$noticed = true;
			/* translators: %s: currency code such as DKK */
			wc_add_notice( sprintf( __( 'This subscription is in %s. Switch it from a page in that currency.', 'beltoft-services' ), $code ), 'notice' );
		}

		return false;
	}
}
