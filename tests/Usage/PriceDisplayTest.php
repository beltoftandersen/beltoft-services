<?php
/**
 * Catalogue prices follow the shop's tax display and can be re-rendered through beltoft_services_price_html.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Bricks\DomainQuery;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\PriceTags;
use BeltoftServices\Domains\Search;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Catalog;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * One currency, display excluding tax and no listener: every output is what it always was. Display including tax with a 25 percent rate: the
 * including figure. A listener on the filter can build both figures.
 */
class PriceDisplayTest extends UsageTestCase {

	use FakeCurrency;

	/** @var array<int, array{0: string, 1: callable, 2: int}> Filters a test added. */
	private array $added = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	public function tear_down(): void {
		foreach ( $this->added as $f ) {
			remove_filter( $f[0], $f[1], $f[2] );
		}
		$this->fake_currency_down();
		parent::tear_down();
	}

	private function display( string $mode ): void {
		update_option( 'woocommerce_tax_display_shop', $mode );
		update_option( 'woocommerce_tax_display_cart', $mode );
	}

	private function both(): void {
		$f = static function ( $html, $amount, $currency, $context, $render ) {
			return $render( 'excl' ) . ' | ' . $render( 'incl' );
		};
		add_filter( 'beltoft_services_price_html', $f, 10, 5 );
		$this->added[] = array( 'beltoft_services_price_html', $f, 10 );
	}

	public function test_the_usage_tags_are_unchanged_with_the_display_excluding_tax(): void {
		$v  = $this->usage_product();
		$id = $v['m-None']->get_id();

		$this->assertSame( '€0.0226', Catalog::value( 'beltoft_usage_hourly', $id ) );
		$this->assertSame( '€16.50', Catalog::value( 'beltoft_usage_cap', $id ) );
		$this->assertSame( "Or €0.0226/\u{2060}hour, max €16.50/\u{2060}month, pay as you go", Catalog::value( 'beltoft_usage_line', $id ) );
		$this->assertSame( '€16.50', apply_filters( 'bricks/frontend/render_data', '{beltoft_usage_cap:' . $id . '}', null ) );
	}

	public function test_the_usage_tags_follow_a_display_including_tax(): void {
		$v  = $this->usage_product();
		$id = $v['m-None']->get_id();
		$this->display( 'incl' );

		$this->assertSame( '€0.0283', Catalog::value( 'beltoft_usage_hourly', $id ) );
		$this->assertSame( '€20.63', Catalog::value( 'beltoft_usage_cap', $id ) );
		$this->assertSame( '€20.63', apply_filters( 'bricks/frontend/render_data', '{beltoft_usage_cap:' . $id . '}', null ) );
	}

	public function test_a_listener_builds_both_figures_for_a_usage_tag_and_nothing_is_cached(): void {
		$v  = $this->usage_product();
		$id = $v['m-None']->get_id();
		$this->both();

		$this->assertSame( '€16.50 | €20.63', apply_filters( 'bricks/frontend/render_data', '{beltoft_usage_cap:' . $id . '}', null ) );

		$this->display( 'incl' );
		$this->assertSame( '€16.50 | €20.63', apply_filters( 'bricks/frontend/render_data', '{beltoft_usage_cap:' . $id . '}', null ) );
	}

	public function test_a_tag_with_no_offer_stays_empty_and_calls_no_listener(): void {
		$v     = $this->usage_product();
		$calls = 0;
		$f     = static function ( $html ) use ( &$calls ) {
			++$calls;
			return $html;
		};
		add_filter( 'beltoft_services_price_html', $f );
		$this->added[] = array( 'beltoft_services_price_html', $f, 10 );

		$this->assertSame( '', apply_filters( 'bricks/frontend/render_data', '{beltoft_usage_cap:' . $v['u-None']->get_id() . '}', null ) );
		$this->assertSame( 0, $calls );
	}

	public function test_the_rate_sentences_are_unchanged_without_a_product_and_follow_the_display_with_one(): void {
		$v = $this->usage_product();
		$p = $v['m-None'];

		$this->assertSame( '€0.0226 per hour, max €16.50 per month excluding VAT', Rates::terms( 0.02260274, 16.5 ) );
		$this->assertSame( '€0.0226 per hour, max €16.50 per month excluding VAT', Rates::terms( 0.02260274, 16.5, $p ) );
		$this->assertSame( '€15.00 per month, paid in advance', Rates::tile_monthly( 15.0, $p ) );
		$this->assertSame( '+€5 per month', Rates::plus_per_month( 5.0, $p ) );

		$this->display( 'incl' );

		$this->assertSame( '€0.0226 per hour, max €16.50 per month excluding VAT', Rates::terms( 0.02260274, 16.5 ), 'API and MCP answers stay excluding tax.' );
		$this->assertSame( '€0.0283 per hour, max €20.63 per month including VAT', Rates::terms( 0.02260274, 16.5, $p ) );
		$this->assertSame( '€18.75 per month, paid in advance', Rates::tile_monthly( 15.0, $p ) );
		$this->assertSame( '+€6.25 per month', Rates::plus_per_month( 5.0, $p ) );
		$this->assertSame( '€0.0226', Rates::money( 0.02260274, 4 ), 'An invoice figure is the amount as given.' );
	}

	public function test_a_non_taxable_product_is_shown_as_it_is_in_the_including_display(): void {
		$v = $this->usage_product();
		$parent = wc_get_product( $v['m-None']->get_parent_id() );
		$parent->set_tax_status( 'none' );
		$parent->save();
		$this->display( 'incl' );

		$this->assertSame( '€15.00 per month, paid in advance', Rates::tile_monthly( 15.0, wc_get_product( $v['m-None']->get_id() ) ) );
	}

	public function test_the_picker_text_is_unchanged_excluding_and_including_when_the_display_says_so(): void {
		$v = $this->usage_product();
		$p = $v['m-None'];

		$this->assertSame( '€10.00 per month excluding VAT', ServerPicker::price_text( 10.0, 'month' ) );
		$this->assertSame( '€10.00 per month excluding VAT', ServerPicker::price_text( 10.0, 'month', $p ) );
		$this->assertSame( '€10.00 excluding VAT', ServerPicker::price_text( 10.0, '', $p ) );

		$this->display( 'incl' );

		$this->assertSame( '€12.50 per month including VAT', ServerPicker::price_text( 10.0, 'month', $p ) );
		$this->assertSame( '€10.00 per month excluding VAT', ServerPicker::price_text( 10.0, 'month' ) );
		$this->assertSame( $p->get_id(), ServerPicker::tax_of( array( 'variation_id' => $p->get_id() ) )->get_id() );
		$this->assertNull( ServerPicker::tax_of( array() ) );
	}

	public function test_the_plan_price_html_is_wc_price_excluding_and_switchable(): void {
		$v = $this->usage_product();
		$p = $v['m-None'];

		$this->assertSame( wc_price( 15 ), TaxNote::html( 15.0, 'server-picker', array( 'product' => $p ) ) );

		$this->display( 'incl' );
		$this->assertSame( wc_price( 18.75 ), TaxNote::html( 15.0, 'server-picker', array( 'product' => $p ) ) );

		$this->both();
		$this->assertSame( wc_price( 15 ) . ' | ' . wc_price( 18.75 ), TaxNote::html( 15.0, 'server-picker', array( 'product' => $p ) ) );
	}

	public function test_the_breakdown_rows_follow_the_cart_display_only_with_a_product(): void {
		$v    = $this->usage_product();
		$p    = $v['m-None'];
		$part = array( 'plan' => 45.0, 'ip' => 0, 'storage' => 0, 'backups' => 3.0 );

		$this->assertSame( array( array( 'Plan', '€45 / month' ), array( 'Daily backups', '+€3 / month' ) ), Breakdown::rows( $part ) );

		update_option( 'woocommerce_tax_display_cart', 'incl' );

		$this->assertSame( array( array( 'Plan', '€45 / month' ), array( 'Daily backups', '+€3 / month' ) ), Breakdown::rows( $part ), 'No product: as given.' );
		$this->assertSame( array( array( 'Plan', '€45 / month' ), array( 'Daily backups', '+€3 / month' ) ), Breakdown::rows( $part, '', $p ), 'The shop display is still excluding.' );
		$this->assertSame( array( array( 'Plan', '€56.25 / month' ), array( 'Daily backups', '+€3.75 / month' ) ), TaxNote::in_cart( fn() => Breakdown::rows( $part, '', $p ) ) );
	}

	public function test_a_backups_price_difference_is_plain_text_in_the_display(): void {
		$v = $this->usage_product();

		$this->assertSame( '+€3.00', Backups::price_difference( $v['m-None'], $v['m-Daily'] ) );
		$this->display( 'incl' );
		$this->assertSame( '+€3.75', Backups::price_difference( $v['m-None'], $v['m-Daily'] ) );
	}

	private function table(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ), 'dk' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 14 ) ) );
	}

	public function test_the_domain_price_text_is_unchanged_excluding_and_follows_the_display(): void {
		$this->table();

		$this->assertSame( '€12', Search::price_text( 12 ) );
		$this->assertSame( wp_kses_post( wc_price( 12, array( 'decimals' => 0 ) ) ), Search::price_html( 12 ) );
		$this->assertSame( '', Search::price_text( null ) );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Domain' );
		$product->update_meta_data( \BeltoftServices\Product::META_DRIVER, 'domain' );
		$product->set_regular_price( '1' );
		$product->set_status( 'publish' );
		$product->save();
		$this->display( 'incl' );

		$this->assertSame( '€15', Search::price_text( 12 ), '12 plus 25 percent is a whole amount' );
		$this->assertSame( '€17.50', Search::price_text( 14 ) );
	}

	public function test_the_domain_tags_print_the_price_or_nothing(): void {
		$this->table();

		$this->assertSame( '€12', apply_filters( 'bricks/frontend/render_data', '{beltoft_domain_register:com}', null ) );
		$this->assertSame( '€12', apply_filters( 'bricks/frontend/render_data', '{beltoft_domain_renew:COM}', null ) );
		$this->assertSame( '€12', apply_filters( 'bricks/frontend/render_data', '{beltoft_domain_transfer:com}', null ) );
		$this->assertSame( '', apply_filters( 'bricks/frontend/render_data', '{beltoft_domain_register:xyz}', null ), 'Not sold: empty.' );
		$tags = array_column( PriceTags::tags_list( array() ), 'name' );
		$this->assertSame( array( '{beltoft_domain_register:com}', '{beltoft_domain_renew:com}', '{beltoft_domain_transfer:com}' ), $tags );

		$this->both();
		$this->assertSame( '€12 | €12', apply_filters( 'bricks/frontend/render_data', '{beltoft_domain_register:com}', null ), 'No domain product: no tax context, the amount as given in both.' );
	}

	public function test_a_domain_price_in_another_currency_needs_a_line_there_and_the_euro_price_is_never_relabelled(): void {
		$this->table();

		$this->assertSame( 12, Prices::price( 'com', 'EUR' ) );
		$this->assertNull( Prices::price( 'com', 'DKK' ) );
		$this->assertFalse( Prices::sellable( 'com', 'DKK' ) );
		$this->assertTrue( Prices::sellable( 'com', 'EUR' ) );

		$this->fake_amounts( array( 'DKK' => array( 'domain_price_overrides' => "com=119\n" ) ) );

		$this->assertSame( 119, Prices::price( 'com', 'DKK' ) );
		$this->assertTrue( Prices::sellable( 'com', 'DKK' ) );
		$this->assertNull( Prices::price( 'dk', 'DKK' ), 'An extension without a line has no price in that currency.' );
		$this->assertFalse( Prices::sellable( 'dk', 'DKK' ) );

		$this->fake_currency_up( 'EUR' );
		$this->fake_set_currency( 'DKK' );
		$this->assertSame( 119, Prices::price( 'com' ), 'No currency given: the current one.' );
		$this->assertNull( Prices::price( 'dk' ) );
	}

	public function test_the_search_rows_for_bricks_use_the_shop_currency_and_no_euro_sign(): void {
		$this->table();
		$money = Rates::money( 12.0, 0 );

		$rows = DomainQuery::rows( array( array( 'domain' => 'a.com', 'status' => 'free', 'price' => 12 ) ), 'a' );

		$this->assertSame( $money . ' / year', $rows[0]['price'] );
		$this->assertSame( 'Renews at ' . $money . ' / year', $rows[0]['renewal_price'] );
		$this->assertStringNotContainsString( 'x€', file_get_contents( BELTOFT_SERVICES_PATH . 'src/Bricks/DomainQuery.php' ) );
		$this->assertStringNotContainsString( '€', file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.js' ) );
	}

	public function test_the_db_picker_summary_and_the_cart_rate_follow_the_display(): void {
		$v    = $this->usage_product();
		$plan = array( 'name' => 'Small', 'price' => 25.0, 'period' => 'month', 'variation_id' => $v['m-None']->get_id() );
		$call = new \ReflectionMethod( \BeltoftServices\Account\DbPicker::class, 'summary' );
		$call->setAccessible( true );

		$this->assertSame( 'Small: €25.00 per month excluding VAT', $call->invoke( null, $plan ) );

		$this->display( 'incl' );
		$this->assertSame( 'Small: €31.25 per month including VAT', $call->invoke( null, $plan ) );

		update_option( 'woocommerce_tax_display_cart', 'excl' );
		$item = array( 'data' => $v['u-None'] );
		$this->assertSame( '€0.0226 / hour', \BeltoftServices\Usage\Summary::cart_price( '', $item ), 'The cart shows the cart display: excluding.' );
		update_option( 'woocommerce_tax_display_cart', 'incl' );
		$this->assertSame( '€0.0283 / hour', \BeltoftServices\Usage\Summary::cart_price( '', $item ) );
		$this->assertSame( 'max €20.63 / month', \BeltoftServices\Usage\Summary::cart_subtotal( '', $item ) );
	}
}
