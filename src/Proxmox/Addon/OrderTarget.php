<?php
/**
 * Where an order for a server add-on leads after the hand-off screen: the server's page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Addon;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Answers beltoft_services_order_target_license (Account\OrderTarget) for an add-on line (a disk, a snapshot pack, an app licence): the
 * line names its server in its own meta, set at add to cart, so the page is known before the add-on's service exists.
 */
final class OrderTarget {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_order_target_license', array( __CLASS__, 'answer' ), 10, 3 );
	}

	/**
	 * The licence row of the server the add-on lines are for.
	 *
	 * @param mixed $found Answer so far.
	 * @param mixed $order Order.
	 * @param mixed $phase 'meta' or 'lines'.
	 * @return mixed
	 */
	public static function answer( $found, $order, $phase ) {
		if ( null !== $found || 'lines' !== $phase || ! $order instanceof \WC_Order ) {
			return $found;
		}

		return self::server_from_lines( $order );
	}

	/**
	 * The server the add-on lines of the order are for, read from the lines' own meta: the licence row of that server, false when the
	 * lines point to no single server of the customer (a mix, or another customer's), null when no line is an add-on line.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>|false|null
	 */
	private static function server_from_lines( \WC_Order $order ) {
		$parent = 0;
		$linked = 0;
		$other  = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! Product::is_service( (int) ( $item->get_variation_id() ?: $item->get_product_id() ) ) ) {
				continue;
			}

			$link = 0;

			foreach ( Addon::all() as $addon ) {
				$value = $item->get_meta( $addon->item_server(), true );

				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$link = (int) $value;
					break;
				}
			}

			if ( $link < 1 ) {
				++$other;
				continue;
			}

			if ( $parent > 0 && $parent !== $link ) {
				return false;
			}

			$parent = $link;
			++$linked;
		}

		if ( 0 === $linked ) {
			return null;
		}

		$server = 0 === $other ? Licenses::find( $parent ) : null;

		return $server && (int) $server['user_id'] === (int) $order->get_customer_id() ? $server : false;
	}
}
