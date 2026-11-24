<?php
/**
 * Hosting add-on prices follow the tax display (moved here from PriceDisplayInclTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Tests\TestCase;

/**
 * Price display.
 */
final class AddonPriceDisplayTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		update_option( 'woocommerce_tax_display_cart', 'incl' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		foreach ( array( '' => '25.0000', 'reduced-rate' => '10.0000' ) as $class => $rate ) {
			\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => $rate, 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => $class ) );
		}
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	public function test_the_hosting_add_on_prices_follow_the_display(): void {
		$this->assertSame( '€10.00', \BeltoftServices\Hosting\Addons::shown( 8.0, 'EUR' ) );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );
		$this->assertSame( '€8.00', \BeltoftServices\Hosting\Addons::shown( 8.0, 'EUR' ) );
	}
}
