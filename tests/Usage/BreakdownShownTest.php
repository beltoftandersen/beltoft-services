<?php
/**
 * In the including display the maximum shown is the maximum charged, and the rows add up to it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Usage\Breakdown;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Review of slice 6, item 4.
 */
class BreakdownShownTest extends UsageTestCase {

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
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	public function tear_down(): void {
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		parent::tear_down();
	}

	/** What the invoice charges at most: each line taxed as WooCommerce does, summed (or the total taxed once). */
	private function charged( array $lines, bool $subtotal ): float {
		if ( $subtotal ) {
			return round( array_sum( $lines ) * 1.25, 2 );
		}

		return array_sum( array_map( static fn( $l ) => round( $l + round( $l * 0.25, 2 ), 2 ), $lines ) );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: array<int, float>}> */
	public static function cases(): array {
		$out = array();

		foreach ( array( array( 45.0, 5.0 ), array( 16.5, 3.3 ), array( 7.77, 1.11 ), array( 12.35, 0.29 ), array( 99.99, 4.4 ), array( 3.21, 0.01 ) ) as list( $plan, $extra ) ) {
			// A database: the plan, the address and the extra storage are one line (the maximum).
			$out[ "db $plan + $extra" ] = array( array( 'driver' => 'db', 'plan' => $plan, 'ip' => $extra, 'storage' => $extra * 2, 'backups' => 0.0, 'max' => round( $plan + $extra * 3, 2 ), 'hourly' => 0.1 ), array( round( $plan + $extra * 3, 2 ) ) );
			// A server: the plan, the backups and the disk are a line each.
			$out[ "server $plan + $extra" ] = array( array( 'driver' => 'proxmox', 'plan' => $plan, 'ip' => 0.0, 'storage' => 0.0, 'backups' => $extra, 'disk' => $extra * 2, 'max' => round( $plan + $extra, 2 ), 'hourly' => 0.1 ), array( $plan, $extra, $extra * 2 ) );
		}

		return $out;
	}

	/**
	 * @dataProvider cases
	 *
	 * @param array<string, mixed> $parts Parts.
	 * @param float[]              $lines Invoice lines excluding tax.
	 */
	public function test_the_maximum_shown_is_the_maximum_charged_and_the_rows_add_up_to_it( array $parts, array $lines ): void {
		$v = $this->usage_product()['m-None'];
		$parts['driver'] = 'db' === $parts['driver'] ? \BeltoftServices\Proxmox\Db\DbDriver::ID : $parts['driver'];

		foreach ( array( false, true ) as $subtotal ) {
			update_option( 'woocommerce_tax_round_at_subtotal', $subtotal ? 'yes' : 'no' );
			$shown = TaxNote::in_cart( static fn() => Breakdown::shown( $parts, $v ) );

			$this->assertEqualsWithDelta( $this->charged( $lines, $subtotal ), $shown['max'], 0.0001, ( $subtotal ? 'subtotal' : 'per line' ) );
			$rows = $shown['plan'] + $shown['ip'] + $shown['storage'] + $shown['backups'] + $shown['disk'];
			$this->assertEqualsWithDelta( $shown['max'], $rows, 0.0001, 'The rows add up to the maximum.' );
		}
	}

	public function test_the_database_of_the_review_is_68_75(): void {
		$v     = $this->usage_product()['m-None'];
		$parts = array( 'driver' => \BeltoftServices\Proxmox\Db\DbDriver::ID, 'plan' => 45.0, 'ip' => 5.0, 'storage' => 5.0, 'storage_gb' => 10, 'backups' => 0.0, 'max' => 55.0, 'hourly' => 0.1 );

		$shown = TaxNote::in_cart( static fn() => Breakdown::shown( $parts, $v ) );

		$this->assertSame( 68.75, $shown['max'], 'One invoice line of 55: 68.75, not the sum of three rounded rows.' );
		$this->assertStringContainsString( '€68.75', TaxNote::in_cart( static fn() => Breakdown::blocks( $v, $parts, 'Database' )[1] ) );
	}

	public function test_nothing_is_worked_out_in_the_excluding_display(): void {
		update_option( 'woocommerce_tax_display_cart', 'excl' );
		$v = $this->usage_product()['m-None'];

		$this->assertNull( TaxNote::in_cart( static fn() => Breakdown::shown( array( 'plan' => 45.0, 'max' => 45.0 ), $v ) ) );
	}
}
