<?php
/** DNS-only subscriptions end at once. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Tests\TestCase;

class DnsCancelTest extends TestCase {
	private function subscription( array $drivers, string $price = '0' ): \WC_Subscription {
		$user = self::factory()->user->create();
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'active' ) );
		foreach ( $drivers as $driver ) {
			$product = $this->service_product( $driver );
			$sub->add_product( $product, 1, array( 'subtotal' => $price, 'total' => $price ) );
		}
		$sub->calculate_totals();
		$sub->save();
		$sub->update_status( 'active' );
		return $sub;
	}

	public function test_a_free_dns_subscription_is_cancelled_at_once(): void {
		$sub = $this->subscription( array( 'dns' ) );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'cancelled', wcs_get_subscription( $sub->get_id() )->get_status() );
		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( $note ) => str_contains( $note, 'DNS hosting ends at once when cancelled.' ) ) );
	}

	public function test_a_paid_dns_subscription_is_left_alone(): void {
		$sub = $this->subscription( array( 'dns' ), '10' );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_a_mixed_subscription_is_left_alone(): void {
		$sub = $this->subscription( array( 'dns', 'domain' ) );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_another_driver_is_left_alone(): void {
		$sub = $this->subscription( array( 'fake' ) );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $sub->get_id() )->get_status() );
	}
}
