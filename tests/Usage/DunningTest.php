<?php
/**
 * A failed usage payment pauses the usage services, a recovered one resumes them; the early bill at a threshold.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Lifecycle\Expiry;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Threshold;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Dunning (through beltoft-licenses and Lifecycle) and thresholds.
 */
class DunningTest extends UsageTestCase {

	/**
	 * Calls to the gateway hook of the renewal payment.
	 *
	 * @var array<int, array{0: mixed, 1: \WC_Order}>
	 */
	private $charges = array();

	public function set_up(): void {
		parent::set_up();
		$this->charges = array();
		add_action(
			'woocommerce_scheduled_subscription_payment_fakecard',
			function ( $amount, $order ) {
				$this->charges[] = array( $amount, $order );
			},
			10,
			2
		);
	}

	private function hours( int $resource, int $user, int $hours, float $rate ): void {
		$end = Ledger::hour( time() ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( $resource, $user, 'server', 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS );
		}
	}

	public function test_failed_payment_pauses_every_usage_service_after_the_grace_and_recovery_resumes_them(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$one  = $this->usage_vm( $v['u-None'], $user, 2, $sub->get_id() );
		$two  = $this->usage_vm( $v['u-None'], $user, 2, $sub->get_id() );

		// The renewal payment fails: WooCommerce Subscriptions puts the account on hold.
		$sub->update_status( 'on-hold' );

		foreach ( array( $one, $two ) as $r ) {
			$license = Licenses::find( (int) $r->license()['id'] );
			$this->assertNotNull( $license['expires_at'], 'The grace period starts.' );
			$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ), 'Still running during the grace period.' );

			// The grace period is over.
			Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		}

		Expiry::run();
		Jobs::run( $one->id() );
		Jobs::run( $two->id() );

		foreach ( array( $one, $two ) as $r ) {
			$fresh = Resource::find( $r->id() );
			$this->assertSame( 'paused', $fresh->get( 'desired' ) );
			$this->assertSame( 'paused', $fresh->get( 'state' ) );
		}

		// The invoice is paid: the account is active again (WooCommerce Subscriptions' status action).
		do_action( 'woocommerce_subscription_renewal_payment_complete', $sub, null );
		\BeltoftLicenses\Lifecycle\Subscriptions::activated( $sub );
		Jobs::run( $one->id() );
		Jobs::run( $two->id() );

		foreach ( array( $one, $two ) as $r ) {
			$fresh = Resource::find( $r->id() );
			$this->assertSame( 'ready', $fresh->get( 'desired' ) );
			$this->assertSame( 'ready', $fresh->get( 'state' ) );
			$this->assertNull( Licenses::find( (int) $fresh->license()['id'] )['expires_at'] );
		}
	}

	public function test_a_cancelled_account_ends_its_licenses_for_the_existing_retention(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$r    = $this->usage_vm( $v['u-None'], $user, 2, $sub->get_id() );

		$sub->update_status( 'cancelled' );
		$license = Licenses::find( (int) $r->license()['id'] );
		$this->assertNotNull( $license['expires_at'], 'Cancelled: the license ends now.' );
		Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		Expiry::run();
		Jobs::run( $r->id() );

		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'desired' ) );
		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'state' ) );
	}

	public function test_the_threshold_bills_early_once(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		// 3000 hours at 0.02 is 59.44 after the monthly caps: over the 50 of a new account.
		$this->hours( 501, $user, 3000, 0.02 );

		$this->assertSame( 50.0, Threshold::for_account( $sub ) );
		$this->assertTrue( Threshold::check( $sub ) );

		$this->assertCount( 1, $this->charges, 'The gateway hook of a scheduled payment ran.' );
		$this->assertEquals( 59.44, (float) $this->charges[0][0] );
		$this->assertSame( 'fakecard', $this->charges[0][1]->get_payment_method() );
		$this->assertGreaterThan( 0, count( $this->charges[0][1]->get_items( 'fee' ) ), 'One line per month.' );

		$this->assertFalse( Threshold::check( $sub ), 'Nothing is left to bill, and the first invoice still waits for its payment.' );
		$this->assertSame( 0, Threshold::run() );
		$this->assertCount( 1, $this->charges );
	}

	public function test_below_the_threshold_nothing_happens_and_the_threshold_is_higher_after_a_paid_usage_invoice(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 3000, 0.01 );

		$this->assertFalse( Threshold::check( $sub ), '30 is under 50.' );
		$this->assertSame( array(), $this->charges );

		$this->hours( 502, $user, 3000, 0.02 );
		$sub->update_meta_data( Account::META_CHARGED, 'yes' );
		$sub->save();
		$sub = wcs_get_subscription( $sub->get_id() );
		$this->assertSame( 250.0, Threshold::for_account( $sub ) );
		$this->assertFalse( Threshold::check( $sub ), '90 is under 250.' );

		$this->settings( array( 'usage_threshold' => 80 ) );
		$this->assertTrue( Threshold::check( $sub ), 'The threshold is a setting.' );
		$this->assertCount( 1, $this->charges );
	}

	public function test_an_account_without_a_card_is_not_billed_early(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$sub->set_payment_method( '' );
		$sub->save();
		$this->hours( 501, $user, 3000, 0.02 );

		$this->assertFalse( Threshold::check( wcs_get_subscription( $sub->get_id() ) ) );
		$this->assertSame( array(), $this->charges );
		$this->assertSame( 2, (int) Settings::get( 'usage_new_limit' ) );
	}
}
