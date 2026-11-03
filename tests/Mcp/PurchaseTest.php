<?php
/**
 * Buying a server through Claude: list_server_plans, and order_server in its two ways (a direct 0 order for a customer with a
 * usage account, a link for one without). FakeClient only; every address is the owner's; no card is ever charged.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Api\Tokens;
use BeltoftServices\BillingRequirements;
use BeltoftServices\Mcp\Access;
use BeltoftServices\Mcp\Purchase;
use BeltoftServices\Mcp\Sandbox;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Proxmox\SshKeyField;
use BeltoftServices\Settings;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';
require_once __DIR__ . '/CallsTools.php';

/**
 * Purchase tool tests.
 */
final class PurchaseTest extends UsageTestCase {

	use CallsTools;

	private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDqjJLYRRTB9Z55AmrMBnnZqXMgIfW4QWnlAurKuKSeI test@example';

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $v = array();

	/**
	 * The account of the customer with one (made first: renewal orders commit the test transaction).
	 *
	 * @var \WC_Subscription
	 */
	private $account;

	/**
	 * Customer with a usage account.
	 *
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();

		$this->user    = $this->new_user();
		// The hidden account product must exist before the account is made.
		$this->account = $this->usage_account( $this->user );
		$this->save_cluster( array( 'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'default_template' => '9000' ) );
		$this->v = $this->usage_product();
		$this->settings( array( 'offer_monthly' => 0, 'usage_new_limit' => 0 ) );
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		update_option( 'woocommerce_currency', 'EUR' );
		Offers::reset();
		$this->billing_details( $this->user );
	}

	public function tear_down(): void {
		// A checkout run in a process of its own leaves its rows behind for the next test: take the orders (and their items) away.
		foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
			$order->delete( true );
		}

		$this->mcp_tear_down();
		remove_all_filters( 'beltoft_services_picker_can_buy' );
		remove_all_filters( 'beltoft_services_mcp_order_limit' );
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	/**
	 * The billing details a service order needs, saved on the customer.
	 */
	private function billing_details( int $user ): void {
		$values = array(
			'billing_first_name' => 'Test',
			'billing_last_name'  => 'Customer',
			'billing_email'      => self::MAIL,
			'billing_phone'      => '+4512345678',
			'billing_address_1'  => 'Teststreet 1',
			'billing_city'       => 'Aarhus',
			'billing_postcode'   => '8000',
			'billing_country'    => 'DK',
		);

		foreach ( $values as $key => $value ) {
			update_user_meta( $user, $key, $value );
		}
	}

	private function plan_id(): int {
		return (int) $this->v['m-None']->get_id();
	}

	/**
	 * @param array<string, mixed> $over Arguments.
	 * @return array<string, mixed>
	 */
	private function args( array $over = array() ): array {
		return array_merge( array( 'plan' => (string) $this->plan_id(), 'os' => '9001', 'server_name' => 'web-one' ), $over );
	}

	/**
	 * The orders of a customer.
	 *
	 * @return \WC_Order[]
	 */
	private function orders( int $user ): array {
		return wc_get_orders( array( 'customer_id' => $user, 'type' => 'shop_order', 'limit' => -1 ) );
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} Summary result and confirmed result.
	 */
	private function order( array $args, string $token ): array {
		$first = $this->tool( 'order_server', $args, $token );

		if ( $first['isError'] ) {
			return array( $first, $first );
		}

		$summary = $this->json( $first );

		if ( empty( $summary['requires_confirmation'] ) ) {
			return array( $summary, $first );
		}

		return array( $summary, $this->tool( 'order_server', $args + array( 'confirm' => $summary['confirm'] ), $token ) );
	}

	// -- list_server_plans --------------------------------------------------------------------

	public function test_the_plans_are_listed_for_a_reading_connection(): void {
		$read = $this->oauth( $this->user, Tokens::READ );
		$out  = $this->json( $this->tool( 'list_server_plans', array(), $read ) );

		$this->assertSame( 'EUR', $out['currency'] );
		$this->assertCount( 1, $out['plans'], 'Only plans sold pay as you go.' );

		$plan = $out['plans'][0];
		$this->assertSame( $this->plan_id(), $plan['id'] );
		$this->assertSame( 2, $plan['specs']['cpu_cores'] );
		$this->assertSame( 4096, $plan['specs']['memory_mb'] );
		$this->assertEqualsWithDelta( 15 * 1.1 / 730, $plan['price']['hourly'], 0.0001 );
		$this->assertSame( 16.5, $plan['price']['max_per_month'] );
		$this->assertStringContainsString( 'per hour, max', $plan['price']['text'] );
		$this->assertStringContainsString( 'billed monthly for the hours used', $plan['price']['text'] );
		$this->assertTrue( $plan['backups']['available'] );
		$this->assertContains( 9001, array_column( $plan['systems'], 'id' ) );
		$this->assertStringNotContainsString( '—', wp_json_encode( $out ) );
	}

	public function test_a_plan_with_no_pay_as_you_go_offer_is_not_listed(): void {
		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();
		Offers::reset();

		$out = $this->json( $this->tool( 'list_server_plans', array(), $this->oauth( $this->user, Tokens::READ ) ) );
		$this->assertSame( array(), $out['plans'] );
	}

	// -- Levels and the confirmation ------------------------------------------------------------

	public function test_only_full_access_can_order_and_only_full_sees_the_tool(): void {
		foreach ( array( Tokens::READ, Tokens::ACTIONS ) as $level ) {
			$token = $this->oauth( $this->user, $level );
			$this->assertNotContains( 'order_server', array_column( $this->rpc( 'tools/list', array(), $token )['result']['tools'], 'name' ) );
			$this->assertTrue( $this->tool( 'order_server', $this->args(), $token )['isError'], $level );
		}

		$personal = $this->personal( $this->user, Tokens::ACTIONS );
		$this->assertTrue( $this->tool( 'order_server', $this->args(), $personal )['isError'] );
		$this->assertSame( array(), $this->orders( $this->user ), 'Nothing was ordered.' );

		$full = $this->oauth( $this->user, Tokens::FULL );
		$this->assertContains( 'order_server', array_column( $this->rpc( 'tools/list', array(), $full )['result']['tools'], 'name' ) );
	}

	public function test_the_summary_says_plan_price_choices_and_what_happens_next_and_changes_nothing(): void {
		$token   = $this->oauth( $this->user, Tokens::FULL );
		$summary = $this->json( $this->tool( 'order_server', $this->args( array( 'ssh_key' => self::KEY ) ), $token ) );

		$this->assertTrue( $summary['requires_confirmation'] );
		$this->assertStringStartsWith( 'bsc_', $summary['confirm'] );
		$text = $summary['summary'];
		$this->assertStringContainsString( '2 CPU cores', $text );
		$this->assertStringContainsString( '4 GB RAM', $text );
		$this->assertStringContainsString( 'Ubuntu 24.04', $text );
		$this->assertStringContainsString( 'web-one', $text );
		$this->assertStringContainsString( 'SSH key: yes', $text );
		$this->assertStringContainsString( "\u{20AC}0.0226 per hour, max \u{20AC}16.50 per month, billed monthly for the hours used", $text );
		$this->assertStringContainsString( 'Nothing is charged now', $text );
		$this->assertStringContainsString( 'server_status', $text );
		$this->assertStringNotContainsString( self::KEY, $text, 'The key itself is not echoed.' );
		$this->assertSame( array(), $this->orders( $this->user ), 'A summary orders nothing.' );
		$this->assertSame( array(), Licenses::where( 'user_id', $this->user ) );
	}

	// -- The direct path -----------------------------------------------------------------------------

	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_customer_with_an_account_gets_the_server_with_no_payment(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );
		wp_set_current_user( 0 );
		$args  = $this->args( array( 'ssh_key' => self::KEY ) );

		list( , $done ) = $this->order( $args, $token );
		$out            = $this->json( $done );

		$this->assertTrue( $out['ordered'] );
		$orders = $this->orders( $this->user );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( $order->get_id(), $out['order_id'] );
		$this->assertEquals( 0, $order->get_total() );
		$this->assertSame( '', $order->get_payment_method() );
		$this->assertContains( $order->get_status(), array( 'processing', 'completed' ) );
		$this->assertNotSame( '', (string) $order->get_meta( Purchase::ORDER_META ), 'The order is marked as placed through the connected app.' );

		// The line item carries what a picker order carries.
		$item = array_values( $order->get_items() )[0];
		$this->assertSame( (string) $this->v['u-None']->get_id(), (string) $item->get_variation_id() );
		$this->assertSame( '9001', (string) $item->get_meta( OsField::ITEM_META ) );
		$this->assertSame( 'web-one', (string) $item->get_meta( ServerName::ITEM_META ) );
		$this->assertSame( SshKeyField::normalise( self::KEY ), (string) $item->get_meta( SshKeyField::ITEM_META ) );

		// No second account line: the customer has one.
		$this->assertCount( 1, $order->get_items() );

		// The normal license and resource follow.
		$licenses = Licenses::where( 'order_id', $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( 'usage', $licenses[0]['source'] );
		$this->assertSame( $this->account->get_id(), (int) $licenses[0]['subscription_id'] );
		$this->assertNotNull( $out['service_id'] );
		$line = 'Claude: ordered server ' . (string) Offers::find( $this->plan_id() )['name'] . ' (order #' . $order->get_id() . ')';
		$this->assertContains( $line, array_column( Events::for_license( (int) $licenses[0]['id'] ), 'message' ), 'The trail of the new service says it.' );
		$this->assertContains( $line, array_map( static fn( $l ) => $l['text'], \BeltoftServices\Mcp\Audit::lines( $this->user ) ) );

		$this->run_jobs();
		$this->assertNotEmpty( $this->tool_mails, 'The customer is told by mail.' );
	}

	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_order_has_the_same_meta_as_an_order_from_the_picker(): void {
		// The dashboard's way: the picker fills the cart, WooCommerce's checkout places it, for another customer with an account.
		$other = $this->new_user();
		$this->usage_account( $other );
		$this->billing_details( $other );
		wp_set_current_user( $other );
		$dash = Sandbox::run(
			$other,
			array(),
			function ( \WC_Cart $cart ) use ( $other ) {
				$done = ServerPicker::process(
					array(
						ServerPicker::FIELD   => wp_create_nonce( ServerPicker::NONCE ),
						ServerPicker::PLAN    => (string) $this->plan_id(),
						ServerPicker::BILLING => 'usage',
						OsField::POST_KEY     => '9001',
						ServerName::POST_KEY  => 'web-one',
						SshKeyField::POST_KEY => self::KEY,
					),
					$other
				);
				$this->assertSame( array(), $done['errors'] );
				$checkout = WC()->checkout();
				$form     = array( 'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ), 'payment_method' => '' );

				foreach ( array_keys( $checkout->get_checkout_fields( 'billing' ) ) as $key ) {
					$form[ $key ] = (string) $checkout->get_value( $key );
				}

				$_POST    = $form;
				$_REQUEST = $form;

				return Sandbox::checkout();
			}
		);
		$this->assertSame( 'success', $dash['result'], implode( ' ', $dash['messages'] ) );

		$token = $this->oauth( $this->user, Tokens::FULL );
		list( , $done ) = $this->order( $this->args( array( 'ssh_key' => self::KEY ) ), $token );
		$this->json( $done );

		$meta = static function ( \WC_Order $o ): array {
			$item = array_values( $o->get_items() )[0];
			$out  = array();

			foreach ( $item->get_meta_data() as $m ) {
				$out[ $m->key ] = is_scalar( $m->value ) ? (string) $m->value : wp_json_encode( $m->value );
			}

			unset( $out['_beltoft_licenses_ids'] );
			ksort( $out );

			return array( 'variation' => $item->get_variation_id(), 'meta' => $out, 'total' => (float) $o->get_total(), 'items' => count( $o->get_items() ) );
		};

		$this->assertSame( $meta( wc_get_order( $dash['order_id'] ) ), $meta( $this->orders( $this->user )[0] ) );
	}

	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_backups_and_an_app_go_the_pickers_way(): void {
		$this->save_cluster( array( 'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'default_template' => '9000', 'apps_ready' => 1, 'apps_enabled' => array( 'docker' ), 'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12", 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml' ) );
		$token = $this->oauth( $this->user, Tokens::FULL );
		list( $sum, $done ) = $this->order( $this->args( array( 'backups' => true ) ), $token );
		$this->assertStringContainsString( 'Daily backups: yes', $sum['summary'] );
		$this->json( $done );
		$item = array_values( $this->orders( $this->user )[0]->get_items() )[0];
		$this->assertSame( (string) $this->v['u-Daily']->get_id(), (string) $item->get_variation_id() );
	}

	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_customers_own_cart_is_untouched(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );
		wp_set_current_user( $this->user );
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Other' );
		$simple->set_regular_price( '5' );
		$simple->save();
		WC()->cart->add_to_cart( $simple->get_id() );
		$before = array_keys( WC()->cart->get_cart() );
		$this->assertNotEmpty( $before );

		list( , $done ) = $this->order( $this->args(), $token );
		$this->json( $done );

		wp_set_current_user( $this->user );
		$this->assertSame( $before, array_keys( WC()->cart->get_cart() ), 'The cart is as it was.' );
		$this->assertCount( 1, $this->orders( $this->user ) );
		$this->assertSame( 'Other', array_values( WC()->cart->get_cart() )[0]['data']->get_name() );
	}

	// -- The browser path ----------------------------------------------------------------------------

	public function test_a_customer_without_an_account_gets_a_link_and_nothing_is_created(): void {
		$new = $this->new_user();
		$this->billing_details( $new );
		$token = $this->oauth( $new, Tokens::FULL );

		$out = $this->json( $this->tool( 'order_server', $this->args( array( 'backups' => true, 'ssh_key' => self::KEY ) ), $token ) );

		$this->assertFalse( $out['ordered'] );
		$this->assertArrayNotHasKey( 'confirm', $out, 'No token: nothing is done that needs a yes.' );
		$this->assertStringContainsString( 'once in the browser', $out['message'] );
		$url = $out['checkout_url'];
		$this->assertStringContainsString( 'plan=' . $this->plan_id(), $url );
		$this->assertStringContainsString( OsField::POST_KEY . '=9001', $url );
		$this->assertStringContainsString( ServerPicker::BACKUP . '=1', $url );
		$this->assertStringContainsString( ServerName::POST_KEY . '=web-one', $url );
		$this->assertSame( array(), $this->orders( $new ) );
		$this->assertSame( array(), Licenses::where( 'user_id', $new ) );
		$this->assertNull( \BeltoftServices\Usage\Account::for_user( $new ) );
	}

	public function test_the_link_fills_the_picker_in(): void {
		$new = $this->new_user();
		wp_set_current_user( $new );
		$_GET = array( 'plan' => (string) $this->plan_id(), OsField::POST_KEY => '9001', ServerName::POST_KEY => 'web-one' );
		ob_start();
		ServerPicker::render();
		$html = (string) ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( 'value="web-one"', $html );
		$this->assertMatchesRegularExpression( '/value="9001"[^>]*checked/', $html );
	}

	// -- Refusals ------------------------------------------------------------------------------------

	/**
	 * @return array<string, mixed> The refusal text of a summary call.
	 */
	private function refused( array $args, ?int $user = null ): string {
		$token = $this->oauth( $user ?? $this->user, Tokens::FULL );
		$res   = $this->tool( 'order_server', $args, $token );
		$this->assertTrue( $res['isError'], $this->text( $res ) );

		return $this->text( $res );
	}

	public function test_what_the_picker_refuses_is_refused(): void {
		$this->assertStringContainsString( 'not offered', $this->refused( $this->args( array( 'plan' => '999999' ) ) ) );
		$this->assertStringContainsString( 'not offered', $this->refused( $this->args( array( 'plan' => 'No such plan' ) ) ) );
		$this->assertStringContainsString( 'operating system', $this->refused( $this->args( array( 'os' => '12345' ) ) ) );
		$this->assertStringContainsString( 'operating system', $this->refused( array( 'plan' => (string) $this->plan_id() ) ) );
		$this->assertStringContainsString( 'hostname', $this->refused( $this->args( array( 'server_name' => 'bad name!.' ) ) ) );
		$this->assertStringContainsString( 'key', strtolower( $this->refused( $this->args( array( 'ssh_key' => 'not a key' ) ) ) ) );
		$this->assertStringContainsString( 'app', strtolower( $this->refused( $this->args( array( 'app' => 'nosuchapp' ) ) ) ) );
		$this->assertSame( array(), $this->orders( $this->user ) );
	}

	public function test_a_plan_by_name_works_and_one_without_a_pay_as_you_go_offer_is_refused(): void {
		$name = (string) Offers::find( $this->plan_id() )['name'];
		$sum  = $this->json( $this->tool( 'order_server', $this->args( array( 'plan' => $name ) ), $this->oauth( $this->user, Tokens::FULL ) ) );
		$this->assertTrue( $sum['requires_confirmation'] );

		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();
		Offers::reset();
		$this->assertStringContainsString( 'not sold pay as you go', $this->refused( $this->args() ) );
	}

	public function test_stock_and_the_sites_rule_stop_an_order(): void {
		add_filter( 'beltoft_services_picker_can_buy', '__return_false' );
		$this->assertStringContainsString( 'cannot be ordered right now', $this->refused( $this->args() ) );
		remove_all_filters( 'beltoft_services_picker_can_buy' );

		$this->v['m-None']->set_stock_status( 'outofstock' );
		$this->v['m-None']->save();
		Offers::reset();
		$this->assertStringContainsString( 'out of stock', $this->refused( $this->args() ) );
	}

	public function test_an_account_on_hold_and_the_new_account_limit_stop_an_order(): void {
		$this->account->update_status( 'on-hold' );
		$this->assertStringContainsString( 'unpaid invoice', $this->refused( $this->args() ) );
		$this->account->update_status( 'active' );

		$this->settings( array( 'usage_new_limit' => 1 ) );
		$this->usage_vm( $this->v['u-None'], $this->user, 1, $this->account->get_id() );
		$this->assertStringContainsString( 'A new account can run 1 pay as you go service', $this->refused( $this->args() ) );

		// The limit also holds for a customer who has no account yet.
		$new = $this->new_user();
		$this->usage_vm( $this->v['u-None'], $new, 1 );
		$this->assertStringContainsString( 'A new account can run 1 pay as you go service', $this->refused( $this->args(), $new ) );
	}

	public function test_an_account_without_a_saved_mandate_is_refused_with_the_dashboard_as_the_way(): void {
		add_filter( 'woocommerce_subscription_is_manual', '__return_false' );
		$this->account->set_payment_method( 'mollie_wc_gateway_creditcard' );
		$this->account->save();
		$text = $this->refused( $this->args() );
		$this->assertStringContainsString( 'cannot be charged without you', $text );
		$this->assertSame( array(), $this->orders( $this->user ) );

		$this->account->update_meta_data( '_mollie_customer_id', 'cst_test' );
		$this->account->update_meta_data( '_mollie_mandate_id', 'mdt_test' );
		$this->account->save();
		$sum = $this->json( $this->tool( 'order_server', $this->args(), $this->oauth( $this->user, Tokens::FULL ) ) );
		$this->assertTrue( $sum['requires_confirmation'], 'With a customer and a mandate it goes the direct way.' );
	}

	public function test_incomplete_billing_details_are_refused(): void {
		delete_user_meta( $this->user, 'billing_phone' );
		$text = $this->refused( $this->args() );
		$this->assertStringContainsString( 'billing details are incomplete', $text );
		$this->assertStringContainsString( 'phone', $text );
	}

	public function test_site_billing_policy_also_applies_to_direct_purchase_eligibility(): void {
		delete_user_meta( $this->user, 'billing_phone' );
		$without_phone = static fn( $fields ) => array_values( array_diff( $fields, array( 'billing_phone' ) ) );
		add_filter( 'beltoft_services_required_billing_fields', $without_phone );
		try {
			$state = Purchase::account_state( $this->user, 'https://example.org/account/' );
			$this->assertIsArray( $state );
			$this->assertTrue( $state['direct'] );
			$this->assertSame( array(), $this->orders( $this->user ), 'Eligibility must not place an order.' );
		} finally {
			remove_filter( 'beltoft_services_required_billing_fields', $without_phone );
		}
	}

	public function test_nothing_that_costs_money_is_placed(): void {
		$this->v['u-None']->set_regular_price( '3' );
		$this->v['u-None']->save();
		Offers::reset();
		$this->assertStringContainsString( 'cannot be ordered pay as you go through Claude', $this->refused( $this->args() ) );
		$this->assertSame( array(), $this->orders( $this->user ) );
	}

	public function test_the_customers_own_switch_stops_every_call(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );
		Access::set_user( $this->user, false );
		$reply = $this->post( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'order_server', 'arguments' => $this->args() ) ), $token );
		$this->assertGreaterThanOrEqual( 400, $reply->get_status() );
		$this->assertSame( array(), $this->orders( $this->user ) );

		Access::set_user( $this->user, true );
	}

	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hourly_limit_stops_a_run_of_orders(): void {
		add_filter( 'beltoft_services_mcp_order_limit', static fn() => 1 );
		$token = $this->oauth( $this->user, Tokens::FULL );
		list( , $one ) = $this->order( $this->args( array( 'server_name' => 'one' ) ), $token );
		$this->json( $one );
		list( , $two ) = $this->order( $this->args( array( 'server_name' => 'two' ) ), $token );
		$this->assertTrue( $two['isError'] );
		$this->assertStringContainsString( 'Too many servers', $this->text( $two ) );
		$this->assertCount( 1, $this->orders( $this->user ) );
	}

	// -- The token --------------------------------------------------------------------------------------

	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_confirmation_works_once_and_only_for_these_arguments(): void {
		$token   = $this->oauth( $this->user, Tokens::FULL );
		$args    = $this->args();
		$summary = $this->json( $this->tool( 'order_server', $args, $token ) );

		// Other arguments, another customer: refused and nothing ordered.
		$this->assertTrue( $this->tool( 'order_server', $this->args( array( 'server_name' => 'other' ) ) + array( 'confirm' => $summary['confirm'] ), $token )['isError'] );
		$other = $this->new_user();
		$this->usage_account( $other );
		$this->billing_details( $other );
		$thief = $this->oauth( $other, Tokens::FULL );
		$this->assertTrue( $this->tool( 'order_server', $args + array( 'confirm' => $summary['confirm'] ), $thief )['isError'] );
		$this->assertSame( array(), $this->orders( $this->user ) );

		$this->json( $this->tool( 'order_server', $args + array( 'confirm' => $summary['confirm'] ), $token ) );
		$this->assertCount( 1, $this->orders( $this->user ) );

		$again = $this->tool( 'order_server', $args + array( 'confirm' => $summary['confirm'] ), $token );
		$this->assertTrue( $again['isError'] );
		$this->assertStringContainsString( 'used already', $this->text( $again ) );
		$this->assertCount( 1, $this->orders( $this->user ), 'A replay orders nothing.' );

		$bogus = $this->tool( 'order_server', $args + array( 'confirm' => 'bsc_nonsense' ), $token );
		$this->assertTrue( $bogus['isError'] );
	}

	public function test_a_price_change_between_summary_and_confirmation_orders_nothing(): void {
		$token   = $this->oauth( $this->user, Tokens::FULL );
		$summary = $this->json( $this->tool( 'order_server', $this->args(), $token ) );

		$this->v['m-None']->set_regular_price( '20' );
		$this->v['m-None']->save();
		Offers::reset();

		$res = $this->tool( 'order_server', $this->args() + array( 'confirm' => $summary['confirm'] ), $token );
		$this->assertTrue( $res['isError'] );
		$this->assertStringContainsString( 'Things changed', $this->text( $res ) );
		$this->assertSame( array(), $this->orders( $this->user ) );
	}
}
