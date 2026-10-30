<?php
/**
 * Pay as you go on the website: the Bricks tags, the "from" price, the plan loops, the picker and the checkout choice.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\BackupField;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Usage\BillingField;
use BeltoftServices\Usage\Catalog;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Website prices and the billing choice.
 */
class CatalogTest extends UsageTestCase {

	/**
	 * Variations by key (m-None, m-Daily, u-None, u-Daily).
	 *
	 * @var array<string, \WC_Product_Variation>
	 */
	private $v = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		$this->v = $this->usage_product();
		Offers::reset();
		wp_set_current_user( $this->new_user() );
	}

	public function tear_down(): void {
		Offers::reset();
		unset( $GLOBALS['wp']->query_vars['vps'] );
		$_POST    = array();
		$_REQUEST = array();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	public function test_the_rates_of_a_plan_use_the_ten_percent_premium(): void {
		$rates = Rates::for_plan( $this->v['m-None'] );

		$this->assertSame( $this->v['u-None']->get_id(), $rates['variation_id'] );
		$this->assertEqualsWithDelta( 15 * 1.1 / 730, $rates['hourly'], 0.0000001 );
		$this->assertSame( 16.5, $rates['cap'] );
		$this->assertNull( Rates::for_plan( $this->v['u-None'] ), 'A pay as you go variation has no pay as you go offer.' );
	}

	public function test_the_tags_say_the_hourly_price_and_the_cap(): void {
		$id = $this->v['m-None']->get_id();

		$this->assertSame( 'EUR 0.0226', Catalog::value( 'beltoft_usage_hourly', $id ) );
		$this->assertSame( 'EUR 16.50', Catalog::value( 'beltoft_usage_cap', $id ) );
		$this->assertSame( 'or EUR 0.0226/hour, max EUR 16.50/month pay as you go', Catalog::value( 'beltoft_usage_line', $id ) );
		$this->assertStringNotContainsString( "\u{2014}", Catalog::value( 'beltoft_usage_line', $id ) );
	}

	public function test_the_tags_render_in_content_for_the_post_and_for_an_id(): void {
		$post = (object) array( 'ID' => $this->v['m-Daily']->get_id() );

		$this->assertSame( 'EUR 19.80', Catalog::render( '{beltoft_usage_cap}', $post ), 'The current post is the variation (18 x 1.1).' );
		$this->assertSame( 'a EUR 16.50 b', Catalog::render( 'a {beltoft_usage_cap:' . $this->v['m-None']->get_id() . '} b', $post ) );
		$this->assertSame( 'no tag', Catalog::render( 'no tag', $post ) );
	}

	public function test_the_tags_are_empty_without_an_offer(): void {
		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $this->v['u-None']->get_id() ), 'A pay as you go variation.' );
		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', 0 ) );
		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $this->v['m-None']->get_parent_id() ), 'A product is not a variation.' );

		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();

		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $this->v['m-None']->get_id() ), 'No published sibling.' );
	}

	public function test_the_tags_are_in_the_bricks_list(): void {
		$names = wp_list_pluck( Catalog::tags_list( array() ), 'name' );

		$this->assertSame( array( '{beltoft_usage_line}', '{beltoft_usage_hourly}', '{beltoft_usage_cap}' ), $names );
	}

	public function test_the_from_price_never_counts_a_pay_as_you_go_variation(): void {
		$product = wc_get_product( $this->v['m-None']->get_parent_id() );

		$this->assertSame( 15.0, (float) $product->get_variation_price( 'min' ), 'Not 0.' );
		$this->assertNotContains( $this->v['u-None']->get_id(), $product->get_visible_children() );
		$this->assertContains( $this->v['u-None']->get_id(), $product->get_children(), 'The variation still exists.' );
		$this->assertNotContains( $this->v['u-Daily']->get_id(), Catalog::visible_children( array( $this->v['u-Daily']->get_id(), $this->v['m-None']->get_id() ), $product, true ) );
		$this->assertSame( array( 5 ), Catalog::visible_children( array( 5 ), $product, false ), 'Only the visible list is touched.' );
	}

	public function test_a_bricks_loop_over_variations_gets_a_meta_query_that_leaves_usage_out(): void {
		$vars = Catalog::query_vars( array( 'post_type' => array( 'product_variation' ), 'meta_query' => array( array( 'key' => 'attribute_backups', 'value' => 'None' ) ) ) );

		$this->assertCount( 2, $vars['meta_query'] );
		$this->assertSame( Rates::META_BILLING, $vars['meta_query'][1]['key'] );
		$this->assertSame( 'NOT EXISTS', $vars['meta_query'][1]['compare'] );

		$found = get_posts( array_merge( $vars, array( 'post_parent' => $this->v['m-None']->get_parent_id(), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );

		$this->assertContains( $this->v['m-None']->get_id(), $found );
		$this->assertNotContains( $this->v['u-None']->get_id(), $found );
		$this->assertNotContains( $this->v['u-Daily']->get_id(), $found );

		$posts = array( 'post_type' => array( 'post' ) );
		$this->assertSame( $posts, Catalog::query_vars( $posts ), 'Other loops are untouched.' );
	}

	public function test_a_pay_as_you_go_variation_is_never_a_plan(): void {
		$plans = Offers::plans();

		$this->assertArrayHasKey( $this->v['m-None']->get_id(), $plans );
		$this->assertArrayNotHasKey( $this->v['u-None']->get_id(), $plans );
		$this->assertArrayNotHasKey( $this->v['u-Daily']->get_id(), $plans );

		$plan = $plans[ $this->v['m-None']->get_id() ];

		$this->assertSame( $this->v['u-None']->get_id(), $plan['usage']['variation_id'] );
		$this->assertSame( $this->v['u-Daily']->get_id(), $plan['usage']['backups_variation_id'] );
		$this->assertSame( 19.8, $plan['usage']['backups_cap'] );
		$this->assertStringNotContainsString( 'Monthly', $plan['name'], 'The billing attribute is not part of the plan name.' );
		$this->assertStringNotContainsString( 'Pay as you go', $plan['name'] );
	}

	private function page(): string {
		$GLOBALS['wp']->query_vars['vps'] = 'new';
		Offers::reset();
		ob_start();
		Servers::render( array() );

		return (string) ob_get_clean();
	}

	public function test_the_picker_prints_the_billing_choice_with_its_explanation(): void {
		$html = $this->page();

		$this->assertStringContainsString( 'name="' . ServerPicker::BILLING . '"', $html );
		$this->assertStringContainsString( 'Pay as you go: billed per hour each month, never more than EUR 16.50. A card is required.', $html );
		$this->assertStringContainsString( 'EUR 0.0226 per hour, max EUR 16.50 per month excluding VAT', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( 'value="' . $this->v['u-None']->get_id() . '"', $html, 'No pay as you go card.' );
	}

	public function test_a_product_without_pay_as_you_go_prints_no_billing_choice(): void {
		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();
		$this->v['u-Daily']->set_status( 'private' );
		$this->v['u-Daily']->save();

		$this->assertStringNotContainsString( ServerPicker::BILLING, $this->page() );
	}

	private function check( array $over = array() ): array {
		Offers::reset();

		return ServerPicker::check(
			array_merge(
				array( 'plan' => (string) $this->v['m-None']->get_id(), 'backups' => '', 'billing' => '', 'os' => '9000', 'app' => '', 'version' => '', 'domain_on' => '', 'zone' => '', 'sub' => '', 'name' => '', 'ssh_key' => '' ),
				$over
			),
			get_current_user_id()
		);
	}

	public function test_the_picker_chooses_the_variation_from_billing_and_backups(): void {
		$this->assertSame( $this->v['m-None']->get_id(), $this->check()['variation_id'] );
		$this->assertSame( $this->v['u-None']->get_id(), $this->check( array( 'billing' => 'usage' ) )['variation_id'] );
		$this->assertSame( $this->v['m-Daily']->get_id(), $this->check( array( 'backups' => '1' ) )['variation_id'] );
		$this->assertSame( $this->v['u-Daily']->get_id(), $this->check( array( 'billing' => 'usage', 'backups' => '1' ) )['variation_id'] );
	}

	public function test_the_picker_refuses_pay_as_you_go_for_a_plan_without_it(): void {
		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();

		$out = $this->check( array( 'billing' => 'usage' ) );

		$this->assertArrayHasKey( 'billing', $out['errors'] );
	}

	public function test_the_picker_refuses_pay_as_you_go_with_backups_when_only_that_is_missing(): void {
		$this->v['u-Daily']->set_status( 'private' );
		$this->v['u-Daily']->save();

		$this->assertArrayHasKey( 'billing', $this->check( array( 'billing' => 'usage', 'backups' => '1' ) )['errors'] );
		$this->assertArrayNotHasKey( 'billing', $this->check( array( 'billing' => 'usage' ) )['errors'] );
	}

	public function test_a_post_with_pay_as_you_go_puts_the_usage_variation_in_the_cart(): void {
		Offers::reset();
		$result = ServerPicker::process(
			array(
				ServerPicker::FIELD   => wp_create_nonce( ServerPicker::NONCE ),
				ServerPicker::PLAN    => (string) $this->v['m-None']->get_id(),
				ServerPicker::BILLING => 'usage',
				OsField::POST_KEY     => '9000',
			),
			get_current_user_id()
		);

		$this->assertSame( array(), $result['errors'] );
		$lines = array_values( array_filter( WC()->cart->get_cart(), static fn( $i ) => 'Pay as you go' === ( $i['variation']['attribute_billing'] ?? '' ) ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( $this->v['u-None']->get_id(), (int) $lines[0]['variation_id'] );
	}

	private function in_cart( \WC_Product_Variation $v ): void {
		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), BackupField::attributes( $v ) ) );
	}

	private function server_variation(): int {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['variation_id'] ) ) {
				return (int) $item['variation_id'];
			}
		}

		return 0;
	}

	public function test_checkout_swaps_to_pay_as_you_go_and_back_keeping_the_backups_state(): void {
		$this->in_cart( $this->v['m-Daily'] );

		$this->assertNotNull( BillingField::cart_server() );
		$this->assertTrue( BillingField::swap( true )['ok'] );
		$this->assertSame( $this->v['u-Daily']->get_id(), $this->server_variation() );
		$this->assertTrue( BillingField::swap( false )['ok'] );
		$this->assertSame( $this->v['m-Daily']->get_id(), $this->server_variation() );
	}

	public function test_checkout_swap_to_pay_as_you_go_adds_the_usage_account_line(): void {
		$this->in_cart( $this->v['m-None'] );
		BillingField::swap( true );
		$id = (int) get_option( \BeltoftServices\Usage\Account::OPTION_PRODUCT );

		$this->assertGreaterThan( 0, $id );
		$this->assertCount( 1, array_filter( WC()->cart->get_cart(), static fn( $i ) => (int) $i['product_id'] === $id ), 'The card rule needs the account line.' );

		BillingField::swap( false );

		$this->assertCount( 0, array_filter( WC()->cart->get_cart(), static fn( $i ) => (int) $i['product_id'] === $id ) );
	}

	public function test_the_checkout_field_prints_the_choice_and_the_explanation(): void {
		$this->in_cart( $this->v['m-None'] );

		ob_start();
		BillingField::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="beltoft_services_billing"', $html );
		$this->assertStringContainsString( 'value="usage"', $html );
		$this->assertStringContainsString( 'Pay as you go: billed per hour each month, never more than EUR 16.50. A card is required.', $html );
		$this->assertStringContainsString( 'data-note-backups="Pay as you go: billed per hour each month, never more than EUR 19.80. A card is required."', $html );
	}

	public function test_the_checkout_field_is_not_shown_without_a_sibling_or_for_an_existing_server(): void {
		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();
		$this->in_cart( $this->v['m-None'] );

		$this->assertNull( BillingField::cart_server() );
		ob_start();
		BillingField::render();
		$this->assertSame( '', (string) ob_get_clean() );

		$this->v['u-None']->set_status( 'publish' );
		$this->v['u-None']->save();
		$this->in_cart( $this->v['m-None'] );
		$this->assertNotNull( BillingField::cart_server() );

		$cart = WC()->cart->get_cart();
		$key  = (string) array_key_first( $cart );
		$cart[ $key ]['subscription_renewal'] = array( 'x' => 1 );
		WC()->cart->set_cart_contents( $cart );

		$this->assertNull( BillingField::cart_server(), 'A renewal never gets the field.' );
	}

	public function test_the_ajax_answer_checks_the_nonce(): void {
		$this->in_cart( $this->v['m-None'] );
		$_POST    = array( 'nonce' => 'bad', 'billing' => 'usage' );
		$_REQUEST = $_POST;

		$this->assertSame( 403, BillingField::respond()[1] );

		$_POST    = array( 'nonce' => wp_create_nonce( BillingField::NONCE ), 'billing' => 'usage' );
		$_REQUEST = $_POST;
		$answer   = BillingField::respond();

		$this->assertSame( 200, $answer[1] );
		$this->assertSame( $this->v['u-None']->get_id(), $this->server_variation() );
	}

	public function test_the_backups_price_in_pay_as_you_go_is_per_hour(): void {
		$this->assertSame( '+EUR 0.0045 / hour', \BeltoftServices\Proxmox\Backups::price_difference( $this->v['u-None'], $this->v['u-Daily'] ) );
	}
}
