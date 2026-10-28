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

	public function test_the_transfer_code_length_and_characters_are_bounded(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'x', 32 ) ) );
		foreach ( array( 'short', str_repeat( 'a', 65 ), "tab\tcode123", 'caf' . "\xc3\xa9" . 'code1', "line\nbreak12" ) as $bad ) {
			$result = Cart::line_data( 'test.com', 'transfer', $bad );
			$this->assertWPError( $result, $bad );
			$this->assertSame( 'auth_invalid', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
			$this->assertStringContainsString( '6 to 64 characters', $result->get_error_message() );
		}
		foreach ( array( 'abcdef', str_repeat( 'a', 64 ), '  Zx9!pQ7#kL  ', 'A1b2-C3d4/E5=' ) as $good ) {
			$this->assertIsArray( Cart::line_data( 'test.com', 'transfer', $good ), $good );
		}
		$this->assertSame( 'Zx9!pQ7#kL', Crypto::decrypt( Cart::line_data( 'test.com', 'transfer', '  Zx9!pQ7#kL  ' )['beltoft_services_domain_auth'] ), 'Stored trimmed.' );
		$this->assertSame( 'auth_required', Cart::line_data( 'test.com', 'transfer', '   ' )->get_error_code() );
	}

	public function test_search_markup_carries_a_rest_nonce_so_logged_in_users_keep_their_identity(): void {
		$html = \BeltoftServices\Domains\Search::shortcode();
		$this->assertStringContainsString( 'data-nonce="' . wp_create_nonce( 'wc_store_api' ) . '"', $html );
		$this->assertStringContainsString( 'data-rest-nonce="' . wp_create_nonce( 'wp_rest' ) . '"', $html );
	}

	public function test_public_cart_route_still_refuses_dns_hosting(): void {
		$this->assertSame( 'dns_verification_required', Cart::line_data( 'example.com', 'dns' )->get_error_code() );
		$this->assertSame( 'dns_verification_required', Cart::line_data( 'www.example.com', 'dns' )->get_error_code() );
	}

	public function test_direct_dns_add_without_a_claim_is_refused(): void {
		$product = $this->service_product( 'dns' );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'This DNS hosting check has expired. Start again from My Account, Domains.' );
		Cart::require_domain_selection( array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'dns' ) ), $product->get_id() );
	}

	public function test_saved_dns_cart_item_without_a_valid_claim_blocks_checkout(): void {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->set_cart_contents( array( 'old-dns-selection' => array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'dns' ) ) ) );
		wc_clear_notices();
		Cart::check_unverified_dns_items();
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertSame( 'This DNS hosting check has expired. Start again from My Account, Domains.', wc_get_notices( 'error' )[0]['notice'] );
		WC()->cart->empty_cart();
		wc_clear_notices();
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
		$this->assertWPError( $other_way, 'Unverified DNS hosting remains closed even when a domain is in the cart.' );
		$this->assertSame( 'dns_verification_required', $other_way->get_error_code() );
		WC()->cart->empty_cart();
	}

	private function cart_with( array $line, array $extra = array() ): void {
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
		$product = $this->service_product( 'domain' );
		WC()->cart->cart_contents = array( 'line' => array_merge( array( 'key' => 'line', 'product_id' => $product->get_id(), 'quantity' => 1, 'data' => $product, 'beltoft_services_domain' => $line ), $extra ) );
	}

	public function test_a_domain_line_whose_extension_left_the_price_table_is_blocked(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$this->cart_with( array( 'domain' => 'gone.xyz', 'mode' => 'register' ) );
		Cart::check_unpriced_domain_items();
		$notices = wc_get_notices( 'error' );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'This domain extension is not offered any more. Remove it from your cart and search again.', wp_strip_all_tags( $notices[0]['notice'] ) );
		$errors = new \WP_Error();
		Cart::validate_unpriced_domain_checkout( array(), $errors );
		$this->assertSame( 'domain_not_offered', $errors->get_error_code() );
		wc_clear_notices();
	}

	public function test_priced_transfer_and_continuation_lines_are_not_blocked(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$this->cart_with( array( 'domain' => 'fine.com', 'mode' => 'transfer' ) );
		Cart::check_unpriced_domain_items();
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$this->cart_with( array( 'domain' => 'gone.xyz', 'mode' => 'register' ), array( 'subscription_renewal' => true ) );
		Cart::check_unpriced_domain_items();
		$errors = new \WP_Error();
		Cart::validate_unpriced_domain_checkout( array(), $errors );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$this->assertFalse( $errors->has_errors() );
		WC()->cart->empty_cart();
	}

	private function held_domain( string $name ): void {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'held-domain-test' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$this->assertInstanceOf( \BeltoftServices\Resource::class, \BeltoftServices\Resource::create( $license, new \BeltoftServices\Domains\DomainDriver(), array(), array( 'state' => 'ready', 'domain' => $name, 'mode' => 'register', 'remote_id' => '12' ) ) );
	}

	public function test_a_domain_already_held_here_cannot_be_added_to_the_cart(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 16, 'restricted' => false ) ) );
		$name = 'held-' . strtolower( wp_generate_password( 8, false, false ) ) . '.com';
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'x', 32 ) ) );
		$this->held_domain( $name );
		$fake = new \BeltoftServices\Domains\FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->service_product( 'domain' );
		wc_load_cart();
		WC()->cart->empty_cart();
		foreach ( array( 'register', 'transfer' ) as $mode ) {
			$request = $this->add_request( strtoupper( $name ), $mode );
			$request->set_body_params( array( 'domain' => strtoupper( $name ), 'mode' => $mode, 'auth_code' => 'transfer-code-1' ) );
			$result = Cart::rest_add( $request );
			$this->assertWPError( $result, $mode );
			$this->assertSame( 'domain_held', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
		}
		$this->assertSame( array(), $fake->calls(), 'The registrar was not asked.' );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_checkout_stops_a_domain_held_here_or_twice_in_the_cart_but_not_a_renewal(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$name = 'held-checkout-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
		$this->held_domain( $name );
		$this->cart_with( array( 'domain' => $name, 'mode' => 'register' ) );
		Cart::check_duplicate_domain_items();
		$this->assertCount( 1, wc_get_notices( 'error' ) );
		$this->assertStringContainsString( 'already registered here', wp_strip_all_tags( wc_get_notices( 'error' )[0]['notice'] ) );
		$errors = new \WP_Error();
		Cart::validate_duplicate_domain_checkout( array(), $errors );
		$this->assertSame( 'domain_duplicate', $errors->get_error_code() );
		wc_clear_notices();
		$this->cart_with( array( 'domain' => $name, 'mode' => 'register' ), array( 'subscription_renewal' => true ) );
		Cart::check_duplicate_domain_items();
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$free = 'free-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
		$this->cart_with( array( 'domain' => $free, 'mode' => 'register' ) );
		$product = WC()->cart->cart_contents['line']['data'];
		WC()->cart->cart_contents['second'] = array( 'key' => 'second', 'product_id' => $product->get_id(), 'quantity' => 1, 'data' => $product, 'beltoft_services_domain' => array( 'domain' => $free, 'mode' => 'transfer' ) );
		Cart::check_duplicate_domain_items();
		$this->assertStringContainsString( 'in your cart twice', wp_strip_all_tags( wc_get_notices( 'error' )[0]['notice'] ) );
		wc_clear_notices();
		unset( WC()->cart->cart_contents['second'] );
		Cart::check_duplicate_domain_items();
		$this->assertSame( array(), wc_get_notices( 'error' ), 'A free name once in the cart is fine.' );
		WC()->cart->empty_cart();
	}

	public function test_a_registrar_error_on_the_cart_route_is_generic(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new \BeltoftServices\Domains\FakeClient();
		$fake->reply( 'POST', '/domains/check', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400, 'desc' => 'secret registrar detail' ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->service_product( 'domain' );
		wc_load_cart();
		WC()->cart->empty_cart();
		$result = Cart::rest_add( $this->add_request( 'generic-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com', 'register' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'registrar_unavailable', $result->get_error_code() );
		$this->assertSame( 'This domain cannot be added right now. Try again in a few minutes.', $result->get_error_message() );
		$this->assertSame( array( 'status' => 503 ), $result->get_error_data() );
		$json = wp_json_encode( rest_convert_error_to_response( $result )->get_data() );
		$this->assertStringNotContainsString( 'op_', $json );
		$this->assertStringNotContainsString( 'secret', $json );
		$this->assertStringNotContainsString( 'desc', $json );
	}

	public function test_a_restricted_extension_that_is_not_allowlisted_is_blocked(): void {
		update_option( Prices::OPTION, array( 'eu' => array( 'price' => 14, 'restricted' => true ), 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$this->settings( array( 'domain_restricted_allowlist' => 'dk' ) );
		$this->cart_with( array( 'domain' => 'shop.eu', 'mode' => 'register' ) );
		Cart::check_unpriced_domain_items();
		$this->assertCount( 1, wc_get_notices( 'error' ) );
		wc_clear_notices();
		$this->settings( array( 'domain_restricted_allowlist' => 'dk,eu' ) );
		Cart::check_unpriced_domain_items();
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		WC()->cart->empty_cart();
	}

	public function test_a_domain_line_quantity_goes_back_to_one(): void {
		WC()->cart->set_cart_contents( array( 'dom' => array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'register' ), 'quantity' => 5 ), 'other' => array( 'quantity' => 3 ) ) );
		Cart::apply_prices( WC()->cart );
		$this->assertSame( 1, WC()->cart->cart_contents['dom']['quantity'] );
		$this->assertSame( 3, WC()->cart->cart_contents['other']['quantity'] );
		WC()->cart->empty_cart();
	}
}
