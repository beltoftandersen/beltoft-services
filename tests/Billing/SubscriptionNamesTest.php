<?php
/**
 * The subscription list names what each subscription is for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\SubscriptionNames;
use BeltoftServices\Tests\TestCase;

/**
 * Item names under the number.
 */
final class SubscriptionNamesTest extends TestCase {

	/**
	 * A subscription with the given products as line items.
	 *
	 * @param string[] $names Product names.
	 */
	private function subscription_with( array $names ): \WC_Subscription {
		$user = self::factory()->user->create();
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1 ) );

		foreach ( $names as $name ) {
			$product = new \WC_Product_Simple();
			$product->set_name( $name );
			$product->set_regular_price( '5' );
			$product->save();
			$sub->add_product( $product, 1 );
		}

		$sub->save();

		return wcs_get_subscription( $sub->get_id() );
	}

	public function test_the_names_of_the_items_are_one_line(): void {
		$this->assertSame( 'VPS hosting', SubscriptionNames::names( $this->subscription_with( array( 'VPS hosting' ) ) ) );
		$this->assertSame( 'VPS hosting, Extra disk', SubscriptionNames::names( $this->subscription_with( array( 'VPS hosting', 'Extra disk' ) ) ) );
		$this->assertSame( 'A, B and 2 more', SubscriptionNames::names( $this->subscription_with( array( 'A', 'B', 'C', 'D' ) ) ) );
	}

	public function test_the_line_is_printed_under_the_number_and_escaped(): void {
		$this->assertNotFalse( has_action( 'woocommerce_my_subscriptions_after_subscription_id', array( SubscriptionNames::class, 'print_names' ) ) );

		ob_start();
		SubscriptionNames::print_names( $this->subscription_with( array( 'Mail <b>x</b>' ) ) );
		$html = (string) ob_get_clean();

		$this->assertSame( '<span class="bs-sub-name">Mail x</span>', $html );

		ob_start();
		SubscriptionNames::print_names( null );
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
