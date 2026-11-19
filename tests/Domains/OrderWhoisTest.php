<?php
/** The WHOIS privacy box in the checkout order review. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\OrderWhois;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Tests\TestCase;

class OrderWhoisTest extends TestCase {
	private function cart_with( array $lines ): void {
		update_option( Prices::OPTION, array(
			'com' => array( 'price' => 14, 'restricted' => false, 'whois' => true, 'dnssec' => array( 'allowed' => false, 'keys' => false, 'digests' => false, 'algs' => array(), 'max' => 0 ) ),
			'dk'  => array( 'price' => 13, 'restricted' => false, 'whois' => false, 'dnssec' => array( 'allowed' => false, 'keys' => false, 'digests' => false, 'algs' => array(), 'max' => 0 ) ),
		) );
		wc_load_cart();
		WC()->cart->empty_cart();
		$product = $this->service_product( 'domain' );
		foreach ( $lines as $domain => $line ) {
			WC()->cart->cart_contents[ md5( $domain ) ] = array( 'key' => md5( $domain ), 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1, 'data' => $product, 'beltoft_services_domain' => $line + array( 'domain' => $domain ) );
		}
	}

	public function test_a_box_per_line_that_allows_it_on_by_default_and_none_for_dk_or_dns_lines(): void {
		$this->cart_with( array(
			'a-example.com' => array( 'mode' => 'register', 'whois_privacy' => true ),
			'b-example.com' => array( 'mode' => 'transfer' ),
			'c-example.dk'  => array( 'mode' => 'register' ),
			'd-example.com' => array( 'mode' => 'dns' ),
		) );
		$lines = OrderWhois::lines();
		$this->assertSame( array( 'a-example.com', 'b-example.com' ), array_column( $lines, 'domain' ) );
		$this->assertSame( array( true, true ), array_column( $lines, 'on' ), 'No stored choice means on.' );
		ob_start();
		OrderWhois::print_rows();
		$html = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $html, 'data-bs-whois=' ) );
		$this->assertSame( 2, substr_count( $html, ' checked' ) );
		$this->assertStringContainsString( 'WHOIS privacy (free): hides your name, address, email and phone in the public WHOIS.', $html );
		$this->assertDoesNotMatchRegularExpression( '/[€$]|price/i', $html, 'No price anywhere.' );
	}

	public function test_a_change_is_written_to_the_cart_line_and_only_where_allowed(): void {
		$this->cart_with( array( 'a-example.com' => array( 'mode' => 'register', 'whois_privacy' => true ), 'c-example.dk' => array( 'mode' => 'register' ) ) );
		$this->assertSame( 1, OrderWhois::set( 'A-Example.com', false ) );
		$this->assertFalse( WC()->cart->cart_contents[ md5( 'a-example.com' ) ]['beltoft_services_domain']['whois_privacy'] );
		$this->assertFalse( OrderWhois::lines()[0]['on'] );
		$this->assertSame( 0, OrderWhois::set( 'c-example.dk', true ), '.dk has no such option.' );
		$this->assertArrayNotHasKey( 'whois_privacy', WC()->cart->cart_contents[ md5( 'c-example.dk' ) ]['beltoft_services_domain'] );
		$this->assertSame( 0, OrderWhois::set( 'not-in-cart.com', true ) );
		$this->assertSame( 1, OrderWhois::set( 'a-example.com', true ) );
		$this->assertTrue( OrderWhois::lines()[0]['on'] );
	}
}
