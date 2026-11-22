<?php
/**
 * Where an order for a hosting add-on leads after the hand-off screen: the hosting account's page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Answers beltoft_services_order_target_license (Account\OrderTarget) for a hosting add-on order: its meta names the account.
 */
final class OrderTarget {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_order_target_license', array( __CLASS__, 'answer' ), 10, 3 );
	}

	/**
	 * The licence row of the hosting account the add-on order is for.
	 *
	 * @param mixed $found Answer so far.
	 * @param mixed $order Order.
	 * @param mixed $phase 'meta' or 'lines'.
	 * @return mixed
	 */
	public static function answer( $found, $order, $phase ) {
		if ( null !== $found || 'meta' !== $phase || ! $order instanceof \WC_Order ) {
			return $found;
		}

		$meta = $order->get_meta( Addons::ORDER_META );

		if ( is_array( $meta ) && (int) ( $meta['resource'] ?? 0 ) > 0 ) {
			$r = Resource::find( (int) $meta['resource'] );

			return $r ? $r->license() : false;
		}

		return $found;
	}
}
