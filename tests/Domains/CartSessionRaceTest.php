<?php
/**
 * The domain line of an order against a parallel request that writes the WooCommerce session last.
 *
 * Each "request" is its own session handler for one customer, as in two PHP processes: it reads the stored session when it starts
 * and writes everything it holds when it ends.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Tests\TestCase;

/**
 * Lost update of the session cart.
 */
class CartSessionRaceTest extends TestCase {

	private string $customer = 't_racecustomer0001';

	private ?\WC_Session_Handler $saved_session = null;

	private ?FakeClient $fake = null;

	public function set_up(): void {
		parent::set_up();
		$this->saved_session = WC()->session instanceof \WC_Session_Handler ? WC()->session : null;
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 16, 'restricted' => false ) ) );
		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_domains_client', fn() => $this->fake );
		$this->service_product( 'domain' );
		wc_load_cart();
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $this->customer ) );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $this->customer ) );
		wp_cache_flush();

		if ( $this->saved_session ) {
			WC()->session = $this->saved_session;
		}

		WC()->cart->set_cart_contents( array() );
		parent::tear_down();
	}

	/** A new request: a session handler that reads the stored session of the customer now, and a cart loaded from it. */
	private function request(): \WC_Session_Handler {
		wp_cache_flush();
		$session = new \WC_Session_Handler();
		$props   = array( '_customer_id' => $this->customer, '_has_cookie' => true, '_session_expiration' => time() + DAY_IN_SECONDS, '_session_expiring' => time() + DAY_IN_SECONDS - HOUR_IN_SECONDS );

		foreach ( $props as $name => $value ) {
			$ref = new \ReflectionProperty( \WC_Session_Handler::class, $name );
			$ref->setAccessible( true );
			$ref->setValue( $session, $value );
		}

		$data = new \ReflectionProperty( \WC_Session_Handler::class, '_data' );
		$data->setAccessible( true );
		$data->setValue( $session, $session->get_session_data() );
		WC()->session = $session;
		$this->load_cart();

		return $session;
	}

	/** What WooCommerce does at wp_loaded: the cart is read from the session the request holds. */
	private function load_cart(): void {
		WC()->cart->set_cart_contents( array() );
		$prop = new \ReflectionProperty( \WC_Cart::class, 'session' );
		$prop->setAccessible( true );
		$prop->getValue( WC()->cart )->get_cart_from_session();
	}

	/** What update_order_review does: totals are worked out and the customer fields are posted, so the session is dirty. */
	private function recalculate( \WC_Session_Handler $session ): void {
		WC()->cart->calculate_totals();
		$session->set( 'customer', array( 'billing_city' => 'Aarhus' ) );
	}

	/** The domain lines of the stored session, read as a request that starts now. */
	private function stored_domains(): array {
		$this->request();
		$names = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['beltoft_services_domain']['domain'] ) ) {
				$names[] = $item['beltoft_services_domain']['domain'];
			}
		}

		return $names;
	}

	private function register_reply( string $name ): void {
		$this->fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => $name, 'status' => 'free', 'is_premium' => false ) ) ) );
	}

	/** A cart that already holds one other line, stored. */
	private function seed_cart(): void {
		$session = $this->request();
		$other   = $this->service_product( 'fake', array(), array( 'slug' => 'other-' . wp_generate_password( 4, false ) ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $other->get_id() ) );
		WC()->cart->calculate_totals();
		$session->save_data();
	}

	public function test_a_parallel_request_that_saves_last_does_not_take_the_domain_line_out_of_the_cart(): void {
		$name = 'race-' . strtolower( wp_generate_password( 8, false, false ) ) . '.com';
		$this->register_reply( $name );
		$this->seed_cart();

		// Request B (update_order_review) starts and reads the cart before the add is stored.
		$b = $this->request();
		$this->assertCount( 1, WC()->cart->get_cart() );
		$b_cart = WC()->cart->get_cart();

		// Request A (domains/cart) adds the line and stores the session before it answers.
		$a = $this->request();
		$added = Cart::add_line( $name, 'register' );
		$this->assertIsArray( $added );
		$this->assertTrue( $added['added'] );
		$this->assertSame( array( $name ), $this->stored_domains(), 'The add is stored.' );

		// B recalculates on the cart it read and writes its session at shutdown, after A.
		WC()->session = $b;
		WC()->cart->set_cart_contents( $b_cart );
		$this->recalculate( $b );
		$b->save_data();

		$this->assertSame( array( $name ), $this->stored_domains(), 'The domain line is still in the cart after the parallel request wrote last.' );
	}

	/** The race, then the stale request's shutdown: the line is in the stored session itself, not only put back at the next load. */
	public function test_the_stale_request_writes_the_line_back_with_its_own_session(): void {
		// Only the shutdown guard may do it here: the put back at the totals is switched off.
		remove_action( 'woocommerce_before_calculate_totals', array( \BeltoftServices\Domains\PendingLines::class, 'restore' ), 5 );
		$name = $this->race( true );
		add_action( 'woocommerce_before_calculate_totals', array( \BeltoftServices\Domains\PendingLines::class, 'restore' ), 5 );
		remove_action( 'woocommerce_cart_loaded_from_session', array( \BeltoftServices\Domains\PendingLines::class, 'restore' ), 5 );
		$this->request();
		add_action( 'woocommerce_cart_loaded_from_session', array( \BeltoftServices\Domains\PendingLines::class, 'restore' ), 5 );
		$cart = WC()->session->get( 'cart', array() );
		$this->assertSame( array( $name ), array_values( array_map( static fn( $i ) => $i['beltoft_services_domain']['domain'] ?? '', array_filter( $cart, static fn( $i ) => isset( $i['beltoft_services_domain'] ) ) ) ) );
		$this->assertSame( 2, count( $cart ) );
	}

	/** A and B as in the test above. @return string The domain A added. */
	private function race( bool $guard = false, ?callable $between = null ): string {
		$name = 'race-' . strtolower( wp_generate_password( 8, false, false ) ) . '.com';
		$this->register_reply( $name );
		$this->seed_cart();
		$b = $this->request();
		$b_cart = WC()->cart->get_cart();
		$this->request();
		$this->assertTrue( Cart::add_line( $name, 'register' )['added'] );
		$calls = count( $this->fake->calls() );

		if ( $between ) { $between( $name ); }

		WC()->session = $b;
		WC()->cart->set_cart_contents( $b_cart );
		$this->recalculate( $b );

		if ( $guard ) { \BeltoftServices\Domains\PendingLines::guard_session(); }

		$b->save_data();
		$this->registrar_calls = $calls;

		return $name;
	}

	private int $registrar_calls = 0;

	public function test_a_line_removed_on_the_page_does_not_come_back(): void {
		$name = $this->race( true, function ( string $name ): void {
			$this->request();
			$key = array_key_last( array_filter( WC()->cart->get_cart(), static fn( $i ) => isset( $i['beltoft_services_domain'] ) ) );
			$this->assertTrue( WC()->cart->remove_cart_item( $key ) );
			WC()->session->save_data();
		} );
		$this->assertSame( array(), $this->stored_domains(), $name );
	}

	public function test_an_emptied_cart_and_a_placed_order_clear_the_pending_line(): void {
		$name = $this->race( true, function (): void {
			do_action( 'woocommerce_checkout_order_processed', 1, array(), null );
			$this->request();
			WC()->cart->set_cart_contents( array() );
			WC()->session->set( 'cart', null );
			WC()->session->save_data();
		} );
		$this->assertSame( array(), $this->stored_domains(), $name );
		$this->assertFalse( get_transient( \BeltoftServices\Domains\PendingLines::TRANSIENT . md5( $this->customer ) ) );
	}

	public function test_replacing_the_domain_with_another_does_not_bring_the_first_back(): void {
		$first  = 'first-' . strtolower( wp_generate_password( 8, false, false ) ) . '.com';
		$second = 'second-' . strtolower( wp_generate_password( 8, false, false ) ) . '.com';
		$this->register_reply( $first );
		$this->register_reply( $second );
		$this->seed_cart();
		// A request that read the cart before either domain was chosen.
		$stale = $this->request();
		$stale_cart = WC()->cart->get_cart();
		// The page adds the first, then the cart rule or the page removes it and adds the second.
		$this->request();
		$this->assertTrue( Cart::add_line( $first, 'register' )['added'] );
		$key = array_key_last( array_filter( WC()->cart->get_cart(), static fn( $i ) => ( $i['beltoft_services_domain']['domain'] ?? '' ) === $first ) );
		$this->assertTrue( WC()->cart->remove_cart_item( $key ) );
		$this->assertTrue( Cart::add_line( $second, 'register' )['added'] );
		WC()->session = $stale;
		WC()->cart->set_cart_contents( $stale_cart );
		$this->recalculate( $stale );
		\BeltoftServices\Domains\PendingLines::guard_session();
		$stale->save_data();

		$this->assertSame( array( $second ), $this->stored_domains() );
	}

	public function test_a_pending_line_for_a_domain_that_became_held_is_dropped_not_added(): void {
		$name = $this->race( true, function ( string $name ): void {
			$product = $this->service_product( 'domain', array(), array( 'slug' => 'held-domain-test' ) );
			$license = $this->service_license( $product, array( 'fire' => false ) );
			\BeltoftServices\Resource::create( $license, new \BeltoftServices\Domains\DomainDriver(), array(), array( 'state' => 'ready', 'domain' => $name, 'mode' => 'register', 'remote_id' => '12' ) );
			// The request that follows is the stale one: it must not put the line back.
			$this->request();
			WC()->cart->set_cart_contents( array() );
		} );
		$this->assertSame( array(), array_values( array_filter( $this->stored_domains(), static fn( $d ) => $d === $name ) ), 'Not put back.' );
		$this->assertFalse( get_transient( \BeltoftServices\Domains\PendingLines::TRANSIENT . md5( $this->customer ) ), 'And forgotten.' );
	}

	public function test_a_pending_line_for_an_extension_that_left_the_price_table_is_dropped(): void {
		$name = $this->race( true, static function (): void {
			update_option( Prices::OPTION, array( 'net' => array( 'price' => 9, 'restricted' => false ) ) );
		} );
		$this->assertSame( array(), array_values( array_filter( $this->stored_domains(), static fn( $d ) => $d === $name ) ) );
		$this->assertFalse( get_transient( \BeltoftServices\Domains\PendingLines::TRANSIENT . md5( $this->customer ) ) );
	}

	public function test_another_customer_gets_nothing_back(): void {
		$this->race( true );
		$mine = $this->customer;
		$this->customer = 't_someoneelse00002';
		$this->assertSame( array(), $this->stored_domains() );
		$this->customer = $mine;
		$this->assertCount( 1, $this->stored_domains() );
	}

	public function test_putting_a_line_back_never_asks_the_registrar(): void {
		$name = $this->race( true );
		$before = count( $this->fake->calls() );
		$this->assertSame( array( $name ), $this->stored_domains() );
		$this->assertSame( array( $name ), $this->stored_domains() );
		$this->assertSame( $before, count( $this->fake->calls() ), 'The restore made no registrar call.' );
		$this->assertSame( $this->registrar_calls, $before );
	}

	public function test_the_line_comes_back_as_it_was_added_with_no_second_line_and_the_same_price(): void {
		$name = $this->race( true );
		$this->request();
		$lines = array_values( array_filter( WC()->cart->get_cart(), static fn( $i ) => isset( $i['beltoft_services_domain'] ) ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( array( 'domain' => $name, 'mode' => 'register' ), $lines[0]['beltoft_services_domain'] );
		WC()->cart->calculate_totals();
		$this->assertSame( 16.0, (float) array_values( array_filter( WC()->cart->get_cart(), static fn( $i ) => isset( $i['beltoft_services_domain'] ) ) )[0]['data']->get_price() );
		$this->request();
		$this->assertCount( 1, array_filter( WC()->cart->get_cart(), static fn( $i ) => isset( $i['beltoft_services_domain'] ) ) );
	}

	public function test_a_subscription_recurring_cart_never_gets_a_pending_line(): void {
		$this->race( true );
		$this->request();
		// What WooCommerce Subscriptions does for each billing group: a clone of the cart without the other groups' lines.
		$recurring = clone WC()->cart;
		$recurring->recurring_cart_key = 'hosting_monthly';
		foreach ( $recurring->cart_contents as $key => $item ) {
			if ( isset( $item['beltoft_services_domain'] ) ) { unset( $recurring->cart_contents[ $key ] ); }
		}
		\BeltoftServices\Domains\PendingLines::restore( $recurring );
		$this->assertSame( array(), array_filter( $recurring->cart_contents, static fn( $i ) => isset( $i['beltoft_services_domain'] ) ), 'The domain stays out of the other subscription.' );
		$this->assertCount( 1, array_filter( WC()->cart->get_cart(), static fn( $i ) => isset( $i['beltoft_services_domain'] ) ), 'The customer cart keeps it.' );

		// A copy without the key that is not the customer's cart is left alone too.
		$copy = clone WC()->cart;
		foreach ( $copy->cart_contents as $key => $item ) {
			if ( isset( $item['beltoft_services_domain'] ) ) { unset( $copy->cart_contents[ $key ] ); }
		}
		\BeltoftServices\Domains\PendingLines::restore( $copy );
		$this->assertSame( array(), array_filter( $copy->cart_contents, static fn( $i ) => isset( $i['beltoft_services_domain'] ) ) );
	}

	private function pending_key(): string {
		return \BeltoftServices\Domains\PendingLines::TRANSIENT . md5( $this->customer );
	}

	public function test_a_pending_line_ends_with_the_session_it_was_kept_in(): void {
		$name = $this->race( true );
		$rows = get_transient( $this->pending_key() );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $rows[ $name ]['exp'], 'Not longer than the session.' );
		$this->assertSame( array( $name ), $this->stored_domains(), 'Within the session it comes back.' );
	}

	public function test_a_pending_line_does_not_come_back_after_the_session_ended(): void {
		// The stale request B runs after the customer's cart session expired: the same id (a logged-in customer), an old row.
		$this->race( true, function ( string $name ): void {
			$rows = get_transient( $this->pending_key() );
			$rows[ $name ]['exp'] = time() - 5;
			set_transient( $this->pending_key(), $rows, DAY_IN_SECONDS );
		} );
		$this->assertSame( array(), $this->stored_domains() );
	}

	public function test_a_guest_who_logs_in_leaves_no_pending_store_behind(): void {
		$this->race( true );
		$this->assertNotFalse( get_transient( $this->pending_key() ) );
		$this->request();
		do_action( 'wp_login', 'someone', new \WP_User( self::factory()->user->create() ) );
		$this->assertFalse( get_transient( $this->pending_key() ) );
	}
}
