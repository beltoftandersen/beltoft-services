<?php
/**
 * A backup box made from an order knows the order line that paid for it (the spend guard needs it).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Lifecycle;
use BeltoftServices\Resource;
use BeltoftServices\Storage\StorageDriver;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageOrderLineTest extends StorageTestCase {

	/**
	 * An order with $lines lines of one storage product, and the licences made for it.
	 *
	 * @return array{0: \WC_Order, 1: array<int, array<string, mixed>>}
	 */
	private function order_with_lines( int $lines ): array {
		$product = $this->service_product( StorageDriver::ID, array( 'storage_type' => 'bx11', 'storage_size_gb' => 1000 ), array( 'slug' => 'storage-' . wp_generate_password( 6, false ) ) );
		$user    = (int) self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		for ( $i = 0; $i < $lines; $i++ ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_props( array( 'quantity' => 1, 'subtotal' => 8, 'total' => 8 ) );
			$order->add_item( $item );
		}
		$order->save();
		$licenses = array();
		for ( $i = 0; $i < $lines; $i++ ) {
			$licenses[] = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		}

		return array( $order, $licenses );
	}

	public function test_a_box_made_from_an_order_records_its_paid_line(): void {
		list( $order, $licenses ) = $this->order_with_lines( 1 );
		Lifecycle::provide( $licenses[0] );
		$r = Resource::for_license( (int) $licenses[0]['id'] );

		$this->assertNotNull( $r );
		$lines = array_keys( $order->get_items() );
		$this->assertSame( (int) $lines[0], (int) $r->get( 'order_item_id' ), 'The guard finds the paid line through this.' );
		$this->assertNotNull( \BeltoftServices\Domains\SpendGuard::item_of_resource( $r ) );
	}

	public function test_two_boxes_of_one_order_take_one_line_each(): void {
		list( $order, $licenses ) = $this->order_with_lines( 2 );
		Lifecycle::provide( $licenses[0] );
		Lifecycle::provide( $licenses[1] );
		$a = (int) Resource::for_license( (int) $licenses[0]['id'] )->get( 'order_item_id' );
		$b = (int) Resource::for_license( (int) $licenses[1]['id'] )->get( 'order_item_id' );

		$this->assertGreaterThan( 0, $a );
		$this->assertGreaterThan( 0, $b );
		$this->assertNotSame( $a, $b );
		$this->assertEqualsCanonicalizing( array_map( 'intval', array_keys( $order->get_items() ) ), array( $a, $b ) );
	}

	public function test_a_licence_without_an_order_gets_no_line(): void {
		$product = $this->service_product( StorageDriver::ID, array( 'storage_type' => 'bx11', 'storage_size_gb' => 1000 ), array( 'slug' => 'storage-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		Lifecycle::provide( $license );
		$r = Resource::for_license( (int) $license['id'] );

		$this->assertSame( 0, (int) $r->get( 'order_item_id' ), 'No order: the guard refuses, nothing is bought.' );
	}
}
