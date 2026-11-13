<?php
/**
 * The period a subscription line of an order pays for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

use BeltoftServices\OrderLanguage;

defined( 'ABSPATH' ) || exit;

/**
 * Each product line of an order that starts or renews a subscription keeps the period it pays for, as one line of text in the
 * order's language ("Period: 13.11.2026 - 12.12.2026"), so an accounting system can print it under the line. The first order pays
 * from its date; a renewal from the date it is made. The end is one billing period later, less a day.
 */
final class LinePeriod {

	/** Order item meta: the period as text. Hidden from the order screens. */
	public const META = '_beltoft_services_period';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_subscription_created', array( __CLASS__, 'on_checkout' ), 20, 2 );
		add_filter( 'wcs_renewal_order_created', array( __CLASS__, 'on_renewal' ), 20, 2 );
	}

	/**
	 * The first order of a subscription.
	 *
	 * @param mixed $subscription Subscription.
	 * @param mixed $order        Order.
	 */
	public static function on_checkout( $subscription, $order = null ): void {
		if ( $subscription instanceof \WC_Subscription && $order instanceof \WC_Order ) {
			self::write( $order, $subscription );
		}
	}

	/**
	 * A renewal order.
	 *
	 * @param mixed $renewal      Renewal order.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public static function on_renewal( $renewal, $subscription = null ) {
		if ( $renewal instanceof \WC_Order && $subscription instanceof \WC_Subscription ) {
			self::write( $renewal, $subscription );
		}

		return $renewal;
	}

	/**
	 * Writes the period on the order's lines of the subscription's products.
	 *
	 * @param \WC_Order        $order        Order.
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public static function write( \WC_Order $order, \WC_Subscription $subscription ): void {
		$text = self::text( $order, $subscription );

		if ( '' === $text ) {
			return;
		}

		$products = array();

		foreach ( $subscription->get_items() as $line ) {
			if ( $line instanceof \WC_Order_Item_Product ) {
				$products[] = (int) ( $line->get_variation_id() ?: $line->get_product_id() );
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && in_array( (int) ( $item->get_variation_id() ?: $item->get_product_id() ), $products, true ) && (float) $item->get_total() > 0 ) {
				$item->update_meta_data( self::META, $text );
				$item->save();
			}
		}
	}

	/**
	 * The period as text, or '' when the subscription has no fixed period.
	 *
	 * @param \WC_Order        $order        Order.
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public static function text( \WC_Order $order, \WC_Subscription $subscription ): string {
		$interval = (int) $subscription->get_billing_interval();
		$period   = (string) $subscription->get_billing_period();
		$created  = $order->get_date_created();

		if ( $interval < 1 || '' === $period || ! $created || ! function_exists( 'wcs_add_time' ) ) {
			return '';
		}

		$start = $created->getTimestamp();
		$end   = wcs_add_time( $interval, $period, $start ) - DAY_IN_SECONDS;
		$text  = '';

		OrderLanguage::run(
			$order,
			static function () use ( &$text, $start, $end ): void {
				/* translators: 1: first day, 2: last day of the period (dates). */
				$text = sprintf( __( 'Period: %1$s - %2$s', 'beltoft-services' ), wp_date( 'd.m.Y', $start ), wp_date( 'd.m.Y', $end ) );
			}
		);

		return $text;
	}
}
