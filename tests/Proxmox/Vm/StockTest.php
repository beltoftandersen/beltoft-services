<?php
/**
 * Stock of virtual machine products. Only the fake client and the capacity cache are used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Vm;

use BeltoftServices\Billing\ExistingFlow;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Pve\Capacity;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Pve\Placement;
use BeltoftServices\Proxmox\Vm\ProxmoxJobs;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Proxmox\Vm\Stock;
use BeltoftServices\Tests\TestCase;

/**
 * A VM product is closed (stored `outofstock`) only while no public address is free, or by the owner by hand;
 * renewals, switches and resubscribes are never blocked. Capacity never closes it (owner decision 2026-11-11):
 * the tests that proved a closing for lack of memory are changed on purpose to prove the opposite, and the ones
 * that used a capacity write to close a plan use the address pool instead, the one automatic closing left.
 */
class StockTest extends TestCase {

	private const GB = 1073741824;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		$this->wipe();

		// WooCommerce Subscriptions' order creation commits the test transaction, so a product of an
		// earlier test can survive: sync() writes every Proxmox product, so none may be left over.
		foreach ( get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) ) as $left ) {
			wp_delete_post( (int) $left, true );
		}

		$this->assertTrue(
			Clusters::save(
				'lab',
				array(
					'label'             => 'Lab',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => 'secret-secret-secret-1',
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a, node-b',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => '9000 = Debian 12',
				)
			)
		);

		IpPool::add( '192.0.2.10' );
		$this->capacity( 8 );
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Clusters::OPTION );
		unset( $_REQUEST['switch-subscription'], $_GET['resubscribe'] );
		WC()->cart->empty_cart();
		$this->wipe();

		parent::tear_down();
	}

	private function wipe(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%beltoft\\_services\\_proxmox\\_%'" );
		// phpcs:enable
		wp_cache_flush();
	}

	/**
	 * Caches the capacity: two nodes with this much free memory each (committed + reserve already in `mem`).
	 *
	 * @param int $free_gb Free GB per node; null leaves no cache.
	 */
	private function capacity( ?int $free_gb ): void {
		if ( null === $free_gb ) {
			delete_transient( Placement::CAPACITY_PREFIX . 'lab' );
			return;
		}

		$rows = array();

		foreach ( array( 'node-a', 'node-b' ) as $node ) {
			$rows[] = array(
				'node'   => $node,
				'status' => 'online',
				'maxmem' => 64 * self::GB,
				'mem'    => ( 64 - $free_gb ) * self::GB,
			);
		}

		set_transient( Placement::CAPACITY_PREFIX . 'lab', $rows, HOUR_IN_SECONDS );
	}

	private function product( int $memory_mb = 4096, array $extra = array() ): \WC_Product {
		return $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => $memory_mb, 'disk_gb' => 30 ), $extra );
	}

	/**
	 * The stored stock status, read fresh.
	 *
	 * @param \WC_Product|int $p Product or id.
	 */
	private function stored( $p ): string {
		wp_cache_flush();

		return wc_get_product( is_object( $p ) ? $p->get_id() : $p )->get_stock_status( 'edit' );
	}

	/**
	 * The address comes back without the pool telling anybody (another process wrote it): no sync runs.
	 */
	private function quiet_unblock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( Schema::tables()['ips'], array( 'state' => 'free' ), array( 'state' => 'blocked' ) );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	private function in_stock( \WC_Product $p ): bool {
		$p = wc_get_product( $p->get_id() );

		return $p->is_in_stock() && $p->is_purchasable();
	}

	public function test_out_of_stock_without_free_ip(): void {
		$product = $this->product();
		$this->assertTrue( $this->in_stock( $product ), 'Free address and room: in stock.' );

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertFalse( $this->in_stock( $product ) );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'WooCommerce\'s own stored status says sold out.' );
		$this->assertTrue( wc_get_product( $product->get_id() )->is_purchasable(), 'Purchasable is not touched: sold out is the stock status.' );

		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertTrue( $this->in_stock( $product ), 'The cached count is cleared by the change.' );
	}
	public function test_capacity_never_closes_a_server_plan(): void {
		$product = $this->product( 4096 );

		foreach ( array( 3, 0, 4 ) as $free ) {
			$this->capacity( $free );
			$this->assertTrue( $this->in_stock( $product ), $free . ' GB free: a 4 GB plan is still on sale.' );
		}

		$this->capacity( null );
		as_unschedule_all_actions( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP ); // Saving the cluster queued one.
		$this->assertTrue( $this->in_stock( $product ), 'No cache: nothing is asked, nothing is queued.' );
		$this->assertSame( array(), $this->http_calls() );
		$this->assertFalse( as_has_scheduled_action( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP ), 'A page view does not queue a capacity run.' );

		// Every node full or offline: still on sale.
		set_transient( Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'node-a', 'status' => 'offline', 'maxmem' => 64 * self::GB, 'mem' => 0 ), array( 'node' => 'node-b', 'status' => 'online', 'maxmem' => 64 * self::GB, 'mem' => 64 * self::GB ) ), HOUR_IN_SECONDS );
		$this->assertTrue( $this->in_stock( $product ) );

		// Set out of stock by hand: sold out, and a capacity write does not reopen it.
		$fresh = wc_get_product( $product->get_id() );
		$fresh->set_stock_status( 'outofstock' );
		$fresh->save();
		$this->capacity( 32 );
		$this->assertFalse( $this->in_stock( $product ) );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock() );
	}
	public function test_variation_plan_memory_does_not_decide(): void {
		$parent = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 2048 ), array( 'memory_mb' => 16384 ) ) ) );
		$this->capacity( 8 );
		$ids = $parent->get_children();

		$this->assertTrue( $this->in_stock( wc_get_product( $ids[0] ) ) );
		$this->assertTrue( $this->in_stock( wc_get_product( $ids[1] ) ), 'The large variation does not fit 8 GB free and is on sale all the same.' );

		$variation = wc_get_product( $ids[1] );
		$variation->set_stock_status( 'outofstock' );
		$variation->save();
		$this->assertFalse( $this->in_stock( wc_get_product( $ids[1] ) ), 'Out of stock by hand: sold out.' );
		$this->assertTrue( $this->in_stock( wc_get_product( $ids[0] ) ) );
	}
	public function test_other_products_are_left_alone(): void {
		$other = $this->service_product( 'fake' );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->capacity( null );

		$this->assertTrue( $this->in_stock( $other ) );

		$plain = new \WC_Product_Simple();
		$plain->set_regular_price( '5' );
		$plain->save();
		$this->assertTrue( $this->in_stock( $plain ) );
	}

	public function test_showing_stock_for_many_plans_makes_no_capacity_query(): void {
		global $wpdb;
		$parent   = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 2048 ), array( 'memory_mb' => 16384 ), array( 'memory_mb' => 65536 ) ) ) );
		$products = array_merge( array( $this->product( 4096 ), $this->product( 8192 ) ), array_map( 'wc_get_product', $parent->get_children() ) );
		$this->capacity( 0 );
		wp_cache_flush();

		$seen = array();
		$grab = static function ( $query ) use ( &$seen ) {
			$seen[] = (string) $query;

			return $query;
		};
		add_filter( 'query', $grab );

		foreach ( $products as $product ) {
			$fresh = wc_get_product( $product->get_id() );
			$fresh->is_purchasable();
			$fresh->is_in_stock();
			apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 );
		}
		do_action( 'woocommerce_check_cart_items' );
		do_action( 'woocommerce_checkout_process' );

		remove_filter( 'query', $grab );

		foreach ( $seen as $sql ) {
			$this->assertStringNotContainsString( Schema::tables()['vms'], $sql, 'The display, cart and checkout path read the machine records: ' . $sql );
			$this->assertStringNotContainsString( $wpdb->prefix . 'beltoft_licenses_activations', $sql, 'The display, cart and checkout path read the activations: ' . $sql );
		}
		$this->assertSame( array(), $this->http_calls() );
	}

	public function test_a_product_already_out_of_stock_stays_so(): void {
		$this->assertFalse( \BeltoftServices\Proxmox\Vm\Stock::in_stock( false, $this->product() ), 'Only a flow of an existing customer opens it.' );
		$this->assertTrue( \BeltoftServices\Proxmox\Vm\Stock::in_stock( true, $this->product() ), 'It never closes.' );
	}
	/**
	 * A subscription of a user (WooCommerce Subscriptions' own factory).
	 *
	 * @param int $user_id Owner.
	 */
	private function subscription( int $user_id ): \WC_Subscription {
		$subscription = wcs_create_subscription(
			array(
				'customer_id'      => $user_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
			)
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		return $subscription;
	}

	/**
	 * A customer, logged in, with a subscription that holds the product (a variation or a simple product).
	 *
	 * @param \WC_Product $product Product the subscription holds.
	 * @param string      $status  Subscription status.
	 */
	private function holder( \WC_Product $product, string $status = 'active' ): \WC_Subscription {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user );
		$sub->add_product( $product );
		$sub->save();

		$sub->update_status( 'active' );

		if ( 'active' !== $status ) {
			$sub->update_status( $status );
		}

		wp_set_current_user( $user );

		return $sub;
	}

	/**
	 * The item data WooCommerce Subscriptions gives a cart line of a flow.
	 *
	 * @param string $key Flow key.
	 * @param int    $id  Subscription id.
	 * @return array<string, mixed>
	 */
	private function flow( string $key, int $id ): array {
		return array( $key => array( 'subscription_id' => $id, 'item_id' => 0, 'next_payment_timestamp' => 0, 'upgraded_or_downgraded' => '' ) );
	}

	/**
	 * WooCommerce Subscriptions allows the switch of an active subscription (these test products are not
	 * subscription products, so its own type check would say no). The filter is the one its own rule runs through.
	 */
	private function allow_switching(): void {
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', static fn( $can, $item, $sub ) => $sub instanceof \WC_Subscription && $sub->has_status( 'active' ), 10, 3 );
	}

	/**
	 * The product set out of stock by hand: no marker.
	 */
	private function hand_closed_product(): \WC_Product {
		$product = $this->product();
		$fresh   = wc_get_product( $product->get_id() );
		$fresh->set_stock_status( 'outofstock' );
		$fresh->save();
		$this->assertFalse( $this->in_stock( $product ) );
		$this->assertSame( '', (string) get_post_meta( $product->get_id(), Stock::META_CLOSED, true ), 'No marker: the owner closed it.' );

		return $product;
	}

	private function sold_out_product(): \WC_Product {
		$product = $this->product();
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertFalse( $this->in_stock( $product ), 'Sold out for a new customer.' );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'The status is stored, so every flow below must beat WooCommerce\'s own stored-status checks.' );

		return $product;
	}

	public function test_a_real_renewal_switch_and_resubscribe_pass_on_a_hand_closed_product(): void {
		$product = $this->hand_closed_product();
		$this->allow_switching();

		// A new VM added while sold out is refused by WooCommerce itself.
		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $product->get_id() ), 'A new server is refused.' );
		$this->assertSame( array(), WC()->cart->get_cart() );
		wc_clear_notices();

		// WCS adds each of its flows through WC()->cart->add_to_cart() with its key in the item data
		// (class-wcs-cart-renewal.php:364-370); is_purchasable() runs before the item is in the cart.
		foreach ( array( 'subscription_renewal' => 'active', 'subscription_resubscribe' => 'cancelled', 'subscription_switch' => 'active' ) as $key => $status ) {
			$subscription = $this->holder( $product, $status );

			WC()->cart->empty_cart();
			$added = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( $key, $subscription->get_id() ) );
			$this->assertNotFalse( $added, $key . ' is added while sold out: ' . wp_json_encode( wc_get_notices() ) );
			// WCS's own cart checks may drop an item whose data is not a full switch; the add itself is what matters here.
			$this->assertArrayHasKey( $added, WC()->cart->cart_contents, $key . ' in cart' );

			// The flag is closed again: a plain add to an empty cart is refused.
			WC()->cart->empty_cart();
			$this->assertFalse( WC()->cart->add_to_cart( $product->get_id() ), 'A plain add after a ' . $key . ' add is refused.' );
			wc_clear_notices();
		}
	}

	public function test_a_product_the_address_pool_closed_opens_for_a_renewal_and_a_switch_but_not_a_resubscribe(): void {
		$product = $this->sold_out_product();
		$this->assertSame( 'yes', (string) get_post_meta( $product->get_id(), Stock::META_CLOSED, true ) );
		$this->allow_switching();

		foreach ( array( 'subscription_renewal' => 'active', 'subscription_switch' => 'active' ) as $key => $status ) {
			$subscription = $this->holder( $product, $status );
			WC()->cart->empty_cart();
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( $key, $subscription->get_id() ) ), $key . ': the machine exists and has its address: ' . wp_json_encode( wc_get_notices() ) );
		}

		// A resubscribe builds a new machine, and there is no address for it.
		$subscription = $this->holder( $product, 'cancelled' );
		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( 'subscription_resubscribe', $subscription->get_id() ) ), 'A resubscribe that would build a new server stays closed while the pool is empty.' );
		wc_clear_notices();
		WC()->cart->empty_cart();
	}

	public function test_forged_flow_data_opens_nothing_for_a_guest_or_an_unrelated_or_foreign_subscription(): void {
		$product = $this->hand_closed_product();
		$other   = $this->product();
		$this->allow_switching();

		$mine    = $this->holder( $other ); // Own, active, but it holds another product.
		$mine_id = get_current_user_id();
		$foreign = $this->subscription( self::factory()->user->create() );
		$foreign->add_product( $product );
		$foreign->save();
		$guest_sub = $this->subscription( self::factory()->user->create() ); // No customer at all: user 0 must not match a guest.
		$guest_sub->add_product( $product );
		$guest_sub->set_customer_id( 0 );
		$guest_sub->save();
		$empty = $this->subscription( $mine_id ); // Own, but it has no line item at all.
		$empty->update_status( 'cancelled' );

		foreach ( array( 'subscription_renewal', 'subscription_resubscribe', 'subscription_switch' ) as $key ) {
			foreach ( array(
				'own subscription of another product' => $mine->get_id(),
				'own empty cancelled subscription'    => $empty->get_id(),
				'somebody else\'s subscription'       => $foreign->get_id(),
				'a subscription that does not exist'  => 999999,
			) as $what => $id ) {
				wp_set_current_user( $mine_id );
				WC()->cart->empty_cart();
				$this->assertFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( $key, $id ) ), $key . ' with ' . $what );
				wc_clear_notices();
			}

			wp_set_current_user( 0 );
			WC()->cart->empty_cart();
			$this->assertFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( $key, $foreign->get_id() ) ), $key . ' of a guest' );
			$this->assertFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( $key, $guest_sub->get_id() ) ), $key . ' of a guest, naming a subscription with no customer' );
			wc_clear_notices();
		}

		WC()->cart->empty_cart();
	}

	public function test_a_switch_only_counts_when_subscriptions_allows_switching_that_item(): void {
		$product = $this->hand_closed_product();
		$sub     = $this->holder( $product, 'on-hold' );
		$seen    = array();
		add_filter(
			'woocommerce_subscriptions_can_item_be_switched',
			static function ( $can, $item, $subscription ) use ( &$seen ) {
				$seen[] = $subscription instanceof \WC_Subscription ? $subscription->get_id() : 0;

				return $subscription instanceof \WC_Subscription && $subscription->has_status( 'active' );
			},
			10,
			3
		);

		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( 'subscription_switch', $sub->get_id() ) ), 'An on-hold subscription cannot be switched.' );
		$this->assertContains( $sub->get_id(), $seen, 'The decision is WooCommerce Subscriptions\' own.' );
		wc_clear_notices();
	}

	public function test_a_failed_flow_add_does_not_leave_the_exemption_open(): void {
		$product = $this->sold_out_product();
		$other   = $this->product();
		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );

		WC()->cart->empty_cart();
		// Item data with a flow key for ANOTHER product does not open this one.
		WC()->cart->add_to_cart( $other->get_id(), 1, 0, array(), array( 'subscription_renewal' => array( 'subscription_id' => 1 ) ) );
		$this->assertFalse( WC()->cart->add_to_cart( $product->get_id() ), 'Sold out product refused after another product\'s renewal add.' );
		wc_clear_notices();
	}

	public function test_an_add_that_aborts_after_the_item_data_filter_closes_the_flag(): void {
		$product = $this->sold_out_product();
		$sub     = $this->holder( $product );

		// WooCommerce throws after the item-data filter (here: something else makes the item not purchasable).
		$abort = static fn() => false;
		add_filter( 'woocommerce_is_purchasable', $abort, 999 );
		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( 'subscription_renewal', $sub->get_id() ) ) );
		remove_filter( 'woocommerce_is_purchasable', $abort, 999 );
		wc_clear_notices();

		// Between the abort and the next add the exemption must not stay open: a validation of the
		// next add (WooCommerce's form handler and REST/store routes run it first) closes it.
		apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 );
		$this->assertFalse( $this->in_stock( $product ), 'Closed by the next add\'s validation.' );

		// And so does the end of the request, for an abort nothing else followed.
		add_filter( 'woocommerce_is_purchasable', $abort, 999 );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( 'subscription_renewal', $sub->get_id() ) );
		remove_filter( 'woocommerce_is_purchasable', $abort, 999 );
		wc_clear_notices();
		$this->assertTrue( has_action( 'shutdown', array( ExistingFlow::class, 'clear' ) ) !== false );
		ExistingFlow::clear();
		$this->assertFalse( $this->in_stock( $product ), 'Closed at the end of the request.' );
	}

	public function test_a_session_reload_keeps_a_renewal_item(): void {
		$product = $this->sold_out_product();

		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( 'subscription_renewal', $this->holder( $product )->get_id() ) ) );

		// What the session holds for the cart (class-wc-cart-session.php:277 reads it back).
		$session = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$session[ $key ] = array_diff_key( $item, array( 'data' => 1, 'data_hash' => 1 ) );
		}

		WC()->cart->cart_contents = array();
		WC()->session->set( 'cart', $session );
		WC()->cart->get_cart_from_session();

		$this->assertCount( 1, WC()->cart->get_cart(), 'The renewal item survives a reload while sold out.' );

		// A plain item in the session is dropped.
		$plain   = $this->product();
		$session = array(
			'plainitem' => array(
				'key'               => 'plainitem',
				'product_id'        => $plain->get_id(),
				'variation_id'      => 0,
				'variation'         => array(),
				'quantity'          => 1,
				'data_hash'         => wc_get_cart_item_data_hash( $plain ),
				'line_tax_data'     => array( 'subtotal' => array(), 'total' => array() ),
				'line_subtotal'     => 10,
				'line_subtotal_tax' => 0,
				'line_total'        => 10,
				'line_tax'          => 0,
			),
		);
		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		WC()->cart->cart_contents = array();
		WC()->session->set( 'cart', $session );
		WC()->cart->get_cart_from_session();
		$this->assertCount( 1, WC()->cart->get_cart(), 'The reload no longer drops it (purchasable is not touched); WooCommerce\'s own stock check refuses it.' );
		$this->assertNotTrue( WC()->cart->check_cart_item_stock(), 'A new server with no address is refused at the cart check, as WooCommerce does it.' );
		wc_clear_notices();
	}

	public function test_paying_a_renewal_order_is_not_blocked(): void {
		$product = $this->sold_out_product();
		$user    = self::factory()->user->create();

		$subscription = $this->subscription( $user );
		$subscription->add_product( $product );
		$subscription->save();

		$renewal = wcs_create_renewal_order( $subscription );
		$this->assertInstanceOf( \WC_Order::class, $renewal );

		// The call of WooCommerce's order-pay check (class-wc-shortcode-checkout.php:154).
		$p = wc_get_product( $product->get_id() );
		$this->assertTrue( (bool) apply_filters( 'woocommerce_pay_order_product_in_stock', $p->is_in_stock(), $p, $renewal ), 'A renewal order can be paid.' );

		$plain = wc_create_order( array( 'customer_id' => $user ) );
		$plain->add_product( $product );
		$plain->save();
		$this->assertFalse( (bool) apply_filters( 'woocommerce_pay_order_product_in_stock', $p->is_in_stock(), $p, $plain ), 'An ordinary order for a new server cannot.' );
	}

	public function test_request_args_only_count_for_a_subscription_the_user_owns_that_holds_the_product(): void {
		$product = $this->hand_closed_product();
		$other   = $this->product();
		$this->allow_switching();
		$mine    = self::factory()->user->create();
		$theirs  = self::factory()->user->create();

		$own = $this->subscription( $mine );
		$own->add_product( $product );
		$own->save();
		$own->update_status( 'active' );
		$cancelled = $this->subscription( $mine );
		$cancelled->add_product( $product );
		$cancelled->save();
		$cancelled->update_status( 'cancelled' );
		$unrelated = $this->subscription( $mine );
		$unrelated->add_product( $other );
		$unrelated->save();
		$foreign = $this->subscription( $theirs );
		$foreign->add_product( $product );
		$foreign->save();
		// WooCommerce Subscriptions' own resubscribe rule needs a payment made; the filter is the one that rule ends in.
		add_filter( 'wcs_can_user_resubscribe_to_subscription', static fn( $can, $sub ) => $sub instanceof \WC_Subscription && $sub->has_status( array( 'cancelled', 'expired', 'pending-cancel' ) ), 10, 2 );

		$set = static function ( string $arg, ?string $value ): void {
			if ( 'switch-subscription' === $arg ) {
				if ( null === $value ) {
					unset( $_REQUEST[ $arg ] );
					return;
				}

				$_REQUEST[ $arg ] = $value;
				return;
			}

			if ( null === $value ) {
				unset( $_GET[ $arg ] );
				return;
			}

			$_GET[ $arg ] = $value;
		};

		foreach ( array( 'switch-subscription' => $own, 'resubscribe' => $cancelled ) as $arg => $good ) {
			wp_set_current_user( $mine );
			$set( $arg, (string) $good->get_id() );
			$this->assertTrue( $this->in_stock( $product ), $arg . ' with an own subscription that holds the product.' );

			$set( $arg, (string) $unrelated->get_id() );
			$this->assertFalse( $this->in_stock( $product ), $arg . ' with an own subscription of another product.' );

			$set( $arg, (string) $foreign->get_id() );
			$this->assertFalse( $this->in_stock( $product ), $arg . ' with somebody else\'s subscription.' );

			$set( $arg, '1' );
			$this->assertFalse( $this->in_stock( $product ), 'A forged ' . $arg . '=1 stays refused.' );

			$set( $arg, '999999' );
			$this->assertFalse( $this->in_stock( $product ), $arg . ' naming nothing.' );

			wp_set_current_user( 0 );
			$set( $arg, (string) $good->get_id() );
			$this->assertFalse( $this->in_stock( $product ), $arg . ' without a login.' );
			$set( $arg, '1' );
			$this->assertFalse( $this->in_stock( $product ), 'A guest with a forged ' . $arg . '=1.' );

			$set( $arg, null );
		}

		// A switch of a subscription that is not active, and a resubscribe of one that is.
		wp_set_current_user( $mine );
		$_REQUEST['switch-subscription'] = (string) $cancelled->get_id();
		$this->assertFalse( $this->in_stock( $product ), 'A cancelled subscription cannot be switched.' );
		unset( $_REQUEST['switch-subscription'] );
		$_GET['resubscribe'] = (string) $own->get_id();
		$this->assertFalse( $this->in_stock( $product ), 'An active subscription cannot be resubscribed.' );
		unset( $_GET['resubscribe'] );
	}

	public function test_the_stored_status_follows_the_pool(): void {
		$product = $this->product();
		$this->assertSame( 'instock', $this->stored( $product ) );

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'Written with WooCommerce CRUD when the last address goes.' );

		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertSame( 'instock', $this->stored( $product ) );
		$this->assertSame( '', (string) wc_get_product( $product->get_id() )->get_meta( Stock::META_CLOSED ), 'The marker goes with the closing.' );
	}

	public function test_every_pool_write_syncs_the_status(): void {
		$product = $this->product();
		$resource = 4242;

		$this->assertNotNull( IpPool::hold( $resource ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'hold(): the only address is taken.' );

		$this->assertTrue( IpPool::assign( $resource ) );
		$this->assertSame( 'outofstock', $this->stored( $product ) );

		$this->assertTrue( IpPool::release( $resource ) );
		$this->assertSame( 'instock', $this->stored( $product ), 'release()' );

		$this->assertTrue( IpPool::take( '192.0.2.10', $resource ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'take()' );
		IpPool::release( $resource );
		$this->assertSame( 'instock', $this->stored( $product ) );

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'block()' );

		IpPool::add( '192.0.2.11' );
		$this->assertSame( 'instock', $this->stored( $product ), 'add(): a new free address reopens it.' );
	}

	public function test_an_unchanged_status_is_not_saved_and_fires_no_hook(): void {
		$product = $this->product();
		$fired   = 0;
		$count   = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'woocommerce_product_set_stock_status', $count );
		add_action( 'woocommerce_before_product_object_save', $count );

		Stock::sync();
		Stock::sync( 'lab' );
		IpPool::add( '192.0.2.10' ); // Already in the pool: a pool write that changes nothing.
		$this->assertSame( 0, $fired, 'In stock and available: nothing is written.' );

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 2, $fired, 'One save, one stock status hook.' );

		$fired = 0;
		Stock::sync();
		IpPool::add( '192.0.2.10' );
		$this->assertSame( 0, $fired, 'Out of stock and still unavailable: nothing is written.' );

		remove_action( 'woocommerce_product_set_stock_status', $count );
		remove_action( 'woocommerce_before_product_object_save', $count );
	}

	public function test_set_stock_status_hook_fires_for_a_simple_product_and_a_variation(): void {
		$simple = $this->product();
		$parent = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 2048 ), array( 'memory_mb' => 16384 ) ) ) );
		$ids    = $parent->get_children();
		$seen   = array();

		add_action(
			'woocommerce_product_set_stock_status',
			static function ( $id, $status ) use ( &$seen ) {
				$seen[ 'p' . $id ] = $status;
			},
			10,
			2
		);
		add_action(
			'woocommerce_variation_set_stock_status',
			static function ( $id, $status ) use ( &$seen ) {
				$seen[ 'v' . $id ] = $status;
			},
			10,
			2
		);

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );

		$this->assertSame( 'outofstock', $seen[ 'p' . $simple->get_id() ] ?? '', 'The simple product: WooCommerce\'s hook fired (page caches purge on it).' );
		$this->assertSame( 'outofstock', $seen[ 'v' . $ids[0] ] ?? '' );
		$this->assertSame( 'outofstock', $seen[ 'v' . $ids[1] ] ?? '' );
		$this->assertSame( 'outofstock', $this->stored( $parent ) );
	}
	public function test_a_variable_parent_follows_its_variations(): void {
		$parent = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 2048 ), array( 'memory_mb' => 16384 ) ) ) );
		$ids    = $parent->get_children();

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $ids[0] ) );
		$this->assertSame( 'outofstock', $this->stored( $ids[1] ) );
		$this->assertSame( 'outofstock', $this->stored( $parent ), 'WC_Product_Variable::sync() closed the parent: every variation is out.' );

		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertSame( 'instock', $this->stored( $ids[0] ) );
		$this->assertSame( 'instock', $this->stored( $ids[1] ) );
		$this->assertSame( 'instock', $this->stored( $parent ) );
	}
	public function test_a_capacity_write_by_the_placement_does_not_touch_the_status(): void {
		$product = $this->product( 4096 );
		$this->capacity( 4 );
		$this->assertSame( 'instock', $this->stored( $product ) );

		// A placement commits 2 GB on each node's cache row it names; here the two nodes are filled in two steps.
		$this->assertTrue( Capacity::place( 'lab', 'node-a', 2 * self::GB, static fn() => true ) );
		$this->assertTrue( Capacity::place( 'lab', 'node-b', 2 * self::GB, static fn() => true ) );
		$this->assertSame( 'instock', $this->stored( $product ), 'Nothing fits any more, and the plan is still on sale.' );
		$this->assertTrue( $this->in_stock( $product ) );
	}
	public function test_a_sync_writes_no_product_of_another_driver_and_ignores_capacity(): void {
		$product = $this->product();
		$this->capacity( 1 );
		Stock::sync();
		$this->assertSame( 'instock', $this->stored( $product ), 'Capacity is not a reason to close.' );

		$other = $this->service_product( 'fake' );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $product ) );
		$this->assertSame( 'instock', $this->stored( $other ), 'A product of another driver is never written.' );
	}
	public function test_a_status_the_owner_set_by_hand_is_not_reopened(): void {
		$product = $this->product();
		$product->set_stock_status( 'outofstock' );
		$product->save();

		Stock::sync();
		$this->assertSame( 'outofstock', $this->stored( $product ), 'Available, but not closed by this plugin.' );
		$this->assertSame( '', (string) wc_get_product( $product->get_id() )->get_meta( Stock::META_CLOSED ) );

		// A plugin-closed product that the owner reopens by hand is closed again when it is still unavailable.
		$mine = $this->product();
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $mine ) );
		$mine = wc_get_product( $mine->get_id() );
		$mine->set_stock_status( 'instock' );
		$mine->save();
		Stock::sync();
		$this->assertSame( 'outofstock', $this->stored( $mine ) );
	}

	public function test_products_that_manage_stock_are_left_alone(): void {
		$product = $this->product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->save();

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertSame( 'instock', $fresh->get_stock_status( 'edit' ) );
		$this->assertSame( 5, $fresh->get_stock_quantity() );
	}

	public function test_no_capacity_cache_closes_nothing_and_queues_nothing(): void {
		$product = $this->product();
		$this->capacity( null );
		as_unschedule_all_actions( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP );

		$this->assertTrue( $this->in_stock( $product ), 'The stored status (instock) stands.' );
		$this->in_stock( $product );
		$this->assertFalse( as_has_scheduled_action( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP ), 'No capacity run is queued by a page view.' );

		Stock::sync();
		$this->assertSame( 'instock', $this->stored( $product ) );
		$this->assertSame( array(), $this->http_calls() );
	}
	public function test_a_plan_naming_a_cluster_that_is_not_saved_is_closed_as_a_fault_and_opens_when_it_is_saved(): void {
		$ghost = $this->service_product( 'proxmox', array( 'cluster' => 'ghost', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ) );
		$this->assertFalse( $this->in_stock( $ghost ), 'A configuration fault, not capacity: an order for it could never be built.' );
		$this->assertSame( 'yes', (string) get_post_meta( $ghost->get_id(), Stock::META_CLOSED, true ), 'With the marker, like the address pool.' );

		$this->assertTrue( $this->save_cluster_as( 'ghost' ) );
		$this->assertTrue( $this->in_stock( $ghost ), 'The cluster is saved: it opens.' );

		$this->assertTrue( Clusters::delete( 'ghost' ) );
		$this->assertFalse( $this->in_stock( $ghost ), 'Deleting the cluster closes its plans.' );

		// A plan the owner closed by hand is never reopened by a fixed fault.
		$this->assertTrue( $this->save_cluster_as( 'ghost2' ) );
		$hand = $this->service_product( 'proxmox', array( 'cluster' => 'ghost2', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ) );
		$h    = wc_get_product( $hand->get_id() );
		$h->set_stock_status( 'outofstock' );
		$h->save();
		$this->assertTrue( Clusters::delete( 'ghost2' ) );
		$this->assertTrue( $this->save_cluster_as( 'ghost2' ) );
		$this->assertFalse( $this->in_stock( $hand ), 'Closed by hand: a fixed fault does not reopen it.' );
	}

	public function test_an_extra_disk_product_without_a_size_is_closed_until_it_has_one(): void {
		$disk = $this->service_product( 'proxmox-disk', array( 'cluster' => 'lab', 'disk_gb' => 0 ) );
		$this->assertFalse( $this->in_stock( $disk ), 'No size, no disk to make.' );
		$this->assertSame( 'yes', (string) get_post_meta( $disk->get_id(), Stock::META_CLOSED, true ) );

		$fresh = wc_get_product( $disk->get_id() );
		$fresh->update_meta_data( \BeltoftServices\Product::META_PLAN . 'disk_gb', '20' );
		$fresh->save();
		$this->assertTrue( $this->in_stock( $disk ), 'A size is set: it opens.' );
	}
	public function test_a_product_closed_for_lack_of_addresses_reopens_with_the_address_whatever_the_cache(): void {
		$product = $this->product();
		$this->capacity( null );

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'No address needs no cache to be known.' );

		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertSame( 'instock', $this->stored( $product ), 'An address is back: it reopens, with or without a capacity cache.' );
	}
	public function test_stored_outofstock_does_not_block_a_renewal_cart_check(): void {
		$product = $this->sold_out_product();
		$sub     = $this->holder( $product );

		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( 'subscription_renewal', $sub->get_id() ) );
		$this->assertNotFalse( $key );

		// WC_Cart::check_cart_item_stock() (class-wc-cart.php:897) reads is_in_stock() of the item's product.
		$result = WC()->cart->check_cart_item_stock();
		$this->assertTrue( $result, 'The renewal item passes the cart stock check while stored out of stock.' );

		// The same item without a flow key does not.
		WC()->cart->empty_cart();
		WC()->cart->cart_contents['plain'] = array( 'key' => 'plain', 'product_id' => $product->get_id(), 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'data' => wc_get_product( $product->get_id() ) );
		$result = WC()->cart->check_cart_item_stock();
		$this->assertWPError( $result );
		$this->assertSame( 'out-of-stock', $result->get_error_code() );
		WC()->cart->empty_cart();
	}

	public function test_stored_outofstock_does_not_block_a_variation_in_a_flow(): void {
		$parent = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 2048 ), array( 'memory_mb' => 16384 ) ) ) );
		$ids    = $parent->get_children();
		$closed = wc_get_product( $ids[1] );
		$closed->set_stock_status( 'outofstock' );
		$closed->save();
		$this->assertSame( 'outofstock', $this->stored( $ids[1] ) );

		$this->allow_switching();
		$sub = $this->holder( wc_get_product( $ids[0] ) ); // The customer holds the other variation of the same product.
		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $parent->get_id(), 1, $ids[1], array( 'attribute_tier' => '1' ) ), 'A new server for the variation set out of stock is refused.' );
		wc_clear_notices();

		$key = WC()->cart->add_to_cart( $parent->get_id(), 1, $ids[1], array( 'attribute_tier' => '1' ), $this->flow( 'subscription_switch', $sub->get_id() ) );
		$this->assertNotFalse( $key, 'A switch to it is not: ' . wp_json_encode( wc_get_notices() ) );
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function test_a_stored_outofstock_the_owner_set_still_lets_an_existing_customer_through(): void {
		$product = $this->product();
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$own = $this->holder( $product, 'cancelled' );
		add_filter( 'wcs_can_user_resubscribe_to_subscription', '__return_true' ); // WooCommerce Subscriptions' payment-made rule.

		$_GET['resubscribe'] = (string) $own->get_id();
		$this->assertTrue( $this->in_stock( $product ), 'Renewals, switches and resubscribes of a subscription that holds it are never blocked by the stored status.' );
		unset( $_GET['resubscribe'] );
		$this->assertFalse( $this->in_stock( $product ), 'A new customer sees out of stock.' );
		wp_set_current_user( 0 );
	}

	public function test_paying_a_switch_and_a_resubscribe_order_is_not_blocked_by_the_stored_status(): void {
		$product = $this->sold_out_product();
		$user    = self::factory()->user->create();
		$sub     = $this->subscription( $user );
		$sub->add_product( $product );
		$sub->save();
		$p = wc_get_product( $product->get_id() );

		foreach ( array( 'resubscribe', 'switch' ) as $type ) {
			$order = wc_create_order( array( 'customer_id' => $user ) );
			$order->add_product( $product );
			$order->update_meta_data( '_subscription_' . $type, $sub->get_id() );
			$order->save();

			$this->assertTrue( (bool) apply_filters( 'woocommerce_pay_order_product_in_stock', $p->is_in_stock(), $p, $order ), 'A ' . $type . ' order can be paid while stored out of stock.' );
		}
	}

	/**
	 * The cluster "lab" as in set_up(), with these overrides.
	 *
	 * @param array<string, string> $over Overrides.
	 * @return true|\WP_Error
	 */
	private function save_cluster( array $over = array() ) {
		return $this->save_cluster_as( 'lab', $over );
	}

	/**
	 * A cluster with this id, otherwise as in set_up().
	 *
	 * @param string                $id   Cluster id.
	 * @param array<string, string> $over Overrides.
	 * @return true|\WP_Error
	 */
	private function save_cluster_as( string $id, array $over = array() ) {
		return Clusters::save(
			$id,
			array_merge(
				array(
					'label'             => 'Lab',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => 'secret-secret-secret-1',
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a, node-b',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => '9000 = Debian 12',
				),
				$over
			)
		);
	}

	public function test_the_owner_changing_the_status_ends_the_plugins_claim_on_it(): void {
		$product = $this->product( 4096 );
		$id      = $product->get_id();

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $id ) );
		$this->assertSame( 'yes', get_post_meta( $id, Stock::META_CLOSED, true ), 'Closed by the plugin.' );

		// The address comes back (no sync yet), and the owner reopens by hand, then closes on purpose.
		$this->quiet_unblock();
		$owner = wc_get_product( $id );
		$owner->set_stock_status( 'instock' );
		$owner->save();
		$this->assertSame( '', (string) get_post_meta( $id, Stock::META_CLOSED, true ), 'The owner\'s change ends the claim.' );

		$owner = wc_get_product( $id );
		$owner->set_stock_status( 'outofstock' );
		$owner->save();

		Stock::sync();
		$this->assertSame( 'outofstock', $this->stored( $id ), 'Closed on purpose: the next sync does not reopen it.' );
		IpPool::add( '192.0.2.77' );
		$this->assertSame( 'outofstock', $this->stored( $id ) );
	}

	public function test_the_owner_flipping_twice_between_syncs_leaves_no_claim(): void {
		$product = $this->product( 4096 );
		$id      = $product->get_id();

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->quiet_unblock();

		foreach ( array( 'instock', 'outofstock', 'instock', 'outofstock' ) as $status ) {
			$owner = wc_get_product( $id );
			$owner->set_stock_status( $status );
			$owner->save();
		}

		$this->assertSame( '', (string) get_post_meta( $id, Stock::META_CLOSED, true ) );
		Stock::sync();
		$this->assertSame( 'outofstock', $this->stored( $id ), 'The owner\'s last word stands.' );
	}

	public function test_the_plugins_own_writes_keep_their_claim(): void {
		$product = $this->product( 4096 );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );

		$this->assertSame( 'yes', get_post_meta( $product->get_id(), Stock::META_CLOSED, true ) );
		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertSame( 'instock', $this->stored( $product ), 'Its own closing is undone by it.' );
	}
	public function test_a_new_product_with_no_address_is_stored_out_of_stock_when_saved(): void {
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$product = $this->product( 4096 );

		$this->assertSame( 'outofstock', $this->stored( $product ), 'No sync: the save itself wrote it.' );
		$this->assertSame( 'yes', get_post_meta( $product->get_id(), Stock::META_CLOSED, true ) );
	}

	public function test_a_new_product_with_no_room_on_any_node_is_stored_in_stock(): void {
		$this->capacity( 1 );
		$product = $this->product( 4096 );

		$this->assertSame( 'instock', $this->stored( $product ), 'Capacity never closes a plan.' );
		$this->assertSame( '', (string) get_post_meta( $product->get_id(), Stock::META_CLOSED, true ) );
	}
	public function test_editing_the_plan_memory_does_not_change_the_status(): void {
		$product = $this->product( 4096 );
		$this->capacity( 3 );
		$product = wc_get_product( $product->get_id() );
		$product->update_meta_data( \BeltoftServices\Product::META_PLAN . 'memory_mb', '8192' );
		$product->save();
		$this->assertSame( 'instock', $this->stored( $product ), 'A plan that does not fit is on sale.' );
	}
	public function test_editing_a_variation_does_not_close_it_or_its_parent_for_capacity(): void {
		$parent = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 16384 ) ) ) );
		$ids    = $parent->get_children();
		$this->assertSame( 'instock', $this->stored( $ids[0] ), 'Too big for 8 GB, in stock.' );
		$this->assertSame( 'instock', $this->stored( $parent ) );

		$variation = wc_get_product( $ids[0] );
		$variation->update_meta_data( \BeltoftServices\Product::META_PLAN . 'memory_mb', '2048' );
		$variation->save();

		$this->assertSame( 'instock', $this->stored( $ids[0] ) );
		$this->assertSame( 'instock', $this->stored( $parent ) );
	}
	public function test_a_product_of_another_driver_is_not_synced_by_its_save(): void {
		$this->capacity( 1 );
		$other = $this->service_product( 'fake' );
		$this->assertSame( 'instock', $this->stored( $other ) );
	}

	public function test_saving_a_cluster_with_other_nodes_does_not_change_the_status_and_deleting_it_closes_its_plans(): void {
		$product = $this->product( 4096 );
		$this->assertSame( 'instock', $this->stored( $product ) );

		$this->assertTrue( $this->save_cluster( array( 'nodes' => 'node-c' ) ) );
		$this->assertSame( 'instock', $this->stored( $product ), 'The nodes are not a reason to close a plan.' );

		$this->assertTrue( $this->save_cluster() );
		$this->assertTrue( Clusters::delete( 'lab' ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'A plan without a saved cluster is a fault: it closes.' );
		$this->assertTrue( $this->save_cluster() );
		$this->assertSame( 'instock', $this->stored( $product ), 'The cluster is back: it opens.' );
	}

	public function test_one_failing_product_does_not_stop_the_others_and_is_logged(): void {
		$a = $this->product( 4096 );
		$b = $this->product( 4096 );
		global $wpdb;
		// The last address goes without the pool telling anybody (another process wrote it): no sync has run yet.
		$wpdb->update( Schema::tables()['ips'], array( 'state' => 'blocked' ), array( 'state' => 'free' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		delete_transient( IpPool::FREE_TRANSIENT );

		$fail = static function ( $object ) use ( $a ) {
			if ( $object instanceof \WC_Product && $object->get_id() === $a->get_id() ) {
				throw new \RuntimeException( 'boom' );
			}
		};
		add_action( 'woocommerce_before_product_object_save', $fail );

		$log = tempnam( sys_get_temp_dir(), 'bsl' );
		$old = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture the log line.

		Stock::sync();

		ini_set( 'error_log', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		remove_action( 'woocommerce_before_product_object_save', $fail );

		$this->assertSame( 'instock', $this->stored( $a ), 'The failing one is left as it was.' );
		$this->assertSame( 'outofstock', $this->stored( $b ), 'The other one is still synced.' );
		$this->assertStringContainsString( 'product ' . $a->get_id() . ' could not be synced: boom', (string) file_get_contents( $log ) );
		unlink( $log );
	}

	public function test_pool_writes_that_change_nothing_do_not_sync(): void {
		$resource = 4343;
		$this->assertNotNull( IpPool::hold( $resource ) );
		IpPool::add( '192.0.2.20' );

		$probe = static function (): void {
			set_transient( IpPool::FREE_TRANSIENT, 99, MINUTE_IN_SECONDS ); // Every real change clears it (changed()).
		};
		$still = function ( string $what ) {
			$this->assertSame( 99, get_transient( IpPool::FREE_TRANSIENT ), $what . ' changed nothing, so nothing was synced.' );
		};

		$probe();
		$this->assertNotNull( IpPool::hold( $resource ) );
		$still( 'hold() of a resource that already holds an address' );

		IpPool::assign( $resource );
		$still( 'assign()' );

		$this->assertFalse( IpPool::release( 9999 ) );
		$still( 'release() of a resource without an address' );

		$this->assertFalse( IpPool::take( '192.0.2.10', 7777 ), 'The address is in use.' );
		$still( 'take() of an address that is not free' );

		$this->assertSame( 0, IpPool::add( 'not an address' ) );
		$still( 'add() of nothing' );
		$this->assertSame( 0, IpPool::add( '192.0.2.20' ) );
		$still( 'add() of an address already in the pool' );

		$this->assertFalse( IpPool::block( '192.0.2.99' ) );
		$still( 'block() of an unknown address' );
		$this->assertFalse( IpPool::unblock( '192.0.2.20' ) );
		$still( 'unblock() of an address that is not blocked' );

		$this->assertTrue( IpPool::release( $resource ) );
		$this->assertFalse( get_transient( IpPool::FREE_TRANSIENT ), 'A real change clears it.' );
	}

	public function test_a_plain_renewal_order_and_a_failed_renewal_order_can_be_paid_while_stored_out_of_stock(): void {
		$product = $this->sold_out_product();
		$user    = self::factory()->user->create();
		$sub     = $this->subscription( $user );
		$sub->add_product( $product );
		$sub->save();
		$p = wc_get_product( $product->get_id() );
		$this->assertFalse( $p->is_in_stock() );

		foreach ( array( 'pending', 'failed' ) as $status ) {
			$renewal = wcs_create_renewal_order( $sub );
			$renewal->set_status( $status );
			$renewal->save();

			$this->assertTrue( (bool) apply_filters( 'woocommerce_pay_order_product_in_stock', $p->is_in_stock(), $p, $renewal ), 'A ' . $status . ' renewal order can be paid.' );
		}
	}

	public function test_an_early_renewal_cart_and_its_session_reload_survive_a_stored_out_of_stock(): void {
		$product = $this->sold_out_product();
		$sub     = $this->holder( $product );

		// WooCommerce Subscriptions' early renewal is a renewal line with its own flag inside (wcs-early-renewal-functions.php:25).
		$data = $this->flow( 'subscription_renewal', $sub->get_id() );
		$data['subscription_renewal']['subscription_renewal_early'] = true;
		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $data );
		$this->assertNotFalse( $key, 'An early renewal is added: ' . wp_json_encode( wc_get_notices() ) );
		$this->assertTrue( WC()->cart->check_cart_item_stock() );

		$session = array();
		foreach ( WC()->cart->get_cart() as $k => $item ) {
			$session[ $k ] = array_diff_key( $item, array( 'data' => 1, 'data_hash' => 1 ) );
		}

		WC()->cart->cart_contents = array();
		WC()->session->set( 'cart', $session );
		WC()->cart->get_cart_from_session();
		$this->assertCount( 1, WC()->cart->get_cart(), 'Kept after the reload.' );
		$this->assertTrue( WC()->cart->check_cart_item_stock() );
		WC()->cart->empty_cart();
	}

	public function test_a_cart_reloaded_from_the_session_with_a_stored_out_of_stock_keeps_renewal_and_drops_new(): void {
		$product = $this->sold_out_product();
		$this->assertSame( 'outofstock', $this->stored( $product ) );

		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $this->flow( 'subscription_renewal', $this->holder( $product )->get_id() ) ) );
		$session = array();
		foreach ( WC()->cart->get_cart() as $k => $item ) {
			$session[ $k ] = array_diff_key( $item, array( 'data' => 1, 'data_hash' => 1 ) );
		}

		WC()->cart->cart_contents = array();
		WC()->session->set( 'cart', $session );
		WC()->cart->get_cart_from_session();
		$this->assertCount( 1, WC()->cart->get_cart() );
		WC()->cart->empty_cart();
	}

	public function test_medusa_availability_is_the_address_pool_only(): void {
		// Medusa answered availability from the pool and the node's real memory. Only the pool still moves it: memory
		// is oversold on purpose (owner decision 2026-11-11), so a full cluster is on sale.
		$product = $this->product( 2048 );

		$this->assertTrue( $this->in_stock( $product ) );
		$this->capacity( 1 );
		$this->assertTrue( $this->in_stock( $product ), 'Memory does not close a plan.' );
		$this->capacity( 8 );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertFalse( $this->in_stock( $product ), 'Addresses do.' );
	}

	public function test_the_first_run_after_the_policy_change_reopens_what_capacity_had_closed(): void {
		$closed = $this->product( 4096 );
		$hand   = $this->product( 4096 );
		$p      = wc_get_product( $closed->get_id() );
		$p->set_stock_status( 'outofstock' );
		$p->save();
		update_post_meta( $p->get_id(), Stock::META_CLOSED, 'yes' ); // What the old capacity rule wrote (a save of the status by anybody else would forget it).
		$h = wc_get_product( $hand->get_id() );
		$h->set_stock_status( 'outofstock' ); // The owner, by hand: no marker.
		$h->save();
		delete_option( Stock::MIGRATED );

		as_unschedule_all_actions( Stock::MIGRATE_JOB, array(), Jobs::GROUP );
		Stock::migrate();
		$this->assertTrue( as_has_scheduled_action( Stock::MIGRATE_JOB, array(), Jobs::GROUP ), 'A request only queues the job.' );
		$this->assertSame( 'outofstock', $this->stored( $closed ), 'Nothing is loaded or written by the request.' );
		Stock::run_migration();

		$this->assertSame( 'instock', $this->stored( $closed ), 'Closed by the old capacity rule: reopened.' );
		$this->assertSame( 'outofstock', $this->stored( $hand ), 'Closed by the owner: stays closed.' );
		$this->assertSame( '1', get_option( Stock::MIGRATED ) );

		$p = wc_get_product( $closed->get_id() );
		$p->set_stock_status( 'outofstock' );
		$p->save();
		update_post_meta( $p->get_id(), Stock::META_CLOSED, 'yes' );
		Stock::migrate();
		Stock::run_migration();
		$this->assertSame( 'outofstock', $this->stored( $closed ), 'Once only.' );
	}}
