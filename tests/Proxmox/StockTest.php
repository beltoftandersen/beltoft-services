<?php
/**
 * Stock of virtual machine products. Only the fake client and the capacity cache are used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\Placement;
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
		$this->assertFalse( $this->in_stock( $product ), 'No cache fails closed; Proxmox is not asked.' );
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
