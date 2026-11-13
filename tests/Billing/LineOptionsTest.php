<?php
/**
 * The invoice text of an order line.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\LineOptions;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

final class LineOptionsTest extends UsageTestCase {

	public function test_a_line_keeps_the_product_name_and_its_main_choice(): void {
		$v      = $this->usage_product();
		$order  = wc_create_order();
		$item   = new \WC_Order_Item_Product();
		$item->set_product( $v['m-Daily'] );
		$item->set_props( array( 'variation' => array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'Daily' ) ) );
		$item->add_meta_data( 'plan', 'Performance', true );
		$order->add_item( $item );
		$parent = wc_get_product( $v['m-Daily']->get_parent_id() )->get_name();

		$filter = static fn( $name ) => 'Dansk ' . $name;
		add_filter( 'beltoft_services_line_title', $filter );
		$this->assertSame( 20, has_action( 'woocommerce_checkout_create_order_line_item', array( LineOptions::class, 'keep' ) ) );
		LineOptions::keep( $item );
		remove_filter( 'beltoft_services_line_title', $filter );

		$this->assertSame( 'Dansk ' . $parent, $item->get_meta( LineOptions::META ), 'No main choice among billing and backups: the name alone.' );
	}

	public function test_a_domain_line_names_its_domain(): void {
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $this->service_product() );
		$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => 'example.com' ), true );
		$order->add_item( $item );

		$this->assertStringEndsWith( ' - example.com', LineOptions::text( $item ) );
	}

	public function test_a_line_without_a_product_gets_nothing(): void {
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'Fee-like line' );
		$order->add_item( $item );

		LineOptions::keep( $item );

		$this->assertSame( '', (string) $item->get_meta( LineOptions::META ) );
	}
}
