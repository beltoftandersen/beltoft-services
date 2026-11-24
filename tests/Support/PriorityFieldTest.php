<?php
/**
 * The "Priority support" box of the checkout: offered with any new service, also to a visitor, never to a customer who has it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Support\Priority;
use BeltoftServices\Support\PriorityDriver;
use BeltoftServices\Support\PriorityField;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Proxmox/Backup/BackupTestCase.php';

class PriorityFieldTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function product(): \WC_Product {
		$product = new \WC_Product_Subscription();
		$product->set_name( 'Priority support' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '49' );
		$product->update_meta_data( '_subscription_price', '49' );
		$product->update_meta_data( '_subscription_period', 'month' );
		$product->update_meta_data( '_subscription_period_interval', '1' );
		$product->update_meta_data( Product::META_DRIVER, PriorityDriver::ID );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'prio-' . wp_generate_password( 5, false ) );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	private function server_in_cart(): void {
		$server = $this->variable_product();
		$attrs  = array();

		foreach ( $server['a-None']->get_attributes() as $name => $value ) {
			$attrs[ 'attribute_' . $name ] = $value;
		}

		$this->assertNotFalse( WC()->cart->add_to_cart( $server['a-None']->get_parent_id(), 1, $server['a-None']->get_id(), $attrs ), wp_json_encode( wc_get_notices() ) );
	}

	private function lines( int $product_id ): int {
		return count( array_filter( WC()->cart->get_cart(), static fn( $item ) => (int) $item['product_id'] === $product_id ) );
	}

	private function html(): string {
		ob_start();
		PriorityField::render();

		return (string) ob_get_clean();
	}

	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', array( PriorityField::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'wc_ajax_' . PriorityField::AJAX, array( PriorityField::class, 'ajax' ) ) );
	}

	public function test_a_visitor_can_add_it_with_a_new_service(): void {
		wp_set_current_user( 0 );
		$product = $this->product();
		$this->assertSame( '', $this->html(), 'An empty cart gets no box.' );

		$this->server_in_cart();
		$html = $this->html();
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'Priority support', $html );
		$this->assertStringNotContainsString( '24/7', $html );

		$this->assertTrue( Priority::validate( true, $product->get_id(), 1 ), 'A visitor may add it: the account is made at checkout.' );
		$this->assertTrue( PriorityField::choose( true )['ok'], wp_json_encode( wc_get_notices() ) );
		$this->assertTrue( PriorityField::choose( true )['ok'] );
		$this->assertSame( 1, $this->lines( $product->get_id() ) );
		$this->assertStringContainsString( 'checked', $this->html() );

		$this->assertTrue( PriorityField::choose( false )['ok'] );
		$this->assertSame( 0, $this->lines( $product->get_id() ) );
	}

	public function test_a_customer_who_has_it_gets_no_box_and_cannot_buy_a_second(): void {
		$product = $this->product();
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r       = Resource::create( $license, Registry::get( PriorityDriver::ID ), Product::plan( $product->get_id() ), array() );
		Jobs::run( $r->id() );
		wp_set_current_user( (int) $license['user_id'] );
		$this->server_in_cart();

		$this->assertSame( '', $this->html() );
		$this->assertFalse( PriorityField::choose( true )['ok'] );

		$errors = new \WP_Error();
		WC()->cart->add_to_cart( $product->get_id() );
		Priority::checkout_validation( array(), $errors );
		$this->assertTrue( $errors->has_errors() || 0 === $this->lines( $product->get_id() ) );
	}
}
