<?php
/**
 * What the MCP tools say about prices: pay as you go first, add-ons, the quote, the card and the credit. Amounts must be
 * the ones Breakdown gives the checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Credit\Balance;
use BeltoftServices\Mcp\Endpoint;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Tests\Usage\UsageTestCase;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Rates;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';
require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/DatabaseFixture.php';

/**
 * Pricing text of the MCP tools.
 */
final class PricingTest extends UsageTestCase {

	use CallsTools;
	use DatabaseFixture;

	/**
	 * @var int
	 */
	private $user = 0;

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $server = array();

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $db = array();

	public function set_up(): void {
		parent::set_up();

		$this->user = $this->new_user();
		$this->usage_account( $this->user );
		$this->save_cluster( array( 'templates' => "9000 = Debian 12", 'default_template' => '9000' ) );
		$this->server = $this->usage_product( '0' );
		$this->db     = $this->database_product();
		$this->settings( array( 'offer_monthly' => 0, 'usage_new_limit' => 0, 'db_disk_max_gb' => 1000, 'db_disk_step_gb' => 10, 'credit_enabled' => 1 ) );
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		update_option( 'woocommerce_currency', 'EUR' );
		Offers::reset();
	}

	public function tear_down(): void {
		$this->mcp_tear_down();
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	private function read(): string {
		return $this->oauth( $this->user, Tokens::READ );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function call( string $tool, array $args = array() ): array {
		return $this->json( $this->tool( $tool, $args, $this->read() ) );
	}

	public function test_database_plans_lead_with_pay_as_you_go_and_list_storage_and_ip_options(): void {
		$out  = $this->call( 'catalog', array( 'kind' => 'database_plans' ) );
		$plan = $out['plans'][0];

		$this->assertSame( (int) $this->db['m-0-0']->get_id(), $plan['variation_id'] );
		$this->assertEquals( 45.0, $plan['price']['max_per_month'] );
		$this->assertEqualsWithDelta( 45 / 730, $plan['price']['hourly'], 0.0001 );
		$this->assertTrue( $plan['price']['excl_vat'] );
		$this->assertSame( 'EUR', $plan['price']['currency'] );
		$this->assertStringContainsString( 'per hour, max', $plan['price']['text'] );
		$this->assertArrayNotHasKey( 'monthly', $plan, 'Monthly is hidden.' );
		$this->assertArrayNotHasKey( 'period', $plan );

		$ip = $plan['options']['public_ipv4'];
		$this->assertEquals( 5.0, $ip['max_per_month'] );
		$this->assertEqualsWithDelta( 5 / 730, $ip['hourly'], 0.0001 );
		$this->assertStringContainsString( '+€5 per month', $ip['text'] );

		$this->assertCount( 1, $plan['options']['extra_storage'] );
		$step = $plan['options']['extra_storage'][0];
		$this->assertSame( 50, $step['extra_gb'] );
		$this->assertSame( 90, $step['data_disk_gb'] );
		$this->assertEquals( 4.0, $step['adds']['max_per_month'] );
		$this->assertEqualsWithDelta( 4 / 730, $step['adds']['hourly'], 0.0001 );
		$this->assertSame( 1000, $plan['options']['max_data_disk_gb'] );
		$this->assertStringContainsString( 'added later in the dashboard', $plan['options']['storage_note'] );
		$this->assertStringContainsString( 'never smaller', $plan['options']['storage_note'] );
		$this->assertStringContainsString( 'order_database', $out['note'] );
	}

	public function test_monthly_is_listed_only_when_the_site_offers_it(): void {
		$this->settings( array( 'offer_monthly' => 1 ) );
		$plan = $this->call( 'catalog', array( 'kind' => 'database_plans' ) )['plans'][0];
		$this->assertEquals( 45.0, $plan['monthly']['price'] );

		$this->settings( array( 'offer_monthly' => 0 ) );
		$this->assertArrayNotHasKey( 'monthly', $this->call( 'catalog', array( 'kind' => 'database_plans' ) )['plans'][0] );
	}

	public function test_a_plan_without_a_pay_as_you_go_price_is_not_listed_when_monthly_is_hidden(): void {
		$this->db['u-0-0']->set_status( 'private' );
		$this->db['u-0-0']->save();
		$this->server['u-None']->set_status( 'private' );
		$this->server['u-None']->save();
		Offers::reset();

		$this->assertSame( array(), $this->call( 'catalog', array( 'kind' => 'database_plans' ) )['plans'] );
		$this->assertSame( array(), $this->call( 'catalog', array( 'kind' => 'server_plans' ) )['plans'] );
	}

	public function test_server_plans_in_catalog_have_the_same_price_shape_and_backups_as_an_add_on(): void {
		$plan = $this->call( 'catalog', array( 'kind' => 'server_plans' ) )['plans'][0];

		$this->assertEquals( 15.0, $plan['price']['max_per_month'] );
		$this->assertEqualsWithDelta( 15 / 730, $plan['price']['hourly'], 0.0001 );
		$this->assertTrue( $plan['price']['excl_vat'] );
		$this->assertArrayNotHasKey( 'pay_as_you_go', $plan );
		$this->assertArrayNotHasKey( 'with_backups', $plan );
		$this->assertTrue( $plan['backups']['available'] );
		$this->assertEquals( 3.0, $plan['backups']['adds']['max_per_month'] );
		$this->assertEquals( 18.0, $plan['backups']['total_with_backups']['max_per_month'] );

		$listed = $this->call( 'list_server_plans' )['plans'][0];
		$this->assertSame( $plan['price'], $listed['price'] );
		$this->assertEquals( 3.0, $listed['backups']['adds']['max_per_month'] );
	}

	public function test_the_quote_of_a_database_with_ip_and_50_gb_is_the_checkouts_breakdown(): void {
		$quote = $this->call( 'price_quote', array( 'plan' => $this->db['m-0-0']->get_id(), 'public_ipv4' => true, 'extra_storage_gb' => 50 ) );
		$parts = Breakdown::parts( $this->db['u-50-1'] );

		$this->assertEquals( 54.0, $quote['max_per_month'] );
		$this->assertEquals( $parts['max'], $quote['max_per_month'] );
		$this->assertSame( round( $parts['hourly'], 4 ), $quote['hourly'] );
		$this->assertStringContainsString( '€0.0740 per hour', $quote['hourly_text'] );
		$this->assertSame( '€54', $quote['max_per_month_text'] );
		$this->assertEquals( array( 45.0, 5.0, 4.0 ), array_column( $quote['lines'], 'per_month' ) );
		$this->assertSame( array( '€45 / month', '+€5 / month', '+€4 / month' ), array_column( $quote['lines'], 'text' ) );
		$this->assertTrue( $quote['excl_vat'] );
		$this->assertSame( 'database', $quote['kind'] );
	}

	public function test_the_quote_of_a_plan_alone_and_of_a_server_with_backups(): void {
		$alone = $this->call( 'price_quote', array( 'plan' => $this->db['m-0-0']->get_id() ) );
		$this->assertEquals( 45.0, $alone['max_per_month'] );
		$this->assertCount( 1, $alone['lines'] );

		$server = $this->call( 'price_quote', array( 'plan' => $this->server['m-None']->get_id(), 'backups' => true ) );
		$this->assertEquals( 18.0, $server['max_per_month'] );
		$this->assertEquals( array( 15.0, 3.0 ), array_column( $server['lines'], 'per_month' ) );
		$this->assertEquals( Breakdown::parts( $this->server['u-Daily'] )['max'], $server['max_per_month'] );
		$this->assertSame( 'server', $server['kind'] );
	}

	public function test_the_quote_refuses_what_is_not_offered(): void {
		$plan = (int) $this->db['m-0-0']->get_id();

		$this->assertTrue( $this->tool( 'price_quote', array( 'plan' => $plan, 'extra_storage_gb' => 7 ), $this->read() )['isError'] );
		$this->assertTrue( $this->tool( 'price_quote', array( 'plan' => 999999 ), $this->read() )['isError'] );
		$this->assertTrue( $this->tool( 'price_quote', array( 'plan' => (int) $this->server['m-None']->get_id(), 'public_ipv4' => true ), $this->read() )['isError'] );
		$this->assertTrue( $this->tool( 'price_quote', array( 'plan' => $plan, 'backups' => true ), $this->read() )['isError'] );
	}

	public function test_billing_summary_says_card_credit_and_usage(): void {
		$none = $this->call( 'billing_summary' )['account'];
		$this->assertTrue( $none['card_saved'] );
		$this->assertTrue( $none['credit']['enabled'] );
		$this->assertEquals( 0.0, $none['credit']['balance'] );

		Balance::credit( $this->user, 2500, Balance::TOPUP, 'test', 'pricing-1' );
		$some = $this->call( 'billing_summary' )['account'];
		$this->assertEquals( 25.0, $some['credit']['balance'] );
		$this->assertSame( '€25.00', $some['credit']['text'] );
		$this->assertStringContainsString( 'not enough to order', $some['credit']['note'] );
		$this->assertStringContainsString( 'VAT', $some['vat_note'] );

		$this->assertArrayHasKey( 'account', $this->call( 'usage_summary' ) );
	}

	public function test_a_customer_without_a_card_is_told_so(): void {
		$other = $this->new_user();
		$out   = $this->json( $this->tool( 'billing_summary', array(), $this->oauth( $other ) ) );

		$this->assertFalse( $out['account']['card_saved'] );
		$this->assertNull( $out['account']['usage_this_month'] );
	}

	public function test_the_model_is_in_the_instructions_and_texts_are_plain(): void {
		$text = Endpoint::instructions( Tokens::READ );

		$this->assertStringContainsString( 'pay as you go', $text );
		$this->assertStringContainsString( 'saved card', $text );
		$this->assertStringContainsString( 'extra storage', $text );
		$this->assertStringContainsString( 'public IPv4', $text );

		$all = wp_json_encode( array( $this->call( 'catalog', array( 'kind' => 'database_plans' ) ), $this->call( 'catalog', array( 'kind' => 'server_plans' ) ), $this->call( 'billing_summary' ), $text ) );
		$this->assertStringNotContainsString( '—', $all );
		$this->assertStringNotContainsString( '·', $all );
		$this->assertStringNotContainsString( 'password', $all );
	}
}
