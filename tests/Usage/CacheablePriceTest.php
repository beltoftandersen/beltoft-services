<?php
/**
 * The prices on pages a cache may keep (the Bricks usage and domain tags, the domain rows) are renderers that work the figure out again for
 * the currency in force when a listener calls them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Bricks\DomainQuery;
use BeltoftServices\Currency;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\PriceTags;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Usage\Catalog;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * A listener that prints every currency gets each currency's own figure, never the page's relabelled, and nothing for a currency with no price.
 */
class CacheablePriceTest extends UsageTestCase {

	use FakeCurrency;

	/** @var int[] Renderer calls, by context. */
	private array $calls = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ), 'dk' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ) ) );
		$this->calls = array();
		Currency::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_price_html' );
		$this->fake_currency_down();
		parent::tear_down();
	}

	/** A listener that prints EUR and DKK (what the site's bridge does through the add-on), '-' for a currency with no figure. */
	private function every_currency(): void {
		add_filter(
			'beltoft_services_price_html',
			function ( $html, $amount, $currency, $context, $render ) {
				$parts = array();

				foreach ( array( 'EUR', 'DKK' ) as $code ) {
					list( $ok, $figure ) = Currency::with(
						$code,
						function () use ( $render, $context ) {
							$this->calls[ $context ] = ( $this->calls[ $context ] ?? 0 ) + 1;
							return $render( '' );
						}
					);
					$parts[] = $code . ':' . ( $ok && '' !== $figure ? $figure : '-' );
				}

				return implode( ' | ', $parts );
			},
			10,
			5
		);
	}

	public function test_a_usage_tag_gets_the_figure_of_each_currency_and_nothing_where_there_is_none(): void {
		$v  = $this->usage_product();
		$id = $v['m-None']->get_id();
		$this->every_currency();

		$out = apply_filters( 'bricks/dynamic_data/render_content', '{beltoft_usage_cap:' . $id . '}', null, 'text' );
		$this->assertSame( 'EUR:€16.50 | DKK:-', $out, 'No DKK price: no variant, and never the euro figure relabelled.' );

		$this->fake_price( $id, 'DKK', '120' );
		$out = apply_filters( 'bricks/dynamic_data/render_content', '{beltoft_usage_cap:' . $id . '}', null, 'text' );
		$this->assertStringStartsWith( 'EUR:€16.50 | DKK:', $out );
		$this->assertStringContainsString( '132', $out, 'The DKK plan (120 plus the 10 percent premium).' );
		$this->assertStringNotContainsString( '16.50 | DKK:€', $out );
		$this->assertSame( 4, $this->calls['usage-tag'], 'One renderer call per currency and per tag.' );
		$this->assertSame( 'EUR', Currency::current() );
	}

	public function test_a_domain_tag_gets_the_dkk_price_of_the_extension_and_nothing_where_there_is_none(): void {
		$this->every_currency();

		$this->assertSame( 'EUR:€12 | DKK:-', PriceTags::value( 'com' ) );

		$this->fake_amounts( array( 'DKK' => array( 'domain_price_overrides' => "com=95.20\n" ) ) );
		// The second currency's line arrives through a filter added in the middle of the request, which no site does: the figures
		// remembered for the request are dropped by hand (a saved option drops them by itself).
		PriceTags::forget();
		$out = PriceTags::value( 'com' );
		$this->assertStringStartsWith( 'EUR:€12 | DKK:', $out );
		$this->assertStringContainsString( '95', $out );
		$this->assertStringNotContainsString( '€12 | DKK:€', $out );
		$this->assertSame( 'EUR:€12 | DKK:-', PriceTags::value( 'dk' ), 'An extension with no line in DKK.' );
	}

	public function test_the_domain_search_results_and_rows_carry_the_renderer_too(): void {
		$this->every_currency();
		$this->fake_amounts( array( 'DKK' => array( 'domain_price_overrides' => "com=119\n" ) ) );

		$found = Search::decorate( array( 'results' => array( array( 'domain' => 'a.com', 'status' => 'free', 'price' => 12 ) ) ) );
		$this->assertStringStartsWith( 'EUR:', $found['results'][0]['price_html'] );
		$this->assertStringContainsString( 'DKK:', $found['results'][0]['price_html'] );
		$this->assertStringContainsString( '119', $found['results'][0]['price_html'] );

		$rows = DomainQuery::rows( $found['results'], 'a.com' );
		$this->assertStringContainsString( '119', $rows[0]['price_markup'] );
		$this->assertStringContainsString( '/ year', $rows[0]['price_markup'] );
	}

	public function test_with_no_listener_and_one_currency_every_converted_output_is_what_it_was(): void {
		$v  = $this->usage_product();
		$id = $v['m-None']->get_id();

		$this->assertSame( '€16.50', Catalog::value( 'beltoft_usage_cap', $id ) );
		$this->assertSame( '€16.50', apply_filters( 'bricks/dynamic_data/render_content', '{beltoft_usage_cap:' . $id . '}', null, 'text' ) );
		$this->assertSame( '€12', PriceTags::value( 'com', false ) );
		$this->assertSame( '€12', PriceTags::value( 'com' ), 'A tag prints the plain figure.' );

		$found = Search::decorate( array( 'results' => array( array( 'domain' => 'a.com', 'status' => 'free', 'price' => 12 ) ) ) );
		$this->assertSame( '€12', $found['results'][0]['price_text'] );
		$this->assertSame( '€12', $found['results'][0]['price_text_html'] );
		$this->assertStringContainsString( '12', $found['results'][0]['price_html'] );
		$this->assertArrayNotHasKey( 'price_markup', DomainQuery::rows( $found['results'], 'a.com' )[0], 'A row with no listener has exactly its old keys.' );
	}

	public function test_the_domain_rows_memo_is_kept_per_currency(): void {
		$ref = new \ReflectionProperty( DomainQuery::class, 'memo' );
		$ref->setAccessible( true );
		$ref->setValue( null, array( 'EUR|a' => array( 'term' => 'a', 'rows' => array( 'eur' ), 'error' => '' ) ) );
		$_GET['bds_term'] = 'a';

		$this->fake_set_currency( 'DKK' );
		$this->assertNotSame( array( 'eur' ), DomainQuery::search()['rows'], 'A window in another currency never reads the page\'s rows.' );
		$this->fake_set_currency( 'EUR' );
		$ref->setValue( null, array() );
		unset( $_GET['bds_term'] );
	}

	public function test_a_domain_figure_is_worked_out_once_in_a_request_and_again_after_the_table_changes(): void {
		$calls = 0;
		add_filter(
			'beltoft_services_price_html',
			static function ( $html ) use ( &$calls ) {
				++$calls;
				return $html;
			}
		);

		$first = PriceTags::value( 'com' );
		$this->assertSame( $first, PriceTags::value( 'com' ) );
		$this->assertSame( $first, PriceTags::render( '{beltoft_domain_register:com}' ) );
		$this->assertSame( 1, $calls, 'The same figure asked three times is worked out once.' );

		$table                 = get_option( Prices::OPTION );
		$table['com']['price'] = 15;
		update_option( Prices::OPTION, $table );

		$this->assertNotSame( $first, PriceTags::value( 'com' ), 'A changed price shows in the same request.' );
		$this->assertSame( 2, $calls );
	}
}
