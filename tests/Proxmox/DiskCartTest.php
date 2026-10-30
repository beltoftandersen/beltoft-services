<?php
/** Cart identity and ownership for extra disks. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\DiskCart;
use BeltoftServices\Proxmox\DiskField;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Proxmox\BackupField;
use BeltoftServices\Product;

require_once __DIR__ . '/BackupTestCase.php';

class DiskCartTest extends BackupTestCase {
	public function set_up(): void {
		parent::set_up();
		WC()->cart->empty_cart();
		wc_clear_notices();
		DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_REQUEST['beltoft_services_disk_for'], $_REQUEST['beltoft_services_disk_nonce'] );
		parent::tear_down();
	}

	private function variation(): \WC_Product_Variation {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'Extra disk ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( '50 GB' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-disk' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'disk-' . wp_generate_password( 5, false ) );
		$product->save();
		$variation = new \WC_Product_Subscription_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => '50 GB' ) );
		$variation->set_regular_price( '5' );
		$variation->update_meta_data( '_subscription_price', '5' );
		$variation->update_meta_data( '_subscription_period', 'month' );
		$variation->update_meta_data( '_subscription_period_interval', '1' );
		$variation->update_meta_data( '_subscription_length', '0' );
		$variation->set_status( 'publish' );
		$variation->update_meta_data( Product::META_PLAN . 'disk_gb', '50' );
		$variation->save();
		\WC_Product_Variable::sync( $product->get_id() );
		return wc_get_product( $variation->get_id() );
	}

	public function test_the_server_card_offers_disk_sizes_as_tiles_with_their_price(): void {
		$server = $this->ready_vm();
		wp_set_current_user( (int) $server->license()['user_id'] );
		$this->variation();
		$add = \BeltoftServices\Proxmox\DiskPanels::server( $server )['add'];
		$this->assertNotEmpty( $add, 'The 50 GB size fits and is offered.' );
		ob_start();
		\BeltoftServices\Proxmox\Panels::account( \BeltoftServices\Resource::find( $server->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-disk-add', $html );
		$this->assertSame( count( $add ), substr_count( $html, 'class="beltoft-services-add__tile"' ) );
		$this->assertStringContainsString( '<span class="beltoft-services-add__name">50 GB</span>', $html );
		$this->assertStringContainsString( 'a month', $add[0]['price'] );
		$this->assertStringContainsString( 'excluding VAT', $html );
	}

	public function test_existing_server_two_same_size_disks_keep_distinct_cart_lines(): void {
		$server = $this->ready_vm();
		$license = $server->license();
		wp_set_current_user( (int) $license['user_id'] );
		$variation = $this->variation();
		$_REQUEST['beltoft_services_disk_for'] = (string) $license['id'];
		$_REQUEST['beltoft_services_disk_nonce'] = wp_create_nonce( 'beltoft-services-disk:' . $license['id'] );
		$attrs = array( 'attribute_size' => '50 GB' );
		$one = WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation->get_id(), $attrs );
		$two = WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation->get_id(), $attrs );
		$this->assertNotFalse( $one, wp_json_encode( wc_get_notices() ) );
		$this->assertNotFalse( $two, wp_json_encode( wc_get_notices() ) );
		$this->assertNotSame( $one, $two );
		$this->assertCount( 2, WC()->cart->get_cart() );
		foreach ( WC()->cart->get_cart() as $item ) {
			$this->assertSame( 1, $item['quantity'] );
			$this->assertSame( (int) $license['id'], $item['beltoft_services_disk_for'] );
			$this->assertStringContainsString( '_disk_', DiskCart::recurring_key( 'base', $item ) );
		}
		WC()->cart->calculate_totals();
		$this->assertCount( 2, WC()->cart->recurring_carts, 'WooCommerce Subscriptions must create a separate renewal cart for each identical disk.' );
	}

	public function test_direct_add_without_a_server_is_refused(): void {
		$variation = $this->variation();
		$this->assertFalse( WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation->get_id(), array( 'attribute_size' => '50 GB' ) ) );
	}

	public function test_checkout_rechecks_a_new_servers_memory_and_disk_capacity(): void {
		$server = $this->variable_product();
		$variation = $this->variation();
		$this->assertNotFalse( WC()->cart->add_to_cart( $server['a-None']->get_parent_id(), 1, $server['a-None']->get_id(), array( 'attribute_tier' => 'a', 'attribute_backups' => 'None' ) ) );
		$this->assertTrue( DiskField::choose( $variation->get_id() ) );
		$errors = new \WP_Error();
		DiskCart::checkout_validation( array(), $errors );
		$this->assertFalse( $errors->has_errors(), $errors->get_error_message() );
		$this->settings( array( 'disk_promise_percent' => 1, 'disk_reserve_gb' => 0 ) );
		$errors = new \WP_Error();
		DiskCart::checkout_validation( array(), $errors );
		$this->assertSame( 'disk_capacity', $errors->get_error_code() );
	}

	public function test_checkout_rejects_two_disks_with_one_new_server(): void {
		$server = $this->variable_product();
		$variation = $this->variation();
		$this->assertNotFalse( WC()->cart->add_to_cart( $server['a-None']->get_parent_id(), 1, $server['a-None']->get_id(), array( 'attribute_tier' => 'a', 'attribute_backups' => 'None' ) ) );
		$this->assertTrue( DiskField::choose( $variation->get_id() ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation->get_id(), array( 'attribute_size' => '50 GB' ), array( 'beltoft_services_disk_for' => 'cart_server' ) ) );
		$errors = new \WP_Error();
		DiskCart::checkout_validation( array(), $errors );
		$this->assertSame( 'disk_limit', $errors->get_error_code() );
	}

	public function test_checkout_field_adds_and_removes_a_disk_for_a_new_server(): void {
		$server = $this->variable_product();
		$variation = $this->variation();
		$this->assertNotFalse( WC()->cart->add_to_cart( $server['a-None']->get_parent_id(), 1, $server['a-None']->get_id(), array( 'attribute_tier' => 'a', 'attribute_backups' => 'None' ) ) );
		$this->assertNotNull( DiskField::cart_server() );
		$this->assertArrayHasKey( $variation->get_id(), DiskField::sizes() );
		$this->assertTrue( DiskField::choose( $variation->get_id() ) );
		$this->assertCount( 2, WC()->cart->get_cart() );
		$this->assertTrue( DiskField::choose( $variation->get_id() ) );
		$this->assertCount( 2, WC()->cart->get_cart(), 'Changing the checkout disk leaves exactly one disk line.' );
		$this->assertTrue( DiskField::choose( 0 ) );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_backup_changes_keep_the_selected_disk_but_server_removal_cleans_it(): void {
		$server = $this->variable_product();
		$disk = $this->variation();
		$this->assertNotFalse( WC()->cart->add_to_cart( $server['a-None']->get_parent_id(), 1, $server['a-None']->get_id(), array( 'attribute_tier' => 'a', 'attribute_backups' => 'None' ) ) );
		$this->assertTrue( DiskField::choose( $disk->get_id() ) );

		foreach ( array( true, false ) as $with_backups ) {
			$this->assertTrue( BackupField::swap( $with_backups )['ok'] );
			$this->assertCount( 2, WC()->cart->get_cart() );
			$items = WC()->cart->get_cart();
			$first = reset( $items );
			$this->assertSame( $server['a-None']->get_parent_id(), (int) $first['product_id'], 'The server stays above its disk.' );
			$disks = array_filter( WC()->cart->get_cart(), static fn( $item ) => 'cart_server' === ( $item['beltoft_services_disk_for'] ?? null ) );
			$this->assertCount( 1, $disks );
			$this->assertSame( $disk->get_id(), (int) reset( $disks )['variation_id'] );
		}

		$server_key = BackupField::cart_server()['key'];
		WC()->cart->remove_cart_item( $server_key );
		$this->assertCount( 0, WC()->cart->get_cart(), 'A real server removal still cleans its disk.' );
	}

	public function test_a_disk_line_quantity_goes_back_to_one(): void {
		WC()->cart->set_cart_contents( array( 'disk' => array( 'beltoft_services_disk_uid' => 'u1', 'quantity' => 5 ), 'other' => array( 'quantity' => 2 ) ) );
		\BeltoftServices\Proxmox\DiskCart::one_each( WC()->cart );
		$this->assertSame( 1, WC()->cart->cart_contents['disk']['quantity'] );
		$this->assertSame( 2, WC()->cart->cart_contents['other']['quantity'] );
		WC()->cart->empty_cart();
	}
}
