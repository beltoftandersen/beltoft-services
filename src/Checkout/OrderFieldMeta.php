<?php
/**
 * Where a checkout field's value sits on an order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * A value the customer typed is stored on the order line (classic checkout) or on the order
 * itself (block checkout). This lists both places, in the order they are tried.
 */
final class OrderFieldMeta {

	/**
	 * The stored values that may hold the customer's entry, line meta first, the block field last.
	 *
	 * @param \WC_Order $order        Order.
	 * @param int       $product_id   Product of the license.
	 * @param int       $variation_id Variation of the license, 0 for any.
	 * @param string    $item_key     Line meta key of the classic field.
	 * @param string    $block_id     Block checkout field id.
	 * @return array<int, mixed>
	 */
	public static function candidates( \WC_Order $order, int $product_id, int $variation_id, string $item_key, string $block_id ): array {
		$candidates = array();

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && ( 0 === $variation_id || (int) $item->get_variation_id() === $variation_id ) ) {
				$candidates[] = $item->get_meta( $item_key, true );
			}
		}

		$prefix       = class_exists( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class ) ? \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::get_group_key( 'other' ) : '_wc_other/';
		$candidates[] = $order->get_meta( $prefix . $block_id, true );

		return $candidates;
	}
}
