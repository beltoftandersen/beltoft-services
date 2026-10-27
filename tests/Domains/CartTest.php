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

	public function test_search_markup_carries_a_rest_nonce_so_logged_in_users_keep_their_identity(): void {
		$html = \BeltoftServices\Domains\Search::shortcode();
		$this->assertStringContainsString( 'data-nonce="' . wp_create_nonce( 'wc_store_api' ) . '"', $html );
		$this->assertStringContainsString( 'data-rest-nonce="' . wp_create_nonce( 'wp_rest' ) . '"', $html );
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

	public function test_order_line_preserves_domain_for_customer_and_provisioning(): void {
		$item = new \WC_Order_Item_Product();
		$order = new \WC_Order();
		Cart::order_item( $item, 'domain-item', array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'register' ) ), $order );
		$this->assertSame( 'example.com', $item->get_meta( 'Domain' ) );
		$this->assertSame( 'example.com', $item->get_meta( '_beltoft_services_domain' )['domain'] );
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

	private function add_request( string $domain, string $mode ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/domains/cart' );
		$request->set_header( 'X-WC-Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params( array( 'domain' => $domain, 'mode' => $mode ) );
		return $request;
	}

	public function test_choosing_the_same_domain_again_goes_on_to_checkout(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 16, 'restricted' => false ) ) );
		$name = 'again-' . strtolower( wp_generate_password( 8, false, false ) ) . '.com';
		$fake = new \BeltoftServices\Domains\FakeClient();
		$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => $name, 'status' => 'free', 'is_premium' => false ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' ); // no browser here to set a session cookie
		$this->service_product( 'domain' );
		wc_load_cart();
		WC()->cart->empty_cart();

		$first = Cart::rest_add( $this->add_request( $name, 'register' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$this->assertSame( 201, $first->get_status() );

		$again = Cart::rest_add( $this->add_request( $name, 'register' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $again, 'Not an error: the customer came back from checkout.' );
		$this->assertSame( 200, $again->get_status() );
		$this->assertTrue( $again->get_data()['already_in_cart'] );
		$this->assertSame( wc_get_checkout_url(), $again->get_data()['checkout_url'] );
		$this->assertCount( 1, WC()->cart->get_cart(), 'Nothing is added twice.' );

		$other_way = Cart::rest_add( $this->add_request( $name, 'dns' ) );
		$this->assertWPError( $other_way, 'The same name chosen another way is still explained.' );
		$this->assertSame( 'duplicate_domain', $other_way->get_error_code() );
	}
}
