<?php
/** Domain cart tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Crypto;
use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Tests\TestCase;

class CartTest extends TestCase {
	public function test_cart_line_price_from_table_and_dns_uses_product_price(): void {
		update_option( Prices::OPTION, array( 'dk' => array( 'price' => 13, 'restricted' => true ) ) );
		$this->assertSame( 13, Cart::price_for( array( 'beltoft_services_domain' => array( 'domain' => 'test.dk', 'mode' => 'register' ) ) ) );
		$this->assertNull( Cart::price_for( array( 'beltoft_services_domain' => array( 'domain' => 'test.dk', 'mode' => 'dns' ) ) ) );
	}

	public function test_transfer_needs_auth_code_and_it_is_encrypted(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'x', 32 ) ) );
		$this->assertWPError( Cart::line_data( 'test.com', 'transfer', '' ) );
		$data = Cart::line_data( 'test.com', 'transfer', 'auth-secret' );
		$this->assertIsArray( $data );
		$this->assertStringStartsWith( 'bs1:', $data['beltoft_services_domain_auth'] );
		$this->assertSame( 'auth-secret', Crypto::decrypt( $data['beltoft_services_domain_auth'] ) );
	}

	public function test_dns_mode_refuses_subdomain(): void {
		$this->assertWPError( Cart::line_data( 'www.example.com', 'dns', '' ) );
	}

	public function test_dk_orders_wait_for_agreement_approval(): void {
		$this->assertSame( 'dk_pending', Cart::line_data( 'example.dk', 'register' )->get_error_code() );
	}

	public function test_renewal_cart_keeps_subscription_price(): void {
		$this->assertNull( Cart::price_for( array( 'beltoft_services_domain' => array( 'domain' => 'test.dk', 'mode' => 'register' ), 'subscription_renewal' => true ) ) );
	}

	public function test_two_domains_get_separate_recurring_subscriptions(): void {
		$one = Cart::recurring_key( 'yearly', array( 'beltoft_services_domain' => array( 'domain' => 'one.dk' ) ) );
		$two = Cart::recurring_key( 'yearly', array( 'beltoft_services_domain' => array( 'domain' => 'two.dk' ) ) );
		$this->assertNotSame( $one, $two );
	}

	public function test_cart_summary_shows_selected_domain(): void {
		$data = Cart::item_data( array(), array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'dns' ) ) );
		$this->assertSame( 'example.com', $data[0]['value'] );
	}

	public function test_domain_product_requires_selection_but_renewal_is_allowed(): void {
		$product = $this->service_product( 'domain' );
		$this->expectException( \Exception::class );
		Cart::require_domain_selection( array(), $product->get_id() );
	}

	public function test_selected_domain_and_subscription_renewal_can_enter_cart(): void {
		$product = $this->service_product( 'domain' );
		$chosen = array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'register' ) );
		$this->assertSame( $chosen, Cart::require_domain_selection( $chosen, $product->get_id() ) );
		$this->assertSame( array( 'subscription_renewal' => true ), Cart::require_domain_selection( array( 'subscription_renewal' => true ), $product->get_id() ) );
	}

	public function test_direct_product_add_is_rejected_by_woocommerce_validation(): void {
		$product = $this->service_product( 'dns' );
		$this->assertFalse( Cart::validate_direct_add( true, $product->get_id(), 1, 0, array(), array() ) );
	}
}
