<?php
/**
 * The "Daily backups" checkbox at checkout and its cart swap. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\BackupField;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * Rendering rules and the swap.
 */
class BackupFieldTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_POST['nonce'], $_POST['with'], $_REQUEST['nonce'] );

		parent::tear_down();
	}

	/**
	 * Adds a variation to the cart.
	 *
	 * @param \WC_Product_Variation $v    Variation.
	 * @param array<string, mixed>  $data Cart item data.
	 */
	private function add( \WC_Product_Variation $v, array $data = array() ): string {
		$attrs = array();

		foreach ( $v->get_attributes() as $name => $value ) {
			$attrs[ 'attribute_' . $name ] = $value;
		}

		$key = WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), $attrs, $data );
		$this->assertNotFalse( $key, 'Added to the cart: ' . wp_json_encode( wc_get_notices() ) );

		return (string) $key;
	}

	private function html(): string {
		ob_start();
		BackupField::render();

		return (string) ob_get_clean();
	}

	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', array( BackupField::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'wc_ajax_' . BackupField::AJAX, array( BackupField::class, 'ajax' ) ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( BackupField::class, 'assets' ) ) );
	}

	public function test_a_new_server_with_a_sibling_gets_the_box_with_the_price_difference(): void {
		$v = $this->variable_product();
		$this->add( $v['a-None'] );

		$html = $this->html();

		$this->assertStringContainsString( 'id="beltoft_services_backups"', $html );
		$this->assertMatchesRegularExpression( '#Daily backups \(\+[^)]*2\.00#', $html, 'The difference, 12 - 10.' );
		$this->assertDoesNotMatchRegularExpression( '#id="beltoft_services_backups"[^>]*checked#', $html );
		$this->assertStringContainsString( 'kept for 7 days', $html );
		$this->assertStringNotContainsString( ' name=', substr( $html, 0, (int) strpos( $html, 'Daily backups' ) ), 'The box is never posted with the order.' );

		WC()->cart->empty_cart();
		$this->add( $v['a-Daily'] );

		$this->assertMatchesRegularExpression( '#id="beltoft_services_backups"[^>]*checked#', $this->html(), 'A cart with backups shows it ticked.' );
	}

	public function test_no_box_for_the_wrong_carts(): void {
		$v = $this->variable_product();

		$this->assertSame( '', $this->html(), 'An empty cart.' );

		// A renewal or switch pays for a server that exists.
		foreach ( array( 'subscription_renewal', 'subscription_switch' ) as $flow ) {
			WC()->cart->empty_cart();
			$key = $this->add( $v['a-None'] );
			WC()->cart->cart_contents[ $key ][ $flow ] = array( 'subscription_id' => 1 );
			$this->assertSame( '', $this->html(), $flow );
			$this->assertNull( BackupField::cart_server(), $flow );
		}

		// A cluster without a backup storage cannot deliver backups.
		WC()->cart->empty_cart();
		$this->add( $v['a-None'] );
		$this->save_cluster( array( 'backup_storage' => '' ) );
		$this->assertSame( '', $this->html() );
		$this->save_cluster();
		$this->assertNotSame( '', $this->html() );

		// Two servers on one order: the box would be ambiguous.
		$this->add( $v['b-None'] );
		$this->assertSame( '', $this->html() );
	}

	public function test_no_box_without_a_sibling_or_for_other_products(): void {
		$plain = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ), array( 'slug' => 'plain-vm' ) );
		WC()->cart->add_to_cart( $plain->get_id() );
		$this->assertSame( '', $this->html(), 'A simple product has no variation to swap.' );

		WC()->cart->empty_cart();
		$other = new \WC_Product_Simple();
		$other->set_name( 'Mug' );
		$other->set_regular_price( '5' );
		$other->set_status( 'publish' );
		$other->save();
		WC()->cart->add_to_cart( $other->get_id() );
		$this->assertSame( '', $this->html() );
	}

	public function test_the_swap_works_both_ways_and_keeps_quantity_and_item_data(): void {
		$v   = $this->variable_product();
		$key = $this->add( $v['a-None'], array( 'beltoft_test' => 'kept' ) );

		$result = BackupField::swap( true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $v['a-Daily']->get_id(), $result['variation_id'] );

		$cart = WC()->cart->get_cart();
		$this->assertCount( 1, $cart );
		$item = reset( $cart );
		$this->assertSame( $v['a-Daily']->get_id(), (int) $item['variation_id'] );
		$this->assertSame( 1, (int) $item['quantity'] );
		$this->assertSame( 'kept', $item['beltoft_test'] );
		$this->assertSame( 'Daily', $item['variation']['attribute_backups'] );
		$this->assertSame( 'a', $item['variation']['attribute_tier'] );
		$this->assertNotSame( $key, array_key_first( $cart ), 'A different line.' );

		$back = BackupField::swap( false );
		$this->assertTrue( $back['ok'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$this->assertSame( $v['a-None']->get_id(), (int) $item['variation_id'] );
		$this->assertSame( 'kept', $item['beltoft_test'] );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_cart_item_data_names_daily_backups_and_shows_the_surcharge(): void {
		$v = $this->variable_product();
		$this->add( $v['a-Daily'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$data = BackupField::item_data( array( array( 'key' => 'Tier', 'value' => 'a' ), array( 'key' => 'Backups', 'value' => 'Daily' ) ), $item );
		$this->assertCount( 2, $data );
		$this->assertSame( 'Daily backups', $data[1]['key'] );
		$this->assertStringStartsWith( '+', $data[1]['value'] );
		$this->assertStringContainsString( '2.00', $data[1]['value'] );

		WC()->cart->empty_cart();
		$this->add( $v['a-None'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$data = BackupField::item_data( array( array( 'key' => 'Backups', 'value' => 'None' ) ), $item );
		$this->assertSame( array(), $data );
	}

	public function test_asking_for_the_state_the_cart_already_has_changes_nothing(): void {
		$v   = $this->variable_product();
		$key = $this->add( $v['a-None'] );

		$result = BackupField::swap( false );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( $key ), array_keys( WC()->cart->get_cart() ) );
	}

	public function test_a_refused_add_puts_the_first_item_back(): void {
		$v = $this->variable_product();
		$this->add( $v['a-None'] );
		$v['a-Daily']->set_stock_status( 'outofstock' );
		$v['a-Daily']->save();
		wc_delete_product_transients( $v['a-Daily']->get_parent_id() );

		$result = BackupField::swap( true );

		$this->assertFalse( $result['ok'] );
		$cart = WC()->cart->get_cart();
		$this->assertCount( 1, $cart );
		$this->assertSame( $v['a-None']->get_id(), (int) reset( $cart )['variation_id'], 'The customer keeps what they had.' );
		wc_clear_notices();
	}

	public function test_the_endpoint_refuses_a_bad_nonce_and_a_cart_without_a_server(): void {
		$v = $this->variable_product();
		$this->add( $v['a-None'] );

		$_POST['nonce']    = 'wrong';
		$_REQUEST['nonce'] = 'wrong';
		$_POST['with']     = '1';
		$answer            = BackupField::respond();

		$this->assertSame( 403, $answer[1] );
		$this->assertFalse( $answer[0]['ok'] );
		$cart = WC()->cart->get_cart();
		$this->assertSame( $v['a-None']->get_id(), (int) reset( $cart )['variation_id'], 'Nothing changed.' );

		$_POST['nonce']    = wp_create_nonce( BackupField::NONCE );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$answer            = BackupField::respond();

		$this->assertSame( 200, $answer[1] );
		$this->assertTrue( $answer[0]['ok'] );
		$cart = WC()->cart->get_cart();
		$this->assertSame( $v['a-Daily']->get_id(), (int) reset( $cart )['variation_id'] );

		WC()->cart->empty_cart();
		$answer = BackupField::respond();
		$this->assertSame( 409, $answer[1] );
		$this->assertFalse( $answer[0]['ok'] );
	}
}
