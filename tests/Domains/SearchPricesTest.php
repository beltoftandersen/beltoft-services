<?php
/** The domain search answer keeps its shape for the API and MCP; only the visitor's route is decorated. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\TestCase;

class SearchPricesTest extends TestCase {

	private function client( string $name ): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 12, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => $name . '.com', 'status' => 'free', 'is_premium' => false ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	private function incl(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Domain' );
		$product->update_meta_data( \BeltoftServices\Product::META_DRIVER, 'domain' );
		$product->set_regular_price( '1' );
		$product->set_status( 'publish' );
		$product->save();
	}

	public function test_find_keeps_the_shape_the_api_and_mcp_return_whatever_the_display(): void {
		$this->incl();
		$name = 'shape-' . strtolower( wp_generate_password( 8, false, false ) );
		$this->client( $name );

		$found = Search::find( $name . '.com' );

		$this->assertSame( array( 'results' ), array_keys( $found ) );
		$this->assertSame( array( 'domain', 'display', 'status', 'price', 'transfer', 'note' ), array_keys( $found['results'][0] ) );
		$this->assertSame( 12, $found['results'][0]['price'], 'The price number is excluding tax.' );
	}

	public function test_the_visitor_answer_adds_the_price_as_the_page_prints_it_and_the_tax_display(): void {
		$this->incl();
		$name = 'visitor-' . strtolower( wp_generate_password( 8, false, false ) );
		$this->client( $name );

		$found = Search::visitor_search( $name . '.com' );

		$this->assertSame( 12, $found['results'][0]['price'] );
		$this->assertSame( '€15', $found['results'][0]['price_text'] );
		$this->assertSame( wp_kses_post( wc_price( 15, array( 'decimals' => 0 ) ) ), $found['results'][0]['price_html'] );
		$this->assertSame( 'incl', $found['tax'] );
		$this->assertSame( 'EUR', $found['currency'] );

		update_option( 'woocommerce_tax_display_shop', 'excl' );
		$this->assertSame( 'excl', Search::decorate( $found )['tax'] );
		$this->assertSame( '€12', Search::decorate( $found )['results'][0]['price_text'] );
	}

	public function test_a_token_request_gets_the_plain_answer_and_a_browser_the_decorated_one(): void {
		$this->incl();
		$name = 'route-' . strtolower( wp_generate_password( 8, false, false ) );
		$this->client( $name );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/domains/check' );
		$request->set_param( 'name', $name . '.com' );
		$prop = new \ReflectionProperty( Tokens::class, 'current' );
		$prop->setAccessible( true );

		$prop->setValue( null, array( 'user' => 1 ) );
		$plain = Search::rest_check( $request )->get_data();
		$prop->setValue( null, null );
		$request->set_param( 'name', $name . '.com' );
		$browser = Search::rest_check( $request )->get_data();

		$this->assertSame( array( 'results' ), array_keys( $plain ) );
		$this->assertArrayHasKey( 'price_html', $browser['results'][0] );
		$this->assertArrayHasKey( 'tax', $browser );
	}

	public function test_the_price_html_with_a_domain_product_is_the_including_figure(): void {
		$this->incl();

		$this->assertSame( wp_kses_post( wc_price( 15, array( 'decimals' => 0 ) ) ), Search::price_html( 12 ) );
		$this->assertSame( '€15', Search::price_text( 12 ) );
		$this->assertSame( 'incl', TaxNote::display() );
	}

	public function test_a_price_of_a_thousand_or_more_carries_the_shops_thousands_separator(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_price_thousand_sep', ',' );
		update_option( 'woocommerce_calc_taxes', 'no' );

		$this->assertSame( '€1,234', Search::price_text( 1234 ) );
		$this->assertStringContainsString( '1,234', Search::price_html( 1234 ) );
		$this->assertSame( '€12', Search::price_text( 12 ) );
	}
}
