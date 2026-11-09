<?php
/**
 * The subscription bookkeeping of a yearly renewal, shared by domains and certificates.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftLicenses\Licenses;
use BeltoftServices\Billing\RenewalSweep;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Renewals;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\SslRenewals;
use BeltoftServices\Tests\Ssl\SslTestCase;

require_once dirname( __DIR__ ) . '/Ssl/SslTestCase.php';

class RenewalSweepTest extends SslTestCase {

	/**
	 * Name, class, expiry meta key, days setting key, no-subscription text, no-expiry text.
	 *
	 * @return array<string, array{0: string, 1: class-string, 2: string, 3: string, 4: string, 5: string}>
	 */
	public static function drivers(): array {
		return array(
			'domain' => array( 'domain', Renewals::class, 'expires_at', 'domain_renew_days_before', 'The domain subscription is unavailable.', 'The registrar expiry date is unavailable.' ),
			'ssl'    => array( 'ssl', SslRenewals::class, 'ssl_order_expires', 'ssl_renew_days_before', 'The certificate subscription is unavailable.', 'The order expiry date is unavailable.' ),
		);
	}

	public function set_up(): void {
		parent::set_up();
		foreach ( array( Renewals::class, SslRenewals::class ) as $class ) {
			remove_action( 'woocommerce_subscription_renewal_payment_complete', array( $class, 'on_paid' ), 20 );
		}
	}

	public function tear_down(): void {
		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->delete_action( (string) $id );
		}
		parent::tear_down();
	}

	/**
	 * A resource of the kind on a yearly subscription.
	 *
	 * @return array{0: Resource, 1: \WC_Subscription}
	 */
	private function resource( string $kind, string $expiry_key, array $meta = array() ): array {
		if ( 'ssl' === $kind ) {
			$r       = $this->built();
			$product = wc_get_product( (int) $r->license()['product_id'] );
			$user    = (int) $r->license()['user_id'];
		} else {
			$product = $this->service_product( 'domain' );
			$user    = self::factory()->user->create();
		}
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1 ) );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_order_items', array( 'order_id' => $sub->get_id() ) );
		$sub->add_product( $product, 1 );
		$sub->save();
		if ( 'ssl' === $kind ) {
			$this->assertTrue( Licenses::update( (int) $r->license()['id'], array( 'subscription_id' => $sub->get_id() ) ) );
			$this->assertTrue( $r->set( $meta + array( $expiry_key => '2027-10-26 00:00:00' ) ) );
		} else {
			$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
			$r       = Resource::create( $license, new DomainDriver(), array(), $meta + array( 'domain' => 'example.com', 'remote_id' => '123', $expiry_key => '2027-10-26 00:00:00', 'state' => 'ready' ) );
		}

		return array( Resource::find( $r->id() ), $sub );
	}

	private function paid_order( \WC_Subscription $sub, string $status = 'processing' ): \WC_Order {
		$order = wcs_create_renewal_order( $sub );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	private function waiting( Resource $r, \WC_Order $order ): int {
		return count( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'args' => array( $r->id(), 'renew', array( 'order' => (string) $order->get_id() ) ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' ) );
	}

	public function test_next_payment_is_before_the_expiry_and_never_in_the_past(): void {
		$this->assertSame( '2027-09-26 00:00:00', gmdate( 'Y-m-d H:i:s', RenewalSweep::next_payment_at( '2027-10-26 00:00:00', 30, strtotime( '2026-10-26 00:00:00 UTC' ) ) ) );
		$now = strtotime( '2027-10-25 00:00:00 UTC' );
		$this->assertSame( $now + HOUR_IN_SECONDS, RenewalSweep::next_payment_at( '2027-10-26 00:00:00', 30, $now ) );
		$this->assertSame( 0, RenewalSweep::next_payment_at( 'not a date', 30 ) );
	}

	/**
	 * @dataProvider drivers
	 */
	public function test_set_dates_writes_the_next_payment_and_names_the_missing_piece( string $kind, string $class, string $expiry_key, string $days_key, string $no_sub, string $no_expiry ): void {
		$this->settings( array( $days_key => 20 ) );
		list( $r, $sub ) = $this->resource( $kind, $expiry_key );
		$result = $class::set_dates( $r );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$next = (int) $result->meta()['next_payment_at'];
		$this->assertSame( strtotime( '2027-10-26 00:00:00 UTC' ) - 20 * DAY_IN_SECONDS, $next );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $next ), wcs_get_subscription( $sub->get_id() )->get_date( 'next_payment', 'gmt' ) );

		$this->assertTrue( $r->set( array( $expiry_key => 'garbage' ) ) );
		$missing = $class::set_dates( Resource::find( $r->id() ) );
		$this->assertTrue( $missing->is_retry() );
		$this->assertSame( $no_expiry, $missing->error() );

		$this->assertTrue( Licenses::update( (int) $r->license()['id'], array( 'subscription_id' => 99999999 ) ) );
		$gone = $class::set_dates( Resource::find( $r->id() ) );
		$this->assertTrue( $gone->is_retry() );
		$this->assertSame( $no_sub, $gone->error() );

		$this->assertTrue( Licenses::update( (int) $r->license()['id'], array( 'subscription_id' => 0 ) ) );
		$this->assertTrue( $class::set_dates( Resource::find( $r->id() ) )->is_done(), 'No subscription at all is nothing to date.' );
	}

	/**
	 * @dataProvider drivers
	 */
	public function test_the_sweep_queues_a_paid_renewal_once_and_skips_renewed_and_open_ones( string $kind, string $class, string $expiry_key ): void {
		list( $r, $sub ) = $this->resource( $kind, $expiry_key );
		$paid    = $this->paid_order( $sub );
		$unpaid  = $this->paid_order( $sub, 'pending' );
		list( $r2, $sub2 ) = $this->resource( $kind, $expiry_key );
		$renewed = $this->paid_order( $sub2 );
		$this->assertTrue( $r2->set( array( 'renewed_orders' => array( (string) $renewed->get_id() ) ) ) );

		$this->assertSame( 1, $class::sweep() );
		$this->assertSame( 1, $this->waiting( $r, $paid ) );
		$this->assertSame( 0, $this->waiting( $r, $unpaid ), 'An unpaid order is not queued.' );
		$this->assertSame( 0, $this->waiting( $r2, $renewed ), 'A renewed order is not queued.' );
		$this->assertSame( 0, $class::sweep(), 'A waiting job is not queued twice.' );
		$this->assertTrue( RenewalSweep::act_open( $r->id(), (string) $paid->get_id() ) );
		$this->assertFalse( RenewalSweep::act_open( $r->id(), (string) $unpaid->get_id() ) );
	}

	public function test_the_sweep_stops_at_its_limit(): void {
		list( $r, $sub ) = $this->resource( 'domain', 'expires_at' );
		$first  = $this->paid_order( $sub );
		$second = $this->paid_order( $sub );
		$this->assertSame( 1, RenewalSweep::sweep( 'domain', 'domain', 1 ) );
		$this->assertSame( 1, $this->waiting( $r, $first ) + $this->waiting( $r, $second ) );
	}

	/**
	 * @dataProvider drivers
	 */
	public function test_on_paid_queues_only_the_drivers_resources( string $kind, string $class, string $expiry_key ): void {
		list( $r, $sub ) = $this->resource( $kind, $expiry_key );
		$order = $this->paid_order( $sub );
		$other = 'ssl' === $kind ? 'domain' : 'ssl';
		$class::on_paid( $sub, $order );
		$this->assertSame( 1, $this->waiting( $r, $order ) );

		// Another driver's id queues nothing for this resource.
		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->delete_action( (string) $id );
		}
		RenewalSweep::on_paid( $sub, $order, $other );
		$this->assertSame( 0, $this->waiting( $r, $order ) );

		// An unpaid order queues nothing.
		$unpaid = $this->paid_order( $sub, 'pending' );
		$class::on_paid( $sub, $unpaid );
		$this->assertSame( 0, $this->waiting( $r, $unpaid ) );
	}
}
