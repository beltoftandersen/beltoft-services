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

	public function test_stock_does_not_block_renewal_switch_or_resubscribe(): void {
		$product = $this->product();
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$this->assertFalse( $this->in_stock( $product ), 'Sold out for a new customer.' );

		// Cart items carry WooCommerce Subscriptions' own keys (Checkout reads the same ones).
		foreach ( array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe' ) as $key ) {
			WC()->cart->empty_cart();
			WC()->cart->cart_contents['k'] = array(
				'key'          => 'k',
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'quantity'     => 1,
				$key           => array( 'subscription_id' => 1 ),
			);

			$this->assertTrue( $this->in_stock( $product ), $key . ' is not blocked.' );

			WC()->cart->cart_contents['k'] = array(
				'key'          => 'k',
				'product_id'   => $product->get_id() + 1000,
				'variation_id' => 0,
				'quantity'     => 1,
				$key           => array(),
			);
			$this->assertFalse( $this->in_stock( $product ), 'A renewal of another product lets this one through no more.' );
		}

		WC()->cart->empty_cart();

		// The switch link (request argument the switcher reads before the item is in the cart) and the resubscribe link.
		$_REQUEST['switch-subscription'] = '5';
		$this->assertTrue( $this->in_stock( $product ), 'The switch landing page is not blocked.' );
		unset( $_REQUEST['switch-subscription'] );
		$this->assertFalse( $this->in_stock( $product ) );

		$_GET['resubscribe'] = '5';
		$this->assertTrue( $this->in_stock( $product ) );
		unset( $_GET['resubscribe'] );
		$this->assertFalse( $this->in_stock( $product ) );

		// A plain item of the same product is still blocked.
		WC()->cart->cart_contents['p'] = array( 'key' => 'p', 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1 );
		$this->assertFalse( $this->in_stock( $product ) );
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
