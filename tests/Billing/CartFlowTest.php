<?php
/**
 * The WooCommerce Subscriptions cart-item keys and the three sets of them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\CartFlow;
use BeltoftServices\BillingRequirements;
use BeltoftServices\IntroCoupon;
use BeltoftServices\Tests\TestCase;

class CartFlowTest extends TestCase {

	/**
	 * Key => in EXISTING_SERVICE, BOUGHT_BEFORE, ALL.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool}>
	 */
	public static function keys(): array {
		return array(
			'renewal'       => array( 'subscription_renewal', true, true, true ),
			'switch'        => array( 'subscription_switch', true, true, true ),
			'resubscribe'   => array( 'subscription_resubscribe', false, true, true ),
			'early renewal' => array( 'subscription_renewal_early', false, false, true ),
			'other key'     => array( 'subscription_other', false, false, false ),
		);
	}

	/**
	 * @dataProvider keys
	 */
	public function test_each_key_against_each_set( string $key, bool $existing, bool $before, bool $all ): void {
		$item = array( 'product_id' => 5, $key => array( 'subscription_id' => 1 ) );

		$this->assertSame( $existing, CartFlow::has( $item, CartFlow::EXISTING_SERVICE ), $key . ' existing' );
		$this->assertSame( $before, CartFlow::has( $item, CartFlow::BOUGHT_BEFORE ), $key . ' bought before' );
		$this->assertSame( $all, CartFlow::has( $item, CartFlow::ALL ), $key . ' all' );
		$this->assertSame( $existing, CartFlow::has_value( $item, CartFlow::EXISTING_SERVICE ) );
		$this->assertSame( $before, CartFlow::has_value( $item, CartFlow::BOUGHT_BEFORE ) );
		$this->assertSame( $all, CartFlow::has_value( $item, CartFlow::ALL ) );
		$this->assertSame( 'subscription_renewal' === $key, CartFlow::is_renewal( $item ) );
		$this->assertSame( 'subscription_switch' === $key, CartFlow::is_switch( $item ) );
		$this->assertSame( 'subscription_resubscribe' === $key, CartFlow::is_resubscribe( $item ) );
	}

	public function test_a_plain_line_is_in_no_set(): void {
		$item = array( 'product_id' => 5, 'variation_id' => 6 );

		foreach ( array( CartFlow::EXISTING_SERVICE, CartFlow::BOUGHT_BEFORE, CartFlow::ALL ) as $set ) {
			$this->assertFalse( CartFlow::has( $item, $set ) );
			$this->assertFalse( CartFlow::has_value( $item, $set ) );
		}
	}

	public function test_the_key_names_are_the_wcs_ones(): void {
		$this->assertSame( 'subscription_renewal', CartFlow::RENEWAL );
		$this->assertSame( 'subscription_switch', CartFlow::SWITCH );
		$this->assertSame( 'subscription_resubscribe', CartFlow::RESUBSCRIBE );
		$this->assertSame( 'subscription_renewal_early', CartFlow::RENEWAL_EARLY );
		$this->assertSame( array( CartFlow::RENEWAL, CartFlow::SWITCH ), CartFlow::EXISTING_SERVICE );
		$this->assertSame( array( CartFlow::RENEWAL, CartFlow::SWITCH, CartFlow::RESUBSCRIBE ), CartFlow::BOUGHT_BEFORE );
		$this->assertSame( array( CartFlow::RENEWAL, CartFlow::SWITCH, CartFlow::RESUBSCRIBE, CartFlow::RENEWAL_EARLY ), CartFlow::ALL );
	}

	public function test_set_and_empty_differ_only_on_empty_values(): void {
		$this->assertTrue( CartFlow::has( array( CartFlow::SWITCH => array() ), CartFlow::EXISTING_SERVICE ) );
		$this->assertFalse( CartFlow::has_value( array( CartFlow::SWITCH => array() ), CartFlow::EXISTING_SERVICE ) );
		$this->assertFalse( CartFlow::has( array( CartFlow::SWITCH => null ), CartFlow::EXISTING_SERVICE ) );
	}

	/**
	 * The two questions differ on a resubscribe on purpose: Checkout and IntroCoupon treat it as not new, while
	 * BillingRequirements and Usage\Cart treat it as a new service (it provisions a new resource).
	 */
	public function test_resubscribe_is_not_new_for_checkout_but_new_for_billing_requirements_and_usage_cart(): void {
		$resub = array( CartFlow::RESUBSCRIBE => array( 'subscription_id' => 1 ) );

		$this->assertFalse( $this->call( IntroCoupon::class, 'is_new', $resub ), 'IntroCoupon: not a new purchase.' );
		foreach ( array( CartFlow::RENEWAL, CartFlow::SWITCH, CartFlow::RENEWAL_EARLY ) as $key ) {
			$this->assertFalse( $this->call( IntroCoupon::class, 'is_new', array( $key => array( 'subscription_id' => 1 ) ) ) );
		}

		$this->assertTrue( CartFlow::has( $resub, CartFlow::BOUGHT_BEFORE ), 'Checkout set: a resubscribe is not a new trial.' );
		$this->assertFalse( CartFlow::has( $resub, CartFlow::EXISTING_SERVICE ), 'Billing set: a resubscribe is a new service.' );
	}

	/**
	 * On a real cart: BillingRequirements skips a renewal and a switch, but a resubscribe of a service that needs
	 * billing details still needs them.
	 */
	public function test_billing_requirements_skip_renewal_and_switch_but_not_resubscribe(): void {
		$this->fake_driver();
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );

		foreach ( array(
			CartFlow::RENEWAL     => false,
			CartFlow::SWITCH      => false,
			CartFlow::RESUBSCRIBE => true,
		) as $key => $needs ) {
			WC()->cart->empty_cart();
			$this->assertNotFalse( WC()->cart->add_to_cart( $this->service_product( 'proxmox' )->get_id() ), $key );
			foreach ( WC()->cart->cart_contents as $id => $item ) {
				WC()->cart->cart_contents[ $id ][ $key ] = array( 'subscription_id' => 1 );
			}
			$this->assertSame( $needs, BillingRequirements::cart_needs_billing(), $key );
		}

		WC()->cart->empty_cart();
	}

	/**
	 * Call a private static method on a class.
	 *
	 * @param class-string         $class  Class.
	 * @param string               $method Method.
	 * @param array<string, mixed> $item   Argument.
	 */
	private function call( string $class, string $method, array $item ) {
		$ref = new \ReflectionMethod( $class, $method );
		$ref->setAccessible( true );

		return $ref->invoke( null, $item );
	}
}
