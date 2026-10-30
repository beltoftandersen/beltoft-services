<?php
/**
 * The New server page: the offers, the plan cards, the post that fills the cart, and checkout's use of what was carried.
 * FakeClient and the capacity cache only: nothing reaches a network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Account\Servers;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Picked;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Proxmox\SshKeyField;
use BeltoftServices\Tests\Proxmox\BackupTestCase;

require_once dirname( __DIR__ ) . '/Proxmox/BackupTestCase.php';

class ServerPickerTest extends BackupTestCase {

	/**
	 * Variations by "<plan>-<backups>": a-None, a-Daily, b-None, b-Daily.
	 *
	 * @var array<string, \WC_Product_Variation>
	 */
	private $v = array();

	/**
	 * The product.
	 *
	 * @var \WC_Product_Variable
	 */
	private $product;

	/**
	 * The customer.
	 *
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();

		$this->save_cluster(
			array(
				'templates'              => "9000 = Debian 12\n9001 = Ubuntu 24.04",
				'default_template'       => '9000',
				'vendor_snippet'         => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
				'apps_ready'             => 1,
				'apps_enabled'           => array( 'docker', 'wordpress', 'coolify' ),
				'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
			)
		);

		$this->v = $this->variable_product( array( 'None' => '10', 'Daily' => '12' ) );

		// Plan b is the bigger one.
		foreach ( array( 'b-None', 'b-Daily' ) as $key ) {
			foreach ( array( 'cores' => '4', 'memory_mb' => '8192', 'disk_gb' => '100' ) as $field => $value ) {
				$this->v[ $key ]->update_meta_data( Product::META_PLAN . $field, $value );
			}

			$this->v[ $key ]->save();
		}

		$this->product = wc_get_product( $this->v['a-None']->get_parent_id() );
		$this->product->update_meta_data( Product::META_PLAN . 'disk_gb', '50' );
		$this->product->save();
		$this->user    = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Offers::reset();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		foreach ( array( ServerPicker::HANDLE, \BeltoftServices\Proxmox\Tiles::HANDLE, AppField::HANDLE ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_dequeue_script( $handle );
		}
		remove_all_filters( 'beltoft_services_picker_products' );
		remove_all_filters( 'beltoft_services_picker_can_buy' );
		remove_all_filters( 'beltoft_services_picker_rate_limit' );
		unset( $GLOBALS['wp']->query_vars['vps'], $_GET['plan'] );
		Offers::reset();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A post of the page.
	 *
	 * @param array<string, string> $over Fields to set (by field name).
	 * @return array<string, string>
	 */
	private function post( array $over = array() ): array {
		return array_merge(
			array(
				ServerPicker::FIELD => wp_create_nonce( ServerPicker::NONCE ),
				ServerPicker::PLAN  => (string) $this->v['a-None']->get_id(),
				OsField::POST_KEY   => '9000',
			),
			$over
		);
	}

	/**
	 * @param array<string, string> $over Fields.
	 * @return array{redirect: string, errors: array<string, string>}
	 */
	private function send( array $over = array() ): array {
		Offers::reset();

		return ServerPicker::process( $this->post( $over ), $this->user );
	}

	private function server_lines(): array {
		return array_values( WC()->cart->get_cart() );
	}

	private function page(): string {
		$GLOBALS['wp']->query_vars['vps'] = 'new';
		Offers::reset();
		ob_start();
		Servers::render( array() );

		return (string) ob_get_clean();
	}

	private function ed25519(): string {
		$str = static fn( string $s ): string => pack( 'N', strlen( $s ) ) . $s;

		return 'ssh-ed25519 ' . base64_encode( $str( 'ssh-ed25519' ) . $str( random_bytes( 32 ) ) ) . ' me@host'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a test key.
	}

	// -- The offers --------------------------------------------------------------------------------

	public function test_the_plans_come_from_the_variations_without_backups(): void {
		$plans = Offers::plans();

		$this->assertSame( array( $this->v['a-None']->get_id(), $this->v['b-None']->get_id() ), array_keys( $plans ), 'A variation with backups is the option of its plan, not a card.' );

		$a = $plans[ $this->v['a-None']->get_id() ];
		$this->assertSame( 'a', $a['name'], 'The name leaves out the backups attribute.' );
		$this->assertSame( array( 2, 4096, 50 ), array( $a['cores'], $a['memory_mb'], $a['disk_gb'] ) );
		$this->assertSame( 10.0, $a['price'] );
		$this->assertTrue( $a['in_stock'] );
		$this->assertSame( $this->v['a-Daily']->get_id(), $a['backups']['variation_id'] );
		$this->assertSame( 2.0, $a['backups']['extra'] );
		$this->assertSame( 7, $a['backups']['days'] );

		$b = $plans[ $this->v['b-None']->get_id() ];
		$this->assertSame( array( 4, 8192, 100 ), array( $b['cores'], $b['memory_mb'], $b['disk_gb'] ) );
	}

	public function test_the_price_is_excluding_tax(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );

		try {
			Offers::reset();
			$this->assertEqualsWithDelta( 8.0, Offers::plans()[ $this->v['a-None']->get_id() ]['price'], 0.01, '10 including 25 percent VAT is 8 excluding.' );
		} finally {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reset.
			$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
			update_option( 'woocommerce_calc_taxes', 'no' );
			update_option( 'woocommerce_prices_include_tax', 'no' );
			update_option( 'woocommerce_tax_display_shop', 'excl' );
			Offers::reset();
		}
	}

	public function test_a_product_is_hidden_by_its_setting_its_driver_its_status_and_the_filter(): void {
		$this->assertArrayHasKey( $this->product->get_id(), Offers::products(), 'A VM product that never saved the setting is offered.' );

		$this->product->update_meta_data( Product::META_PICKER, 'no' );
		$this->product->save();
		Offers::reset();
		$this->assertSame( array(), Offers::products(), 'Show in the server picker is off.' );

		$this->product->update_meta_data( Product::META_PICKER, 'yes' );
		$this->product->save();
		Offers::reset();
		$this->assertCount( 1, Offers::products() );

		add_filter( 'beltoft_services_picker_products', static fn() => array() );
		Offers::reset();
		$this->assertSame( array(), Offers::products(), 'The filter narrows the list.' );
		remove_all_filters( 'beltoft_services_picker_products' );

		$this->product->set_status( 'draft' );
		$this->product->save();
		Offers::reset();
		$this->assertSame( array(), Offers::products(), 'A product that is not published is never offered.' );
		$this->product->set_status( 'publish' );
		$this->product->save();

		$other = $this->service_product( 'fake', array(), array( 'variations' => array( array(), array() ), 'slug' => 'other-' . wp_generate_password( 5, false ) ) );
		Offers::reset();
		$this->assertArrayNotHasKey( $other->get_id(), Offers::products(), 'Another driver is left out.' );
	}

	public function test_the_setting_is_saved_only_when_the_panel_printed_it(): void {
		$this->product->update_meta_data( Product::META_PICKER, 'yes' );
		$_POST = array( Product::META_DRIVER => 'proxmox', Product::FIELD_PICKER => '1' );
		Product::save( $this->product );
		$this->assertSame( 'no', $this->product->get_meta( Product::META_PICKER ), 'The marker with no tick is off.' );

		$_POST[ Product::META_PICKER ] = 'yes';
		Product::save( $this->product );
		$this->assertSame( 'yes', $this->product->get_meta( Product::META_PICKER ) );

		unset( $_POST[ Product::FIELD_PICKER ], $_POST[ Product::META_PICKER ] );
		Product::save( $this->product );
		$this->assertSame( 'yes', $this->product->get_meta( Product::META_PICKER ), 'A save that did not print the box changes nothing.' );
		$_POST = array();
	}

	public function test_stock_follows_the_shop_status_and_the_room_for_a_machine(): void {
		$this->v['b-None']->set_stock_status( 'outofstock' );
		$this->v['b-None']->save();
		Offers::reset();
		$plans = Offers::plans();

		$this->assertTrue( $plans[ $this->v['a-None']->get_id() ]['in_stock'] );
		$this->assertFalse( $plans[ $this->v['b-None']->get_id() ]['in_stock'] );

		$this->v['b-None']->set_stock_status( 'instock' );
		$this->v['b-None']->save();
		delete_transient( 'beltoft_services_proxmox_capacity_lab' );
		Offers::reset();
		$plan = Offers::plans()[ $this->v['a-None']->get_id() ];
		$this->assertTrue( $plan['in_stock'], 'No cached capacity is unknown, not no room: checkout lets it through too.' );
		$this->assertTrue( $plan['unknown'] );
	}

	public function test_unknown_stock_shows_selectable_plans_and_a_line_while_a_full_cluster_stays_closed(): void {
		delete_transient( 'beltoft_services_proxmox_capacity_lab' );
		$html = $this->page();
		$this->assertStringNotContainsString( 'out of stock', strtolower( $html ) );
		$this->assertStringContainsString( 'Availability is checked again at checkout.', $html );
		$this->assertDoesNotMatchRegularExpression( '#<input[^>]*name="' . ServerPicker::PLAN . '"[^>]*disabled#', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*value="' . $this->v['a-None']->get_id() . '"[^>]*checked#', $html );
		$this->assertTrue( wc_get_product( $this->v['a-None']->get_id() )->is_in_stock(), 'Checkout treats a missing cache as it did: the stored status stands.' );
		$this->assertSame( array(), $this->send()['errors'] );

		WC()->cart->empty_cart();
		set_transient( 'beltoft_services_proxmox_capacity_lab', array( array( 'node' => 'node-a', 'status' => 'online', 'maxmem' => 68719476736, 'mem' => 68719476736 ) ), HOUR_IN_SECONDS );
		$html = $this->page();
		$this->assertStringContainsString( 'Every plan is out of stock', $html );
		$this->assertStringNotContainsString( 'Availability is checked again', $html );
		$this->assertFalse( wc_get_product( $this->v['a-None']->get_id() )->is_in_stock(), 'A really full cluster is still refused by the shop.' );
		$this->assertArrayHasKey( 'plan', $this->send()['errors'] );
	}

	private function fake_line( array $item ): string {
		$key  = md5( wp_json_encode( $item ) );
		$cart = WC()->cart->get_cart_contents();
		$cart[ $key ] = array_merge( array( 'key' => $key, 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'data' => wc_get_product( $item['product_id'] ) ), $item );
		WC()->cart->set_cart_contents( $cart );

		return $key;
	}

	public function test_an_add_the_shop_refuses_leaves_the_cart_as_it_was(): void {
		$this->send( array( ServerName::POST_KEY => 'first' ) );
		$before = array_keys( WC()->cart->get_cart() );
		// The shop's own refusal of an add: a cart-item-data filter that throws, as WooCommerce reports it.
		add_filter(
			'woocommerce_add_cart_item_data',
			static function ( $data ) {
				throw new \Exception( 'No room for that.' );
			}
		);
		$result = $this->send( array( ServerPicker::PLAN => (string) $this->v['b-None']->get_id(), ServerName::POST_KEY => 'second' ) );
		remove_all_filters( 'woocommerce_add_cart_item_data' );

		$this->assertSame( array( 'form' => 'No room for that.' ), $result['errors'] );
		$this->assertSame( $before, array_keys( WC()->cart->get_cart() ) );
		$this->assertSame( 'first', $this->server_lines()[0][ Picked::KEY ]['name'] );
	}

	public function test_a_subscription_payment_in_the_cart_refuses_the_picker_and_stays(): void {
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Other' );
		$simple->set_regular_price( '5' );
		$simple->set_status( 'publish' );
		$simple->save();
		$kinds = array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe' );

		foreach ( $kinds as $kind ) {
			WC()->cart->empty_cart();
			$key    = $this->fake_line( array( 'product_id' => $simple->get_id(), $kind => array( 'subscription_id' => 1 ) ) );
			$result = $this->send();
			$this->assertSame( 'Finish or remove the subscription payment in your cart first.', $result['errors']['form'], $kind );
			$this->assertSame( array( $key ), array_keys( WC()->cart->get_cart() ), $kind );
		}
	}

	public function test_replacing_the_server_takes_its_extra_disk_with_it(): void {
		$this->send( array( ServerName::POST_KEY => 'first' ) );
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Disk' );
		$simple->set_regular_price( '3' );
		$simple->set_status( 'publish' );
		$simple->save();
		$mine  = $this->fake_line( array( 'product_id' => $simple->get_id(), 'beltoft_services_disk_for' => 'cart_server' ) );
		$other = $this->fake_line( array( 'product_id' => $simple->get_id(), 'beltoft_services_disk_for' => 55 ) );

		$this->assertSame( array(), $this->send( array( ServerPicker::PLAN => (string) $this->v['b-None']->get_id(), ServerName::POST_KEY => 'second' ) )['errors'] );
		$cart = WC()->cart->get_cart();
		$this->assertArrayNotHasKey( $mine, $cart, 'The disk of the replaced server goes.' );
		$this->assertArrayHasKey( $other, $cart, 'A disk for an existing server stays.' );
		$this->assertCount( 2, $cart );
	}

	// -- The page ----------------------------------------------------------------------------------

	public function test_the_page_prints_the_cards_the_tick_box_and_the_choices(): void {
		$html = $this->page();

		$this->assertStringContainsString( 'class="beltoft-services-picker"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="' . ServerPicker::PLAN . '"' ), 'One radio per plan.' );
		$this->assertStringContainsString( '2 vCPU', $html );
		$this->assertStringContainsString( '4 GB RAM', $html );
		$this->assertStringContainsString( '50 GB disk', $html );
		$this->assertStringContainsString( '8 GB RAM', $html );
		$this->assertStringContainsString( 'excluding VAT', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html, 'No em dash.' );
		$this->assertMatchesRegularExpression( '#name="' . ServerPicker::BACKUP . '"#', $html );
		$this->assertStringContainsString( 'Daily backups, kept for 7 days (+', $html );
		$this->assertStringContainsString( 'name="' . OsField::POST_KEY . '"', $html );
		$this->assertStringContainsString( 'name="' . AppField::POST_APP . '"', $html );
		$this->assertStringContainsString( 'name="' . ServerName::POST_KEY . '"', $html );
		$this->assertStringContainsString( 'name="' . SshKeyField::POST_KEY . '"', $html );
		$this->assertStringContainsString( 'name="' . ServerPicker::FIELD . '"', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*value="' . $this->v['a-None']->get_id() . '"[^>]*checked#', $html, 'The first plan that can be bought starts selected.' );
	}

	public function test_a_plan_note_comes_only_from_the_filter_and_is_printed_as_text(): void {
		$this->assertStringNotContainsString( 'beltoft-services-plan__note', $this->page(), 'No note unless the site gives one.' );

		$a    = $this->v['a-None']->get_id();
		$note = static fn( string $text, \WC_Product_Variation $variation ): string => $a === $variation->get_id() ? '<b>For small sites</b> & tests.' : '';
		add_filter( 'beltoft_services_picker_plan_note', $note, 10, 2 );
		$html = $this->page();
		remove_filter( 'beltoft_services_picker_plan_note', $note, 10 );

		$this->assertSame( 'For small sites & tests.', Offers::plans()[ $a ]['note'] ?? null, 'Tags are stripped.' );
		$this->assertSame( 1, substr_count( $html, 'beltoft-services-plan__note' ), 'Only the plan with a note prints one.' );
		$this->assertStringContainsString( '<span class="beltoft-services-plan__note">For small sites &amp; tests.</span>', $html );
	}

	public function test_a_plan_the_app_does_not_fit_is_greyed_and_a_sold_out_plan_is_plain_text(): void {
		$html = $this->page();
		$this->assertMatchesRegularExpression( '#<input[^>]*value="coolify"[^>]*disabled[^>]*>.*?>Needs Performance</span>#s', $html, 'Coolify is not for the small plan.' );
		$this->assertStringNotContainsString( 'Out of stock', $html );

		$this->v['b-None']->set_stock_status( 'outofstock' );
		$this->v['b-None']->save();
		$html = $this->page();
		$this->assertStringContainsString( '<span class="beltoft-services-plan__stock">Out of stock</span>', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*value="' . $this->v['b-None']->get_id() . '"[^>]*disabled#', $html );

		$_GET['plan'] = (string) $this->v['b-None']->get_id();
		$this->assertMatchesRegularExpression( '#<input[^>]*value="' . $this->v['a-None']->get_id() . '"[^>]*checked#', $this->page(), 'A sold-out plan in the address is ignored.' );
	}

	public function test_the_address_can_start_the_page_on_a_plan(): void {
		$_GET['plan'] = (string) $this->v['b-None']->get_id();
		$this->assertMatchesRegularExpression( '#<input[^>]*value="' . $this->v['b-None']->get_id() . '"[^>]*checked#', $this->page() );
		$_GET['plan'] = '999999';
		$this->assertMatchesRegularExpression( '#<input[^>]*value="' . $this->v['a-None']->get_id() . '"[^>]*checked#', $this->page(), 'An unknown id is ignored.' );
	}

	public function test_with_nothing_to_offer_the_page_says_so(): void {
		add_filter( 'beltoft_services_picker_products', static fn() => array() );
		$html = $this->page();
		$this->assertStringContainsString( 'No server can be ordered right now.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_the_address_routes_to_the_picker_and_the_servers_list_is_unchanged(): void {
		$GLOBALS['wp']->query_vars['vps'] = 'new';
		$this->assertTrue( Servers::is_new() );
		$this->assertSame( array( 'id' => 0, 'tab' => '' ), Servers::requested() );

		foreach ( array( 'new/extra' => true, '12/snapshots' => false, '' => false, 'newer' => false, 'abc' => false ) as $value => $new ) {
			$GLOBALS['wp']->query_vars['vps'] = (string) $value;
			$this->assertSame( $new, Servers::is_new(), $value );
		}

		$this->assertMatchesRegularExpression( '#vps(/|=)new/?(\?|$)#', rawurldecode( Servers::url_new() ) );
		$this->assertStringContainsString( 'plan=' . $this->v['b-None']->get_id(), Servers::url_new( $this->v['b-None']->get_id() ) );

		$GLOBALS['wp']->query_vars['vps'] = '';
		ob_start();
		Servers::render( array() );
		$list = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'beltoft-services-picker', $list );
		$this->assertStringContainsString( 'You have no servers yet.', $list );
	}

	public function test_a_visitor_who_is_not_logged_in_goes_to_the_login_and_back(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp']->query_vars['vps'] = 'new';
		$_SERVER['REQUEST_METHOD']        = 'GET';
		$sent                             = '';

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$sent ) {
				$sent = (string) $location;
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			ServerPicker::submit();
			$this->fail( 'A visitor is redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			remove_all_filters( 'wp_redirect' );
		}

		$this->assertStringContainsString( wc_get_page_permalink( 'myaccount' ), $sent );
		$this->assertStringContainsString( 'redirect_to=', $sent );
		$this->assertMatchesRegularExpression( '#vps(/|=)new#', rawurldecode( $sent ), 'The way back is the New server page.' );
	}

	public function test_the_style_and_script_load_only_on_the_picker(): void {
		wp_dequeue_style( ServerPicker::HANDLE );
		wp_dequeue_script( ServerPicker::HANDLE );
		$GLOBALS['wp']->query_vars['vps'] = '';
		ServerPicker::assets( 'beltoft-services-account', Servers::slug() );
		$this->assertFalse( wp_style_is( ServerPicker::HANDLE, 'enqueued' ), 'Not on the Servers list.' );

		$GLOBALS['wp']->query_vars['vps'] = '12';
		ServerPicker::assets( 'beltoft-services-account', Servers::slug() );
		$this->assertFalse( wp_style_is( ServerPicker::HANDLE, 'enqueued' ), 'Not on a server page.' );

		$GLOBALS['wp']->query_vars['vps'] = 'new';
		ServerPicker::assets( 'beltoft-services-account', Servers::slug() );

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( Servers::slug() ) ) {
			$this->assertTrue( wp_style_is( ServerPicker::HANDLE, 'enqueued' ) );
			$this->assertTrue( wp_script_is( ServerPicker::HANDLE, 'enqueued' ) );
		} else {
			$this->markTestIncomplete( 'is_wc_endpoint_url needs a main query in this harness.' );
		}
	}

	// -- The post ----------------------------------------------------------------------------------

	public function test_a_good_post_fills_the_cart_with_one_line_and_goes_to_checkout(): void {
		$result = $this->send(
			array(
				ServerName::POST_KEY => 'Web 01',
				SshKeyField::POST_KEY => $this->ed25519(),
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( wc_get_checkout_url(), $result['redirect'] );

		$lines = $this->server_lines();
		$this->assertCount( 1, $lines );
		$this->assertSame( $this->v['a-None']->get_id(), (int) $lines[0]['variation_id'] );
		$this->assertSame( 1, (int) $lines[0]['quantity'] );
		$this->assertSame( '9000', $lines[0][ Picked::KEY ]['os'] );
		$this->assertSame( 'web-01', $lines[0][ Picked::KEY ]['name'], 'The name is the normalised one.' );
		$this->assertStringStartsWith( 'ssh-ed25519 ', $lines[0][ Picked::KEY ]['ssh_key'] );
		$this->assertSame( '', $lines[0][ Picked::KEY ]['app'] );
	}

	public function test_the_backups_box_puts_the_backups_variation_in_the_cart(): void {
		$this->assertSame( array(), $this->send( array( ServerPicker::BACKUP => '1' ) )['errors'] );
		$this->assertSame( $this->v['a-Daily']->get_id(), (int) $this->server_lines()[0]['variation_id'] );
	}

	public function test_an_app_sets_the_system_and_is_carried_with_its_version(): void {
		$result = $this->send( array( AppField::POST_APP => 'docker', OsField::POST_KEY => '9000' ) );

		$this->assertSame( array(), $result['errors'] );
		$carried = $this->server_lines()[0][ Picked::KEY ];
		$this->assertSame( 'docker', $carried['app'] );
		$this->assertSame( '9001', $carried['os'], 'An app runs on Ubuntu 24.04 whatever the tiles said.' );
		$this->assertNotSame( '', $carried['version'] );
	}

	public function test_an_own_domain_is_checked_and_carried_to_checkout(): void {
		$result = $this->send( array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => AppField::MODE_OWN, AppField::POST_OWN => 'Shop.Elsewhere.Test' ) );

		$this->assertSame( array(), $result['errors'] );
		$carried = $this->server_lines()[0][ Picked::KEY ];
		$this->assertSame( 'shop.elsewhere.test', $carried['own'] );
		$this->assertSame( '', $carried['domain_on'], 'It is not a domain on our name servers.' );
		$this->assertSame( '', $carried['zone'] );
		WC()->cart->empty_cart();

		$bad = $this->send( array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => AppField::MODE_OWN, AppField::POST_OWN => 'not a domain' ) );
		$this->assertStringContainsString( 'not a domain name we can use', $bad['errors']['app'] );
		$none = $this->send( array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => AppField::MODE_OWN, AppField::POST_OWN => '' ) );
		$this->assertSame( 'Enter the domain name you want to use.', $none['errors']['app'] );
	}

	public function test_a_second_post_replaces_the_first_server_line(): void {
		$this->send( array( ServerName::POST_KEY => 'first' ) );
		$this->send( array( ServerPicker::PLAN => (string) $this->v['b-None']->get_id(), ServerName::POST_KEY => 'second' ) );

		$lines = $this->server_lines();
		$this->assertCount( 1, $lines );
		$this->assertSame( $this->v['b-None']->get_id(), (int) $lines[0]['variation_id'] );
		$this->assertSame( 'second', $lines[0][ Picked::KEY ]['name'] );

		WC()->cart->empty_cart();
		$this->send( array( ServerName::POST_KEY => 'again' ) );
		$this->send( array( ServerName::POST_KEY => 'again2' ) );
		$this->assertCount( 1, $this->server_lines(), 'The same plan again does not keep the old choices.' );
		$this->assertSame( 'again2', $this->server_lines()[0][ Picked::KEY ]['name'] );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
	 */
	public static function refused(): array {
		return array(
			'no plan'          => array( array( ServerPicker::PLAN => '' ), 'plan', 'Choose a plan.' ),
			'unknown plan'     => array( array( ServerPicker::PLAN => '99999999' ), 'plan', 'Choose a plan.' ),
			'unknown system'   => array( array( OsField::POST_KEY => '1234' ), 'os', 'Choose an operating system for your server.' ),
			'no system'        => array( array( OsField::POST_KEY => '' ), 'os', 'Choose an operating system for your server.' ),
			'unknown app'      => array( array( AppField::POST_APP => 'nextcloud' ), 'app', 'Choose one of the listed apps, or none.' ),
			'unknown version'  => array( array( AppField::POST_APP => 'docker', AppField::POST_VERSION => '0.1' ), 'app', 'Choose one of the listed versions.' ),
			'foreign zone'     => array( array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => '1', AppField::POST_ZONE => 'theirs.example' ), 'app', 'Choose one of your domains that uses our name servers.' ),
			'a bad key'        => array( array( SshKeyField::POST_KEY => 'not a key' ), 'ssh_key', '' ),
			'a long name'      => array( array( ServerName::POST_KEY => str_repeat( 'a', 64 ) ), 'name', '' ),
			'a name with html' => array( array( ServerName::POST_KEY => '<b>x</b>' ), 'name', '' ),
		);
	}

	/**
	 * @dataProvider refused
	 *
	 * @param array<string, string> $over    Fields.
	 * @param string                $field   Field the message is for.
	 * @param string                $message Message, '' for any.
	 */
	public function test_a_refused_post_changes_nothing_and_says_why( array $over, string $field, string $message ): void {
		$result = $this->send( $over );

		$this->assertSame( '', $result['redirect'] );
		$this->assertArrayHasKey( $field, $result['errors'] );

		if ( '' !== $message ) {
			$this->assertSame( $message, $result['errors'][ $field ] );
		}

		$this->assertCount( 0, $this->server_lines(), 'Nothing was added.' );
	}

	public function test_an_app_the_plan_is_too_small_for_is_refused_with_the_checkout_message(): void {
		$result = $this->send( array( AppField::POST_APP => 'coolify' ) );
		$offer  = AppField::offer_for( $this->product->get_id(), $this->v['a-None']->get_id() );
		$why    = Apps::plan_refusal( reset( $offer['apps']['coolify']['choices'] ), $offer['plan'] );

		$this->assertNotNull( $why );
		$this->assertSame( $why, $result['errors']['app'] );
		$this->assertCount( 0, $this->server_lines() );

		$this->assertSame( array(), $this->send( array( ServerPicker::PLAN => (string) $this->v['b-None']->get_id(), AppField::POST_APP => 'coolify' ) )['errors'], 'The bigger plan can run it.' );
	}

	public function test_a_backups_variation_is_not_a_plan_and_a_sold_out_plan_is_refused(): void {
		$result = $this->send( array( ServerPicker::PLAN => (string) $this->v['a-Daily']->get_id() ) );
		$this->assertSame( 'Choose a plan.', $result['errors']['plan'], 'Backups are the box, not a plan id.' );

		$this->v['b-None']->set_stock_status( 'outofstock' );
		$this->v['b-None']->save();
		$result = $this->send( array( ServerPicker::PLAN => (string) $this->v['b-None']->get_id() ) );
		$this->assertSame( 'That plan is out of stock. Choose another plan.', $result['errors']['plan'] );
		$this->assertCount( 0, $this->server_lines() );
	}

	public function test_a_refused_post_keeps_the_values_on_the_page(): void {
		$this->send( array( ServerName::POST_KEY => 'keep-me', AppField::POST_APP => 'nextcloud' ) );
		$GLOBALS['wp']->query_vars['vps'] = 'new';
		ob_start();
		Servers::render( array() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="keep-me"', $html );
		$this->assertStringContainsString( 'Choose one of the listed apps, or none.', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
	}

	public function test_a_bad_nonce_a_missing_user_and_the_rate_limit_are_refused(): void {
		$result = ServerPicker::process( array_merge( $this->post(), array( ServerPicker::FIELD => 'nope' ) ), $this->user );
		$this->assertSame( '', $result['redirect'] );
		$this->assertArrayHasKey( 'form', $result['errors'] );

		$result = ServerPicker::process( $this->post(), 0 );
		$this->assertSame( '', $result['redirect'] );

		add_filter( 'beltoft_services_picker_rate_limit', static fn() => 1 );
		$this->assertSame( array(), $this->send()['errors'] );
		$this->assertArrayHasKey( 'form', $this->send()['errors'], 'The second try in the hour is over the limit.' );
		$this->assertCount( 1, $this->server_lines() );
	}

	public function test_a_site_rule_can_refuse_a_plan(): void {
		add_filter( 'beltoft_services_picker_can_buy', '__return_false' );
		$this->assertArrayHasKey( 'plan', $this->send()['errors'] );
		$this->assertCount( 0, $this->server_lines() );
	}

	// -- Checkout reads what was carried -------------------------------------------------------------

	/**
	 * Runs the checkout validation and the line item savers the way the checkout does, with the given post.
	 *
	 * @param array<string, string> $post Fields.
	 * @return array{errors: string[], meta: array<string, string>}
	 */
	private function checkout( array $post ): array {
		do_action( 'woocommerce_checkout_process' );

		foreach ( array( OsField::POST_KEY, AppField::POST_APP, AppField::POST_VERSION, AppField::POST_DOMAIN, AppField::POST_ZONE, AppField::POST_SUB, AppField::POST_OWN, ServerName::POST_KEY, SshKeyField::POST_KEY ) as $key ) {
			unset( $_POST[ $key ] );
		}

		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		$errors = new \WP_Error();
		OsField::validate_classic( array(), $errors );
		AppField::validate_classic( array(), $errors );
		ServerName::validate_classic( array(), $errors );
		SshKeyField::validate_classic( array(), $errors );

		$line = array_values( WC()->cart->get_cart() )[0];
		$item = new \WC_Order_Item_Product();
		$item->set_product_id( (int) $line['product_id'] );
		$item->set_variation_id( (int) $line['variation_id'] );

		foreach ( array( OsField::class, AppField::class, ServerName::class, SshKeyField::class ) as $class ) {
			$class::save_on_item( $item, '', $line );
		}

		$meta = array();

		foreach ( $item->get_meta_data() as $data ) {
			$meta[ (string) $data->key ] = (string) $data->value;
		}

		ksort( $meta );

		foreach ( array_keys( $post ) as $key ) {
			unset( $_POST[ $key ] );
		}

		return array(
			'errors' => $errors->get_error_messages(),
			'meta'   => $meta,
		);
	}

	public function test_checkout_without_the_fields_in_the_post_uses_the_carried_choices_and_saves_what_a_typed_form_saves(): void {
		$key = $this->ed25519();
		$this->send( array( AppField::POST_APP => 'docker', ServerName::POST_KEY => 'web-01', SshKeyField::POST_KEY => $key ) );
		$carried = $this->server_lines()[0][ Picked::KEY ];

		$skipped = $this->checkout( array() );
		$this->assertSame( array(), $skipped['errors'] );

		$typed = $this->checkout(
			array(
				OsField::POST_KEY     => $carried['os'],
				AppField::POST_APP    => $carried['app'],
				AppField::POST_VERSION => $carried['version'],
				ServerName::POST_KEY  => $carried['name'],
				SshKeyField::POST_KEY => $carried['ssh_key'],
			)
		);
		$this->assertSame( array(), $typed['errors'] );
		$this->assertSame( $typed['meta'], $skipped['meta'], 'The same order line meta either way.' );
		$this->assertSame( 'docker', $skipped['meta'][ AppField::ITEM_APP ] );
		$this->assertSame( 'web-01', $skipped['meta'][ ServerName::ITEM_META ] );
		$this->assertSame( '9001', $skipped['meta'][ OsField::ITEM_META ] );
		$this->assertArrayHasKey( SshKeyField::ITEM_META, $skipped['meta'] );
	}

	public function test_a_posted_value_wins_over_the_carried_one_and_an_empty_one_is_not_refilled(): void {
		$this->send( array( ServerName::POST_KEY => 'carried' ) );

		$this->assertSame( 'typed', $this->checkout( array( OsField::POST_KEY => '9000', ServerName::POST_KEY => 'typed' ) )['meta'][ ServerName::ITEM_META ] );
		$this->assertArrayNotHasKey( ServerName::ITEM_META, $this->checkout( array( OsField::POST_KEY => '9000', ServerName::POST_KEY => '' ) )['meta'], 'A customer who cleared the name does not get it back.' );
	}

	public function test_a_carried_choice_that_no_longer_holds_is_refused_at_checkout(): void {
		$this->send( array( AppField::POST_APP => 'docker' ) );
		$this->save_cluster( array( 'apps_ready' => 0, 'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'default_template' => '9000', 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml', 'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12" ) );

		$this->assertSame( array( 'Apps are not available for this server. Choose none.' ), $this->checkout( array() )['errors'] );
	}

	public function test_checkout_starts_its_fields_from_the_carried_choices(): void {
		$this->send(
			array(
				ServerPicker::PLAN    => (string) $this->v['b-None']->get_id(),
				OsField::POST_KEY     => '9001',
				ServerName::POST_KEY  => 'carried-name',
				SshKeyField::POST_KEY => $this->ed25519(),
				AppField::POST_APP    => 'coolify',
			)
		);

		ob_start();
		OsField::render_classic();
		AppField::render_classic();
		ServerName::render_classic();
		SshKeyField::render_classic();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#name="' . OsField::POST_KEY . '" value="9001"[^>]*checked#', $html );
		$this->assertMatchesRegularExpression( '#name="' . AppField::POST_APP . '" value="coolify"[^>]*checked#', $html );
		$this->assertStringContainsString( 'value="carried-name"', $html );
		$this->assertMatchesRegularExpression( '#<details class="beltoft-services-ssh-key" open>#', $html );
		$this->assertStringContainsString( 'ssh-ed25519 ', $html );
	}

	public function test_checkout_with_nothing_carried_is_what_it_was(): void {
		WC()->cart->add_to_cart( $this->product->get_id(), 1, $this->v['a-None']->get_id(), \BeltoftServices\Proxmox\BackupField::attributes( $this->v['a-None'] ) );

		ob_start();
		OsField::render_classic();
		ServerName::render_classic();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#name="' . OsField::POST_KEY . '" value="9000"[^>]*checked#', $html, 'The cluster default.' );
		$this->assertStringNotContainsString( 'value="carried', $html );
		$this->assertSame( array_fill_keys( array( 'os', 'app', 'version', 'tier', 'domain_on', 'zone', 'sub', 'own', 'name', 'ssh_key' ), '' ), Picked::for_cart() );
	}

	public function test_the_carried_data_is_cleaned(): void {
		$this->assertSame( array_fill_keys( array( 'os', 'app', 'version', 'tier', 'domain_on', 'zone', 'sub', 'own', 'name', 'ssh_key' ), '' ), Picked::clean( 'junk' ) );

		$clean = Picked::clean( array( 'os' => 9000, 'app' => array( 'x' ), 'evil' => 'x', 'name' => str_repeat( 'a', 500 ), 'domain_on' => '11' ) );
		$this->assertSame( '9000', $clean['os'] );
		$this->assertSame( '', $clean['app'], 'Not a string.' );
		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertSame( 120, strlen( $clean['name'] ) );
		$this->assertSame( '1', $clean['domain_on'] );
	}

	public function test_the_backups_box_at_checkout_keeps_the_carried_choices(): void {
		$this->send( array( ServerName::POST_KEY => 'keep' ) );
		$swap = \BeltoftServices\Proxmox\BackupField::swap( true );

		$this->assertTrue( $swap['ok'] );
		$this->assertSame( 'keep', $this->server_lines()[0][ Picked::KEY ]['name'] );
		$this->assertSame( $this->v['a-Daily']->get_id(), (int) $this->server_lines()[0]['variation_id'] );
	}
}
