<?php
/**
 * Where a checkout field's value sits on an order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Checkout;

use BeltoftServices\Checkout\OrderFieldMeta;
use BeltoftServices\Tests\TestCase;

/**
 * The order-line meta and the block field meta, in the order they are tried.
 */
class OrderFieldMetaTest extends TestCase {

	/**
	 * A line for a product, with one meta value.
	 *
	 * @param \WC_Order   $order   Order.
	 * @param \WC_Product $product Product.
	 * @param string     $value   Value of the meta key 'k'.
	 */
	private function line( \WC_Order $order, \WC_Product $product, string $value ): void {
		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->add_meta_data( 'k', $value );
		$order->add_item( $item );
	}

	private function product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'P' );
		$product->save();

		return $product;
	}

	public function test_candidates_are_the_matching_items_meta_then_the_block_meta(): void {
		$one   = $this->product();
		$other = $this->product();
		$order = wc_create_order();

		$this->line( $order, $one, 'first' );
		$this->line( $order, $other, 'foreign' );
		$this->line( $order, $one, 'second' );
		$order->update_meta_data( '_wc_other/beltoft/id', 'block' );
		$order->save();

		$this->assertSame( array( 'first', 'second', 'block' ), OrderFieldMeta::candidates( $order, $one->get_id(), 0, 'k', 'beltoft/id' ) );
	}

	public function test_a_variation_narrows_the_items(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'V' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->save();

		$other = new \WC_Product_Variation();
		$other->set_parent_id( $parent->get_id() );
		$other->save();

		$order = wc_create_order();

		$this->line( $order, $other, 'other variation' );
		$this->line( $order, $variation, 'wanted' );
		$order->save();

		$this->assertSame( array( 'wanted', '' ), OrderFieldMeta::candidates( $order, $parent->get_id(), $variation->get_id(), 'k', 'beltoft/id' ) );
		$this->assertSame( array( 'other variation', 'wanted', '' ), OrderFieldMeta::candidates( $order, $parent->get_id(), 0, 'k', 'beltoft/id' ) );
	}
}
