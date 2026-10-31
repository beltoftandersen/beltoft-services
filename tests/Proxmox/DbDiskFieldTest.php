<?php
/**
 * The "Extra storage" select at checkout, its cart swap and its checkout check. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\DbDiskField;
use BeltoftServices\Proxmox\DbIpField;
use BeltoftServices\Proxmox\DiskCapacity;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * Rendering rules, the swap, the cart line and the checkout check.
 */
class DbDiskFieldTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_POST['nonce'], $_POST['extra'], $_REQUEST['nonce'] );

		parent::tear_down();
	}

	/**
	 * A database product with extra storage None, 50, 100 and 250 GB, each with and without the public IP.
	 *
	 * @param string $driver Driver id.
	 * @return array<string, \WC_Product_Variation> Keys such as e0-0, e50-1.
	 */
	private function db_variations( string $driver = 'proxmox-db' ): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Database ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$storage = new \WC_Product_Attribute();
		$storage->set_name( 'Extra storage' );
		$storage->set_options( array( 'None', '50 GB', '100 GB', '250 GB' ) );
		$storage->set_visible( true );
		$storage->set_variation( true );

		$ip = new \WC_Product_Attribute();
		$ip->set_name( 'Public IP' );
		$ip->set_options( array( 'No', 'Yes' ) );
		$ip->set_visible( true );
		$ip->set_variation( true );

		$product->set_attributes( array( $storage, $ip ) );
		$product->update_meta_data( Product::META_DRIVER, $driver );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'major' => '18', 'cores' => '1', 'memory_mb' => '2048', 'disk_gb' => '20' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 0 => array( 'None', 12 ), 50 => array( '50 GB', 16 ), 100 => array( '100 GB', 20 ), 250 => array( '250 GB', 30 ) ) as $extra => $set ) {
			foreach ( array( 0, 1 ) as $with ) {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'extra-storage' => $set[0], 'public-ip' => $with ? 'Yes' : 'No' ) );
				$v->set_regular_price( (string) ( $set[1] + ( $with ? 5 : 0 ) ) );
				$v->set_status( 'publish' );
				$v->update_meta_data( Product::META_PLAN . 'public_ip', (string) $with );
				$v->update_meta_data( Product::META_PLAN . 'extra_disk_gb', (string) $extra );
				$v->save();
				$out[ 'e' . $extra . '-' . $with ] = $v;
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
		DbDiskField::render();

		return (string) ob_get_clean();
	}

	/**
	 * The storage reading that lets the larger steps be sold.
	 */
	private function room(): void {
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		// What the capacity job does next: the stored stock status follows the readings.
		\BeltoftServices\Proxmox\Stock::sync( 'lab' );
	}

	private function in_cart(): int {
		$cart = WC()->cart->get_cart();

		return (int) reset( $cart )['variation_id'];
	}

	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', array( DbDiskField::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'wc_ajax_' . DbDiskField::AJAX, array( DbDiskField::class, 'ajax' ) ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( DbDiskField::class, 'assets' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_checkout_validation', array( DbDiskField::class, 'validate' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_item_data', array( DbDiskField::class, 'item_data' ) ) );
	}

	public function test_a_new_database_with_a_ladder_gets_the_select_with_the_price_differences(): void {
		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e0-0'] );

		$found = DbDiskField::cart_database();
		$this->assertNotNull( $found );
		$this->assertSame( 0, $found['extra'] );
		$this->assertSame( array( 0, 50, 100, 250 ), array_keys( $found['ladder'] ) );

		$html = $this->html();
		$this->assertStringContainsString( 'id="beltoft_services_db_disk"', $html );
		$this->assertStringContainsString( 'data-nonce="', $html );
		$this->assertMatchesRegularExpression( '#<option value="0"[^>]*selected[^>]*>No extra storage</option>#', $html );
		$this->assertMatchesRegularExpression( '#<option value="50"[^>]*>\+50 GB \(\+[^)]*4\.00#', $html );
		$this->assertMatchesRegularExpression( '#<option value="100"[^>]*>\+100 GB \(\+[^)]*8\.00#', $html );
		$this->assertMatchesRegularExpression( '#<option value="250"[^>]*>\+250 GB \(\+[^)]*18\.00#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<option value="(50|100|250)"[^>]*disabled#', $html );
		$this->assertStringContainsString( 'only be made larger', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_the_select_shows_the_current_step_and_the_prices_from_none_when_the_cart_has_the_public_ip(): void {
		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e100-1'] );

		$html = $this->html();
		$this->assertMatchesRegularExpression( '#<option value="100"[^>]*selected#', $html );
		$this->assertMatchesRegularExpression( '#<option value="50"[^>]*>\+50 GB \(\+[^)]*4\.00#', $html, 'The public IP twins differ by the same amount.' );
	}

	public function test_a_step_that_does_not_fit_is_disabled_and_the_chosen_one_never_is(): void {
		$v = $this->db_variations();
		$this->add( $v['e0-0'] );

		$html = $this->html();
		$this->assertMatchesRegularExpression( '#<option value="50"[^>]*disabled#', $html, 'No reading of the storage: not sold.' );
		$this->assertDoesNotMatchRegularExpression( '#<option value="0"[^>]*disabled#', $html );
		$this->assertStringContainsString( 'Some sizes are not available right now.', $html );

		$this->room();
		$this->assertDoesNotMatchRegularExpression( '#<option value="\d+"[^>]*disabled#', $this->html() );

		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$this->assertMatchesRegularExpression( '#<option value="250"[^>]*disabled#', $this->html() );

		WC()->cart->empty_cart();
		$this->settings( array( 'disk_reserve_gb' => 0 ) );
		$this->room();
		$this->add( $v['e50-0'] );
		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$this->assertDoesNotMatchRegularExpression( '#<option value="50"[^>]*disabled#', $this->html(), 'The step already in the cart is not disabled.' );
	}

	public function test_no_select_for_the_wrong_carts(): void {
		$this->assertNull( DbDiskField::cart_database() );
		$this->assertSame( '', $this->html() );

		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e0-0'] );
		$this->add( $v['e50-0'] );
		$this->assertNull( DbDiskField::cart_database(), 'Two databases: no select.' );

		foreach ( array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe' ) as $flag ) {
			WC()->cart->empty_cart();
			$this->add( $v['e0-0'], array( $flag => array( 'subscription_id' => 1 ) ) );
			$this->assertNull( DbDiskField::cart_database(), $flag . ' never gets the select.' );
			$this->assertSame( '', $this->html() );
		}

		WC()->cart->empty_cart();
		$other = $this->db_variations( 'proxmox' );
		$this->add( $other['e0-0'] );
		$this->assertNull( DbDiskField::cart_database(), 'Not a database product.' );
	}

	public function test_no_select_without_a_ladder(): void {
		$v = $this->db_variations();

		foreach ( array( 'e50-0', 'e100-0', 'e250-0' ) as $key ) {
			$v[ $key ]->set_status( 'private' );
			$v[ $key ]->save();
		}

		wc_delete_product_transients( $v['e0-0']->get_parent_id() );
		$this->add( $v['e0-0'] );

		$this->assertNull( DbDiskField::cart_database() );
		$this->assertSame( '', $this->html() );
		$this->assertTrue( DbIpField::cart_has_new_database() );
	}

	public function test_the_swap_keeps_the_public_ip_quantity_and_item_data(): void {
		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e0-1'], array( 'beltoft_test' => 'kept' ) );

		$result = DbDiskField::swap( 100 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $v['e100-1']->get_id(), $result['variation_id'] );

		$cart = WC()->cart->get_cart();
		$this->assertCount( 1, $cart );
		$item = reset( $cart );
		$this->assertSame( $v['e100-1']->get_id(), (int) $item['variation_id'] );
		$this->assertSame( 'Yes', $item['variation']['attribute_public-ip'], 'The public IP choice is kept.' );
		$this->assertSame( '100 GB', $item['variation']['attribute_extra-storage'] );
		$this->assertSame( 1, (int) $item['quantity'] );
		$this->assertSame( 'kept', $item['beltoft_test'] );

		$this->assertTrue( DbDiskField::swap( 100 )['ok'], 'Asking for the step it has changes nothing.' );
		$this->assertCount( 1, WC()->cart->get_cart() );

		$back = DbDiskField::swap( 0 );
		$this->assertTrue( $back['ok'] );
		$this->assertSame( $v['e0-1']->get_id(), $this->in_cart() );

		// Without the address the step stays without it.
		WC()->cart->empty_cart();
		$this->add( $v['e50-0'] );
		$this->assertTrue( DbDiskField::swap( 250 )['ok'] );
		$this->assertSame( $v['e250-0']->get_id(), $this->in_cart() );
	}

	public function test_the_swap_refuses_a_size_that_is_not_on_the_ladder_or_does_not_fit(): void {
		$v = $this->db_variations();
		$this->add( $v['e0-0'] );

		$this->assertFalse( DbDiskField::swap( 50 )['ok'], 'No reading of the storage: not sold.' );
		$this->assertSame( $v['e0-0']->get_id(), $this->in_cart(), 'Nothing changed.' );

		$this->room();
		$this->assertFalse( DbDiskField::swap( 75 )['ok'], 'Not a step of the ladder.' );
		$this->assertFalse( DbDiskField::swap( 999999 )['ok'] );
		$this->assertSame( $v['e0-0']->get_id(), $this->in_cart() );

		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$this->assertFalse( DbDiskField::swap( 250 )['ok'] );
		$this->assertSame( $v['e0-0']->get_id(), $this->in_cart() );
	}

	public function test_a_step_that_cannot_be_bought_puts_the_first_item_back(): void {
		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e0-0'] );
		$v['e100-0']->set_stock_status( 'outofstock' );
		$v['e100-0']->save();
		wc_delete_product_transients( $v['e100-0']->get_parent_id() );

		$this->assertMatchesRegularExpression( '#<option value="100"[^>]*disabled#', $this->html() );
		$this->assertFalse( DbDiskField::swap( 100 )['ok'] );
		$this->assertSame( $v['e0-0']->get_id(), $this->in_cart() );
	}

	public function test_cart_item_data_names_the_extra_storage_and_hides_the_raw_row(): void {
		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e50-0'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );

		$data = DbDiskField::item_data( array( array( 'key' => 'Extra storage', 'value' => '50 GB' ), array( 'key' => 'Public IP', 'value' => 'No' ) ), $item );
		$this->assertCount( 2, $data );
		$this->assertSame( 'Public IP', $data[0]['key'] );
		$this->assertSame( 'Extra storage', $data[1]['key'] );
		$this->assertStringStartsWith( '+50 GB (+', $data[1]['value'] );
		$this->assertStringContainsString( '4.00', $data[1]['value'] );
		$this->assertStringContainsString( '/ month', $data[1]['value'] . ' / month' );
		$this->assertStringNotContainsString( "\u{2014}", $data[1]['value'] );

		WC()->cart->empty_cart();
		$this->add( $v['e0-0'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$this->assertSame( array(), DbDiskField::item_data( array( array( 'key' => 'Extra storage', 'value' => 'None' ) ), $item ), 'No extra storage: no line.' );
	}

	public function test_the_endpoint_refuses_a_bad_nonce_and_swaps_with_a_good_one(): void {
		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e0-0'] );

		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$_POST['extra']    = '50';
		$this->assertSame( 403, DbDiskField::respond()[1] );
		$this->assertSame( $v['e0-0']->get_id(), $this->in_cart() );

		$_POST['nonce']    = wp_create_nonce( DbDiskField::NONCE );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$answer            = DbDiskField::respond();
		$this->assertSame( 200, $answer[1] );
		$this->assertSame( 50, $answer[0]['extra'] );
		$this->assertSame( $v['e50-0']->get_id(), $this->in_cart() );

		$_POST['extra'] = 'rm -rf';
		$this->assertSame( 409, DbDiskField::respond()[1] );
		$this->assertSame( $v['e50-0']->get_id(), $this->in_cart() );
	}

	public function test_the_checkout_refuses_a_size_that_does_not_fit_and_one_the_settings_forbid(): void {
		$v = $this->db_variations();
		$this->room();
		$this->add( $v['e100-0'] );

		$errors = new \WP_Error();
		DbDiskField::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );

		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$errors = new \WP_Error();
		DbDiskField::validate( array(), $errors );
		$this->assertSame( array( 'beltoft_services_db_disk' ), $errors->get_error_codes(), 'It does not fit.' );
		$this->assertStringContainsString( 'not available right now', $errors->get_error_message() );
		$this->settings( array( 'disk_reserve_gb' => 0 ) );

		delete_transient( 'beltoft_services_disk_capacity_' . md5( 'lab|node-a|disk-store' ) );
		$errors = new \WP_Error();
		DbDiskField::validate( array(), $errors );
		$this->assertSame( array( 'beltoft_services_db_disk' ), $errors->get_error_codes(), 'No reading of the storage: it does not fit.' );
		$this->room();

		$this->settings( array( 'db_disk_max_gb' => 90 ) );
		$errors = new \WP_Error();
		DbDiskField::validate( array(), $errors );
		$this->assertSame( array( 'beltoft_services_db_disk' ), $errors->get_error_codes() );
		$this->assertStringContainsString( 'not available', $errors->get_error_message() );
		$this->settings( array( 'db_disk_max_gb' => 1000 ) );

		WC()->cart->empty_cart();
		$this->add( $v['e0-0'] );
		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$errors = new \WP_Error();
		DbDiskField::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'No extra storage: nothing to check.' );
		$this->settings( array( 'disk_reserve_gb' => 0 ) );

		WC()->cart->empty_cart();
		$this->add( $v['e100-0'], array( 'subscription_renewal' => array( 'subscription_id' => 1 ) ) );
		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$errors = new \WP_Error();
		DbDiskField::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'A renewal is not checked.' );
	}
}
