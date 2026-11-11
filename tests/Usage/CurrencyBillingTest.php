<?php
/**
 * The usage invoice is made in the usage account's currency from that currency's unbilled rows only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Currency;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Minimum;
use BeltoftServices\Usage\Renewal;
use BeltoftServices\Usage\Threshold;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Money invariants of slice 4: one currency per order, no relabelling, rows billed once and never lost.
 */
class CurrencyBillingTest extends UsageTestCase {

	use FakeCurrency;

	/** @var string[] Subjects of the mails sent. */
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		// The alerts hold themselves back with transients named per currency; an earlier test may have set one.
		foreach ( array( 'usage_minimum', 'usage_threshold', 'usage_early_currency' ) as $key ) {
			delete_transient( 'beltoft_services_' . $key . '_dkk' );
		}
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		Estimate::reset();
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		$this->fake_currency_down();
		Estimate::reset();
		parent::tear_down();
	}

	/**
	 * Records and stops a mail.
	 *
	 * @param mixed $pre  Short circuit.
	 * @param array $atts Mail.
	 */
	public function catch_mail( $pre, $atts ) {
		$this->sent[] = (string) $atts['subject'];

		return true;
	}

	/** Subjects that contain a text. */
	private function mails( string $text ): array {
		return array_values( array_filter( $this->sent, static fn( $s ) => false !== strpos( $s, $text ) ) );
	}

	/** Rows ending in the middle of last month (one line per resource and item), in a currency. */
	private function hours( int $resource, int $user, int $hours, float $rate, string $currency = '' ): void {
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;

		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, 'server', 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS, $currency );
		}
	}

	/** An account in a currency. */
	private function account( int $user, string $currency ): \WC_Subscription {
		$account = $this->usage_account( $user );
		$account->set_currency( $currency );
		$account->save();

		return wcs_get_subscription( $account->get_id() );
	}

	/** The unbilled count in a currency. */
	private function open( int $user, string $currency ): int {
		return count( Ledger::unbilled( $user, time() + DAY_IN_SECONDS, $currency ) );
	}

	public function test_a_dkk_account_is_renewed_on_a_dkk_order_with_dkk_lines(): void {
		$user    = $this->new_user();
		$account = $this->account( $user, 'DKK' );
		$this->hours( 501, $user, 100, 0.2, 'DKK' );

		$order = wcs_create_renewal_order( $account );
		$fees  = array_values( $order->get_items( 'fee' ) );

		$this->assertSame( 'DKK', $order->get_currency() );
		$this->assertCount( 1, $fees );
		$this->assertEquals( 20.0, (float) $fees[0]->get_total() );
		$this->assertStringContainsString( 'kr.', html_entity_decode( $fees[0]->get_name() ), 'The rate is named in the order currency.' );
		$this->assertStringNotContainsString( '€', $fees[0]->get_name() );
		$this->assertSame( 0, $this->open( $user, 'DKK' ), 'The rows are on the order.' );
		$this->assertEquals( 20.0, (float) $order->get_meta( Renewal::META_BILLED ) );
	}

	public function test_rows_of_another_currency_are_never_billed_marked_or_lost(): void {
		$user    = $this->new_user();
		$account = $this->account( $user, 'DKK' );
		$this->hours( 501, $user, 100, 0.2, 'DKK' );
		$this->hours( 502, $user, 50, 0.05, 'EUR' );

		$order = wcs_create_renewal_order( $account );

		$this->assertEquals( 20.0, (float) $order->get_meta( Renewal::META_BILLED ), 'Only the DKK rows were billed.' );
		$this->assertCount( 1, $order->get_items( 'fee' ) );
		$this->assertSame( 50, $this->open( $user, 'EUR' ), 'The euro rows are still unbilled and not held by any order.' );
		$this->assertSame( 0, $this->open( $user, 'DKK' ) );
		$this->assertCount( 1, $this->mails( 'two currencies' ), 'The admin is told once.' );

		wcs_create_renewal_order( $account );
		$this->assertCount( 1, $this->mails( 'two currencies' ), 'Once, not on every renewal.' );
	}

	public function test_a_renewal_order_in_the_wrong_currency_adds_nothing_from_the_rows(): void {
		$user    = $this->new_user();
		$account = $this->account( $user, 'EUR' );
		$this->hours( 501, $user, 100, 0.2, 'DKK' );

		$order = wcs_create_renewal_order( $account );

		$this->assertSame( 'EUR', $order->get_currency() );
		$this->assertCount( 0, $order->get_items( 'fee' ), 'No DKK figure is relabelled as euro.' );
		$this->assertSame( 100, $this->open( $user, 'DKK' ) );
		$this->assertCount( 1, $this->mails( 'two currencies' ) );
	}

	public function test_a_row_is_claimed_only_for_an_order_of_its_currency(): void {
		$user = $this->new_user();
		$this->hours( 501, $user, 3, 0.2, 'DKK' );
		$ids = array_column( Ledger::unbilled( $user, time() + DAY_IN_SECONDS, 'DKK' ), 'id' );

		$this->assertSame( array(), Ledger::claim( $ids, 9001, 'EUR' ), 'An euro order takes no DKK row, whatever ids it is given.' );
		$this->assertSame( 3, $this->open( $user, 'DKK' ) );
		$this->assertCount( 3, Ledger::claim( $ids, 9002, 'DKK' ) );
		$this->assertSame( 0, Ledger::stray( $user, time() + DAY_IN_SECONDS, 'DKK' ) );
	}

	public function test_a_cancelled_dkk_order_gives_its_rows_back(): void {
		$user    = $this->new_user();
		$account = $this->account( $user, 'DKK' );
		$this->hours( 501, $user, 100, 0.2, 'DKK' );

		$order = wcs_create_renewal_order( $account );
		$this->assertSame( 0, $this->open( $user, 'DKK' ) );

		$order->update_status( 'cancelled' );

		$this->assertSame( 100, $this->open( $user, 'DKK' ) );
	}

	public function test_the_cap_of_a_month_never_counts_a_billed_amount_of_another_currency(): void {
		$user = $this->new_user();
		$this->hours( 501, $user, 10, 0.2, 'DKK' );
		$this->hours( 502, $user, 10, 0.05, 'EUR' );
		$eur = Ledger::unbilled( $user, time() + DAY_IN_SECONDS, 'EUR' );
		Ledger::claim( array_column( $eur, 'id' ), 9100, 'EUR' );
		$start = (int) strtotime( gmdate( 'Y-m-01 00:00:00', strtotime( $eur[0]['hour_bucket'] . ' UTC' ) ) . ' UTC' );
		$end   = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $start + 32 * DAY_IN_SECONDS ) . ' UTC' );

		$this->assertEqualsWithDelta( 0.5, Ledger::billed( 502, 'server', $start, $end, 0, 'EUR' ), 0.0001 );
		$this->assertEqualsWithDelta( 0.0, Ledger::billed( 502, 'server', $start, $end, 0, 'DKK' ), 0.0001 );

		$lines = Renewal::statement( array_merge( Ledger::unbilled( $user, time() + DAY_IN_SECONDS, 'DKK' ), $eur ) )['lines'];
		$this->assertCount( 2, $lines, 'Rows of two currencies are never added into one line.' );
	}

	public function test_the_minimum_is_the_dkk_amount_and_a_missing_dkk_minimum_does_not_carry(): void {
		$user    = $this->new_user();
		$account = $this->account( $user, 'DKK' );
		$this->settings( array( 'usage_minimum' => 5 ) );
		$this->hours( 501, $user, 100, 0.2, 'DKK' ); // 20.00 DKK.

		$order = wcs_create_renewal_order( $account );
		$this->assertCount( 1, $order->get_items( 'fee' ), 'No DKK minimum: the euro 5 is not reused, nothing is carried.' );
		$order->update_status( 'cancelled' );

		$this->fake_amounts( array( 'DKK' => array( 'usage_minimum' => 40 ) ) );
		$order = wcs_create_renewal_order( $account );

		$this->assertCount( 0, $order->get_items( 'fee' ), '20 DKK is under the 40 DKK minimum: carried.' );
		$this->assertSame( 100, $this->open( $user, 'DKK' ), 'The rows wait.' );
		$this->assertSame( 40.0, Minimum::amount( 'DKK' ) );
	}

	public function test_the_early_bill_is_made_in_dkk_against_the_dkk_threshold(): void {
		$user    = $this->new_user();
		$account = $this->account( $user, 'DKK' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();
		$this->fake_amounts( array( 'DKK' => array( 'usage_threshold_new' => 10 ) ) );
		$this->hours( 501, $user, 100, 0.2, 'DKK' ); // 20.00 DKK, over 10 DKK (and under the euro 50).

		$this->assertTrue( Threshold::check( $account ) );

		$order = $account->get_last_order( 'all', 'renewal' );
		$this->assertSame( 'DKK', $order->get_currency() );
		$this->assertEquals( 20.0, (float) $order->get_meta( Renewal::META_BILLED ) );
		$this->assertSame( 'EUR', Currency::current(), 'The job is back in its own currency.' );
		$this->assertSame( 0, $this->open( $user, 'DKK' ) );
	}

	public function test_the_early_bill_does_nothing_and_alerts_once_when_the_currency_cannot_be_put_in_force(): void {
		$user    = $this->new_user();
		$account = $this->account( $user, 'DKK' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner( false );
		$this->fake_amounts( array( 'DKK' => array( 'usage_threshold_new' => 10 ) ) );
		$this->hours( 501, $user, 100, 0.2, 'DKK' );

		$this->assertFalse( Threshold::check( $account ) );
		$this->assertFalse( Threshold::check( $account ) );

		$this->assertSame( 100, $this->open( $user, 'DKK' ) );
		$this->assertNull( $account->get_last_order( 'all', 'renewal' ) ?: null );
		$this->assertCount( 1, $this->mails( 'Early bill not made' ) );
	}

	public function test_the_estimate_is_for_the_accounts_currency_only(): void {
		$user = $this->new_user();
		$this->account( $user, 'DKK' );
		$this->hours( 501, $user, 100, 0.2, 'DKK' );
		$this->hours( 502, $user, 100, 0.05, 'EUR' );

		$estimate = Estimate::for_user( $user );

		$this->assertSame( 'DKK', $estimate['currency'] );
		$this->assertEquals( 20.0, $estimate['total'], 'The euro rows are not added to a DKK total.' );
		$this->assertSame( array( 501 ), array_keys( $estimate['resources'] ) );
	}

	public function test_a_base_account_bills_its_rows_including_rows_written_with_no_currency(): void {
		global $wpdb;

		$user    = $this->new_user();
		$account = $this->account( $user, 'EUR' );
		$this->hours( 501, $user, 100, 0.02 );
		// Old code wrote rows with an empty currency.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test write.
		$wpdb->query( "UPDATE " . Ledger::table() . " SET currency = '' WHERE user_id = " . (int) $user );

		$order = wcs_create_renewal_order( $account );

		$this->assertSame( 'EUR', $order->get_currency() );
		$this->assertEquals( 2.0, (float) $order->get_meta( Renewal::META_BILLED ) );
		$this->assertSame( 0, $this->open( $user, 'EUR' ) );
		$this->assertSame( array(), $this->mails( 'two currencies' ) );
	}
}
