<?php
/**
 * Pay as you go on the website: the Bricks tags, the "from" price, the plan loops, the picker and the checkout choice.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\Backup\BackupField;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Proxmox\Vm\OsField;
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

		$this->assertSame( "\u{20AC}0.0226", Catalog::value( 'beltoft_usage_hourly', $id ) );
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );
		$this->assertSame( "Or \u{20AC}0.0226/\u{2060}hour, max \u{20AC}16.50/\u{2060}month, pay as you go", Catalog::value( 'beltoft_usage_line', $id ) );
		$this->assertStringNotContainsString( "\u{2014}", Catalog::value( 'beltoft_usage_line', $id ) );
	}

	/**
	 * Counts how often a figure is worked out: every compute reads the parent's children once to find the sibling.
	 */
	private function count_computations(): \Closure {
		$n = 0;
		add_filter(
			'woocommerce_get_children',
			static function ( $children ) use ( &$n ) {
				++$n;
				return $children;
			},
			1
		);

		return static function () use ( &$n ) {
			return $n;
		};
	}

	public function test_a_tag_is_worked_out_once_and_the_warm_text_is_the_cold_text(): void {
		$id    = $this->v['m-None']->get_id();
		$count = $this->count_computations();

		$cold = array();
		foreach ( array( 'beltoft_usage_hourly', 'beltoft_usage_cap', 'beltoft_usage_line' ) as $tag ) {
			$cold[ $tag ] = Catalog::value( $tag, $id );
		}
		$after_cold = $count();
		$this->assertGreaterThan( 0, $after_cold );

		for ( $i = 0; $i < 20; $i++ ) {
			foreach ( $cold as $tag => $text ) {
				$this->assertSame( $text, Catalog::value( $tag, $id ) );
			}
		}

		$this->assertSame( $after_cold, $count(), 'Sixty more uses worked nothing out.' );

		$html = '<p>{beltoft_usage_hourly} {beltoft_usage_cap:' . $id . '} {beltoft_usage_line}</p>';
		$post = (object) array( 'ID' => $id );
		$warm = Catalog::render( $html, $post );
		Catalog::forget();
		$this->assertSame( $warm, Catalog::render( $html, $post ), 'Rendered cold and warm is byte for byte the same.' );
		$this->assertSame( Catalog::render( $html, $post, 'plain' ), Catalog::render( $html, $post, 'plain' ) );
	}

	public function test_a_changed_price_gives_the_new_text_within_the_request(): void {
		$id = $this->v['m-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		$this->v['m-None']->set_regular_price( '20' );
		$this->v['m-None']->save();

		$this->assertSame( "\u{20AC}22", Catalog::value( 'beltoft_usage_cap', $id ) );
	}

	public function test_a_changed_premium_gives_the_new_text_within_the_request(): void {
		$id = $this->v['m-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		update_post_meta( $this->v['m-None']->get_parent_id(), Rates::META_PREMIUM, '20' );

		$this->assertSame( "\u{20AC}18", Catalog::value( 'beltoft_usage_cap', $id ) );
	}

	public function test_a_changed_currency_setting_or_symbol_gives_the_new_text(): void {
		$id = $this->v['m-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		update_option( 'woocommerce_currency', 'USD' );
		$this->assertSame( '$16.50', Catalog::value( 'beltoft_usage_cap', $id ) );

		// A filter on the symbol is not an option change: the state is read again every time.
		add_filter( 'woocommerce_currency_symbol', static fn() => 'kr', 10 );
		$this->assertSame( 'kr16.50', Catalog::value( 'beltoft_usage_cap', $id ) );
	}

	public function test_a_changed_tax_display_or_forced_display_gives_the_new_text(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );

		$id = $this->v['m-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		$forced = \BeltoftServices\Billing\TaxNote::in( 'incl', static fn() => Catalog::value( 'beltoft_usage_cap', $id ) );
		$this->assertSame( "\u{20AC}20.63", $forced, 'Forced display inside the same request.' );
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ), 'And back.' );

		update_option( 'woocommerce_tax_display_shop', 'incl' );
		$this->assertSame( "\u{20AC}20.63", Catalog::value( 'beltoft_usage_cap', $id ) );

		WC()->customer = new \WC_Customer( 0, true );
		WC()->customer->set_is_vat_exempt( true );
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ), 'A VAT-exempt customer is shown no tax.' );
		WC()->customer->set_is_vat_exempt( false );
		$this->assertSame( "\u{20AC}20.63", Catalog::value( 'beltoft_usage_cap', $id ) );

		update_option( 'woocommerce_tax_display_shop', 'excl' );
		delete_option( 'woocommerce_calc_taxes' );
		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	public function test_a_visitor_currency_is_part_of_the_key(): void {
		$id = $this->v['m-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		// What a currency add-on does: the current currency changes with the visitor and prices are converted by a filter.
		$code    = static fn() => 'DKK';
		$convert = static fn( $price ) => '' === $price ? $price : (string) ( (float) $price * 7 );
		add_filter( 'woocommerce_currency', $code );
		add_filter( 'woocommerce_product_variation_get_price', $convert );

		$kroner = Catalog::value( 'beltoft_usage_cap', $id );
		$this->assertStringContainsString( '115.50', $kroner );
		$this->assertStringNotContainsString( "\u{20AC}", $kroner );

		remove_filter( 'woocommerce_currency', $code );
		remove_filter( 'woocommerce_product_variation_get_price', $convert );

		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ), 'Back in the shop currency.' );
	}

	public function test_a_deleted_sibling_is_not_remembered(): void {
		$id = $this->v['m-None']->get_id();
		$this->assertNotSame( '', Catalog::value( 'beltoft_usage_line', $id ) );

		wp_delete_post( $this->v['u-None']->get_id(), true );

		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $id ) );
	}

	public function test_the_tags_render_in_content_for_the_post_and_for_an_id(): void {
		$post = (object) array( 'ID' => $this->v['m-Daily']->get_id() );

		$this->assertSame( "\u{20AC}19.80", Catalog::render( '{beltoft_usage_cap}', $post ), 'The current post is the variation (18 x 1.1).' );
		$this->assertSame( "a \u{20AC}16.50 b", Catalog::render( 'a {beltoft_usage_cap:' . $this->v['m-None']->get_id() . '} b', $post ) );
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
		// Hourly pay as you go is left out; a counted variation is the plan itself and stays in.
		$this->assertSame( 'OR', $vars['meta_query'][1]['relation'] );
		$this->assertSame( 'NOT EXISTS', $vars['meta_query'][1][0]['compare'] );
		$this->assertSame( Rates::KIND_COUNTED, $vars['meta_query'][1][1]['value'] );

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
		$this->assertStringNotContainsString( 'A card is required.', $html, 'Every order needs a payment method: nothing to say for pay as you go.' );
		$this->assertStringContainsString( "\u{20AC}0.0226 per hour, max \u{20AC}16.50 per month excluding VAT", $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( 'value="' . $this->v['u-None']->get_id() . '"', $html, 'No pay as you go card.' );
	}

	public function test_monthly_not_offered_prints_no_tiles_and_cards_with_the_cap_and_hourly_rate(): void {
		$this->settings( array( 'offer_monthly' => 0 ) );
		$html = $this->page();

		$this->assertStringNotContainsString( 'name="' . ServerPicker::BILLING . '"', $html );
		$this->assertStringContainsString( 'data-picker-payg-only', $html );
		$this->assertStringContainsString( 'max per month, excl. VAT', $html );
		$this->assertStringContainsString( '16.50</b>', $html );
		$this->assertStringContainsString( '0.0226 per hour</span>', str_replace( "\u{a0}", ' ', $html ) );
		$this->assertStringContainsString( 'data-card-cap-backups=', $html );
	}

	public function test_monthly_not_offered_posts_pay_as_you_go_and_falls_back_for_a_plan_without_it(): void {
		$this->settings( array( 'offer_monthly' => 0 ) );
		$this->assertSame( $this->v['u-None']->get_id(), $this->check( array( 'billing' => 'usage' ) )['variation_id'] );

		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();
		$result = $this->check( array( 'billing' => 'usage' ) );

		$this->assertSame( $this->v['m-None']->get_id(), $result['variation_id'] );
		$this->assertArrayNotHasKey( 'billing', $result['errors'] );
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

	public function test_the_billing_box_comes_first_and_stays_when_a_plan_has_no_pay_as_you_go(): void {
		Offers::reset();
		ob_start();
		ServerPicker::render();
		$html = (string) ob_get_clean();

		$billing = strpos( $html, 'beltoft-services-picker__billing' );
		$plans   = strpos( $html, 'beltoft-services-picker__plans' );
		$this->assertNotFalse( $billing );
		$this->assertLessThan( $plans, $billing, 'The billing choice is above the plans.' );
		$this->assertStringNotContainsString( 'data-picker-billing hidden', $html, 'The box is never hidden; a plan without it greys the tile out.' );
		$this->assertMatchesRegularExpression( '#<input class="beltoft-services-bill__input" type="radio" name="' . ServerPicker::BILLING . '" value="monthly"#', $html );
		$this->assertDoesNotMatchRegularExpression( '#value="usage"[^>]*disabled#', $html, 'This plan offers pay as you go, so the tile can be chosen.' );
	}

	public function test_pay_as_you_go_is_selected_by_default_when_the_plan_offers_it(): void {
		Offers::reset();
		ob_start();
		ServerPicker::render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#value="usage" checked#', $html );
		$this->assertDoesNotMatchRegularExpression( '#value="monthly" checked#', $html );
	}

	public function test_a_plan_without_pay_as_you_go_is_never_preselected_for_it(): void {
		$this->v['u-None']->set_status( 'private' );
		$this->v['u-None']->save();
		Offers::reset();
		ob_start();
		ServerPicker::render();
		$html = (string) ob_get_clean();
		$this->assertDoesNotMatchRegularExpression( '#value="usage" checked#', $html );
		$this->assertSame( $this->v['m-None']->get_id(), $this->check()['variation_id'], 'Monthly without a billing value.' );
	}

	public function test_a_refused_post_with_monthly_is_not_turned_back_into_pay_as_you_go(): void {
		Offers::reset();
		ServerPicker::process( array( ServerPicker::FIELD => 'bad', ServerPicker::PLAN => (string) $this->v['m-None']->get_id(), ServerPicker::BILLING => 'monthly' ), get_current_user_id() );
		ob_start();
		ServerPicker::render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#value="monthly" checked#', $html );
		$this->assertStringContainsString( 'data-picker-touched', $html );
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
		$this->assertStringNotContainsString( 'A card is required.', $html, 'Every order needs a payment method: nothing to say for pay as you go.' );
		$this->assertStringContainsString( "data-terms-backups=\"\u{20AC}0.0271 per hour, max \u{20AC}19.80 per month\"", $html );
		$this->assertStringContainsString( "data-terms=\"\u{20AC}0.0226 per hour, max \u{20AC}16.50 per month\"", $html );
		$this->assertStringContainsString( "data-monthly=\"\u{20AC}15.00 per month, paid in advance\"", $html );
		$this->assertStringContainsString( "data-monthly-backups=\"\u{20AC}18.00 per month, paid in advance\"", $html );
		$this->assertStringContainsString( 'Billed monthly for the hours used', $html );
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
		$this->assertSame( "+\u{20AC}0.0045 / hour", \BeltoftServices\Proxmox\Backup\Backups::price_difference( $this->v['u-None'], $this->v['u-Daily'] ) );
	}

	public function test_the_choice_sits_at_the_top_of_your_server(): void {
		$this->assertSame( 1, has_action( 'woocommerce_after_order_notes', array( \BeltoftServices\Usage\BillingField::class, 'render' ) ) );
		$this->assertFalse( has_action( 'woocommerce_before_checkout_billing_form', array( \BeltoftServices\Usage\BillingField::class, 'render' ) ) );
	}
}
