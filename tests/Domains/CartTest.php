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
		$this->assertWPError( Cart::line_data( 'test.dk', 'transfer', '' ) );
		$data = Cart::line_data( 'test.dk', 'transfer', 'auth-secret' );
		$this->assertIsArray( $data );
		$this->assertStringStartsWith( 'bs1:', $data['beltoft_services_domain_auth'] );
		$this->assertSame( 'auth-secret', Crypto::decrypt( $data['beltoft_services_domain_auth'] ) );
	}

	public function test_dns_mode_refuses_subdomain(): void {
		$this->assertWPError( Cart::line_data( 'www.example.com', 'dns', '' ) );
	}

	public function test_renewal_cart_keeps_subscription_price(): void {
		$this->assertNull( Cart::price_for( array( 'beltoft_services_domain' => array( 'domain' => 'test.dk', 'mode' => 'register' ), 'subscription_renewal' => true ) ) );
	}

	public function test_two_domains_get_separate_recurring_subscriptions(): void {
		$one = Cart::recurring_key( 'yearly', array( 'beltoft_services_domain' => array( 'domain' => 'one.dk' ) ) );
		$two = Cart::recurring_key( 'yearly', array( 'beltoft_services_domain' => array( 'domain' => 'two.dk' ) ) );
		$this->assertNotSame( $one, $two );
	}
}
