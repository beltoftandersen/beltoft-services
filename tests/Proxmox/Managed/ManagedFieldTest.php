<?php
/**
 * The "Managed server" box of the checkout: bought together with the new server of the cart, also by a visitor, and linked
 * to that server once the order made it. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Managed;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\AddonCart;
use BeltoftServices\Proxmox\Managed\ManagedCart;
use BeltoftServices\Proxmox\Managed\ManagedDriver;
use BeltoftServices\Proxmox\Managed\ManagedField;
use BeltoftServices\Proxmox\Managed\ManagedMeta;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Backup/BackupTestCase.php';

class ManagedFieldTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();
		WC()->cart->empty_cart();
		wc_clear_notices();
		AddonCart::reset_products();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		AddonCart::reset_products();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** The managed server product: one monthly subscription of 299. */
	private function product(): \WC_Product {
		$product = new \WC_Product_Subscription();
		$product->set_name( 'Managed server' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '299' );
		$product->update_meta_data( '_subscription_price', '299' );
		$product->update_meta_data( '_subscription_period', 'month' );
		$product->update_meta_data( '_subscription_period_interval', '1' );
		$product->update_meta_data( Product::META_DRIVER, ManagedDriver::ID );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'managed-' . wp_generate_password( 5, false ) );
		$product->save();
		AddonCart::reset_products();

		return wc_get_product( $product->get_id() );
	}

	private function server_in_cart(): string {
		$server = $this->variable_product();
		$attrs  = array();

		foreach ( $server['a-None']->get_attributes() as $name => $value ) {
			$attrs[ 'attribute_' . $name ] = $value;
		}

		$key = WC()->cart->add_to_cart( $server['a-None']->get_parent_id(), 1, $server['a-None']->get_id(), $attrs );
		$this->assertNotFalse( $key, wp_json_encode( wc_get_notices() ) );

		return (string) $key;
	}

	private function lines(): int {
		return count( array_filter( WC()->cart->get_cart(), static fn( $item ) => 'cart_server' === ( $item['beltoft_services_managed_for'] ?? null ) ) );
	}

	private function html(): string {
		ob_start();
		ManagedField::render();

		return (string) ob_get_clean();
	}

	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', array( ManagedField::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'wc_ajax_' . ManagedField::AJAX, array( ManagedField::class, 'ajax' ) ) );
	}

	public function test_no_box_without_a_new_server_or_without_the_product(): void {
		$this->server_in_cart();
		$this->assertSame( '', $this->html(), 'No product sold: no box.' );

		WC()->cart->empty_cart();
		$this->product();
		$this->assertSame( '', $this->html(), 'No server in the cart: no box.' );
	}

	public function test_a_visitor_ticks_the_box_and_one_line_is_bought_with_the_new_server(): void {
		wp_set_current_user( 0 );
		$this->product();
		$this->server_in_cart();

		$html = $this->html();
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'Managed server', $html );
		$this->assertStringContainsString( '299', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		$this->assertTrue( ManagedField::choose( true )['ok'], wp_json_encode( wc_get_notices() ) );
		$this->assertTrue( ManagedField::choose( true )['ok'] );
		$this->assertSame( 1, $this->lines(), 'Ticked twice is still one line.' );

		$errors = new \WP_Error();
		ManagedCart::checkout_validation( array(), $errors );
		$this->assertFalse( $errors->has_errors(), wp_json_encode( $errors->get_error_messages() ) );

		$this->assertTrue( ManagedField::choose( false )['ok'] );
		$this->assertSame( 0, $this->lines() );
	}

	public function test_removing_the_server_takes_the_line_with_it(): void {
		$this->product();
		$key = $this->server_in_cart();
		ManagedField::choose( true );
		$this->assertSame( 1, $this->lines() );

		WC()->cart->remove_cart_item( $key );

		$this->assertSame( 0, $this->lines() );
	}

	public function test_a_line_bought_with_the_server_finds_its_server_in_the_order(): void {
		$server  = $this->ready_vm();
		$license = $server->license();
		$order   = wc_create_order( array( 'customer_id' => (int) $license['user_id'] ) );
		\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'order_id' => $order->get_id() ) );

		$product = $this->service_product( ManagedDriver::ID, array(), array( 'slug' => 'managed-' . wp_generate_password( 6, false ) ) );
		$mlic    = $this->service_license( $product, array( 'fire' => false, 'user_id' => (int) $license['user_id'] ) );
		$managed = Resource::create( $mlic, Registry::get( ManagedDriver::ID ), Product::plan( $product->get_id() ), array( 'server_order_id' => $order->get_id() ) );

		$this->assertSame( $server->id(), ManagedMeta::server_of( $managed )->id() );
		$this->assertSame( (int) $license['id'], (int) Resource::find( $managed->id() )->get( 'server_license' ), 'The link is kept, so the server page finds it.' );
		$this->assertCount( 1, ManagedMeta::for_server( Resource::find( $server->id() ) ) );
	}
}
