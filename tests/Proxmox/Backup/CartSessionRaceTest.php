<?php
/**
 * The extra disk of a new server against a parallel request that writes the WooCommerce session last (the checkout refresh that
 * was already running when the disk was chosen).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Backup;

use BeltoftServices\Proxmox\Backup\BackupField;
use BeltoftServices\Proxmox\Backup\CartSwap;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Tests\Mcp\ServerDiskFixture;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__, 2 ) . '/Usage/UsageTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Mcp/ServerDiskFixture.php';

/**
 * Lost update of the session cart.
 */
final class CartSessionRaceTest extends UsageTestCase {

	use ServerDiskFixture;

	private string $customer = 't_diskrace0000001';

	private ?\WC_Session_Handler $saved_session = null;

	/** @var array<string, \WC_Product_Variation> */
	private $v = array();

	/** @var array<string, \WC_Product_Variation> */
	private $disk = array();

	public function set_up(): void {
		parent::set_up();
		$this->saved_session = WC()->session instanceof \WC_Session_Handler ? WC()->session : null;
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		$this->settings( array( 'usage_new_limit' => 0, 'disk_max_extra' => 3, 'disk_reserve_gb' => 0 ) );
		$this->v = $this->usage_product();
		$this->capacity( 4000 );
		$this->disk = $this->disk_product();
		update_option( 'woocommerce_currency', 'EUR' );
		Offers::reset();
		wp_set_current_user( $this->new_user() );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $this->customer ) );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $this->customer ) );
		wp_cache_flush();

		if ( $this->saved_session ) {
			WC()->session = $this->saved_session;
		}

		WC()->cart->set_cart_contents( array() );
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	/** A new request: a session handler that reads the stored session of the customer now, and a cart loaded from it. */
	private function request(): \WC_Session_Handler {
		wp_cache_flush();
		$session = new \WC_Session_Handler();
		$props   = array( '_customer_id' => $this->customer, '_has_cookie' => true, '_session_expiration' => time() + DAY_IN_SECONDS, '_session_expiring' => time() + DAY_IN_SECONDS - HOUR_IN_SECONDS );

		foreach ( $props as $name => $value ) {
			$ref = new \ReflectionProperty( \WC_Session_Handler::class, $name );
			$ref->setAccessible( true );
			$ref->setValue( $session, $value );
		}

		$data = new \ReflectionProperty( \WC_Session_Handler::class, '_data' );
		$data->setAccessible( true );
		$data->setValue( $session, $session->get_session_data() );
		WC()->session = $session;
		WC()->cart->set_cart_contents( array() );
		$prop = new \ReflectionProperty( \WC_Cart::class, 'session' );
		$prop->setAccessible( true );
		$prop->getValue( WC()->cart )->get_cart_from_session();

		return $session;
	}

	/** The variation ids of the stored cart, read as a request that starts now. */
	private function stored(): array {
		$this->request();

		return array_values( array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() ) );
	}

	/** The server is in the stored cart; request B (the checkout refresh) has read it; request A chooses the disk; then B writes last. */
	private function race(): void {
		$session = $this->request();
		$server  = $this->v['u-None'];
		$this->assertNotFalse( WC()->cart->add_to_cart( $server->get_parent_id(), 1, $server->get_id(), BackupField::attributes( $server ) ) );
		WC()->cart->calculate_totals();
		$session->save_data();

		$b      = $this->request();
		$b_cart = WC()->cart->get_cart();

		$this->request();
		$this->assertTrue( CartSwap::apply( null, $this->disk['u-50']->get_id() )['ok'] );
		$this->assertContains( $this->disk['u-50']->get_id(), $this->stored(), 'The choice is stored.' );

		WC()->session = $b;
		WC()->cart->set_cart_contents( $b_cart );
		WC()->cart->calculate_totals();
		$b->set( 'customer', array( 'billing_city' => 'Aarhus' ) );
		\BeltoftServices\Domains\PendingLines::guard_session();
		$b->save_data();
	}

	public function test_a_parallel_request_that_saves_last_does_not_take_the_chosen_disk_out_of_the_cart(): void {
		$this->race();

		$this->assertContains( $this->disk['u-50']->get_id(), $this->stored(), 'The disk is still in the cart after the parallel request wrote last.' );
		$this->assertContains( $this->v['u-None']->get_id(), $this->stored() );
	}

	public function test_a_disk_taken_out_on_the_page_does_not_come_back_from_the_stale_request(): void {
		$session = $this->request();
		$server  = $this->v['u-None'];
		$this->assertNotFalse( WC()->cart->add_to_cart( $server->get_parent_id(), 1, $server->get_id(), BackupField::attributes( $server ) ) );
		WC()->cart->calculate_totals();
		$session->save_data();
		$b      = $this->request();
		$b_cart = WC()->cart->get_cart();
		$this->request();
		$this->assertTrue( CartSwap::apply( null, $this->disk['u-50']->get_id() )['ok'] );
		$this->request();
		$this->assertTrue( CartSwap::apply( null, 0 )['ok'] );
		$this->assertNotContains( $this->disk['u-50']->get_id(), $this->stored() );

		WC()->session = $b;
		WC()->cart->set_cart_contents( $b_cart );
		WC()->cart->calculate_totals();
		$b->set( 'customer', array( 'billing_city' => 'Aarhus' ) );
		\BeltoftServices\Domains\PendingLines::guard_session();
		$b->save_data();

		$this->assertNotContains( $this->disk['u-50']->get_id(), $this->stored() );
	}
}
