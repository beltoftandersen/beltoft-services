<?php
/** Domain order item mapping tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Meta;
use BeltoftServices\Tests\Support\FakeDriver;
use BeltoftServices\Tests\TestCase;

class MetaTest extends TestCase {
	public function test_two_domains_in_one_order_map_one_to_one(): void {
		$product = $this->service_product( 'domain' );
		$order = wc_create_order();
		foreach ( array( 'one.dk', 'two.dk' ) as $name ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( 1 );
			$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => $name, 'mode' => 'register' ), true );
			$order->add_item( $item );
		}
		$order->save();
		$driver = new FakeDriver( 'domain' );
		$one = Meta::initial_meta( array(), array( 'id' => 101, 'order_id' => $order->get_id(), 'product_id' => $product->get_id() ), $driver );
		$two = Meta::initial_meta( array(), array( 'id' => 102, 'order_id' => $order->get_id(), 'product_id' => $product->get_id() ), $driver );
		$this->assertSame( 'one.dk', $one['domain'] );
		$this->assertSame( 'two.dk', $two['domain'] );
		$this->assertNotSame( $one['domain'], $two['domain'] );
	}
}
