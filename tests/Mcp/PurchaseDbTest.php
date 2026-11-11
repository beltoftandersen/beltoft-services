<?php
/**
 * Buying a managed database through Claude: order_database, with the confirmation and the breakdown in it. FakeClient only;
 * no card is ever charged.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Licenses;
use BeltoftServices\Account\DbPicker;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\Endpoint;
use BeltoftServices\Mcp\Purchase;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';
require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/DatabaseFixture.php';

/**
 * Purchase tool tests for databases.
 */
final class PurchaseDbTest extends UsageTestCase {

	use CallsTools;
	use DatabaseFixture;

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $db = array();

	/**
	 * @var \WC_Subscription
	 */
	private $account;

	/**
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();
		// The shop currency first: the usage account is made in it, and the MCP call is answered in the account's currency.
		update_option( 'woocommerce_currency', 'EUR' );
		$this->seed_private_nets();

		$this->user    = $this->new_user();
		$this->account = $this->usage_account( $this->user );
		$this->save_cluster( array( 'templates' => '9000 = Debian 12', 'default_template' => '9000' ) );
		// A fresh reading of the storage first, so the extra storage steps are in stock when they are saved.
		$key = ( new \ReflectionMethod( \BeltoftServices\Proxmox\Pve\DiskCapacity::class, 'cache_key' ) );
		$key->setAccessible( true );
		set_transient( $key->invoke( null, 'lab', 'node-a', 'disk-store' ), array( 'total' => 5000 * 1073741824, 'avail' => 4000 * 1073741824, 'read_at' => time() ), HOUR_IN_SECONDS );
		$this->db = $this->database_product();
		$this->settings( array( 'offer_monthly' => 0, 'usage_new_limit' => 0, 'db_disk_max_gb' => 1000, 'db_disk_step_gb' => 10 ) );
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		update_option( 'woocommerce_currency', 'EUR' );
		Offers::reset();

		foreach ( array( 'billing_first_name' => 'Test', 'billing_last_name' => 'Customer', 'billing_email' => self::MAIL, 'billing_phone' => '+4512345678', 'billing_address_1' => 'Teststreet 1', 'billing_city' => 'Aarhus', 'billing_postcode' => '8000', 'billing_country' => 'DK' ) as $key => $value ) {
			update_user_meta( $this->user, $key, $value );
		}
	}

	public function tear_down(): void {
		foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
			$order->delete( true );
		}

		$this->mcp_tear_down();
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	private function plan_id(): int {
		return (int) $this->db['m-0-0']->get_id();
	}

	/**
	 * @param array<string, mixed> $over Arguments.
	 * @return array<string, mixed>
	 */
	private function args( array $over = array() ): array {
		return array_merge( array( 'plan' => (string) $this->plan_id() ), $over );
	}

	/**
	 * @return \WC_Order[]
	 */
	private function orders( int $user ): array {
		return wc_get_orders( array( 'customer_id' => $user, 'type' => 'shop_order', 'limit' => -1 ) );
	}

	public function test_the_summary_has_the_breakdown_and_orders_nothing(): void {
		$token   = $this->oauth( $this->user, Tokens::FULL );
		$summary = $this->json( $this->tool( 'order_database', $this->args( array( 'extra_storage_gb' => 50 ) ), $token ) );

		$this->assertTrue( $summary['requires_confirmation'] );
		$text = $summary['summary'];
		$this->assertStringContainsString( 'postgres 18', $text );
		$this->assertStringContainsString( 'Extra storage: 50 GB', $text );
		$this->assertStringContainsString( 'Public IPv4 address: no', $text );
		$this->assertStringContainsString( '€45 / month', $text );
		$this->assertStringContainsString( '+€4 / month', $text );
		$this->assertStringContainsString( 'Max per month: €49 (excluding VAT)', $text );
		$this->assertStringContainsString( '€0.0671 per hour', $text );
		$this->assertStringContainsString( 'Nothing is charged now', $text );
		$this->assertStringContainsString( 'VAT is added on the invoice', $text );
		$this->assertStringNotContainsString( '—', $text );
		$this->assertStringNotContainsString( '·', $text );
		$this->assertSame( array(), $this->orders( $this->user ) );
		$this->assertSame( array(), Licenses::where( 'user_id', $this->user ) );
	}

	public function test_only_full_access_can_order_a_database(): void {
		foreach ( array( Tokens::READ, Tokens::ACTIONS ) as $level ) {
			$token = $this->oauth( $this->user, $level );
			$this->assertNotContains( 'order_database', array_column( $this->rpc( 'tools/list', array(), $token )['result']['tools'], 'name' ) );
			$this->assertTrue( $this->tool( 'order_database', $this->args(), $token )['isError'], $level );
		}

		$this->assertTrue( $this->tool( 'order_database', $this->args(), $this->personal( $this->user, Tokens::ACTIONS ) )['isError'] );
		$this->assertContains( 'order_database', array_column( $this->rpc( 'tools/list', array(), $this->oauth( $this->user, Tokens::FULL ) )['result']['tools'], 'name' ) );
		$this->assertSame( array(), $this->orders( $this->user ) );
	}

	public function test_an_out_of_stock_plan_is_refused(): void {
		$this->db['m-0-0']->set_stock_status( 'outofstock' );
		$this->db['m-0-0']->save();
		Offers::reset();

		$out = $this->tool( 'order_database', $this->args(), $this->oauth( $this->user, Tokens::FULL ) );
		$this->assertTrue( $out['isError'] );
		$this->assertStringContainsString( 'out of stock', wp_json_encode( $out ) );
		$this->assertSame( array(), $this->orders( $this->user ) );
	}

	public function test_no_private_network_for_the_customer_refuses_the_order_and_marks_the_plan_unavailable(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );
		$this->seed_private_nets( 0 );
		Offers::reset();

		$plans = $this->json( $this->tool( 'catalog', array( 'kind' => 'database_plans' ), $token ) )['plans'];
		$this->assertFalse( $plans[0]['in_stock'] );

		$out = $this->tool( 'order_database', $this->args(), $token );
		$this->assertTrue( $out['isError'] );
		$this->assertStringContainsString( 'New databases are not available right now. Please contact us.', wp_json_encode( $out ) );
		$this->assertSame( array(), $this->orders( $this->user ) );

		$this->seed_private_nets( 1 );
		Offers::reset();
		$this->assertTrue( $this->json( $this->tool( 'catalog', array( 'kind' => 'database_plans' ), $token ) )['plans'][0]['in_stock'] );
		$this->assertTrue( $this->json( $this->tool( 'order_database', $this->args(), $token ) )['requires_confirmation'] );
	}

	public function test_a_plan_with_no_pay_as_you_go_price_is_never_ordered_as_a_fixed_monthly_one(): void {
		$this->db['u-0-0']->set_status( 'private' );
		$this->db['u-0-0']->save();

		$out = $this->tool( 'order_database', $this->args(), $this->oauth( $this->user, Tokens::FULL ) );
		$this->assertTrue( $out['isError'] );
		$this->assertStringContainsString( 'not sold pay as you go', wp_json_encode( $out ) );
		$this->assertSame( array(), $this->orders( $this->user ) );
	}

	public function test_a_choice_the_plan_does_not_offer_is_refused(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );

		$this->assertTrue( $this->tool( 'order_database', $this->args( array( 'extra_storage_gb' => 7 ) ), $token )['isError'] );
		$this->assertTrue( $this->tool( 'order_database', array( 'plan' => '999999' ), $token )['isError'] );
	}

	public function test_a_public_ipv4_is_refused_while_no_address_is_free(): void {
		set_transient( \BeltoftServices\Proxmox\Network\IpPool::FREE_TRANSIENT, 0, MINUTE_IN_SECONDS );
		$out = $this->tool( 'order_database', $this->args( array( 'public_ipv4' => true ) ), $this->oauth( $this->user, Tokens::FULL ) );

		$this->assertTrue( $out['isError'] );
		$this->assertStringContainsString( 'No public IPv4 address is free', wp_json_encode( $out ) );
	}

	public function test_a_customer_without_a_usage_account_gets_a_link_and_nothing_is_ordered(): void {
		$other = $this->new_user();
		$token = $this->oauth( $other, Tokens::FULL );

		$out = $this->json( $this->tool( 'order_database', $this->args(), $token ) );
		$this->assertArrayNotHasKey( 'requires_confirmation', $out, 'No confirmation for a call that changes nothing.' );
		$this->assertFalse( $out['ordered'] );
		$this->assertSame( DbPicker::url(), $out['checkout_url'] );
		$this->assertStringContainsString( 'Nothing was ordered', $out['message'] );
		$this->assertSame( array(), $this->orders( $other ) );
		$this->assertSame( array(), Licenses::where( 'user_id', $other ) );
	}

	public function test_the_instructions_say_servers_and_databases_can_be_ordered(): void {
		$full = Endpoint::instructions( Tokens::FULL );
		$this->assertStringContainsString( 'order_server', $full );
		$this->assertStringContainsString( 'order_database', $full );
		$this->assertStringNotContainsString( 'Buying is not available yet', $full );
		$this->assertStringContainsString( 'ordering servers and databases', Endpoint::instructions( Tokens::ACTIONS ) );
		$this->assertStringNotContainsString( '—', $full );
	}

	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_customer_with_an_account_gets_the_database_with_the_chosen_storage_and_no_payment(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );
		wp_set_current_user( 0 );
		$args  = $this->args( array( 'extra_storage_gb' => 50 ) );

		$first = $this->tool( 'order_database', $args, $token );
		$this->assertFalse( $first['isError'], wp_json_encode( $first ) );
		$sum  = $this->json( $first );
		$done = $this->json( $this->tool( 'order_database', $args + array( 'confirm' => $sum['confirm'] ), $token ) );

		$this->assertTrue( $done['ordered'], wp_json_encode( $done ) );
		$orders = $this->orders( $this->user );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertEquals( 0, $order->get_total() );
		$this->assertNotSame( '', (string) $order->get_meta( Purchase::ORDER_META ) );

		$item = array_values( $order->get_items() )[0];
		$this->assertSame( (string) $this->db['u-50-0']->get_id(), (string) $item->get_variation_id(), 'The pay as you go variation with 50 GB, never a monthly one.' );
		$this->assertCount( 1, $order->get_items() );

		$licenses = Licenses::where( 'order_id', $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( 'usage', $licenses[0]['source'] );
		$this->assertSame( $this->account->get_id(), (int) $licenses[0]['subscription_id'] );
		$this->assertNotNull( $done['service_id'] );
	}
}
