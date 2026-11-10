<?php
/** The order link of a new add-on licence: a licence without a user is refused for a snapshot pack only. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox\Addon;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonLink;
use BeltoftServices\Tests\TestCase;

class AddonLinkTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// WooCommerce's custom order-item tables persist between tests.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_items' );
		// phpcs:enable
	}

	/**
	 * The meta of a licence with no user, whose order line says it is bought with a new server.
	 *
	 * @param string $kind Add-on kind.
	 * @return array
	 */
	private function meta_without_user( string $kind ): array {
		$a = null;

		foreach ( Addon::all() as $candidate ) {
			if ( $candidate->kind === $kind ) {
				$a = $candidate;
			}
		}

		$product = $this->service_product( $a->driver_id, array(), array( 'slug' => $kind . '-' . wp_generate_password( 6, false ) ) );
		$order   = wc_create_order( array( 'customer_id' => 0 ) );
		$item_id = $order->add_product( $product, 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( $a->item_server(), 'cart_server', true );
		$item->add_meta_data( $a->item_uid(), wp_generate_uuid4(), true );
		$item->save();
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => 0, 'order_id' => $order->get_id() ) );

		$meta = AddonLink::initial_meta( $a, array(), $license, Registry::get( $a->driver_id ) );

		return array( $meta, (int) $item_id, $order->get_id() );
	}

	public function test_a_disk_without_a_user_still_gets_its_order_link(): void {
		list( $meta, $item_id, $order_id ) = $this->meta_without_user( 'disk' );

		$this->assertSame( $item_id, $meta['order_item_id'] ?? null );
		$this->assertSame( $order_id, $meta['server_order_id'] ?? null );
	}

	public function test_an_app_licence_without_a_user_still_gets_its_order_link(): void {
		list( $meta, $item_id, $order_id ) = $this->meta_without_user( 'applic' );

		$this->assertSame( $item_id, $meta['order_item_id'] ?? null );
		$this->assertSame( $order_id, $meta['server_order_id'] ?? null );
	}

	public function test_a_snapshot_pack_without_a_user_gets_no_order_link(): void {
		list( $meta ) = $this->meta_without_user( 'snapshot' );

		$this->assertSame( array(), $meta );
	}
}
