<?php
/**
 * Shared set-up of the account credit tests.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Topup;
use BeltoftServices\Tests\Usage\UsageTestCase;
use BeltoftServices\Usage\Estimate;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

/**
 * Base class: credit on, a clean ledger, and a top-up order builder.
 */
abstract class CreditTestCase extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Balance::table() );
		delete_option( Topup::OPTION_PRODUCT );
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'credit_enabled' => 1, 'admin_email' => self::MAIL ) );
		Estimate::reset();
	}

	public function tear_down(): void {
		$this->vat( false );
		Estimate::reset();
		parent::tear_down();
	}

	/**
	 * Turns a flat 25 percent VAT on or off.
	 */
	protected function vat( bool $on ): void {
		update_option( 'woocommerce_calc_taxes', $on ? 'yes' : 'no' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_prices_include_tax', 'no' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		if ( $on ) {
			\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		}
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	/**
	 * A top-up order whose customer pays $cents (no VAT, whatever the tax settings), taxes calculated, in the given status.
	 *
	 * @param int    $user   Customer.
	 * @param int    $cents  Amount the customer pays, in cents.
	 * @param string $status Status to move it to ('' leaves it pending).
	 */
	protected function topup_order( int $user, int $cents, string $status = '' ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_billing_country( 'DK' );
		$order->set_billing_email( self::MAIL );
		$order->set_currency( 'EUR' );

		$item = new \WC_Order_Item_Product();
		$item->set_product( wc_get_product( Topup::product_id() ) );
		$item->set_quantity( 1 );
		$item->set_name( Topup::line_name() );
		$amount = number_format( $cents / 100, 2, '.', '' );
		$item->set_subtotal( $amount );
		$item->set_total( $amount );
		$item->add_meta_data( Topup::META_AMOUNT, $amount, true );
		$order->add_item( $item );
		$order->calculate_totals( true );
		$order->save();

		if ( '' !== $status ) {
			$order->update_status( $status );
		}

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Id of the first top-up line of an order.
	 */
	protected function line_id( \WC_Order $order ): int {
		return (int) array_key_first( Topup::items( $order ) );
	}
}
