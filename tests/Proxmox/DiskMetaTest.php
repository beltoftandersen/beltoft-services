<?php
/** Order metadata linking for extra disks. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Lifecycle;
use BeltoftServices\Proxmox\DiskMeta;
use BeltoftServices\Product;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class DiskMetaTest extends BackupTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// WordPress' test rollback reuses post IDs; WooCommerce's custom order-item tables persist between tests.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_items' );
		// phpcs:enable
	}

	private function subscription_for( \WC_Order $order, \WC_Product $product, int $user_id, string $uid ): int {
		$subscription = wcs_create_subscription( array( 'order_id' => $order->get_id(), 'customer_id' => $user_id, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );
		$item = $subscription->get_item( $subscription->add_product( $product, 1 ) );
		$item->add_meta_data( '_beltoft_services_disk_uid', $uid, true );
		$item->save();
		$subscription->save();
		return $subscription->get_id();
	}

	public function test_paid_disk_order_item_links_to_the_same_customers_server(): void {
		$server = $this->ready_vm();
		$server_license = $server->license();
		$disk_product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$order = wc_create_order( array( 'customer_id' => (int) $server_license['user_id'] ) );
		$item_id = $order->add_product( $disk_product, 1 );
		$item = $order->get_item( $item_id );
		$uid = wp_generate_uuid4();
		$item->add_meta_data( '_beltoft_services_disk_server', (int) $server_license['id'], true );
		$item->add_meta_data( '_beltoft_services_disk_uid', $uid, true );
		$item->save();
		$order->save();
		$subscription_id = $this->subscription_for( $order, $disk_product, (int) $server_license['user_id'], $uid );
		$this->assertSame( $uid, (string) $item->get_meta( '_beltoft_services_disk_uid', true ) );
		$this->assertSame( $uid, (string) array_values( wcs_get_subscription( $subscription_id )->get_items() )[0]->get_meta( '_beltoft_services_disk_uid', true ) );
		$license = $this->service_license( $disk_product, array( 'fire' => false, 'user_id' => $server_license['user_id'], 'order_id' => $order->get_id(), 'subscription_id' => $subscription_id ) );
		$meta = Lifecycle::initial_meta( $license, Registry::get( 'proxmox-disk' ) );
		$this->assertArrayHasKey( 'server_license', $meta );
		$this->assertSame( (int) $server_license['id'], $meta['server_license'] );
		$this->assertSame( (int) $item_id, $meta['order_item_id'] );
	}

	public function test_disk_bought_with_a_new_server_resolves_its_order_link(): void {
		$server_product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'disk_gb' => 30 ), array( 'slug' => 'server-' . wp_generate_password( 6, false ) ) );
		$disk_product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$user = self::factory()->user->create();
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item_id = $order->add_product( $disk_product, 1 );
		$item = $order->get_item( $item_id );
		$uid = wp_generate_uuid4();
		$item->add_meta_data( '_beltoft_services_disk_server', 'cart_server', true );
		$item->add_meta_data( '_beltoft_services_disk_uid', $uid, true );
		$item->save();
		$order->save();
		$subscription_id = $this->subscription_for( $order, $disk_product, (int) $user, $uid );
		$disk = $this->service_license( $disk_product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id(), 'subscription_id' => $subscription_id ) );
		$meta = Lifecycle::initial_meta( $disk, Registry::get( 'proxmox-disk' ) );
		$this->assertSame( $order->get_id(), $meta['server_order_id'] );
		$server = $this->service_license( $server_product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$this->assertSame( (int) $server['id'], DiskMeta::server_in_order( $order->get_id(), $user ) );
	}

	public function test_identical_disk_order_lines_link_through_their_own_subscriptions(): void {
		$server = $this->ready_vm();
		$server_license = $server->license();
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$order = wc_create_order( array( 'customer_id' => (int) $server_license['user_id'] ) );
		$resources = array();
		foreach ( array( wp_generate_uuid4(), wp_generate_uuid4() ) as $uid ) {
			$order_item = $order->get_item( $order->add_product( $product, 1 ) );
			$order_item->add_meta_data( '_beltoft_services_disk_uid', $uid, true );
			$order_item->add_meta_data( '_beltoft_services_disk_server', (int) $server_license['id'], true );
			$order_item->save();
			$subscription = wcs_create_subscription( array( 'order_id' => $order->get_id(), 'customer_id' => (int) $server_license['user_id'], 'billing_period' => 'month', 'billing_interval' => 1 ) );
			$this->assertInstanceOf( \WC_Subscription::class, $subscription );
			$subscription_item = $subscription->get_item( $subscription->add_product( $product, 1 ) );
			$subscription_item->add_meta_data( '_beltoft_services_disk_uid', $uid, true );
			$subscription_item->save();
			$subscription->save();
			$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'], 'order_id' => $order->get_id(), 'subscription_id' => $subscription->get_id() ) );
			$meta = Lifecycle::initial_meta( $license, Registry::get( 'proxmox-disk' ) );
			$this->assertSame( (int) $order_item->get_id(), $meta['order_item_id'] );
			$this->assertSame( (int) $server_license['id'], $meta['server_license'] );
			$resource = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), $meta );
			$this->assertInstanceOf( Resource::class, $resource );
			$resources[] = $resource;
		}
		$this->assertNotSame( $resources[0]->id(), $resources[1]->id() );
		$this->assertNotSame( $resources[0]->license()['subscription_id'], $resources[1]->license()['subscription_id'] );
	}
}
