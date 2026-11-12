<?php
/**
 * Account credit paid onto the renewal orders of subscriptions, and the promotional / paid split behind it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Invoice;
use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\RenewalCredit;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Usage\Ledger;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * Real WooCommerce Subscriptions code paths: the scheduled payment hook creates the renewal order, WooCommerce Subscriptions completes
 * an order of 0 itself and hands the gateway the rest.
 */
class RenewalCreditTest extends CreditTestCase {

	/**
	 * Gateway charges.
	 *
	 * @var array<int, array{0: mixed, 1: \WC_Order}>
	 */
	private $charges = array();

	public function set_up(): void {
		parent::set_up();
		$this->charges = array();
		delete_option( Balance::OPTION_SPLIT_FROM );
		$this->vat( false );
		$this->settings( array( 'credit_renewals' => 1, 'usage_minimum' => 0 ) );
		add_action(
			'woocommerce_scheduled_subscription_payment_fakecard',
			function ( $amount, $order ) {
				$this->charges[] = array( $amount, $order );
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		unset( $_GET['process_early_renewal'] );
		parent::tear_down();
	}

	/**
	 * A service product priced $price; $promo ticks the promotional credit box.
	 */
	private function product( bool $promo, string $price = '10', string $driver = 'fake' ): \WC_Product {
		static $n = 0;
		++$n;
		$product = $this->service_product( $driver, array(), array( 'slug' => 'rc-' . $n ) );
		$product->set_regular_price( $price );
		$product->update_meta_data( RenewalCredit::META_PROMO, $promo ? 'yes' : 'no' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * An active subscription of products.
	 *
	 * @param \WC_Product[] $products Products.
	 */
	private function subscription( int $user, array $products, string $method = 'fakecard' ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->set_billing_email( self::MAIL );
		$sub->set_billing_country( 'DK' );
		$sub->set_currency( 'EUR' );
		$sub->set_payment_method( $method );
		$sub->set_requires_manual_renewal( '' === $method );

		foreach ( $products as $product ) {
			$sub->add_product( $product, 1 );
		}

		$sub->calculate_totals();
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$sub->save();

		return wcs_get_subscription( $sub->get_id() );
	}

	private function paid( int $user, int $cents ): void {
		$this->assertTrue( Balance::credit( $user, $cents, Balance::TOPUP, 'order_item', 'seed-' . wp_generate_uuid4() ) );
	}

	private function promo( int $user, int $cents ): void {
		$this->assertTrue( Balance::credit( $user, $cents, Balance::PROMO, 'code', 'seed-' . wp_generate_uuid4() ) );
	}

	private function renew( \WC_Subscription $sub ): \WC_Order {
		do_action( 'woocommerce_scheduled_subscription_payment', $sub->get_id() );
		$order = wcs_get_subscription( $sub->get_id() )->get_last_order( 'all', 'renewal' );
		$this->assertInstanceOf( \WC_Order::class, $order );

		return $order;
	}

	/**
	 * @return array<int, \WC_Order_Item_Fee>
	 */
	private function credit_fees( \WC_Order $order ): array {
		return array_values( array_filter( $order->get_items( 'fee' ), static fn( $f ) => 'Paid from account credit' === $f->get_name() ) );
	}

	/**
	 * Cents the order's ledger rows took, by kind.
	 *
	 * @return array<string, int>
	 */
	private function rows_of( \WC_Order $order ): array {
		$out = array();

		foreach ( array( Balance::INVOICE, Balance::PROMO_USE ) as $kind ) {
			$row = Balance::find( $kind, Invoice::REF_TYPE, (string) $order->get_id() );
			if ( $row ) {
				$out[ $kind ] = -(int) $row['amount_cents'];
			}
		}

		ksort( $out );

		return $out;
	}

	// Setting and gates.

	public function test_the_setting_off_changes_nothing(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true ) ) );
		$this->paid( $user, 5000 );
		$this->settings( array( 'credit_renewals' => 0 ) );

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertCount( 1, $this->charges );
		$this->assertEqualsWithDelta( 10.0, (float) $this->charges[0][0], 0.001 );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	public function test_credit_off_changes_nothing_even_with_the_setting_on(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true ) ) );
		$this->paid( $user, 5000 );
		$this->settings( array( 'credit_enabled' => 0 ) );

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	public function test_no_balance_no_fee(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true ) ) );

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( array(), Balance::history( $user ) );
		$this->assertCount( 1, $this->charges );
	}

	public function test_a_balance_in_another_currency_is_not_used(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->assertTrue( Balance::credit( $user, 5000, Balance::TOPUP, 'order_item', 'usd-1', '', 'USD' ) );

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertCount( 1, $this->charges );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	public function test_the_filter_can_refuse_an_order(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 5000 );
		$seen = array();
		add_filter(
			'beltoft_services_credit_applies_to_order',
			static function ( $applies, $order, $uid ) use ( &$seen ) {
				$seen = array( $applies, $order instanceof \WC_Order, $uid );
				return false;
			},
			10,
			3
		);

		$order = $this->renew( $sub );

		$this->assertSame( array( true, true, $user ), $seen, 'The default is true for a renewal order.' );
		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	// Paid credit and the real WooCommerce Subscriptions paths.

	public function test_paid_credit_pays_an_unmarked_renewal_in_full_without_a_gateway_charge(): void {
		$user   = $this->new_user();
		$sub    = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 2000 );
		$done = 0;
		add_action(
			'woocommerce_subscription_renewal_payment_complete',
			static function () use ( &$done ) {
				++$done;
			}
		);

		$order = $this->renew( $sub );
		$this->assertSame( 1, $done, 'The renewal counts as paid for the lifecycle (licences, hosting, domains, certificates).' );

		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( array(), $this->charges );
		$this->assertTrue( $order->is_paid(), $order->get_status() );
		$fees = $this->credit_fees( $order );
		$this->assertCount( 1, $fees );
		$this->assertEqualsWithDelta( -10.0, (float) $fees[0]->get_total(), 0.001 );
		$this->assertSame( 1000, Balance::get( $user ) );
		$fresh = wcs_get_subscription( $sub->get_id() );
		$this->assertSame( 'active', $fresh->get_status() );
		// The same next payment as when the order is paid in the ordinary way.
		$other = $this->new_user();
		$sub2  = $this->subscription( $other, array( $this->product( false ) ) );
		$plain = $this->renew( $sub2 );
		$plain->payment_complete();
		$this->assertEqualsWithDelta( wcs_get_subscription( $sub2->get_id() )->get_time( 'next_payment' ), $fresh->get_time( 'next_payment' ), 5 );
	}

	public function test_a_rest_at_or_above_the_minimum_is_charged_to_the_gateway(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 400 );

		$order = $this->renew( $sub );

		$this->assertCount( 1, $this->charges );
		$this->assertEqualsWithDelta( 6.0, (float) $this->charges[0][0], 0.001, 'The gateway is asked for exactly the rest.' );
		$this->assertEqualsWithDelta( 6.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_a_rest_under_the_minimum_uses_no_credit(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 700 );

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertCount( 1, $this->charges );
		$this->assertEqualsWithDelta( 10.0, (float) $this->charges[0][0], 0.001 );
		$this->assertSame( 700, Balance::get( $user ) );
		$this->assertSame( array(), $this->rows_of( $order ) );
	}

	public function test_credit_that_covers_the_whole_order_is_used_whatever_the_minimum(): void {
		$this->settings( array( 'usage_minimum' => 50 ) );
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 1000 );

		$order = $this->renew( $sub );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( array(), $this->charges );
		$this->assertSame( 0, Balance::get( $user ) );
	}

	public function test_the_minimum_looks_at_promo_and_paid_credit_together(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true ) ) );
		$this->promo( $user, 300 );
		$this->paid( $user, 400 ); // Together 7.00: the rest 3.00 is under 5.00.

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 700, Balance::get( $user ) );
	}

	public function test_vat_and_line_totals_stay_credit_is_a_payment(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 5000 );

		$order = $this->renew( $sub );

		$this->assertEqualsWithDelta( 2.5, (float) $order->get_total_tax(), 0.001 );
		$this->assertCount( 1, $order->get_taxes() );
		$lines = array_values( $order->get_items( 'line_item' ) );
		$this->assertEqualsWithDelta( 10.0, (float) $lines[0]->get_total(), 0.001, 'The line is not discounted.' );
		$this->assertEqualsWithDelta( 2.5, (float) $lines[0]->get_total_tax(), 0.001 );
		$fees = $this->credit_fees( $order );
		$this->assertSame( 'none', $fees[0]->get_tax_status() );
		$this->assertEqualsWithDelta( -12.5, (float) $fees[0]->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 3750, Balance::get( $user ) );
	}

	public function test_the_credit_line_shows_in_the_order_totals_and_the_history_names_the_order(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 5000 );
		$order = $this->renew( $sub );

		$labels = array_map( static fn( $row ) => (string) $row['label'], array_values( $order->get_order_item_totals() ) );
		$this->assertTrue( (bool) array_filter( $labels, static fn( $l ) => false !== strpos( $l, 'Paid from account credit' ) ), implode( '|', $labels ) );
		$this->assertSame( 'Used on invoice #' . $order->get_order_number(), Balance::history( $user )[0]['note'] );
	}

	// Promotional credit.

	public function test_promo_only_pays_a_marked_product(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true ) ) );
		$this->promo( $user, 2000 );

		$order = $this->renew( $sub );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( array(), $this->charges );
		$this->assertSame( 1000, Balance::get( $user ) );
		$this->assertSame( 1000, Balance::promo_left( $user ) );
		$this->assertSame( array( Balance::PROMO_USE => 1000 ), $this->rows_of( $order ) );
	}

	public function test_promo_only_does_not_pay_an_unmarked_product(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->promo( $user, 2000 );

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertCount( 1, $this->charges );
		$this->assertEqualsWithDelta( 10.0, (float) $this->charges[0][0], 0.001 );
		$this->assertSame( 2000, Balance::get( $user ) );
	}

	public function test_paid_credit_pays_an_unmarked_product_and_the_promo_stays(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->promo( $user, 600 );
		$this->paid( $user, 1000 );

		$order = $this->renew( $sub );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 600, Balance::get( $user ) );
		$this->assertSame( 600, Balance::promo_left( $user ) );
		$this->assertSame( array( Balance::INVOICE => 1000 ), $this->rows_of( $order ) );
	}

	public function test_a_mixed_order_caps_promo_at_the_marked_lines_and_paid_covers_the_rest(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true, '10' ), $this->product( false, '20' ) ) ); // 30.00.
		$this->promo( $user, 2500 ); // Only 10.00 of it can pay the marked line.
		$this->paid( $user, 1500 );

		$order = $this->renew( $sub );

		$this->assertSame( array( Balance::INVOICE => 1500, Balance::PROMO_USE => 1000 ), $this->rows_of( $order ) );
		$this->assertEqualsWithDelta( 5.0, (float) $order->get_total(), 0.001 );
		$this->assertCount( 1, $this->charges );
		$this->assertEqualsWithDelta( 5.0, (float) $this->charges[0][0], 0.001 );
		$fees = $this->credit_fees( $order );
		$this->assertCount( 1, $fees, 'One fee line with the combined amount.' );
		$this->assertEqualsWithDelta( -25.0, (float) $fees[0]->get_total(), 0.001 );
		$this->assertSame( 1500, Balance::get( $user ), 'The promo credit that cannot pay stays.' );
		$this->assertSame( 1500, Balance::promo_left( $user ) );
	}

	public function test_the_promo_cap_counts_the_vat_of_the_marked_lines(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true, '10' ) ) ); // 12.50 with VAT.
		$this->promo( $user, 2000 );

		$order = $this->renew( $sub );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 750, Balance::get( $user ) );
	}

	public function test_a_variation_reads_the_box_of_its_parent(): void {
		$parent = $this->service_product( 'fake', array(), array( 'slug' => 'rc-var', 'variations' => array( array(), array() ) ) );
		$parent->update_meta_data( RenewalCredit::META_PROMO, 'yes' );
		$parent->save();
		$variation_id = (int) $parent->get_children()[0];

		$this->assertTrue( RenewalCredit::promo_allowed( $variation_id ) );
		$this->assertTrue( RenewalCredit::promo_allowed( $parent->get_id() ) );
		$this->assertFalse( RenewalCredit::promo_allowed( 0 ) );
		$parent->update_meta_data( RenewalCredit::META_PROMO, 'no' );
		$parent->save();
		$this->assertFalse( RenewalCredit::promo_allowed( $variation_id ) );
	}

	public function test_the_promo_line_filter_can_change_one_line(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->promo( $user, 2000 );
		add_filter( 'beltoft_services_promo_credit_line', static fn( $allowed, $item ) => $item instanceof \WC_Order_Item_Product ? true : $allowed, 10, 2 );

		$order = $this->renew( $sub );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 1000, Balance::get( $user ) );
	}

	public function test_the_product_box_saves_and_reads(): void {
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$product = $this->product( false );
		$_POST[ RenewalCredit::META_PROMO ] = 'yes';
		RenewalCredit::save_product_field( $product );
		$this->assertSame( 'yes', $product->get_meta( RenewalCredit::META_PROMO ) );
		unset( $_POST[ RenewalCredit::META_PROMO ] );
		RenewalCredit::save_product_field( $product );
		$this->assertSame( 'no', $product->get_meta( RenewalCredit::META_PROMO ) );

		ob_start();
		RenewalCredit::product_field( $product );
		$this->assertStringContainsString( 'Promotional credit can pay renewals', (string) ob_get_clean() );
	}

	// Split and attribution.

	public function test_the_total_balance_is_the_ledger_sum_through_usage_and_a_renewal(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true, '10' ) ) );
		$this->promo( $user, 700 );
		$this->paid( $user, 1000 );

		// A usage invoice takes promo first, then paid.
		$usage = $this->usage_account( $user );
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < 100; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02, $end - $i * HOUR_IN_SECONDS );
		}
		$invoice = wcs_create_renewal_order( $usage ); // 2.00.

		$this->assertSame( array( Balance::PROMO_USE => 200 ), $this->rows_of( $invoice ), 'Usage: promo first.' );
		$this->assertSame( 1500, Balance::get( $user ) );
		$this->assertSame( 500, Balance::promo_left( $user ) );

		$order = $this->renew( $sub ); // 10.00, marked: promo 5.00 then paid 5.00.
		$this->assertSame( array( Balance::INVOICE => 500, Balance::PROMO_USE => 500 ), $this->rows_of( $order ) );
		$this->assertSame( 500, Balance::get( $user ) );
		$this->assertSame( 0, Balance::promo_left( $user ) );
		$this->assertSame( 500, Balance::recount( $user ), 'The stored balance is the ledger sum.' );
	}

	public function test_the_usage_account_is_not_credited_twice(): void {
		$user = $this->new_user();
		$this->paid( $user, 1000 );
		$usage = $this->usage_account( $user );
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < 100; $i++ ) {
			Ledger::record( 502, $user, 'server', 1.0, 'hour', 0.02, $end - $i * HOUR_IN_SECONDS );
		}

		$order = wcs_create_renewal_order( $usage );

		$this->assertCount( 1, $this->credit_fees( $order ) );
		$this->assertSame( 800, Balance::get( $user ) );
		$this->assertCount( 1, array_filter( Balance::history( $user ), static fn( $r ) => 'invoice' === $r['kind'] ) );
	}

	public function test_historic_debits_are_read_promo_first_then_paid_in_ledger_order(): void {
		$user = $this->new_user();
		$this->paid( $user, 500 );
		$this->promo( $user, 300 );
		$this->assertTrue( Balance::debit( $user, 400, Balance::INVOICE, 'order', 'old-1' ) );
		$this->assertTrue( Balance::debit( $user, 200, Balance::INVOICE, 'order', 'old-2' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture: the cutoff is the highest id that exists.
		update_option( Balance::OPTION_SPLIT_FROM, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Balance::table() ) ) );

		// 300 promo + 100 paid, then 200 paid: no promo left.
		$this->assertSame( 0, Balance::promo_left( $user ) );
		$this->assertSame( 200, Balance::get( $user ) );
		$this->assertSame( 200, Balance::recount( $user ) );

		// With promo credit added after the cutoff, the historic debits do not touch it.
		$this->promo( $user, 100 );
		$this->assertSame( 100, Balance::promo_left( $user ) );
		$this->assertSame( 300, Balance::get( $user ) );
	}

	public function test_a_historic_order_gives_back_to_promo_what_it_took_from_promo(): void {
		$user = $this->new_user();
		$this->promo( $user, 300 );
		$this->paid( $user, 500 );
		$this->assertTrue( Balance::debit( $user, 400, Balance::INVOICE, 'order', '9001' ) ); // Took 300 promo, 100 paid.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture.
		update_option( Balance::OPTION_SPLIT_FROM, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Balance::table() ) ) );
		$this->assertSame( 0, Balance::promo_left( $user ) );

		$this->assertTrue( Balance::credit( $user, 400, Balance::ADJUST, Invoice::REF_RETURN, '9001' ) );

		$this->assertSame( 300, Balance::promo_left( $user ) );
		$this->assertSame( 800, Balance::get( $user ) );
	}

	public function test_a_fresh_install_starts_the_cutoff_at_zero_and_new_invoice_rows_are_paid_only(): void {
		$this->assertSame( 0, Balance::split_from() );
		$user = $this->new_user();
		$this->promo( $user, 300 );
		$this->paid( $user, 500 );
		$this->assertTrue( Balance::debit( $user, 400, Balance::INVOICE, 'order', 'new-1' ) );

		$this->assertSame( 300, Balance::promo_left( $user ), 'A new invoice row is paid only.' );
		$this->assertSame( 0, (int) get_option( Balance::OPTION_SPLIT_FROM ) );
	}

	public function test_a_refund_or_chargeback_takes_paid_credit_first(): void {
		$user = $this->new_user();
		$this->promo( $user, 300 );
		$this->paid( $user, 500 );
		$this->assertTrue( Balance::debit( $user, 500, Balance::REFUND, 'order_item', 'r-1' ) );

		$this->assertSame( 300, Balance::promo_left( $user ) );
		$this->assertTrue( Balance::debit( $user, 100, Balance::CHARGEBACK, 'order_item', 'c-1' ) );
		$this->assertSame( 200, Balance::promo_left( $user ), 'Nothing paid is left: the promo takes it.' );
	}

	// Safety: twice, recalculation, give back.

	public function test_the_hook_firing_twice_credits_once(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 5000 );
		$order = $this->renew( $sub );

		$again = apply_filters( 'wcs_renewal_order_created', wc_get_order( $order->get_id() ), wcs_get_subscription( $sub->get_id() ) );

		$this->assertCount( 1, $this->credit_fees( $again ) );
		$this->assertSame( 4000, Balance::get( $user ) );
		$this->assertSame( 0, Invoice::apply( wc_get_order( $order->get_id() ), $user ) );
		$this->assertSame( 4000, Balance::get( $user ) );
	}

	public function test_an_admin_recalculation_keeps_the_credit_and_the_vat(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 500 );
		$order = $this->renew( $sub ); // 12.50 less 5.00.

		$order = wc_get_order( $order->get_id() );
		$order->calculate_totals( true );
		$order->save();

		$order = wc_get_order( $order->get_id() );
		$this->assertEqualsWithDelta( 7.5, (float) $order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 2.5, (float) $order->get_total_tax(), 0.001 );
		$fees = $this->credit_fees( $order );
		$this->assertEqualsWithDelta( -5.0, (float) $fees[0]->get_total(), 0.001 );
	}

	public function test_a_failed_payment_gives_credit_back_and_the_retry_order_is_credited_again(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true, '10' ), $this->product( false, '10' ) ) );
		$this->promo( $user, 500 );
		$this->paid( $user, 500 ); // 10.00 of 20.00.
		$order = $this->renew( $sub );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertEqualsWithDelta( 10.0, (float) $order->get_total(), 0.001 );

		$order->update_status( 'failed' );

		$this->assertSame( 1000, Balance::get( $user ) );
		$this->assertSame( 500, Balance::promo_left( $user ), 'The promo part went back to promo.' );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( array(), $this->credit_fees( $fresh ) );
		$this->assertEqualsWithDelta( 20.0, (float) $fresh->get_total(), 0.001, 'Payable in full.' );

		$retry = wcs_create_renewal_order( wcs_get_subscription( $sub->get_id() ) );
		$this->assertEqualsWithDelta( 10.0, (float) $retry->get_total(), 0.001, 'A new renewal order behaves the same.' );
		$this->assertSame( 0, Balance::get( $user ) );
	}

	public function test_cancelled_and_refunded_orders_give_back_once_and_each_part_goes_home(): void {
		foreach ( array( 'cancelled', 'refunded' ) as $status ) {
			$user = $this->new_user();
			$sub  = $this->subscription( $user, array( $this->product( true, '10' ), $this->product( false, '20' ) ) );
			$this->promo( $user, 300 );
			$this->paid( $user, 2000 );
			$order = $this->renew( $sub ); // 30.00: promo 3.00, paid 20.00, rest 7.00.
			$this->assertSame( 0, Balance::get( $user ), $status );

			$order->update_status( $status );
			$this->assertSame( 2300, Balance::get( $user ), $status );
			$this->assertSame( 300, Balance::promo_left( $user ), $status );

			do_action( 'woocommerce_order_status_' . $status, $order->get_id() );
			$this->assertSame( 2300, Balance::get( $user ), 'Once only: ' . $status );
			$this->assertSame( 2300, Balance::recount( $user ) );
		}
	}

	public function test_an_unpaid_credited_order_that_is_deleted_gives_credit_back_a_paid_one_does_not(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false, '10' ) ) );
		$this->paid( $user, 400 );
		$order = $this->renew( $sub ); // Pending, 6.00 left.
		$this->assertSame( 0, Balance::get( $user ) );

		wc_get_order( $order->get_id() )->delete( true );
		$this->assertSame( 400, Balance::get( $user ) );

		$user2 = $this->new_user();
		$sub2  = $this->subscription( $user2, array( $this->product( false, '10' ) ) );
		$this->paid( $user2, 5000 );
		$paid = $this->renew( $sub2 );
		$this->assertTrue( $paid->is_paid() );
		wc_get_order( $paid->get_id() )->delete( true );
		$this->assertSame( 4000, Balance::get( $user2 ) );
	}

	public function test_the_early_renewal_of_the_modal_is_not_credited(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 5000 );
		$_GET['process_early_renewal'] = '1';

		$order = wcs_create_renewal_order( $sub );

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	public function test_orders_that_are_not_renewals_are_never_credited(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$this->paid( $user, 5000 );

		$resubscribe = wcs_create_resubscribe_order( $sub );
		$this->assertInstanceOf( \WC_Order::class, $resubscribe );
		$this->assertSame( array(), $this->credit_fees( $resubscribe ) );

		$checkout = wc_create_order( array( 'customer_id' => $user ) );
		$checkout->add_product( $this->product( false ), 1 );
		$checkout->calculate_totals();
		$checkout->update_status( 'processing' );
		$this->assertSame( array(), $this->credit_fees( wc_get_order( $checkout->get_id() ) ) );

		$topup = $this->topup_order( $user, 1000, 'processing' );
		$this->assertSame( array(), $this->credit_fees( $topup ) );
		$this->assertSame( 6000, Balance::get( $user ), 'Only the top-up itself changed the balance.' );
	}

	// Store modes.

	public function test_a_manual_renewal_store_with_enough_credit_is_paid(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ), '' );
		$this->paid( $user, 5000 );

		$order = $this->renew( $sub );

		$this->assertTrue( $order->is_paid(), $order->get_status() );
		$this->assertSame( array(), $this->charges );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
		$this->assertSame( 4000, Balance::get( $user ) );
	}

	public function test_a_manual_renewal_with_part_credit_waits_for_the_rest(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ), '' );
		$this->paid( $user, 400 );

		$order = $this->renew( $sub );

		$this->assertFalse( $order->is_paid() );
		$this->assertEqualsWithDelta( 6.0, (float) $order->get_total(), 0.001 );
		$this->assertTrue( $order->needs_payment() );
		$this->assertSame( array(), $this->charges );
	}

	// Registrar spend and lifecycle.

	public function test_a_domain_renewal_paid_by_credit_keeps_its_line_for_the_spend_guard(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 10.0, 'renew' => 12.5, 'transfer' => 9.0, 'restricted' => false, 'price' => 20 ) ) );
		foreach ( array( false, true ) as $marked ) {
			$user = $this->new_user();
			$sub  = $this->subscription( $user, array( $this->product( $marked, '20', 'domain' ) ) );
			$this->promo( $user, 3000 );
			$this->paid( $user, 3000 );

			$order = $this->renew( $sub );

			$this->assertTrue( $order->is_paid(), 'Paid by credit, marked: ' . ( $marked ? 'yes' : 'no' ) );
			$item = array_values( $order->get_items( 'line_item' ) )[0];
			$this->assertEqualsWithDelta( 20.0, (float) $item->get_total(), 0.001, 'The line is what the customer was charged, not a discounted one.' );
			$this->assertNull( SpendGuard::problem( 'example.com', 'renew', $item ), 'The guard reads the line, not the order total.' );
			$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		}
	}

	public function test_a_licence_service_renewal_keeps_the_licence_active(): void {
		$user    = $this->new_user();
		$product = $this->product( false );
		$sub     = $this->subscription( $user, array( $product ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$this->paid( $user, 5000 );

		$order = $this->renew( $sub );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
		$this->assertSame( 'active', (string) \BeltoftLicenses\Licenses::find( (int) $license['id'] )['status'] );
	}

	// What the customer reads.

	public function test_the_credit_page_shows_the_promo_part_and_one_line_per_order(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( true, '10' ) ) );
		$this->promo( $user, 300 );
		$this->paid( $user, 200 );
		$this->renew( $sub ); // 3.00 promo + 2.00 paid, 5.00 left to the card.
		$this->promo( $user, 800 );
		wp_set_current_user( $user );

		$data = Page::data();
		$this->assertSame( 800, $data['promo'] );
		$lines = array_values( array_filter( $data['history'], static fn( $r ) => 'order' === $r['ref_type'] ) );
		$this->assertCount( 1, $lines, 'The promotional and the paid part are one line.' );
		$this->assertSame( '-500', (string) $lines[0]['amount_cents'] );

		$html = Page::html();
		$this->assertStringContainsString( 'is promotional credit', $html );
	}

	public function test_the_subscription_page_says_credit_pays_first_only_when_it_can(): void {
		$user = $this->new_user();
		$sub  = $this->subscription( $user, array( $this->product( false ) ) );
		$say  = static function ( $s ): string {
			ob_start();
			RenewalCredit::subscription_note( $s );
			return (string) ob_get_clean();
		};

		$this->assertSame( '', $say( $sub ), 'No balance.' );
		$this->promo( $user, 500 );
		$this->assertSame( '', $say( $sub ), 'Promo credit cannot pay an unmarked product.' );
		$this->paid( $user, 500 );
		$this->assertStringContainsString( 'Renewals are paid from your account credit first', $say( $sub ) );
		$this->settings( array( 'credit_renewals' => 0 ) );
		$this->assertSame( '', $say( $sub ), 'Setting off.' );
	}
}
