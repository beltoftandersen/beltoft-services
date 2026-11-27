<?php
/**
 * The IPv4 address in the cart: the checkout line for a new server or database, one cart line per address, the rules at
 * add to cart and at checkout, and what happens when none is free. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Db\DbIpField;
use BeltoftServices\Proxmox\Ipv4\Ipv4Cart;
use BeltoftServices\Proxmox\Ipv4\Ipv4Driver;
use BeltoftServices\Proxmox\Ipv4\Ipv4Field;
use BeltoftServices\Proxmox\Ipv4\Ipv4Meta;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Backup/BackupTestCase.php';

class Ipv4CartTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->seed_private_nets();
		remove_all_filters( 'beltoft_services_ipv4_alerts' );

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_REQUEST['beltoft_services_ipv4_for'], $_REQUEST['beltoft_services_ipv4_nonce'], $_POST['nonce'], $_POST['count'], $_REQUEST['nonce'] );
		parent::tear_down();
	}

	/** The IPv4 add-on product: one monthly subscription of 19. */
	private function product(): \WC_Product_Variation {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'IPv4 address ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Plan' );
		$attribute->set_options( array( 'Monthly' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, Ipv4Driver::ID );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'ipv4-' . wp_generate_password( 5, false ) );
		$product->save();
		$variation = new \WC_Product_Subscription_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'plan' => 'Monthly' ) );
		$variation->set_regular_price( '19' );
		$variation->update_meta_data( '_subscription_price', '19' );
		$variation->update_meta_data( '_subscription_period', 'month' );
		$variation->update_meta_data( '_subscription_period_interval', '1' );
		$variation->update_meta_data( '_subscription_length', '0' );
		$variation->set_status( 'publish' );
		$variation->save();
		\WC_Product_Variable::sync( $product->get_id() );
		\BeltoftServices\Proxmox\Vm\Stock::sync();

		return wc_get_product( $variation->get_id() );
	}

	/** A database product with one plan. */
	private function database(): \WC_Product_Variation {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Database ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$plan = new \WC_Product_Attribute();
		$plan->set_name( 'Plan' );
		$plan->set_options( array( 'Small' ) );
		$plan->set_visible( true );
		$plan->set_variation( true );
		$product->set_attributes( array( $plan ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'major' => '18', 'cores' => '1', 'memory_mb' => '2048', 'disk_gb' => '20' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );
		$v = new \WC_Product_Variation();
		$v->set_parent_id( $product->get_id() );
		$v->set_attributes( array( 'plan' => 'Small' ) );
		$v->set_regular_price( '25' );
		$v->set_status( 'publish' );
		$v->save();

		return $v;
	}

	private function add_line( \WC_Product_Variation $v, array $data = array() ) {
		$attrs = array();

		foreach ( $v->get_attributes() as $name => $value ) {
			$attrs[ 'attribute_' . $name ] = $value;
		}

		return WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), $attrs, $data );
	}

	/** A new dedicated server in the cart; with $basic its plan says no IPv4 (set after, as the cart does not check stock for the plan). */
	private function server_in_cart( bool $basic = false ): void {
		$server = $this->variable_product();
		$this->assertNotFalse( $this->add_line( $server['a-None'] ), wp_json_encode( wc_get_notices() ) );

		if ( $basic ) {
			$server['a-None']->update_meta_data( Product::META_PLAN . 'public_ip', '0' );
			$server['a-None']->save();
		}
	}

	private function lines(): int {
		return count( array_filter( WC()->cart->get_cart(), static fn( $item ) => 'cart_server' === ( $item['beltoft_services_ipv4_for'] ?? null ) ) );
	}

	private function html(): string {
		ob_start();
		Ipv4Field::render();

		return (string) ob_get_clean();
	}

	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'woocommerce_after_order_notes', array( Ipv4Field::class, 'render' ) ) );
		$this->assertNotFalse( has_action( 'wc_ajax_' . Ipv4Field::AJAX, array( Ipv4Field::class, 'ajax' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_add_to_cart_validation', array( Ipv4Cart::class, 'validate' ) ) );
	}

	public function test_a_direct_add_without_a_server_or_database_is_refused(): void {
		$product = $this->product();

		$this->assertFalse( $this->add_line( $product ) );
	}

	public function test_an_existing_server_gets_distinct_lines_for_two_addresses(): void {
		$server  = $this->ready_vm();
		$license = $server->license();
		wp_set_current_user( (int) $license['user_id'] );
		$product = $this->product();
		$_REQUEST['beltoft_services_ipv4_for']   = (string) $license['id'];
		$_REQUEST['beltoft_services_ipv4_nonce'] = wp_create_nonce( 'beltoft-services-ipv4:' . $license['id'] );

		$one = $this->add_line( $product );
		$two = $this->add_line( $product );

		$this->assertNotFalse( $one, wp_json_encode( wc_get_notices() ) );
		$this->assertNotFalse( $two, wp_json_encode( wc_get_notices() ) );
		$this->assertNotSame( $one, $two );

		foreach ( WC()->cart->get_cart() as $item ) {
			$this->assertSame( 1, $item['quantity'] );
			$this->assertSame( (int) $license['id'], $item['beltoft_services_ipv4_for'] );
			$this->assertStringContainsString( '_ipv4_', apply_filters( 'woocommerce_subscriptions_recurring_cart_key', 'base', $item ) );
		}

		$this->assertNotSame( '', Ipv4Cart::add_url( \BeltoftServices\Resource::find( $server->id() ) ), 'The dashboard link is there while an address is free.' );
	}

	public function test_a_server_of_another_customer_is_refused(): void {
		$server  = $this->ready_vm();
		$license = $server->license();
		wp_set_current_user( self::factory()->user->create() );
		$product = $this->product();
		$_REQUEST['beltoft_services_ipv4_for']   = (string) $license['id'];
		$_REQUEST['beltoft_services_ipv4_nonce'] = wp_create_nonce( 'beltoft-services-ipv4:' . $license['id'] );

		$this->assertFalse( $this->add_line( $product ) );
	}

	public function test_the_line_for_a_new_dedicated_server_is_a_number_and_changes_the_cart(): void {
		$this->product();
		$this->server_in_cart();

		$target = Ipv4Field::target();
		$this->assertSame( 'server', $target['kind'] );
		$this->assertFalse( $target['box'] );
		$html = $this->html();
		$this->assertStringContainsString( '<select class="select" id="beltoft_services_ipv4"', $html );
		$this->assertStringNotContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'Extra IPv4 addresses, ', $html, 'The server already has one address: the number counts extra ones.' );
		$this->assertStringContainsString( 'Your server includes one IPv4 address.', $html );
		$this->assertStringContainsString( '19', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		$this->assertTrue( Ipv4Field::choose( 3 )['ok'] );
		$this->assertSame( 3, $this->lines() );
		$this->assertCount( 4, WC()->cart->get_cart(), 'The server and three addresses.' );
		$this->assertTrue( Ipv4Field::choose( 1 )['ok'] );
		$this->assertSame( 1, $this->lines() );
		$this->assertTrue( Ipv4Field::choose( 0 )['ok'] );
		$this->assertSame( 0, $this->lines() );

		$errors = new \WP_Error();
		Ipv4Field::choose( 2 );
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertFalse( $errors->has_errors(), $errors->get_error_message() );
	}

	public function test_cart_server_lines_respect_the_limit_and_a_cart_with_a_server_and_a_database(): void {
		$this->product();
		$this->server_in_cart( true );
		Ipv4Field::choose( 1 );
		$this->assertSame( 1, $this->lines() );

		// A crafted repeat add beyond the limit of 1 is refused.
		$v = Ipv4Cart::product();
		$this->assertFalse( Ipv4Cart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array( 'beltoft_services_ipv4_for' => 'cart_server' ) ) );

		// Lines beyond the limit are refused at checkout.
		WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), array( 'attribute_plan' => 'Monthly' ), array( 'beltoft_services_ipv4_for' => 'cart_server' ) );
		$errors = new \WP_Error();
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertContains( 'ipv4_limit', $errors->get_error_codes() );

		// A database joins the server: the address lines have no owner any more.
		WC()->cart->empty_cart();
		$this->server_in_cart( true );
		Ipv4Field::choose( 1 );
		$this->add_line( $this->database() );
		$this->assertTrue( Ipv4Field::both() );
		$this->assertNull( Ipv4Field::target() );
		$errors = new \WP_Error();
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertTrue( $errors->has_errors() );
		$this->assertStringContainsString( 'dashboard', $errors->get_error_message() );
		$this->assertFalse( Ipv4Cart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array( 'beltoft_services_ipv4_for' => 'cart_server' ) ) );

		// The server goes, the database stays with limit 1: extra lines are trimmed.
		WC()->cart->empty_cart();
		$this->server_in_cart();
		Ipv4Field::choose( 3 );
		$this->add_line( $this->database() );
		$server_key = \BeltoftServices\Proxmox\Disk\DiskField::cart_server()['key'] ?? null;
		WC()->cart->remove_cart_item( (string) $server_key );
		$this->assertLessThanOrEqual( 1, $this->lines() );
	}

	public function test_removing_the_server_takes_its_address_lines_with_it(): void {
		$this->product();
		$this->server_in_cart();
		Ipv4Field::choose( 2 );
		$this->assertSame( 2, $this->lines() );

		$server_key = \BeltoftServices\Proxmox\Disk\DiskField::cart_server()['key'];
		WC()->cart->remove_cart_item( $server_key );

		$this->assertSame( 0, $this->lines() );
	}

	public function test_a_basic_server_and_a_database_get_a_box_for_one_address(): void {
		$this->product();
		$this->server_in_cart( true );

		$target = Ipv4Field::target();
		$this->assertTrue( $target['box'] );
		$this->assertSame( 1, $target['max'] );
		$this->assertStringContainsString( 'type="checkbox"', $this->html() );
		$this->assertStringNotContainsString( 'Extra IPv4', $this->html(), 'A basic server has no address yet: the box is its first.' );
		$this->assertTrue( Ipv4Field::choose( 5 )['ok'] );
		$this->assertSame( 1, $this->lines(), 'A box is one address.' );

		WC()->cart->empty_cart();
		$this->add_line( $this->database() );

		$this->assertTrue( DbIpField::cart_has_new_database() );
		$target = Ipv4Field::target();
		$this->assertSame( 'db', $target['kind'] );
		$this->assertStringContainsString( 'type="checkbox"', $this->html() );
		$this->assertStringContainsString( 'Access stays closed until you add addresses', $this->html() );
		$this->assertTrue( Ipv4Field::choose( 1 )['ok'] );
		$this->assertSame( 1, $this->lines() );
		$this->assertTrue( Ipv4Field::choose( 0 )['ok'] );
		$this->assertSame( 0, $this->lines() );
	}

	public function test_a_basic_server_and_a_database_get_the_ipv6_notice_only_with_a_test_page_that_has_an_aaaa_record(): void {
		$this->product();
		$this->server_in_cart( true );

		$this->assertStringNotContainsString( 'data-ipv6-notice', $this->html(), 'No test page set: no notice.' );

		$this->settings( array( 'ipv6_test_url' => 'https://ipv6test.example.test/ping' ) );
		delete_transient( 'beltoft_services_ipv6_test_aaaa_' . md5( 'ipv6test.example.test' ) );
		add_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_false' );
		$this->assertStringNotContainsString( 'data-ipv6-notice', $this->html(), 'The name has no AAAA record yet: no test, no notice.' );

		remove_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_false' );
		delete_transient( 'beltoft_services_ipv6_test_aaaa_' . md5( 'ipv6test.example.test' ) );
		add_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_true' );

		$html = $this->html();
		$this->assertStringContainsString( 'data-ipv6-notice', $html );
		$this->assertStringContainsString( ' hidden>', $html, 'Hidden until the browser has tried.' );
		$this->assertStringContainsString( 'Your internet connection has no IPv6. This server can only be reached over IPv6 unless you add an IPv4 address.', $html );
		$this->assertMatchesRegularExpression( '/data-ipv6-add>Buy an IPv4 address \(\+[^)]*19[^)]*\)</', $html, 'The button names the price.' );
		$this->assertStringContainsString( 'data-ipv6-check', $html );
		$this->assertStringContainsString( '>Check again</button>', $html );
		$this->assertStringContainsString( 'data-none="Your connection has no IPv6. Buy an IPv4 address, or use the browser console."', $html );
		$this->assertStringContainsString( 'data-ipv6="Your connection has IPv6. You can connect to this server."', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		WC()->cart->empty_cart();
		$this->add_line( $this->database() );
		$this->assertStringContainsString( 'This database can only be reached over IPv6 unless you add an IPv4 address.', $this->html() );

		WC()->cart->empty_cart();
		$this->server_in_cart( false );
		$this->assertStringNotContainsString( 'data-ipv6-notice', $this->html(), 'A dedicated server has its own IPv4 address.' );

		remove_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_true' );
	}

	public function test_the_ipv6_test_script_is_loaded_only_with_a_usable_test_page(): void {
		$this->settings( array( 'ipv6_test_url' => 'http://ipv6test.example.test/ping' ) );
		$this->assertSame( '', \BeltoftServices\Proxmox\Ipv4\Ipv6Test::url(), 'Only https.' );

		$this->settings( array( 'ipv6_test_url' => 'https://ipv6test.example.test/ping' ) );
		delete_transient( 'beltoft_services_ipv6_test_aaaa_' . md5( 'ipv6test.example.test' ) );
		add_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_true' );
		$this->assertSame( 'https://ipv6test.example.test/ping', \BeltoftServices\Proxmox\Ipv4\Ipv6Test::url() );
		remove_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_true' );
	}

	public function test_no_line_for_the_wrong_carts(): void {
		$this->assertNull( Ipv4Field::target() );
		$this->assertSame( '', $this->html() );

		$this->product();
		$this->assertNull( Ipv4Field::target(), 'An empty cart.' );
		$this->add_line( $this->database() );
		$this->add_line( $this->database() );
		$this->assertFalse( DbIpField::cart_has_new_database(), 'Two databases: no choice.' );
		$this->assertNull( Ipv4Field::target() );

		WC()->cart->empty_cart();
		$this->add_line( $this->database(), array( 'subscription_renewal' => array( 'subscription_id' => 1 ) ) );
		$this->assertFalse( DbIpField::cart_has_new_database() );
		$this->assertSame( '', $this->html() );
	}

	public function test_without_the_product_there_is_no_line(): void {
		$this->server_in_cart();

		$this->assertNull( Ipv4Field::target() );
		$this->assertSame( '', $this->html() );
	}

	public function test_the_line_is_disabled_and_the_choice_refused_when_no_address_is_free(): void {
		$this->product();
		$this->server_in_cart();
		$this->backup_wipe();
		$this->assertSame( 0, IpPool::available_for_addon() );

		$html = $this->html();
		$this->assertMatchesRegularExpression( '#id="beltoft_services_ipv4"[^>]*disabled#', $html );
		$this->assertStringContainsString( 'No IPv4 address is free right now. You can add one later from your dashboard.', $html );
		$this->assertFalse( Ipv4Field::choose( 1 )['ok'] );
		$this->assertSame( 0, $this->lines() );
	}

	public function test_checkout_refuses_more_lines_than_there_are_free_addresses_and_keeps_the_reserve(): void {
		$this->product();
		$this->server_in_cart();
		Ipv4Field::choose( 2 );
		$this->settings( array( 'ip_reserve_for_vps' => 1 ) );

		$this->backup_wipe();
		IpPool::add( '192.0.2.200' );
		IpPool::add( '192.0.2.201' );
		IpPool::add( '192.0.2.202' );

		$errors = new \WP_Error();
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertFalse( $errors->has_errors(), 'A new dedicated server takes one, the two lines the others: three are free.' );

		IpPool::block( '192.0.2.202' );
		$errors = new \WP_Error();
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertSame( 'ipv4_sold_out', $errors->get_error_code() );
	}

	public function test_the_ajax_answer_needs_the_nonce(): void {
		$this->product();
		$this->server_in_cart();

		$this->assertSame( 403, Ipv4Field::respond()[1] );

		$_POST['nonce']    = wp_create_nonce( Ipv4Field::NONCE );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$_POST['count']    = '2';
		$answer            = Ipv4Field::respond();

		$this->assertSame( 200, $answer[1] );
		$this->assertSame( 2, $answer[0]['count'] );
	}

	// -- The dropdown and its server side guards ----------------------------------------------------

	private function post( array $post ): array {
		$_POST = $_REQUEST = $post;

		return Ipv4Field::respond();
	}

	private function nonce(): string {
		return wp_create_nonce( Ipv4Field::NONCE );
	}

	public function test_the_limit_is_a_setting_with_default_10_and_the_filter_still_wins(): void {
		$this->assertSame( 10, \BeltoftServices\Settings::get( 'ipv4_max_extra' ) );
		$this->product();
		$this->server_in_cart();
		$this->assertSame( 10, Ipv4Field::target()['max'] );

		$this->settings( array( 'ipv4_max_extra' => 4 ) );
		$this->assertSame( 4, Ipv4Field::target()['max'] );
		$this->assertSame( 4, Ipv4Meta::max_extra() );

		add_filter( 'beltoft_services_ipv4_max_per_server', static fn() => 2 );
		$this->assertSame( 2, Ipv4Field::target()['max'] );
	}

	public function test_the_select_offers_zero_to_the_limit_with_the_chosen_one_selected(): void {
		$this->product();
		$this->settings( array( 'ipv4_max_extra' => 5 ) );
		$this->server_in_cart();
		$this->backup_wipe();
		IpPool::add( '192.0.2.210' );
		IpPool::add( '192.0.2.211' );
		IpPool::add( '192.0.2.212' );
		IpPool::add( '192.0.2.213' );
		$this->assertSame( 3, IpPool::available_for_addon() );
		Ipv4Field::choose( 2 );

		$html = $this->html();
		$this->assertSame( 6, substr_count( $html, '<option ' ) );
		$this->assertStringContainsString( '<option value="0"', $html );
		$this->assertStringContainsString( '<option value="5"', $html );
		$this->assertStringNotContainsString( 'value="6"', $html );
		$this->assertMatchesRegularExpression( '#<option value="2"\s+selected=\'selected\'#', $html );
		$this->assertMatchesRegularExpression( '#data-nonce="[^"]+" data-was="2"#', $html );
		$this->assertStringContainsString( 'for="beltoft_services_ipv4"', $html );

		// The pool gives 3 more now (one is in the cart): up to 3 + 2 is open, nothing above it.
		$this->assertDoesNotMatchRegularExpression( '#<option value="[0-5]"[^>]*disabled#', $html );
		IpPool::block( '192.0.2.213' );
		IpPool::block( '192.0.2.212' );
		$html = $this->html();
		$this->assertDoesNotMatchRegularExpression( '#<option value="[0-3]"[^>]*disabled#', $html );
		$this->assertMatchesRegularExpression( '#<option value="4"[^>]*disabled#', $html );
		$this->assertMatchesRegularExpression( '#<option value="5"[^>]*disabled#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<select[^>]*disabled#', $html );
	}

	public function test_the_select_is_disabled_when_no_address_is_free(): void {
		$this->product();
		$this->server_in_cart();
		$this->backup_wipe();
		$this->assertMatchesRegularExpression( '#<select[^>]*disabled#', $this->html() );
	}

	public function test_a_basic_server_still_gets_a_checkbox_and_no_select(): void {
		$this->product();
		$this->server_in_cart( true );
		$html = $this->html();
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringNotContainsString( '<select', $html );
	}

	public function test_the_ajax_never_puts_more_than_the_limit_in_the_cart(): void {
		$this->product();
		$this->settings( array( 'ipv4_max_extra' => 3 ) );
		$this->server_in_cart();
		$this->assertGreaterThanOrEqual( 3, IpPool::available_for_addon() );

		// A count over the limit is clamped.
		$answer = $this->post( array( 'nonce' => $this->nonce(), 'count' => '999' ) );
		$this->assertSame( 200, $answer[1] );
		$this->assertSame( 3, $this->lines() );

		// A negative count, an array or text means none.
		WC()->cart->empty_cart();
		$this->server_in_cart();
		$this->post( array( 'nonce' => $this->nonce(), 'count' => '2' ) );
		$this->post( array( 'nonce' => $this->nonce(), 'count' => '-7' ) );
		$this->assertSame( 0, $this->lines() );
		$this->post( array( 'nonce' => $this->nonce(), 'count' => '2' ) );
		$this->post( array( 'nonce' => $this->nonce(), 'count' => array( '5' ) ) );
		$this->assertSame( 0, $this->lines() );

		// Not a number, an array, a float and a huge number.
		foreach ( array( 'abc', array( '5', '6' ), '2.9', '99999999999999999999', '' ) as $bad ) {
			$this->post( array( 'nonce' => $this->nonce(), 'count' => $bad ) );
			$this->assertLessThanOrEqual( 3, $this->lines(), wp_json_encode( $bad ) );
		}

		$this->post( array( 'nonce' => $this->nonce(), 'count' => 'abc' ) );
		$this->assertSame( 0, $this->lines(), 'Not a number is 0.' );
	}

	public function test_a_missing_or_wrong_nonce_changes_nothing(): void {
		$this->product();
		$this->server_in_cart();

		$this->assertSame( 403, $this->post( array( 'count' => '3' ) )[1] );
		$this->assertSame( 403, $this->post( array( 'nonce' => 'wrong', 'count' => '3' ) )[1] );
		$this->assertSame( 0, $this->lines() );
	}

	public function test_a_count_the_pool_cannot_give_is_refused_and_the_cart_keeps_what_it_had(): void {
		$this->product();
		$this->server_in_cart();
		$this->backup_wipe();
		IpPool::add( '192.0.2.220' );
		IpPool::add( '192.0.2.221' );
		$free = IpPool::available_for_addon();
		$this->assertGreaterThan( 0, $free );
		$this->assertTrue( Ipv4Field::choose( $free )['ok'] );

		$answer = $this->post( array( 'nonce' => $this->nonce(), 'count' => (string) ( $free + 1 ) ) );
		$this->assertFalse( $answer[0]['ok'] );
		$this->assertSame( $free, $answer[0]['count'] );
		$this->assertSame( $free, $this->lines() );
	}

	public function test_add_to_cart_validation_needs_quantity_one_the_cart_server_link_and_stays_under_the_limit(): void {
		$this->product();
		$this->settings( array( 'ipv4_max_extra' => 2 ) );
		$this->server_in_cart();
		$v    = Ipv4Cart::product();
		$data = array( 'beltoft_services_ipv4_for' => 'cart_server' );

		$this->assertTrue( Ipv4Cart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), $data ) );
		$this->assertFalse( Ipv4Cart::validate( true, $v->get_parent_id(), 2, $v->get_id(), array(), $data ), 'Quantity must be 1.' );
		$this->assertFalse( Ipv4Cart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array() ), 'A link is needed.' );
		$this->assertFalse( Ipv4Cart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array( 'beltoft_services_ipv4_for' => 'nonsense' ) ) );

		Ipv4Field::choose( 2 );
		$this->assertFalse( Ipv4Cart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), $data ), 'Two lines is the limit.' );
	}

	public function test_a_cart_over_the_limit_is_refused_at_checkout_and_trimmed_in_the_cart(): void {
		$this->product();
		$this->server_in_cart();
		$this->assertTrue( Ipv4Field::choose( 3 )['ok'] );
		$this->settings( array( 'ipv4_max_extra' => 1 ) );

		$errors = new \WP_Error();
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertContains( 'ipv4_limit', $errors->get_error_codes(), 'Checkout refuses lines over the limit.' );

		wc_clear_notices();
		Ipv4Field::trim_excess();
		$this->assertSame( 1, $this->lines() );
		$this->assertNotEmpty( wc_get_notices( 'notice' ) );

		$errors = new \WP_Error();
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertNotContains( 'ipv4_limit', $errors->get_error_codes() );

		wc_clear_notices();
		Ipv4Field::trim_excess();
		$this->assertEmpty( wc_get_notices( 'notice' ), 'Nothing to trim, no notice.' );
		$this->assertNotFalse( has_action( 'woocommerce_check_cart_items', array( Ipv4Field::class, 'trim_excess' ) ) );
	}

	public function test_the_script_restores_the_select_on_failure_and_shows_what_the_cart_holds(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/ipv4-field.js' );
		$this->assertStringContainsString( 'field.val( before )', $js );
		$this->assertStringContainsString( "field.val( String( now ) )", $js );
	}
}
