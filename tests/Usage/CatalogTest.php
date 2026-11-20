<?php
/**
 * Pay as you go on the website: the Bricks tags, the "from" price, the plan loops, the picker of a pay as you go product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\Backup\BackupField;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Usage\Catalog;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Website prices and the pickers of a pay as you go product.
 */
class CatalogTest extends UsageTestCase {

	/**
	 * Variations by key (u-None, u-Daily).
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
		$rates = Rates::for_usage( $this->v['u-None'] );

		$this->assertEqualsWithDelta( 15 * 1.1 / 730, $rates['hourly'], 0.0000001 );
		$this->assertSame( 16.5, $rates['cap'] );
		$this->assertNull( Rates::for_usage( $this->monthly_vps()['m-None'] ), 'A monthly variation has no pay as you go rates.' );
	}

	public function test_the_tags_say_the_hourly_price_and_the_cap(): void {
		$id = $this->v['u-None']->get_id();

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
		$id    = $this->v['u-None']->get_id();
		$count = $this->count_computations();

		$cold = array();
		foreach ( array( 'beltoft_usage_hourly', 'beltoft_usage_cap', 'beltoft_usage_line' ) as $tag ) {
			$cold[ $tag ] = Catalog::value( $tag, $id );
		}
		$after_cold = $count();

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
		$id = $this->v['u-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		update_post_meta( $id, Rates::META_MONTHLY, '20' );

		$this->assertSame( "\u{20AC}22", Catalog::value( 'beltoft_usage_cap', $id ) );
	}

	public function test_a_changed_premium_gives_the_new_text_within_the_request(): void {
		$id = $this->v['u-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		update_post_meta( $this->v['u-None']->get_parent_id(), Rates::META_PREMIUM, '20' );

		$this->assertSame( "\u{20AC}18", Catalog::value( 'beltoft_usage_cap', $id ) );
	}

	public function test_a_changed_currency_setting_or_symbol_gives_the_new_text(): void {
		$id = $this->v['u-None']->get_id();
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

		$id = $this->v['u-None']->get_id();
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
		$id = $this->v['u-None']->get_id();
		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ) );

		// What a currency add-on does: the current currency changes with the visitor; the figure of that currency is its own meta.
		update_post_meta( $id, Rates::META_MONTHLY . '_DKK', '105' );
		$code = static fn() => 'DKK';
		add_filter( 'woocommerce_currency', $code );

		$kroner = Catalog::value( 'beltoft_usage_cap', $id );
		$this->assertStringContainsString( '115.50', $kroner );
		$this->assertStringNotContainsString( "\u{20AC}", $kroner );

		remove_filter( 'woocommerce_currency', $code );

		$this->assertSame( "\u{20AC}16.50", Catalog::value( 'beltoft_usage_cap', $id ), 'Back in the shop currency.' );
	}

	public function test_a_deleted_variation_is_not_remembered(): void {
		$id = $this->v['u-None']->get_id();
		$this->assertNotSame( '', Catalog::value( 'beltoft_usage_line', $id ) );

		wp_delete_post( $id, true );

		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $id ) );
	}

	public function test_the_tags_render_in_content_for_the_post_and_for_an_id(): void {
		$post = (object) array( 'ID' => $this->v['u-Daily']->get_id() );

		$this->assertSame( "\u{20AC}19.80", Catalog::render( '{beltoft_usage_cap}', $post ), 'The current post is the variation (18 x 1.1).' );
		$this->assertSame( "a \u{20AC}16.50 b", Catalog::render( 'a {beltoft_usage_cap:' . $this->v['u-None']->get_id() . '} b', $post ) );
		$this->assertSame( 'no tag', Catalog::render( 'no tag', $post ) );
	}

	public function test_the_tags_are_empty_without_an_offer(): void {
		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $this->monthly_vps()['m-None']->get_id() ), 'A monthly variation has no pay as you go price.' );
		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', 0 ) );
		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $this->v['u-None']->get_parent_id() ), 'A product is not a variation.' );

		update_post_meta( $this->v['u-None']->get_id(), Rates::META_MONTHLY, '' );

		$this->assertSame( '', Catalog::value( 'beltoft_usage_line', $this->v['u-None']->get_id() ), 'No figure, no offer.' );
	}

	public function test_the_tags_are_in_the_bricks_list(): void {
		$names = wp_list_pluck( Catalog::tags_list( array() ), 'name' );

		$this->assertSame( array( '{beltoft_usage_line}', '{beltoft_usage_hourly}', '{beltoft_usage_cap}' ), $names );
	}

	public function test_a_pay_as_you_go_variation_is_the_plan(): void {
		$plans = Offers::plans();

		$this->assertArrayHasKey( $this->v['u-None']->get_id(), $plans );
		$this->assertArrayNotHasKey( $this->v['u-Daily']->get_id(), $plans, 'The variation with backups is the option of its plan.' );

		$plan = $plans[ $this->v['u-None']->get_id() ];

		$this->assertSame( 15.0, $plan['price'], 'A pay as you go plan is priced by its monthly figure.' );
		$this->assertSame( 16.5, $plan['usage']['cap'] );
		$this->assertSame( 19.8, $plan['usage']['backups_cap'] );
		$this->assertSame( $this->v['u-Daily']->get_id(), $plan['backups']['variation_id'] );
		$this->assertSame( 3.0, $plan['backups']['extra'] );
	}

	public function test_a_plan_of_a_monthly_product_is_listed_with_its_price_and_no_rates(): void {
		$m = $this->monthly_vps();
		Offers::reset();
		$plan = Offers::plans()[ $m['m-None']->get_id() ];

		$this->assertNull( $plan['usage'] );
		$this->assertSame( 15.0, $plan['price'] );
	}

	public function test_a_product_off_the_picker_lists_no_plan(): void {
		update_post_meta( $this->v['u-None']->get_parent_id(), \BeltoftServices\Product::META_PICKER, 'no' );
		Offers::reset();

		$this->assertArrayNotHasKey( $this->v['u-None']->get_id(), Offers::plans() );
	}

	public function test_a_pay_as_you_go_variation_without_a_figure_is_no_plan(): void {
		update_post_meta( $this->v['u-None']->get_id(), Rates::META_MONTHLY, '' );
		Offers::reset();

		$this->assertArrayNotHasKey( $this->v['u-None']->get_id(), Offers::plans() );
	}

	public function test_backups_are_offered_only_when_the_variation_with_backups_has_a_figure(): void {
		update_post_meta( $this->v['u-Daily']->get_id(), Rates::META_MONTHLY, '' );
		Offers::reset();

		$this->assertNull( Offers::plans()[ $this->v['u-None']->get_id() ]['backups'] );
	}

	private function page(): string {
		$GLOBALS['wp']->query_vars['vps'] = 'new';
		Offers::reset();
		ob_start();
		Servers::render( array() );

		return (string) ob_get_clean();
	}

	public function test_the_picker_prints_a_pay_as_you_go_card_with_the_cap_and_the_hourly_rate_and_no_billing_choice(): void {
		$html = $this->page();

		$this->assertStringNotContainsString( 'beltoft_services_picker_billing', $html );
		$this->assertStringNotContainsString( 'data-picker-billing', $html );
		$this->assertStringContainsString( 'value="' . $this->v['u-None']->get_id() . '"', $html );
		$this->assertStringContainsString( 'max per month, excl. VAT', $html );
		$this->assertStringContainsString( '16.50</b>', $html );
		$this->assertStringContainsString( '0.0226 per hour</span>', str_replace( "\u{a0}", ' ', $html ) );
		$this->assertStringContainsString( 'data-card-cap-backups=', $html );
		$this->assertStringContainsString( "pay as you go: \u{20AC}0.0226 per hour, max \u{20AC}16.50 per month excluding VAT", $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	private function check( array $over = array() ): array {
		Offers::reset();

		return ServerPicker::check(
			array_merge(
				array( 'plan' => (string) $this->v['u-None']->get_id(), 'backups' => '', 'os' => '9000', 'app' => '', 'version' => '', 'domain_on' => '', 'zone' => '', 'sub' => '', 'name' => '', 'ssh_key' => '' ),
				$over
			),
			get_current_user_id()
		);
	}

	public function test_the_picker_chooses_the_variation_from_the_plan_and_backups(): void {
		$this->assertSame( $this->v['u-None']->get_id(), $this->check()['variation_id'] );
		$this->assertSame( $this->v['u-Daily']->get_id(), $this->check( array( 'backups' => '1' ) )['variation_id'] );
	}

	public function test_the_picker_refuses_backups_when_the_plan_has_none(): void {
		$this->v['u-Daily']->set_status( 'private' );
		$this->v['u-Daily']->save();

		$this->assertArrayHasKey( 'backups', $this->check( array( 'backups' => '1' ) )['errors'] );
		$this->assertArrayNotHasKey( 'backups', $this->check()['errors'] );
	}

	public function test_a_post_puts_the_pay_as_you_go_variation_in_the_cart(): void {
		Offers::reset();
		$result = ServerPicker::process(
			array(
				ServerPicker::FIELD => wp_create_nonce( ServerPicker::NONCE ),
				ServerPicker::PLAN  => (string) $this->v['u-None']->get_id(),
				OsField::POST_KEY   => '9000',
			),
			get_current_user_id()
		);

		$this->assertSame( array(), $result['errors'] );
		$lines = array_values( array_filter( WC()->cart->get_cart(), static fn( $i ) => ! empty( $i['variation_id'] ) ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( $this->v['u-None']->get_id(), (int) $lines[0]['variation_id'] );
	}

	public function test_a_post_for_a_plan_of_a_monthly_product_keeps_the_monthly_variation(): void {
		$m = $this->monthly_vps();
		Offers::reset();
		$checked = ServerPicker::check( array( 'plan' => (string) $m['m-None']->get_id(), 'backups' => '', 'os' => '9000', 'app' => '', 'version' => '', 'domain_on' => '', 'zone' => '', 'sub' => '', 'name' => '', 'ssh_key' => '' ), get_current_user_id() );

		$this->assertSame( $m['m-None']->get_id(), $checked['variation_id'] );
	}

	public function test_the_backups_price_in_pay_as_you_go_is_per_hour(): void {
		$this->assertSame( "+\u{20AC}0.0045 / hour", \BeltoftServices\Proxmox\Backup\Backups::price_difference( $this->v['u-None'], $this->v['u-Daily'] ) );
	}

	public function test_the_checkout_has_no_billing_field_any_more(): void {
		$this->assertFalse( class_exists( '\\BeltoftServices\\Usage\\BillingField' ) );
		$this->assertFalse( has_action( 'beltoft_services_billing_swapped' ) );
	}
}
