<?php
/** The cart hooks the three add-on kinds share. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox\Addon;

use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonCart;

class AddonCartTest extends \WP_UnitTestCase {
	public function tear_down(): void {
		unset( $_REQUEST['beltoft_services_snapshot_for'], $_REQUEST['beltoft_services_snapshot_nonce'], $_REQUEST['beltoft_services_disk_for'], $_REQUEST['beltoft_services_disk_nonce'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_the_recurring_key_appends_the_kinds_suffix_and_the_uid_hash(): void {
		foreach ( array( 'disk' => 'k_disk_', 'snapshot' => 'k_snapshots_', 'applic' => 'k_applic_' ) as $kind => $golden ) {
			$a = $this->kind( $kind );
			$this->assertSame( $golden . md5( 'u1' ), apply_filters( 'woocommerce_subscriptions_recurring_cart_key', 'k', array( $a->cart_uid() => 'u1' ) ) );
		}
		$this->assertSame( 'k', apply_filters( 'woocommerce_subscriptions_recurring_cart_key', 'k', array( 'other' => 'u1' ) ) );
	}

	public function test_quantities_go_back_to_one_for_an_add_on_line_only(): void {
		foreach ( Addon::all() as $a ) {
			WC()->cart->set_cart_contents( array( 'a' => array( $a->cart_uid() => 'u1', 'quantity' => 5, 'data' => array() ), 'other' => array( 'quantity' => 2, 'data' => array() ) ) );
			do_action( 'woocommerce_before_calculate_totals', WC()->cart );
			$this->assertSame( 1, WC()->cart->cart_contents['a']['quantity'], $a->kind );
			$this->assertSame( 2, WC()->cart->cart_contents['other']['quantity'], $a->kind );
			$this->assertSame( '1', apply_filters( 'woocommerce_cart_item_quantity', '<input>', 'a', array( $a->cart_uid() => 'u1' ) ) );
			$this->assertSame( '<input>', apply_filters( 'woocommerce_cart_item_quantity', '<input>', 'o', array() ) );
		}
		WC()->cart->empty_cart();
	}

	public function test_the_order_line_carries_uid_and_server_meta_under_the_kinds_keys(): void {
		foreach ( Addon::all() as $a ) {
			$item = new \WC_Order_Item_Product();
			do_action( 'woocommerce_checkout_create_order_line_item', $item, 'k', array( $a->cart_uid() => 'u9', $a->cart_for() => 42, 'data' => array() ), new \WC_Order() );
			$this->assertSame( 'u9', $item->get_meta( $a->item_uid() ), $a->kind );
			$this->assertEquals( 42, $item->get_meta( $a->item_server() ), $a->kind );

			$bare = new \WC_Order_Item_Product();
			do_action( 'woocommerce_checkout_create_order_line_item', $bare, 'k', array( $a->cart_uid() => 'u9', 'data' => array() ), new \WC_Order() );
			$this->assertSame( '', $bare->get_meta( $a->item_uid() ), $a->kind );
		}
	}

	public function test_a_link_needs_the_kinds_nonce_and_the_owner_check(): void {
		$a = $this->kind( 'snapshot' );
		wp_set_current_user( self::factory()->user->create() );
		$yes = static fn( int $id ): bool => true;
		$no  = static fn( int $id ): bool => false;

		$this->assertNull( AddonCart::link( $a, array( $a->cart_for() => 7 ), $yes ), 'No nonce.' );
		$data = array( $a->cart_for() => 7, 'beltoft_services_snapshot_nonce' => wp_create_nonce( 'beltoft-services-snapshots:7' ) );
		$this->assertSame( 7, AddonCart::link( $a, $data, $yes ) );
		$this->assertNull( AddonCart::link( $a, $data, $no ), 'Not the caller\'s own.' );
		$this->assertNull( AddonCart::link( $a, array( $a->cart_for() => 8 ) + $data, $yes ), 'The nonce is for another id.' );
		$this->assertNull( AddonCart::link( $a, array( $a->cart_for() => 'cart_server' ), $yes ), 'A snapshot pack never rides with a new server.' );

		$_REQUEST['beltoft_services_snapshot_for']   = '7';
		$_REQUEST['beltoft_services_snapshot_nonce'] = wp_create_nonce( 'beltoft-services-snapshots:7' );
		$this->assertSame( 7, AddonCart::link( $a, array(), $yes ), 'The request carries both.' );
	}

	private function kind( string $kind ): Addon {
		foreach ( Addon::all() as $a ) {
			if ( $a->kind === $kind ) {
				return $a;
			}
		}
		$this->fail( $kind );
	}
}
