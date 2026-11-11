<?php
/**
 * The meter and the usage settings in a second currency (through the plugin's filters, with no add-on).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Minimum;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Threshold;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * A usage row carries the currency of the account it is metered for and is priced from that currency's rates.
 */
class CurrencyMeterTest extends UsageTestCase {

	use FakeCurrency;

	/** @var string[] Subjects of the admin mails sent. */
	private array $alerts = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$this->alerts = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		$this->fake_currency_down();
		parent::tear_down();
	}

	/**
	 * Records an admin mail and stops it.
	 *
	 * @param mixed $pre  Short circuit.
	 * @param array $atts Mail.
	 */
	public function catch_mail( $pre, $atts ) {
		$this->alerts[] = (string) $atts['subject'];

		return true;
	}

	/** Subjects of the admin mails sent that contain a text. */
	private function sent( string $text ): array {
		return array_values( array_filter( $this->alerts, static fn( $s ) => false !== strpos( $s, $text ) ) );
	}

	/** Rows of a resource. */
	private function rows( int $resource_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Ledger::table() . ' WHERE resource_id = %d ORDER BY hour_bucket', $resource_id ), ARRAY_A );
	}

	/** A DKK usage account and a server on it. */
	private function dkk_server( array $v, int $user ): array {
		$account = $this->usage_account( $user );
		$account->set_currency( 'DKK' );
		$account->save();

		return array( $account, $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() ) );
	}

	public function test_a_base_currency_account_is_metered_as_before_and_carries_the_shop_currency(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );

		Meter::run( time() );
		$rows = $this->rows( $r->id() );

		$this->assertNotEmpty( $rows );
		$this->assertEquals( 0.02260274, (float) $rows[0]['rate'] );
		$this->assertSame( 'EUR', $rows[0]['currency'] );
	}

	public function test_a_dkk_account_is_metered_in_dkk_from_the_dkk_price(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();
		$this->fake_price( $v['m-None']->get_id(), 'DKK', '30' );
		list( , $r ) = $this->dkk_server( $v, $user );

		Meter::run( time() );
		$rows = $this->rows( $r->id() );

		$this->assertNotEmpty( $rows );
		$this->assertEquals( Rates::hourly( 30.0, 10.0 ), (float) $rows[0]['rate'], 'Priced from the DKK figure, not the euro 15.' );
		$this->assertSame( array( 'DKK' ), array_values( array_unique( array_column( $rows, 'currency' ) ) ) );
		$this->assertSame( 'EUR', \BeltoftServices\Currency::current(), 'The job is back in its own currency.' );
	}

	public function test_a_currency_with_no_rate_is_not_metered_and_the_euro_meta_is_never_relabelled(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();
		// No DKK price on the monthly sibling, and a euro figure on the variation's own meta.
		$v['u-None']->update_meta_data( Rates::META_MONTHLY, '20' );
		$v['u-None']->save();
		list( , $r ) = $this->dkk_server( $v, $user );

		$this->assertSame( 0, Meter::run( time() ) );
		$this->assertSame( array(), $this->rows( $r->id() ) );
		$this->assertCount( 1, $this->sent( 'not metered' ), 'One alert.' );

		Meter::run( time() );
		$this->assertCount( 1, $this->sent( 'not metered' ), 'Once per resource and currency.' );
	}

	public function test_the_meta_fallback_still_prices_a_base_currency_account(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$v['u-None']->update_meta_data( Rates::META_MONTHLY, '20' );
		$v['u-None']->save();
		$v['m-None']->set_regular_price( '' );
		$v['m-None']->save();
		\WC_Product_Variable::sync( $v['u-None']->get_parent_id() );
		$r = $this->usage_vm( $v['u-None'], $user, 5 );

		Meter::run( time() );

		$this->assertEquals( Rates::hourly( 20.0, 10.0 ), (float) $this->rows( $r->id() )[0]['rate'] );
	}

	public function test_a_runner_that_forces_nothing_writes_nothing_and_alerts_once(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner( false );
		$this->fake_price( $v['m-None']->get_id(), 'DKK', '30' );
		list( , $r ) = $this->dkk_server( $v, $user );

		$this->assertSame( 0, Meter::run( time() ) );
		$this->assertSame( array(), $this->rows( $r->id() ) );
		$this->assertCount( 1, $this->sent( 'not metered' ) );
	}

	public function test_the_threshold_of_an_account_is_read_in_its_currency(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );

		$this->assertSame( 50.0, Threshold::for_account( $account ), 'Base currency: the setting, as before.' );

		$account->set_currency( 'DKK' );
		$account->save();
		$this->assertNull( Threshold::for_account( $account ), 'No amount for DKK.' );

		$this->fake_amounts( array( 'DKK' => array( 'usage_threshold_new' => 400 ) ) );
		$this->assertSame( 400.0, Threshold::for_account( $account ) );
	}

	public function test_no_threshold_amount_means_no_early_bill_and_one_alert(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$account->set_currency( 'DKK' );
		$account->save();
		Ledger::record( 900, $user, 'server', 1.0, 'hour', 99.0, time() - 5 * HOUR_IN_SECONDS, 'DKK' );

		$this->assertFalse( Threshold::check( $account ) );
		$this->assertFalse( Threshold::check( $account ) );
		$this->assertCount( 1, $this->sent( 'early bill' ) );
	}

	public function test_the_minimum_follows_the_currency_and_a_missing_one_is_off_with_one_alert(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );

		$this->assertSame( 5.0, Minimum::amount() );
		$this->assertSame( 5.0, Minimum::amount( 'EUR' ) );
		$this->assertSame( 0.0, Minimum::amount( 'DKK' ) );
		Minimum::amount( 'DKK' );
		$this->assertCount( 1, $this->sent( 'usage minimum' ) );

		$this->fake_amounts( array( 'DKK' => array( 'usage_minimum' => 40 ) ) );
		$this->assertSame( 40.0, Minimum::amount( 'DKK' ) );
		$this->assertTrue( Minimum::carries( 3000, 0, 'DKK' ) );
		$this->assertFalse( Minimum::carries( 4000, 0, 'DKK' ) );
	}

	public function test_the_minimum_filter_gets_the_currency(): void {
		$seen = array();
		$f    = function ( $amount, $currency = '' ) use ( &$seen ) {
			$seen[] = $currency;
			return $amount;
		};
		add_filter( 'beltoft_services_usage_minimum', $f, 10, 2 );

		Minimum::amount();

		remove_filter( 'beltoft_services_usage_minimum', $f, 10 );
		$this->assertSame( array( 'EUR' ), $seen );
	}

	public function test_the_usage_account_answers_the_customers_currency_after_earlier_listeners(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$account->set_currency( 'DKK' );
		$account->save();

		$this->assertSame( 'DKK', \BeltoftServices\Currency::of_user( $user ) );
		$this->assertSame( 'EUR', \BeltoftServices\Currency::of_user( $this->new_user() ), 'No account: the shop currency.' );

		$this->fake_user_currencies( array( $user => 'SEK' ) );
		$this->assertSame( 'SEK', \BeltoftServices\Currency::of_user( $user ), 'The bridge, at priority 10, comes first.' );
	}
}
