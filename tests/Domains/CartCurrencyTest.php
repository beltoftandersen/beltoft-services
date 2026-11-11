<?php
/** A domain line carries the currency it was priced in; in another currency it goes. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Currency;
use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Tests\TestCase;

class CartCurrencyTest extends TestCase {

	use FakeCurrency;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ) ) );
		$this->fake_currency_up( 'EUR' );
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		$this->fake_currency_down();
		WC()->cart->empty_cart();
		wc_clear_notices();
		parent::tear_down();
	}

	/** Puts a domain line straight into the cart contents. */
	private function put( string $key, array $extra, string $mode = 'register' ): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Domain' );
		$product->set_regular_price( '1' );
		$product->save();
		WC()->cart->cart_contents[ $key ] = array_merge( array( 'key' => $key, 'product_id' => $product->get_id(), 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'data' => $product, 'beltoft_services_domain' => array( 'domain' => 'a.com', 'mode' => $mode ) ), $extra );
	}

	public function test_a_line_priced_in_this_currency_is_kept_priced_and_tagged(): void {
		$this->put( 'k1', array( Currency::CART_KEY => 'EUR' ) );

		Cart::apply_prices( WC()->cart );

		$line = WC()->cart->cart_contents['k1'];
		$this->assertSame( 'EUR', $line[ Currency::CART_KEY ] );
		$this->assertEquals( 12, $line['data']->get_price() );
	}

	public function test_a_line_chosen_in_euro_goes_with_one_notice_when_the_cart_is_in_dkk(): void {
		$this->put( 'k1', array( Currency::CART_KEY => 'EUR' ) );
		$this->put( 'k2', array( Currency::CART_KEY => 'EUR' ) );
		$this->fake_set_currency( 'DKK' );

		Cart::apply_prices( WC()->cart );

		$this->assertCount( 0, WC()->cart->get_cart() );
		$this->assertCount( 1, wc_get_notices( 'notice' ), 'One notice, however many lines.' );
	}

	public function test_a_line_with_no_currency_is_the_shop_currency(): void {
		$this->put( 'k1', array() );
		$this->fake_set_currency( 'DKK' );

		Cart::apply_prices( WC()->cart );

		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_a_dns_line_is_left_alone_and_a_dkk_line_is_priced_from_its_override(): void {
		$this->put( 'dns', array(), 'dns' );
		$this->fake_set_currency( 'DKK' );
		$this->fake_amounts( array( 'DKK' => array( 'domain_price_overrides' => "com=119\n" ) ) );
		$this->put( 'k3', array( Currency::CART_KEY => 'DKK' ) );

		Cart::apply_prices( WC()->cart );

		$this->assertArrayHasKey( 'dns', WC()->cart->cart_contents );
		$this->assertSame( 'DKK', WC()->cart->cart_contents['k3'][ Currency::CART_KEY ], 'Kept, and tagged with the currency its price was worked out in.' );
		$this->assertSame( 119, Cart::price_for( WC()->cart->cart_contents['k3'] ), 'Priced from the DKK line, never the euro 12.' );
	}

	public function test_a_dkk_line_with_no_override_has_no_price_so_checkout_refuses_it(): void {
		$this->fake_set_currency( 'DKK' );
		$this->put( 'k4', array( Currency::CART_KEY => 'DKK' ) );

		$this->assertNull( Cart::price_for( WC()->cart->cart_contents['k4'] ) );
		Cart::check_unpriced_domain_items();
		$this->assertCount( 1, wc_get_notices( 'error' ) );
	}
}
