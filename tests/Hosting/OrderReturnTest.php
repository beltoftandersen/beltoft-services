<?php
/**
 * The page a hosting add-on order returns to (moved here from OverviewOrderTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\Addons;
use BeltoftServices\OrderHandoff;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * The order hand-off of an add-on order.
 */
final class OrderReturnTest extends TestCase {

	/**
	 * The customer.
	 *
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();
		$this->fake_driver();
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_a_hosting_add_on_order_returns_to_the_page_of_its_account(): void {
		$this->settings( array( OrderHandoff::SETTING => 1 ) );
		$order = wc_create_order( array( 'customer_id' => $this->user ) );
		$order->set_status( 'processing' );
		$order->save();

		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'oo-' . wp_generate_password( 6, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
		$order->update_meta_data( Addons::ORDER_META, array( 'resource' => $resource->id(), 'sub' => 1, 'kind' => 'domain', 'target' => '' ) );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		$url = OrderHandoff::target( $order );

		$this->assertNotSame( '', $url );
		$this->assertStringNotContainsString( 'order=', $url );
		$this->assertTrue( OrderHandoff::applies( $order ) );
	}
}
