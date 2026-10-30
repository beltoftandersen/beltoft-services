<?php
/**
 * A managed database needs a server of ours in the account, on the same cluster.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DbNeedsServer;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * Checkout, add to cart and Store API.
 */
class DbNeedsServerTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		remove_all_filters( 'beltoft_services_db_needs_server' );
		remove_all_filters( 'beltoft_services_db_needs_server_url' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function db_product( string $cluster = 'lab' ): \WC_Product {
		return $this->service_product( 'proxmox-db', array( 'cluster' => $cluster, 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'slug' => 'db-' . wp_generate_password( 5, false ) ) );
	}

	/**
	 * A server in the account, as stored (no job run).
	 *
	 * @param array<string, mixed> $meta Meta overrides.
	 */
	private function server( int $user, string $cluster, array $meta = array() ): Resource {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => $cluster, 'template' => 9000 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array_merge( array( 'state' => 'ready', 'desired' => 'ready' ), $meta ) );

		return $resource;
	}

	private function errors_for( \WC_Product $db ): \WP_Error {
		$this->assertNotFalse( WC()->cart->add_to_cart( $db->get_id() ) );
		$errors = new \WP_Error();
		DbNeedsServer::validate( array(), $errors );

		return $errors;
	}

	private function customer(): int {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		return $user;
	}

	public function test_a_customer_without_a_server_is_refused(): void {
		$this->customer();
		$errors = $this->errors_for( $this->db_product() );

		$this->assertSame( array( 'beltoft_services_db_needs_server' ), $errors->get_error_codes() );
		$this->assertStringContainsString( 'Order a server first', $errors->get_error_message() );
		$this->assertStringContainsString( '/vps-hosting/', $errors->get_error_message() );
		$this->assertStringNotContainsString( "\u{2014}", $errors->get_error_message() );
	}

	public function test_a_logged_out_visitor_gets_the_rule_at_checkout_and_is_refused_there(): void {
		$db = $this->db_product();
		// Added (a visitor has no session yet; a refusal here would lose its message on the way to checkout).
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $db->get_id(), 1 ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $db->get_id() ) );
		do_action( 'woocommerce_check_cart_items' );
		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$this->assertStringContainsString( 'Order a server first', wc_get_notices( 'notice' )[0]['notice'] );
		$errors = new \WP_Error();
		DbNeedsServer::validate( array(), $errors );
		$this->assertSame( array( 'beltoft_services_db_needs_server' ), $errors->get_error_codes(), 'The order is still refused.' );
	}

	public function test_a_customer_without_a_server_sees_the_error_on_the_checkout_page(): void {
		$this->customer();
		$db = $this->db_product();
		$this->assertNotFalse( WC()->cart->add_to_cart( $db->get_id() ) );
		do_action( 'woocommerce_check_cart_items' );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertStringContainsString( 'Order a server first', wc_get_notices( 'error' )[0]['notice'] );
	}

	public function test_a_server_on_another_cluster_is_refused(): void {
		$user = $this->customer();
		$this->server( $user, 'elsewhere' );

		$this->assertSame( array( 'beltoft_services_db_needs_server' ), $this->errors_for( $this->db_product() )->get_error_codes() );
	}

	public function test_a_linked_server_is_refused(): void {
		$user = $this->customer();
		$this->server( $user, 'lab', array( 'linked' => true ) );

		$this->assertSame( array( 'beltoft_services_db_needs_server' ), $this->errors_for( $this->db_product() )->get_error_codes() );
	}

	public function test_a_failed_or_deleted_server_is_refused(): void {
		$user = $this->customer();
		$this->server( $user, 'lab', array( 'state' => 'failed' ) );
		$this->server( $user, 'lab', array( 'state' => 'deleted' ) );

		$this->assertSame( array( 'beltoft_services_db_needs_server' ), $this->errors_for( $this->db_product() )->get_error_codes() );
	}

	public function test_another_customers_server_does_not_count(): void {
		$this->server( self::factory()->user->create(), 'lab' );
		$this->customer();

		$this->assertSame( array( 'beltoft_services_db_needs_server' ), $this->errors_for( $this->db_product() )->get_error_codes() );
	}

	public function test_a_ready_server_on_the_same_cluster_allows_it(): void {
		$user = $this->customer();
		$this->server( $user, 'lab' );
		$db = $this->db_product();

		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $db->get_id(), 1 ) );
		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$errors = new \WP_Error();
		DbNeedsServer::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_a_paused_server_on_the_same_cluster_allows_it(): void {
		$user = $this->customer();
		$this->server( $user, 'lab', array( 'state' => 'paused', 'desired' => 'paused' ) );

		$this->assertSame( array(), $this->errors_for( $this->db_product() )->get_error_codes() );
	}

	public function test_the_filter_turns_the_rule_off(): void {
		$this->customer();
		add_filter( 'beltoft_services_db_needs_server', '__return_false' );
		$db = $this->db_product();

		$this->assertNotFalse( WC()->cart->add_to_cart( $db->get_id() ) );
		$errors = new \WP_Error();
		DbNeedsServer::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_the_link_is_filterable(): void {
		$this->customer();
		add_filter( 'beltoft_services_db_needs_server_url', static fn() => 'https://example.com/servers/' );

		$this->assertStringContainsString( 'https://example.com/servers/', $this->errors_for( $this->db_product() )->get_error_message() );
	}

	public function test_other_products_are_not_affected(): void {
		$this->customer();
		$vm = $this->service_product( 'proxmox', array( 'cluster' => 'lab' ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );

		$this->assertNotFalse( WC()->cart->add_to_cart( $vm->get_id() ) );
		$errors = new \WP_Error();
		DbNeedsServer::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_store_api_path(): void {
		$user = $this->customer();
		$db   = $this->db_product();
		$this->assertNotFalse( WC()->cart->add_to_cart( $db->get_id() ) );

		$errors = new \WP_Error();
		do_action( 'woocommerce_store_api_cart_errors', $errors, WC()->cart );
		$this->assertSame( array( 'beltoft_services_db_needs_server' ), $errors->get_error_codes() );
		$this->assertStringNotContainsString( '<a', $errors->get_error_message(), 'The Store API gets plain text.' );

		$this->server( $user, 'lab' );
		$errors = new \WP_Error();
		do_action( 'woocommerce_store_api_cart_errors', $errors, WC()->cart );
		$this->assertSame( array(), $errors->get_error_codes() );
	}
}
