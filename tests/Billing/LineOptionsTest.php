<?php
/**
 * The options of an order line in the customer's words.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\LineOptions;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

final class LineOptionsTest extends UsageTestCase {

	public function test_a_new_line_keeps_its_options_as_label_and_value(): void {
		$v     = $this->usage_product();
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $v['m-Daily'] );
		$item->set_props( array( 'variation' => array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'Daily' ) ) );
		$item->add_meta_data( 'gift-note', 'Happy birthday', true );
		$order->add_item( $item );

		$this->assertSame( 20, has_action( 'woocommerce_checkout_create_order_line_item', array( LineOptions::class, 'keep' ) ) );
		LineOptions::keep( $item );

		$options = json_decode( (string) $item->get_meta( LineOptions::META ), true );
		$this->assertContains( array( 'Backups', 'Daily', 'backups' ), $options, 'Kept before the line is saved, as at checkout, with its key.' );
		$this->assertNotContains( array( 'gift-note', 'Happy birthday', 'gift-note' ), $options, 'Other visible meta is not an option.' );
	}

	public function test_a_line_keeps_the_product_name_through_the_filter(): void {
		$v     = $this->usage_product();
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $v['m-Daily'] );
		$order->add_item( $item );
		$parent = wc_get_product( $v['m-Daily']->get_parent_id() )->get_name();

		$filter = static fn( $name ) => 'Dansk ' . $name;
		add_filter( 'beltoft_services_line_title', $filter );
		LineOptions::keep( $item );
		remove_filter( 'beltoft_services_line_title', $filter );

		$this->assertSame( 'Dansk ' . $parent, $item->get_meta( LineOptions::META_TITLE ), 'The parent product name, as the site gives it.' );
	}

	public function test_a_line_without_options_gets_nothing(): void {
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'Fee-like line' );
		$order->add_item( $item );

		LineOptions::keep( $item );

		$this->assertSame( '', (string) $item->get_meta( LineOptions::META ) );
	}
}
