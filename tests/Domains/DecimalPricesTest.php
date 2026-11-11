<?php
/**
 * Price override lines take decimals (a dot, at most two), so a price that ends in 9 including tax can be written.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Currency;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Search;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Settings;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Tests\TestCase;

class DecimalPricesTest extends TestCase {

	use FakeCurrency;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( Prices::OPTION, array( 'dk' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ), 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ) ) );
	}

	public function tear_down(): void {
		$this->fake_currency_down();
		parent::tear_down();
	}

	public function test_a_decimal_override_is_the_price_and_a_whole_one_stays_an_integer(): void {
		$this->settings( array( 'domain_price_overrides' => "dk=95.20\ncom=15\nnet=7.5" ) );

		$this->assertSame( 95.2, Prices::price( 'dk' ) );
		$this->assertSame( 15, Prices::price( 'com' ) );
		$this->assertSame( 12, Prices::price( 'org' ) === null ? 12 : 0, 'Not in the table: no price.' ) ;
	}

	public function test_a_dkk_price_of_95_20_is_119_including_25_percent_and_the_cart_and_guard_take_the_exact_amount(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'dec-domain' ) );
		$this->fake_currency_up( 'EUR' );
		$this->fake_price( $product->get_id(), 'DKK', '1' );
		$this->fake_set_currency( 'DKK' );
		$this->fake_amounts( array( 'DKK' => array( 'domain_price_overrides' => "dk=95.20\n" ) ) );
		$this->settings( array( 'currency_guard_rates' => 'DKK=7.62', 'admin_email' => 'christian.beltoft@gmail.com' ) );

		$price = Prices::price( 'dk' );
		$this->assertSame( 95.2, $price );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		$this->assertSame( 119.0, round( TaxNote::figure( (float) $price, $product ), 2 ), 'Including 25 percent tax.' );
		$this->assertStringContainsString( '119', Search::price_text( $price ), 'Shown as 119, not 120.' );

		// The cost guard compares what was paid, to the cent: 95.20 DKK is 12.49 EUR at 7.62, over the 8.00 cost.
		$this->assertEqualsWithDelta( 12.4934, (float) Currency::to_cost( 95.20, 'DKK' ), 0.0001 );
	}

	public function test_a_line_with_a_comma_or_more_than_two_decimals_stops_the_save_and_the_old_value_stays(): void {
		$this->settings( array( 'domain_price_overrides' => 'dk=96' ) );
		global $wp_settings_errors;
		$wp_settings_errors = array();

		foreach ( array( 'dk=95,20', 'dk=95.205', 'dk=0', 'dk=', '=95' ) as $bad ) {
			$out = Settings::sanitize( array( 'domain_price_overrides' => "com=15\n" . $bad ) );
			$this->assertSame( 'dk=96', $out['domain_price_overrides'], $bad );
		}

		$this->assertContains( 'beltoft_services_bad_lines_domain_price_overrides', array_column( (array) $wp_settings_errors, 'code' ) );
		$this->assertStringContainsString( 'dk=95,20', (string) $wp_settings_errors[0]['message'] );

		$wp_settings_errors = array();
		$out                = Settings::sanitize( array( 'domain_price_overrides' => "dk=95.20\n\ncom=15" ) );
		$this->assertSame( "dk=95.20\n\ncom=15", $out['domain_price_overrides'] );
		$this->assertSame( array(), (array) $wp_settings_errors );
	}
}
