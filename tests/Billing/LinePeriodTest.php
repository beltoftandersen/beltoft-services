<?php
/**
 * The period of a subscription line, and the domain among the line options.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\LineOptions;
use BeltoftServices\Billing\LinePeriod;
use BeltoftServices\Tests\TestCase;

final class LinePeriodTest extends TestCase {

	public function test_a_paid_subscription_line_gets_its_period_and_a_free_line_none(): void {
		$product = $this->service_product();
		$free    = $this->service_product();
		$user    = self::factory()->user->create();
		$sub     = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$sub->add_product( $product, 1 );
		$sub->add_product( $free, 1, array( 'total' => 0, 'subtotal' => 0 ) );
		$sub->save();

		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( $product, 1, array( 'total' => 10, 'subtotal' => 10 ) );
		$order->add_product( $free, 1, array( 'total' => 0, 'subtotal' => 0 ) );
		$order->set_date_created( strtotime( '2026-11-13 12:00:00 UTC' ) );
		$order->save();

		LinePeriod::write( wc_get_order( $order->get_id() ), $sub );

		$texts = array();
		foreach ( wc_get_order( $order->get_id() )->get_items() as $item ) {
			$texts[] = (string) $item->get_meta( LinePeriod::META );
		}

		$this->assertSame( array( 'Period: 13.11.2026 - 12.12.2026', '' ), $texts, 'From the order date to one month later less a day; a 0 line gets none.' );
	}

	public function test_a_domain_line_has_its_domain_among_its_options(): void {
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'Domain name' );
		$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => 'example.com' ), true );
		$order->add_item( $item );

		$this->assertContains( array( 'Domain', 'example.com', 'domain' ), LineOptions::of( $item ) );
	}
}
