<?php
/** Billing details required for services that need them. @package BeltoftServices */
namespace BeltoftServices\Tests;

use BeltoftServices\BillingRequirements;

class BillingRequirementsTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		$this->fake_driver();
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		parent::tear_down();
	}

	/** Puts a service of the driver in the cart; a domain needs the selection the search makes. */
	private function add( string $driver ): void {
		WC()->cart->empty_cart();
		// A VPS needs cluster capacity to be in stock; capacity is not what this test is about.
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		$data = 'domain' === $driver ? \BeltoftServices\Domains\Cart::line_data( 'billing-test.com', 'register' ) : array();
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->service_product( $driver )->get_id(), 1, 0, array(), $data ), $driver );
	}

	private function fields( string $driver ): array {
		$this->add( $driver );
		return BillingRequirements::require_fields( array( 'billing' => array(
			'billing_phone'     => array( 'required' => false ),
			'billing_address_1' => array( 'required' => false ),
			'billing_company'   => array( 'required' => false ),
		) ) )['billing'];
	}

	public function test_domain_and_vps_carts_require_the_billing_details(): void {
		foreach ( array( 'domain', 'proxmox' ) as $driver ) {
			$billing = $this->fields( $driver );
			$this->assertTrue( $billing['billing_phone']['required'], $driver );
			$this->assertTrue( $billing['billing_address_1']['required'], $driver );
			$this->assertFalse( $billing['billing_company']['required'], 'Company stays optional.' );
		}
	}

	public function test_other_services_and_plain_products_keep_the_store_settings(): void {
		$billing = $this->fields( 'fake' );
		$this->assertFalse( $billing['billing_phone']['required'] );

		WC()->cart->empty_cart();
		$plain = new \WC_Product_Simple();
		$plain->set_regular_price( '10' );
		$plain->save();
		WC()->cart->add_to_cart( $plain->get_id() );
		$this->assertFalse( BillingRequirements::cart_needs_billing() );
	}

	public function test_store_api_refuses_a_service_order_without_a_phone(): void {
		$this->add( 'domain' );
		$order = wc_create_order();
		foreach ( array( 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'address_1' => 'Road 1', 'city' => 'Aarhus', 'postcode' => '8000', 'country' => 'DK' ) as $k => $v ) {
			$order->{"set_billing_$k"}( $v );
		}

		try {
			BillingRequirements::check_store_api( $order, new \WP_REST_Request() );
			$this->fail( 'A missing phone must be refused.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'beltoft_services_billing_required', $e->getErrorCode() );
		}

		$order->set_billing_phone( '+4512345678' );
		BillingRequirements::check_store_api( $order, new \WP_REST_Request() );
		$this->addToAssertionCount( 1 );
	}
}
