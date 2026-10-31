<?php
/**
 * The "Public IPv4 address" checkbox at checkout and its cart swap, and the plan list without the twins. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\DbPicker;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DbIpField;
use BeltoftServices\Proxmox\IpPool;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * Rendering rules, the swap and the cart line.
 */
class DbIpFieldTest extends BackupTestCase {

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
	 * A database product with plans Small and Big, each with and without the public IP.
	 *
	 * @param string $driver Driver id.
	 * @return array<string, \WC_Product_Variation> Keys such as Small-No, Small-Yes.
	 */
	private function db_variations( string $driver = 'proxmox-db' ): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Database ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$plan = new \WC_Product_Attribute();
		$plan->set_name( 'Plan' );
		$plan->set_options( array( 'Small', 'Big' ) );
		$plan->set_visible( true );
		$plan->set_variation( true );

		$ip = new \WC_Product_Attribute();
		$ip->set_name( 'Public IP' );
		$ip->set_options( array( 'No', 'Yes' ) );
		$ip->set_visible( true );
		$ip->set_variation( true );

		$product->set_attributes( array( $plan, $ip ) );
		$product->update_meta_data( Product::META_DRIVER, $driver );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'major' => '18', 'cores' => '1', 'memory_mb' => '2048', 'disk_gb' => '20' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 'Small' => 25, 'Big' => 45 ) as $name => $price ) {
			foreach ( array( 'No', 'Yes' ) as $with ) {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'plan' => $name, 'public-ip' => $with ) );
				$v->set_regular_price( (string) ( $price + ( 'Yes' === $with ? 5 : 0 ) ) );
				$v->set_status( 'publish' );
				$v->update_meta_data( Product::META_PLAN . 'public_ip', 'Yes' === $with ? '1' : '0' );
				$v->save();
				$out[ $name . '-' . $with ] = $v;
			}
		}

		return $out;
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
		DbIpField::render();

		return (string) ob_get_clean();
	}

	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', array( DbIpField::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'wc_ajax_' . DbIpField::AJAX, array( DbIpField::class, 'ajax' ) ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( DbIpField::class, 'assets' ) ) );
	}

	public function test_a_new_database_with_a_sibling_gets_the_box_with_the_price_difference(): void {
		$v = $this->db_variations();
		$this->add( $v['Small-No'] );

		$found = DbIpField::cart_database();
		$this->assertNotNull( $found );
		$this->assertFalse( $found['has'] );
		$this->assertSame( $v['Small-Yes']->get_id(), $found['with']->get_id() );
		$this->assertSame( $v['Small-No']->get_id(), $found['without']->get_id() );
		$this->assertTrue( DbIpField::cart_has_new_database() );

		$html = $this->html();
		$this->assertStringContainsString( 'id="beltoft_services_db_ip"', $html );
		$this->assertMatchesRegularExpression( '#Public IPv4 address \(\+[^)]*5\.00#', $html );
		$this->assertDoesNotMatchRegularExpression( '#id="beltoft_services_db_ip"[^>]*(checked|disabled)#', $html );
		$this->assertStringContainsString( 'Access stays closed until you add addresses to the allow-list in your dashboard.', $html );
		$this->assertStringNotContainsString( 'No public IPv4 address is free', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		WC()->cart->empty_cart();
		$this->add( $v['Small-Yes'] );

		$this->assertMatchesRegularExpression( '#id="beltoft_services_db_ip"[^>]*checked#', $this->html(), 'A cart with the address shows it ticked.' );
	}

	public function test_no_box_for_the_wrong_carts(): void {
		$this->assertFalse( DbIpField::cart_has_new_database() );
		$this->assertNull( DbIpField::cart_database() );
		$this->assertSame( '', $this->html() );

		$v = $this->db_variations();
		$this->add( $v['Small-No'] );
		$this->add( $v['Big-No'] );
		$this->assertNull( DbIpField::cart_database(), 'Two databases: no box.' );
		$this->assertFalse( DbIpField::cart_has_new_database() );

		WC()->cart->empty_cart();
		$this->add( $v['Small-No'], array( 'subscription_renewal' => array( 'subscription_id' => 1 ) ) );
		$this->assertNull( DbIpField::cart_database(), 'A renewal never gets the box.' );
		$this->assertFalse( DbIpField::cart_has_new_database() );
		$this->assertSame( '', $this->html() );
	}

	public function test_no_box_without_a_sibling_or_for_other_products(): void {
		$v = $this->db_variations();
		$v['Small-Yes']->set_status( 'private' );
		$v['Small-Yes']->save();
		wc_delete_product_transients( $v['Small-Yes']->get_parent_id() );
		$this->add( $v['Small-No'] );

		$this->assertNull( DbIpField::cart_database() );
		$this->assertTrue( DbIpField::cart_has_new_database(), 'Still a new database, with or without a twin.' );
		$this->assertSame( '', $this->html() );

		WC()->cart->empty_cart();
		$other = $this->db_variations( 'proxmox' );
		$this->add( $other['Small-No'] );
		$this->assertNull( DbIpField::cart_database() );
		$this->assertFalse( DbIpField::cart_has_new_database() );
	}

	public function test_the_box_is_disabled_and_the_swap_refused_when_no_address_is_free(): void {
		$v = $this->db_variations();
		$this->backup_wipe();
		$this->assertSame( 0, IpPool::available_for_db() );
		$this->add( $v['Small-No'] );

		$html = $this->html();
		$this->assertMatchesRegularExpression( '#id="beltoft_services_db_ip"[^>]*disabled#', $html );
		$this->assertStringContainsString( 'No public IPv4 address is free right now. You can add one later from your dashboard.', $html );

		$result = DbIpField::swap( true );
		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $result['with'] );
		$cart = WC()->cart->get_cart();
		$this->assertSame( $v['Small-No']->get_id(), (int) reset( $cart )['variation_id'], 'Nothing changed.' );
	}

	public function test_an_item_that_already_has_the_address_keeps_the_box_enabled_when_the_pool_runs_dry(): void {
		$v = $this->db_variations();
		$this->add( $v['Small-Yes'] );
		$this->backup_wipe();

		$this->assertDoesNotMatchRegularExpression( '#id="beltoft_services_db_ip"[^>]*disabled#', $this->html() );
		$this->assertTrue( DbIpField::swap( false )['ok'] );
	}

	public function test_the_swap_works_both_ways_and_keeps_quantity_and_item_data(): void {
		$v   = $this->db_variations();
		$key = $this->add( $v['Big-No'], array( 'beltoft_test' => 'kept' ) );

		$result = DbIpField::swap( true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $v['Big-Yes']->get_id(), $result['variation_id'] );

		$cart = WC()->cart->get_cart();
		$this->assertCount( 1, $cart );
		$item = reset( $cart );
		$this->assertSame( $v['Big-Yes']->get_id(), (int) $item['variation_id'] );
		$this->assertSame( 1, (int) $item['quantity'] );
		$this->assertSame( 'kept', $item['beltoft_test'] );
		$this->assertSame( 'Yes', $item['variation']['attribute_public-ip'] );
		$this->assertSame( 'Big', $item['variation']['attribute_plan'] );
		$this->assertNotSame( $key, array_key_first( $cart ), 'A different line.' );

		$this->assertTrue( DbIpField::swap( true )['ok'], 'Asking for the state it has changes nothing.' );
		$this->assertCount( 1, WC()->cart->get_cart() );

		$back = DbIpField::swap( false );
		$this->assertTrue( $back['ok'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$this->assertSame( $v['Big-No']->get_id(), (int) $item['variation_id'], 'The plan stays, only the address changes.' );
		$this->assertSame( 'kept', $item['beltoft_test'] );
		$this->assertCount( 1, $cart );
	}

	public function test_a_refused_add_puts_the_first_item_back(): void {
		$v = $this->db_variations();
		$this->add( $v['Small-No'] );
		$v['Small-Yes']->set_stock_status( 'outofstock' );
		$v['Small-Yes']->save();
		wc_delete_product_transients( $v['Small-Yes']->get_parent_id() );

		$this->assertMatchesRegularExpression( '#id="beltoft_services_db_ip"[^>]*disabled#', $this->html(), 'A twin that cannot be bought is shown disabled.' );
		$this->assertFalse( DbIpField::swap( true )['ok'] );
		$cart = WC()->cart->get_cart();
		$this->assertSame( $v['Small-No']->get_id(), (int) reset( $cart )['variation_id'] );
	}

	public function test_cart_item_data_names_the_public_ip_and_shows_the_surcharge(): void {
		$v = $this->db_variations();
		$this->add( $v['Small-Yes'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$data = DbIpField::item_data( array( array( 'key' => 'Plan', 'value' => 'Small' ), array( 'key' => 'Public IP', 'value' => 'Yes' ) ), $item );
		$this->assertCount( 2, $data, 'The raw attribute row is gone.' );
		$this->assertSame( 'Public IPv4 address', $data[1]['key'] );
		$this->assertStringStartsWith( '+', $data[1]['value'] );
		$this->assertStringContainsString( '5.00', $data[1]['value'] );

		WC()->cart->empty_cart();
		$this->add( $v['Small-No'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$data = DbIpField::item_data( array( array( 'key' => 'Public IP', 'value' => 'No' ) ), $item );
		$this->assertSame( array(), $data );
	}

	public function test_the_endpoint_refuses_a_bad_nonce(): void {
		$v = $this->db_variations();
		$this->add( $v['Small-No'] );

		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$_POST['with']     = '1';
		$this->assertSame( 403, DbIpField::respond()[1] );

		$_POST['nonce']    = wp_create_nonce( DbIpField::NONCE );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$answer            = DbIpField::respond();
		$this->assertSame( 200, $answer[1] );
		$this->assertTrue( $answer[0]['with'] );
	}

	public function test_the_new_database_page_lists_each_plan_once_and_a_plan_change_keeps_the_address(): void {
		$v     = $this->db_variations();
		$plans = DbPicker::plans();

		$this->assertArrayHasKey( $v['Small-No']->get_id(), $plans );
		$this->assertArrayHasKey( $v['Big-No']->get_id(), $plans );
		$this->assertArrayNotHasKey( $v['Small-Yes']->get_id(), $plans, 'The twin with the address is chosen at checkout.' );
		$this->assertArrayNotHasKey( $v['Big-Yes']->get_id(), $plans );

		// A customer's address is kept across a plan change: a database plan change touches nothing but the address
		// (DbDriver::update_plan), and the pair of a plan is found by its address field alone.
		$this->assertSame( $v['Big-Yes']->get_id(), \BeltoftServices\Proxmox\Backups::sibling_by( $v['Big-No'], 'public_ip', true )->get_id() );
		$this->assertNull( \BeltoftServices\Proxmox\Backups::sibling_by( $v['Big-Yes'], 'public_ip', true ) );
	}
}
