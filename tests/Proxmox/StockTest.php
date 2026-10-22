<?php
/**
 * Stock of virtual machine products. Only the fake client and the capacity cache are used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Capacity;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\Placement;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\Stock;
use BeltoftServices\Tests\TestCase;

/**
 * A VM product is sold out without a free address or without room on a node; renewals, switches
 * and resubscribes are never blocked.
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

	private function in_stock( \WC_Product $p ): bool {
		$p = wc_get_product( $p->get_id() );

		return $p->is_in_stock() && $p->is_purchasable();
	}

	public function test_out_of_stock_without_free_ip(): void {
		$product = $this->product();
		$this->assertTrue( $this->in_stock( $product ), 'Free address and room: in stock.' );

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertFalse( $this->in_stock( $product ) );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock() );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_purchasable() );

		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertTrue( $this->in_stock( $product ), 'The cached count is cleared by the change.' );
	}

	public function test_out_of_stock_without_capacity(): void {
		$product = $this->product( 4096 );

		$this->capacity( 3 );
		$this->assertFalse( $this->in_stock( $product ), '3 GB free cannot hold a 4 GB plan.' );

		$this->capacity( 4 );
		$this->assertTrue( $this->in_stock( $product ), 'Exactly enough counts.' );

		$this->capacity( null );
		$this->assertTrue( $this->in_stock( $product ), 'No cache is not "no room": the stored status stands; Proxmox is not asked.' );
		$this->assertSame( array(), $this->http_calls() );

		// One node with room is enough; an offline node does not count.
		$rows         = array(
			array( 'node' => 'node-a', 'status' => 'offline', 'maxmem' => 64 * self::GB, 'mem' => 0 ),
			array( 'node' => 'node-b', 'status' => 'online', 'maxmem' => 64 * self::GB, 'mem' => 62 * self::GB ),
		);
		set_transient( Placement::CAPACITY_PREFIX . 'lab', $rows, HOUR_IN_SECONDS );
		$this->assertFalse( $this->in_stock( $product ) );

		$rows[1]['mem'] = 10 * self::GB;
		set_transient( Placement::CAPACITY_PREFIX . 'lab', $rows, HOUR_IN_SECONDS );
		$this->assertTrue( $this->in_stock( $product ) );
	}

	public function test_variation_plan_memory_decides(): void {
		$parent = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 2048 ), array( 'memory_mb' => 16384 ) ) ) );
		$this->capacity( 8 );
		$ids = $parent->get_children();

		$this->assertTrue( $this->in_stock( wc_get_product( $ids[0] ) ) );
		$this->assertFalse( $this->in_stock( wc_get_product( $ids[1] ) ), 'The large variation does not fit.' );
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

	public function test_a_product_already_out_of_stock_stays_so(): void {
		$this->assertFalse( \BeltoftServices\Proxmox\Stock::filter( false, $this->product() ) );
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

	private function sold_out_product(): \WC_Product {
		$product = $this->product();
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertFalse( $this->in_stock( $product ), 'Sold out for a new customer.' );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'The status is stored, so every flow below must beat WooCommerce\'s own stored-status checks.' );

		return $product;
	}

	public function test_stock_does_not_block_renewal_switch_or_resubscribe(): void {
		$product = $this->sold_out_product();

		// A new VM added while sold out is refused by WooCommerce itself.
		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $product->get_id() ), 'A new server is refused.' );
		$this->assertSame( array(), WC()->cart->get_cart() );
		wc_clear_notices();

		// WCS adds each of its flows through WC()->cart->add_to_cart() with its key in the item data
		// (class-wcs-cart-renewal.php:364-370); is_purchasable() runs before the item is in the cart.
		$subscription = $this->subscription( self::factory()->user->create() );

		foreach ( array( 'subscription_renewal', 'subscription_resubscribe', 'subscription_switch' ) as $key ) {
			WC()->cart->empty_cart();
			$added = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( $key => array( 'subscription_id' => $subscription->get_id(), 'item_id' => 0, 'next_payment_timestamp' => 0, 'upgraded_or_downgraded' => '' ) ) );
			$this->assertNotFalse( $added, $key . ' is added while sold out: ' . wp_json_encode( wc_get_notices() ) );
			// WCS's own cart checks may drop an item whose data is not a full switch; the add itself is what matters here.
			$this->assertArrayHasKey( $added, WC()->cart->cart_contents, $key . ' in cart' );

			// The flag is closed again: a plain add to an empty cart is refused.
			WC()->cart->empty_cart();
			$this->assertFalse( WC()->cart->add_to_cart( $product->get_id() ), 'A plain add after a ' . $key . ' add is refused.' );
			wc_clear_notices();
		}
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
		$sub     = $this->subscription( self::factory()->user->create() );

		// WooCommerce throws after the item-data filter (here: something else makes the item not purchasable).
		$abort = static fn() => false;
		add_filter( 'woocommerce_is_purchasable', $abort, 999 );
		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'subscription_renewal' => array( 'subscription_id' => $sub->get_id() ) ) ) );
		remove_filter( 'woocommerce_is_purchasable', $abort, 999 );
		wc_clear_notices();

		// Between the abort and the next add the exemption must not stay open: a validation of the
		// next add (WooCommerce's form handler and REST/store routes run it first) closes it.
		apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 );
		$this->assertFalse( $this->in_stock( $product ), 'Closed by the next add\'s validation.' );

		// And so does the end of the request, for an abort nothing else followed.
		add_filter( 'woocommerce_is_purchasable', $abort, 999 );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'subscription_renewal' => array( 'subscription_id' => $sub->get_id() ) ) );
		remove_filter( 'woocommerce_is_purchasable', $abort, 999 );
		wc_clear_notices();
		$this->assertTrue( has_action( 'shutdown', array( Stock::class, 'clear_flow' ) ) !== false );
		Stock::clear_flow();
		$this->assertFalse( $this->in_stock( $product ), 'Closed at the end of the request.' );
	}

	public function test_a_session_reload_keeps_a_renewal_item(): void {
		$product = $this->sold_out_product();

		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'subscription_renewal' => array( 'subscription_id' => $this->subscription( self::factory()->user->create() )->get_id() ) ) ) );

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
		$this->assertCount( 0, WC()->cart->get_cart(), 'A new server is dropped from the reloaded cart.' );
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

	public function test_request_args_only_count_for_a_subscription_the_user_owns(): void {
		$product = $this->sold_out_product();
		$mine    = self::factory()->user->create();
		$theirs  = self::factory()->user->create();
		$own     = $this->subscription( $mine );
		$foreign = $this->subscription( $theirs );

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

		foreach ( array( 'switch-subscription', 'resubscribe' ) as $arg ) {
			wp_set_current_user( $mine );
			$set( $arg, (string) $own->get_id() );
			$this->assertTrue( $this->in_stock( $product ), $arg . ' with an own subscription.' );

			$set( $arg, (string) $foreign->get_id() );
			$this->assertFalse( $this->in_stock( $product ), $arg . ' with somebody else\'s subscription.' );

			$set( $arg, '1' );
			$this->assertFalse( $this->in_stock( $product ), 'A forged ' . $arg . '=1 stays refused.' );

			$set( $arg, '999999' );
			$this->assertFalse( $this->in_stock( $product ), $arg . ' naming nothing.' );

			wp_set_current_user( 0 );
			$set( $arg, (string) $own->get_id() );
			$this->assertFalse( $this->in_stock( $product ), $arg . ' without a login.' );

			$set( $arg, null );
		}
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

		$this->capacity( 8 );
		Stock::sync( 'lab' );

		$this->assertSame( array( 'v' . $ids[1] => 'outofstock' ), $seen, 'Only the variation that does not fit changes; the parent keeps "instock" (another variation is in stock).' );
		$this->assertSame( 'instock', $this->stored( $simple ) );
		$this->assertSame( 'instock', $this->stored( $ids[0] ) );
		$this->assertSame( 'outofstock', $this->stored( $ids[1] ) );
		$this->assertSame( 'instock', $this->stored( $parent ) );
	}

	public function test_a_variable_parent_follows_its_variations(): void {
		$parent = $this->product( 1024, array( 'variations' => array( array( 'memory_mb' => 2048 ), array( 'memory_mb' => 16384 ) ) ) );
		$ids    = $parent->get_children();

		$this->capacity( 1 );
		Stock::sync( 'lab' );

		$this->assertSame( 'outofstock', $this->stored( $ids[0] ) );
		$this->assertSame( 'outofstock', $this->stored( $ids[1] ) );
		$this->assertSame( 'outofstock', $this->stored( $parent ), 'WC_Product_Variable::sync() closed the parent: every variation is out.' );

		$this->capacity( 8 );
		Stock::sync( 'lab' );

		$this->assertSame( 'instock', $this->stored( $ids[0] ) );
		$this->assertSame( 'outofstock', $this->stored( $ids[1] ) );
		$this->assertSame( 'instock', $this->stored( $parent ), 'And reopened when one variation is back.' );

		$this->capacity( 32 );
		Stock::sync( 'lab' );
		$this->assertSame( 'instock', $this->stored( $ids[1] ) );
	}

	public function test_a_capacity_write_by_the_placement_syncs_the_status(): void {
		$product = $this->product( 4096 );
		$this->capacity( 4 );
		Stock::sync( 'lab' );
		$this->assertSame( 'instock', $this->stored( $product ) );

		// A placement commits 2 GB on each node's cache row it names; here the two nodes are filled in two steps.
		$this->assertTrue( Capacity::place( 'lab', 'node-a', 2 * self::GB, static fn() => true ) );
		$this->assertSame( 'instock', $this->stored( $product ), 'node-b still has room.' );
		$this->assertTrue( Capacity::place( 'lab', 'node-b', 2 * self::GB, static fn() => true ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'Nothing fits any more.' );

		// A failed record commits nothing and syncs nothing.
		$this->capacity( 8 );
		Stock::sync( 'lab' );
		$this->assertFalse( Capacity::place( 'lab', 'node-a', 8 * self::GB, static fn() => false ) );
		$this->assertSame( 'instock', $this->stored( $product ) );
	}

	public function test_other_clusters_and_other_products_are_not_synced_by_a_cluster_write(): void {
		$product = $this->product();
		$this->capacity( 1 );

		Stock::sync( 'elsewhere' );
		$this->assertSame( 'instock', $this->stored( $product ), 'A capacity write of another cluster does not touch this plan.' );

		$other = $this->service_product( 'fake' );
		Stock::sync();
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
		$this->capacity( 1 );
		Stock::sync();
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

	public function test_no_capacity_cache_does_not_close_and_queues_a_run(): void {
		$product = $this->product();
		$this->capacity( null );
		as_unschedule_all_actions( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP );

		$this->assertTrue( $this->in_stock( $product ), 'The stored status (instock) stands.' );
		$this->assertTrue( as_has_scheduled_action( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP ), 'A capacity run for that cluster is queued.' );

		$this->in_stock( $product );
		$this->in_stock( $product );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => ProxmoxJobs::CAPACITY_NOW, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ), 'One waiting run, however many page views.' );

		// The sync writes nothing without a cache.
		Stock::sync( 'lab' );
		$this->assertSame( 'instock', $this->stored( $product ) );

		// A stored outofstock stays too: the guard only ever closes.
		$closed = $this->product();
		$this->capacity( 1 );
		Stock::sync( 'lab' );
		$this->assertSame( 'outofstock', $this->stored( $closed ) );
		$this->capacity( null );
		$this->assertFalse( $this->in_stock( $closed ) );
		$this->assertSame( 'outofstock', $this->stored( $closed ) );
	}

	public function test_the_queued_run_is_throttled_and_a_cluster_that_is_not_saved_is_closed(): void {
		$product = $this->product();
		$this->capacity( null );
		as_unschedule_all_actions( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP );

		$this->in_stock( $product );
		as_unschedule_all_actions( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP );
		$this->in_stock( $product );
		$this->assertFalse( as_has_scheduled_action( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP ), 'A run was queued a moment ago: a cluster that cannot be read is not asked on every page view.' );

		// A plan naming a cluster that does not exist has no cache to wait for: it stays closed.
		$ghost = $this->service_product( 'proxmox', array( 'cluster' => 'ghost', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ) );
		$this->assertFalse( $this->in_stock( $ghost ) );
	}

	public function test_a_product_closed_for_lack_of_addresses_waits_for_the_capacity_run_when_the_cache_is_gone(): void {
		$product = $this->product();
		$this->capacity( null );
		as_unschedule_all_actions( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP );

		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'No address needs no cache to be known.' );

		$this->assertTrue( IpPool::unblock( '192.0.2.10' ) );
		$this->assertSame( 'outofstock', $this->stored( $product ), 'An address is back, but without a cache the room cannot be told: nothing is written.' );
		$this->assertTrue( as_has_scheduled_action( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP ), 'The capacity run that will tell is queued.' );

		// The run writes the cache and, with it, the status.
		$this->capacity( 8 );
		Stock::sync( 'lab' );
		$this->assertSame( 'instock', $this->stored( $product ) );
	}

	public function test_stored_outofstock_does_not_block_a_renewal_cart_check(): void {
		$product = $this->sold_out_product();
		$sub     = $this->subscription( self::factory()->user->create() );

		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'subscription_renewal' => array( 'subscription_id' => $sub->get_id(), 'item_id' => 0, 'next_payment_timestamp' => 0, 'upgraded_or_downgraded' => '' ) ) );
		$this->assertNotFalse( $key );

		// WC_Cart::check_cart_item_stock() (class-wc-cart.php:897) reads is_in_stock() of the item's product.
		$result = WC()->cart->check_cart_item_stock();
		$this->assertTrue( true === $result || ! is_wp_error( $result ), 'The renewal item passes the cart stock check while stored out of stock.' );

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
		$this->capacity( 8 );
		Stock::sync( 'lab' );
		$this->assertSame( 'outofstock', $this->stored( $ids[1] ) );

		$sub = $this->subscription( self::factory()->user->create() );
		WC()->cart->empty_cart();
		$this->assertFalse( WC()->cart->add_to_cart( $parent->get_id(), 1, $ids[1], array( 'attribute_tier' => '1' ) ), 'A new server for the full variation is refused.' );
		wc_clear_notices();

		$key = WC()->cart->add_to_cart( $parent->get_id(), 1, $ids[1], array( 'attribute_tier' => '1' ), array( 'subscription_switch' => array( 'subscription_id' => $sub->get_id(), 'item_id' => 0, 'next_payment_timestamp' => 0, 'upgraded_or_downgraded' => '' ) ) );
		$this->assertNotFalse( $key, 'A switch to it is not: ' . wp_json_encode( wc_get_notices() ) );
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function test_a_stored_outofstock_the_owner_set_still_lets_an_existing_customer_through(): void {
		$product = $this->product();
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$mine = self::factory()->user->create();
		$own  = $this->subscription( $mine );

		wp_set_current_user( $mine );
		$_GET['resubscribe'] = (string) $own->get_id();
		$this->assertTrue( $this->in_stock( $product ), 'Renewals, switches and resubscribes are never blocked by the stored status.' );
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

	public function test_medusa_availability_is_real(): void {
		// Medusa answered availability from the pool and the node's real memory; here both are read
		// from this site's own records: the pool count and the committed-memory cache. Both must move it.
		$product = $this->product( 2048 );

		$this->assertTrue( $this->in_stock( $product ) );
		$this->capacity( 1 );
		$this->assertFalse( $this->in_stock( $product ), 'Memory, not only addresses.' );
		$this->capacity( 8 );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertFalse( $this->in_stock( $product ), 'Addresses, not only memory.' );
	}
}
