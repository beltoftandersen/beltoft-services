<?php
/**
 * A cancelled DNS-only subscription ends at once.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * DNS hosting for a domain registered elsewhere costs nothing, so there is no paid period to run
 * out. WooCommerce Subscriptions would hold the subscription in pending-cancel to the end of its
 * term; this moves it on to cancelled first (priority 5, before beltoft-licenses at 10), and the
 * records are switched off by the normal pause.
 */
final class DnsCancel {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_subscription_status_pending-cancel', array( __CLASS__, 'on_pending_cancel' ), 5 );
	}

	/**
	 * Cancels the subscription now when it holds DNS hosting only and costs nothing.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function on_pending_cancel( $subscription ): void {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_items' ) || ! self::dns_only( $subscription ) ) {
			return;
		}

		$subscription->update_status( 'cancelled', __( 'DNS hosting ends at once when cancelled.', 'beltoft-services' ) );
	}

	/**
	 * Every line is a DNS hosting product and the total is 0.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private static function dns_only( $subscription ): bool {
		$items = $subscription->get_items();

		if ( ! $items || (float) $subscription->get_total() > 0 ) {
			return false;
		}

		foreach ( $items as $item ) {
			$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$driver     = Product::driver_for( $product_id );

			if ( ! $driver || 'dns' !== $driver->id() ) {
				return false;
			}
		}

		return true;
	}
}
