<?php
/** DNS claim checkout route and the cart gate. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\Meta;
use BeltoftServices\Tests\TestCase;

class DnsClaimCheckoutTest extends TestCase {
	private int $user  = 0;
	private int $other = 0;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'dns_claims_max_per_user' => 50 ) );
		$this->user  = self::factory()->user->create();
		$this->other = self::factory()->user->create();
		$this->service_product( 'dns' );
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		WC()->cart->empty_cart();
		wc_clear_notices();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function checkout( int $claim_id, ?int $user = null ): \WP_REST_Response {
		wp_set_current_user( $user ?? $this->user );
		return rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/domains/dns-claims/' . $claim_id . '/checkout' ) );
	}

	private function claim( string $status = 'verified', string $domain = 'example.com', ?int $user = null ): array {
		$claim = DnsClaims::create( $user ?? $this->user, $domain );
		DnsClaims::set_status( $claim['id'], $status );
		return (array) DnsClaims::get( $claim['id'] );
	}

	/** A verified claim with its line in the cart and the domain reserved. */
	private function in_cart( string $domain = 'example.com' ): array {
		$claim = $this->claim( 'verified', $domain );
		$this->assertSame( 201, $this->checkout( $claim['id'] )->get_status() );
		return $claim;
	}

	private function cart_error_count(): int {
		wc_clear_notices();
		Cart::check_unverified_dns_items();
		return wc_notice_count( 'error' );
	}

	public function test_verified_claim_adds_one_line_and_reserves_the_domain(): void {
		$claim    = $this->claim();
		$response = $this->checkout( $claim['id'] );
		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['added'] );
		$this->assertSame( wc_get_checkout_url(), $response->get_data()['checkout_url'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$lines = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $lines );
		$this->assertSame( array( 'domain' => 'example.com', 'mode' => 'dns', 'claim_id' => $claim['id'] ), $lines[0]['beltoft_services_domain'] );
		$row = DnsAssignments::get( 'example.com' );
		$this->assertSame( 'reserved', $row['status'] );
		$this->assertSame( $this->user, (int) $row['user_id'] );
		$this->assertSame( 0, $this->cart_error_count() );
	}

	public function test_repeat_keeps_the_same_line(): void {
		$claim = $this->claim();
		$this->checkout( $claim['id'] );
		$again = $this->checkout( $claim['id'] );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( wc_get_checkout_url(), $again->get_data()['checkout_url'] );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_claims_that_are_not_verified_are_refused(): void {
		foreach ( array( 'pending', 'expired', 'cancelled' ) as $status ) {
			$claim = $this->claim( $status, $status . '.com' );
			$r     = $this->checkout( $claim['id'] );
			$this->assertSame( 409, $r->get_status(), $status );
			$this->assertSame( 'dns_claim_not_verified', $r->get_data()['code'] );
		}
		$stale = $this->claim( 'verified', 'stale.com' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'beltoft_services_dns_claims', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $stale['id'] ) );
		$this->assertSame( 409, $this->checkout( $stale['id'] )->get_status() );
		$used = $this->claim( 'consumed', 'used.com' );
		$r    = $this->checkout( $used['id'] );
		$this->assertSame( 'dns_claim_used', $r->get_data()['code'] );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_another_users_claim_is_not_found(): void {
		$claim = $this->claim();
		$r     = $this->checkout( $claim['id'], $this->other );
		$this->assertSame( 404, $r->get_status() );
		$this->assertSame( 'dns_claim_not_found', $r->get_data()['code'] );
	}

	public function test_another_users_reservation_gives_the_generic_refusal(): void {
		$theirs = $this->claim( 'verified', 'example.com', $this->other );
		DnsAssignments::reserve( $this->other, $theirs['id'], 'example.com' );
		$mine = $this->claim();
		$r    = $this->checkout( $mine['id'] );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'This domain cannot be added here. Contact support.', $r->get_data()['message'] );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_a_domain_the_user_already_holds_is_already_yours(): void {
		$claim = $this->claim();
		DnsAssignments::reserve( $this->user, $claim['id'], 'example.com' );
		DnsAssignments::activate( 'example.com', $this->user, 1, 1 );
		$r = $this->checkout( $claim['id'] );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'already_yours', $r->get_data()['code'] );
	}

	public function test_feature_off_is_404(): void {
		$claim = $this->claim();
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		$r = $this->checkout( $claim['id'] );
		$this->assertSame( 404, $r->get_status() );
		$this->assertSame( 'dns_claims_disabled', $r->get_data()['code'] );
	}

	public function test_rate_limit_is_ten_an_hour(): void {
		$claim = $this->claim();
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertNotSame( 429, $this->checkout( $claim['id'] )->get_status() );
		}
		$this->assertSame( 429, $this->checkout( $claim['id'] )->get_status() );
	}

	public function test_cart_gate_follows_the_user_the_claim_and_the_assignment(): void {
		$claim = $this->in_cart();
		$this->assertSame( 0, $this->cart_error_count(), 'The right user passes.' );

		wp_set_current_user( $this->other );
		$this->assertSame( 1, $this->cart_error_count(), 'Same cart, another user.' );
		wp_set_current_user( 0 );
		$this->assertSame( 1, $this->cart_error_count(), 'Logged out.' );

		wp_set_current_user( $this->user );
		$errors = new \WP_Error();
		Cart::validate_dns_checkout( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
		wp_set_current_user( $this->other );
		Cart::validate_dns_checkout( array(), $errors );
		$this->assertNotEmpty( $errors->get_error_codes(), 'The checkout POST is checked too.' );

		wp_set_current_user( $this->user );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'beltoft_services_dns_claims', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $claim['id'] ) );
		$this->assertSame( 1, $this->cart_error_count(), 'The claim expired.' );
		$wpdb->update( $wpdb->prefix . 'beltoft_services_dns_claims', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'id' => $claim['id'] ) );
		$this->assertSame( 0, $this->cart_error_count() );

		DnsAssignments::release( 'example.com' );
		DnsAssignments::reserve( $this->other, $this->claim( 'verified', 'example.com', $this->other )['id'], 'example.com' );
		$this->assertSame( 1, $this->cart_error_count(), 'The assignment was taken over.' );
	}

	public function test_direct_add_needs_a_matching_claim_and_reservation(): void {
		$product = $this->service_product( 'dns' );
		$claim   = $this->claim();
		wp_set_current_user( $this->user );
		$data = array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		try {
			Cart::require_domain_selection( $data, $product->get_id() );
			$this->fail( 'No reservation yet.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'expired', $e->getMessage() );
		}
		DnsAssignments::reserve( $this->user, $claim['id'], 'example.com' );
		$this->assertSame( $data, Cart::require_domain_selection( $data, $product->get_id() ) );
		$data['beltoft_services_domain']['domain'] = 'other.com';
		$this->expectException( \Exception::class );
		Cart::require_domain_selection( $data, $product->get_id() );
	}

	public function test_dns_renewal_line_still_passes(): void {
		wc_load_cart();
		WC()->cart->set_cart_contents( array( 'renew' => array( 'subscription_renewal' => array( 'x' => 1 ), 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'dns' ) ) ) );
		$this->assertSame( 0, $this->cart_error_count() );
		$product = $this->service_product( 'dns' );
		$data    = array( 'subscription_renewal' => true );
		$this->assertSame( $data, Cart::require_domain_selection( $data, $product->get_id() ) );
	}

	private function order_for_cart( ?int $user = null ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user ?? $this->user ) );
		foreach ( WC()->cart->get_cart() as $key => $values ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product( wc_get_product( $values['product_id'] ) );
			Cart::order_item( $item, $key, $values, $order );
			$order->add_item( $item );
		}
		$order->set_status( 'pending' );
		$order->save();
		return $order;
	}

	/** The order's line that carries a claim (order ids are reused by the test database, so other lines may exist). */
	private function claim_item( \WC_Order $order ): \WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_meta( '_beltoft_services_dns_claim', true ) > 0 ) { return $item; }
		}
		$this->fail( 'No claim line on the order.' );
	}

	public function test_order_creation_binds_the_assignment_and_consumes_the_claim(): void {
		$claim = $this->in_cart();
		$order = $this->order_for_cart();
		$item  = $this->claim_item( $order );
		$this->assertSame( $claim['id'], (int) $item->get_meta( '_beltoft_services_dns_claim', true ) );
		do_action( 'woocommerce_checkout_order_created', $order );
		$row = DnsAssignments::get( 'example.com' );
		$this->assertSame( $order->get_id(), (int) $row['order_id'] );
		$this->assertSame( $item->get_id(), (int) $row['order_item_id'] );
		$this->assertSame( 'consumed', DnsClaims::get( $claim['id'] )['status'] );
		WC()->session->set( 'order_awaiting_payment', $order->get_id() );
		$this->assertSame( 0, $this->cart_error_count(), 'The cart still passes while the order waits for payment.' );
		WC()->session->set( 'order_awaiting_payment', 0 );
		do_action( 'woocommerce_checkout_order_created', $order ); // Store API style repeat.
		$this->assertSame( 'consumed', DnsClaims::get( $claim['id'] )['status'] );
	}

	public function test_order_creation_with_a_changed_user_does_not_bind(): void {
		$claim = $this->in_cart();
		$order = $this->order_for_cart( $this->other );
		do_action( 'woocommerce_checkout_order_created', $order );
		$this->assertSame( 0, (int) DnsAssignments::get( 'example.com' )['order_id'] );
		$this->assertSame( 'verified', DnsClaims::get( $claim['id'] )['status'] );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes );
	}

	public function test_cancelled_and_failed_orders_release_reserved_rows_only(): void {
		foreach ( array( 'woocommerce_order_status_cancelled', 'woocommerce_order_status_failed' ) as $hook ) {
			WC()->cart->empty_cart();
			$this->in_cart();
			$order = $this->order_for_cart();
			do_action( 'woocommerce_checkout_order_created', $order );
			$this->assertNotNull( DnsAssignments::get( 'example.com' ) );
			do_action( $hook, $order->get_id() );
			$this->assertNull( DnsAssignments::get( 'example.com' ), $hook );
		}
		WC()->cart->empty_cart();
		$this->in_cart( 'kept.com' );
		$order = $this->order_for_cart();
		do_action( 'woocommerce_checkout_order_created', $order );
		DnsAssignments::activate( 'kept.com', $this->user, 5, 5 );
		do_action( 'woocommerce_order_status_cancelled', $order->get_id() );
		$this->assertSame( 'active', DnsAssignments::get( 'kept.com' )['status'] );
	}

	public function test_initial_meta_copies_the_claim_id(): void {
		$claim = $this->in_cart();
		$order = $this->order_for_cart();
		$driver = new \BeltoftServices\Tests\Support\FakeDriver( 'dns' );
		$meta = Meta::initial_meta( array(), array( 'id' => 77, 'order_id' => $order->get_id() ), $driver );
		$this->assertSame( $claim['id'], $meta['claim_id'] );
		$this->assertSame( 'dns', $meta['mode'] );
	}

	public function test_second_cart_with_the_same_claim_never_rebinds_the_row(): void {
		$claim = $this->in_cart();
		$a     = $this->order_for_cart();
		do_action( 'woocommerce_checkout_order_created', $a );
		WC()->session->set( 'order_awaiting_payment', $a->get_id() );
		$this->assertSame( 0, $this->cart_error_count(), 'Order A awaiting payment: its own checkout retry passes.' );

		// Another session: the same line, no awaiting order.
		WC()->session->set( 'order_awaiting_payment', 0 );
		$this->assertSame( 1, $this->cart_error_count() );
		$errors = new \WP_Error();
		Cart::validate_dns_checkout( array(), $errors );
		$this->assertNotEmpty( $errors->get_error_codes() );

		$b = $this->order_for_cart();
		do_action( 'woocommerce_checkout_order_created', $b );
		$this->assertSame( $a->get_id(), (int) DnsAssignments::get( 'example.com' )['order_id'] );
		$this->assertNotEmpty( wc_get_order_notes( array( 'order_id' => $b->get_id() ) ) );
		$this->assertFalse( DnsAssignments::bind_order( 'example.com', $this->user, $b->get_id(), 1 ) );
		$this->assertSame( 'consumed', DnsClaims::get( $claim['id'] )['status'] );
		WC()->session->set( 'order_awaiting_payment', 0 );
	}

	public function test_payment_retry_of_the_same_order_still_passes(): void {
		$this->in_cart();
		$a = $this->order_for_cart();
		do_action( 'woocommerce_checkout_order_created', $a );
		WC()->session->set( 'order_awaiting_payment', $a->get_id() );
		$this->assertSame( 0, $this->cart_error_count() );
		do_action( 'woocommerce_checkout_order_created', $a );
		$this->assertSame( $a->get_id(), (int) DnsAssignments::get( 'example.com' )['order_id'] );
		WC()->session->set( 'order_awaiting_payment', 0 );
	}

	public function test_leftover_line_after_the_order_is_active_is_refused(): void {
		$this->in_cart();
		$a = $this->order_for_cart();
		do_action( 'woocommerce_checkout_order_created', $a );
		DnsAssignments::activate( 'example.com', $this->user, 5, 5 );
		WC()->session->set( 'order_awaiting_payment', $a->get_id() );
		$this->assertSame( 1, $this->cart_error_count() );
		$this->assertSame( 'This DNS hosting check has expired. Start again from My Account, Domains.', wc_get_notices( 'error' )[0]['notice'] );
		WC()->session->set( 'order_awaiting_payment', 0 );
	}

	public function test_cancelling_the_order_expires_the_claim_so_the_customer_can_start_again(): void {
		$claim = $this->in_cart();
		$a     = $this->order_for_cart();
		do_action( 'woocommerce_checkout_order_created', $a );
		do_action( 'woocommerce_order_status_cancelled', $a->get_id() );
		$this->assertSame( 'expired', DnsClaims::get( $claim['id'] )['status'] );
		$this->assertSame( 'dns_claim_not_verified', $this->checkout( $claim['id'] )->get_data()['code'] );
	}

	public function test_missing_product_leaves_no_reservation(): void {
		$claim = $this->claim( 'verified', 'noproduct.com' );
		add_filter( 'pre_get_posts', static function ( $q ) { $q->set( 'post__in', array( 0 ) ); } );
		$r = $this->checkout( $claim['id'] );
		$this->assertSame( 503, $r->get_status() );
		$this->assertNull( DnsAssignments::get( 'noproduct.com' ) );
	}
}
