<?php
/** Paid domain renewal tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Renewals;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class RenewalsTest extends TestCase {
	/** Paying a renewal order queues its renewal job, and that Action Scheduler row would outlive the test transaction: these tests call renew() themselves. */
	public function set_up(): void {
		parent::set_up();
		remove_action( 'woocommerce_subscription_renewal_payment_complete', array( Renewals::class, 'on_paid' ), 20 );
	}

	public function test_next_payment_set_before_expiry(): void {
		$this->settings( array( 'domain_renew_days_before' => 30 ) );
		$this->assertSame( '2027-09-26 00:00:00', gmdate( 'Y-m-d H:i:s', Renewals::next_payment_at( '2027-10-26 00:00:00', strtotime( '2026-10-26 00:00:00 UTC' ) ) ) );
	}

	public function test_past_next_payment_moves_to_one_hour_from_now(): void {
		$now = strtotime( '2027-10-25 00:00:00 UTC' );
		$this->assertSame( $now + HOUR_IN_SECONDS, Renewals::next_payment_at( '2027-10-26 00:00:00', $now ) );
	}

	/** @return array{0:Resource,1:\WC_Order} A domain with a paid renewal order whose domain line totals $total. */
	private function paid_renewal( float $total = 50.0, array $meta = array() ): array {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 10.0, 'renew' => 12.5, 'transfer' => 9.0, 'restricted' => false, 'price' => 20 ) ) );
		$product = $this->service_product( 'domain' );
		$user = self::factory()->user->create();
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$this->clear_items( $sub );
		$sub->add_product( $product, 1 );
		$sub->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$r = Resource::create( $license, new DomainDriver(), array(), $meta + array( 'domain' => 'example.com', 'remote_id' => '123', 'expires_at' => '2027-10-26 00:00:00', 'state' => 'ready' ) );
		$order = wcs_create_renewal_order( $sub );
		$this->domain_line( $order, $product, $total );
		$order->set_status( 'processing' );
		$order->save();
		return array( $r, $order );
	}

	/** Item rows outlive the test transaction and can share a reused subscription id: start from none. */
	private function clear_items( \WC_Subscription $sub ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_order_items', array( 'order_id' => $sub->get_id() ) );
	}

	/** The renewal order's one domain line (stale item rows from reused ids are dropped first). */
	private function domain_line( \WC_Order $order, \WC_Product $product, float $total ): void {
		$order->remove_order_items( 'line_item' );
		$order->add_product( $product, 1, array( 'subtotal' => $total, 'total' => $total ) );
		$order->save();
	}

	private function posts( FakeClient $fake ): int {
		return count( array_filter( $fake->calls(), static fn( $call ) => 'POST' === $call['method'] ) );
	}

	public function test_renewal_paid_below_cost_places_no_order_and_alerts(): void {
		list( $r, $order ) = $this->paid_renewal( 5.0 );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$result = Renewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 0, $this->posts( $fake ) );
		$this->assertNotSame( '', (string) $r->get( 'alerted' ), 'The admin is alerted.' );
	}

	public function test_renewal_paid_exactly_the_cost_proceeds(): void {
		list( $r, $order ) = $this->paid_renewal( 12.5 );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2028-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( ( $done = Renewals::renew( $r, (string) $order->get_id() ) )->is_done(), (string) $done->error() );
		$this->assertSame( 1, $this->posts( $fake ) );
	}

	public function test_a_definitive_refusal_frees_the_marker_so_the_next_run_asks_again(): void {
		list( $r, $order ) = $this->paid_renewal();
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400, 'desc' => 'no' ) ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2028-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$first = Renewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $first->is_retry() );
		$this->assertArrayNotHasKey( (string) $order->get_id(), (array) $r->get( 'renew_started' ) );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_done() );
		$this->assertSame( 2, $this->posts( $fake ) );
	}

	public function test_a_low_balance_alerts_once_and_retries(): void {
		list( $r, $order ) = $this->paid_renewal();
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$fake = new FakeClient();
		for ( $i = 0; $i < 2; $i++ ) {
			$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
			$fake->reply( 'POST', '/domains/123/renew', new \WP_Error( 'op_920', 'Registrar request failed.', array( 'status' => 400 ) ) );
		}
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_retry() );
		$this->assertNotSame( '', (string) $r->get( 'alerted' ) );
		$hash = $r->get( 'alerted' );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_retry() );
		$this->assertSame( $hash, $r->get( 'alerted' ) );
		$this->assertSame( 2, $this->posts( $fake ), 'Each attempt asks again.' );
	}

	public function test_a_transport_error_keeps_the_marker_and_does_not_ask_twice(): void {
		list( $r, $order ) = $this->paid_renewal();
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', new \WP_Error( 'transport', 'Registrar request could not be completed.' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_retry() );
		$this->assertArrayHasKey( (string) $order->get_id(), (array) $r->get( 'renew_started' ) );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_retry() );
		$this->assertSame( 1, $this->posts( $fake ) );
	}

	public function test_a_429_with_a_registrar_code_keeps_the_marker(): void {
		list( $r, $order ) = $this->paid_renewal();
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', new \WP_Error( 'op_100', 'Registrar request failed.', array( 'status' => 429 ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_retry() );
		$this->assertArrayHasKey( (string) $order->get_id(), (array) $r->get( 'renew_started' ) );
	}

	public function test_prepaid_transfer_year_refusal_frees_its_marker_and_a_timeout_keeps_it(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 10.0, 'renew' => 12.5, 'transfer' => 0.0, 'restricted' => false, 'price' => 20 ) ) );
		$product = $this->service_product( 'domain' );
		$order = wc_create_order();
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Domain', 'quantity' => 1, 'subtotal' => 30, 'total' => 30 ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'year.com', 'mode' => 'transfer', 'remote_id' => '789', 'expires_at' => '2027-10-26 00:00:00', 'transfer_expiry_before' => '2027-10-26 00:00:00', 'order_item_id' => (int) $line->get_id() ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/789', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/789/renew', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400 ) ) );
		$fake->reply( 'GET', '/domains/789', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/789/renew', new \WP_Error( 'transport', 'x' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( Renewals::transfer_year( $r )->is_retry() );
		$this->assertFalse( (bool) $r->get( 'transfer_renew_started' ) );
		$this->assertTrue( Renewals::transfer_year( $r )->is_retry() );
		$this->assertTrue( (bool) $r->get( 'transfer_renew_started' ) );
		$this->assertSame( 2, $this->posts( $fake ) );
	}

	public function test_prepaid_transfer_year_paid_below_the_renewal_cost_places_no_order(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 10.0, 'renew' => 12.5, 'transfer' => 0.0, 'restricted' => false, 'price' => 20 ) ) );
		$product = $this->service_product( 'domain' );
		$order = wc_create_order();
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Domain', 'quantity' => 1, 'subtotal' => 0, 'total' => 0 ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'free.com', 'mode' => 'transfer', 'remote_id' => '790', 'expires_at' => '2027-10-26 00:00:00', 'transfer_expiry_before' => '2027-10-26 00:00:00', 'order_item_id' => (int) $line->get_id() ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/790', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( Renewals::transfer_year( $r )->is_failed() );
		$this->assertSame( 0, $this->posts( $fake ) );
	}

	public function test_the_sweep_queues_a_stuck_paid_renewal_and_skips_a_renewed_one(): void {
		list( $r, $order ) = $this->paid_renewal();
		list( $r2, $order2 ) = $this->paid_renewal( 50.0, array( 'domain' => 'other.com' ) );
		$r2->set( array( 'renewed_orders' => array( (string) $order2->get_id() ) ) );
		$queued = Renewals::sweep();
		$this->assertSame( 1, $queued );
		$this->assertSame( 0, Renewals::sweep(), 'A waiting job is not queued twice.' );
		$pending = as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Jobs::ACT, 'args' => array( $r->id(), 'renew', array( 'order' => (string) $order->get_id() ) ), 'group' => 'beltoft-services', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		$this->assertCount( 1, $pending );
	}

	public function test_the_sweep_is_scheduled_daily_in_the_services_group(): void {
		as_unschedule_all_actions( Renewals::SWEEP_HOOK, array(), 'beltoft-services' );
		Renewals::schedule();
		$this->assertTrue( as_has_scheduled_action( Renewals::SWEEP_HOOK, array(), 'beltoft-services' ) );
		Renewals::unschedule();
		$this->assertFalse( as_has_scheduled_action( Renewals::SWEEP_HOOK, array(), 'beltoft-services' ) );
	}

	public function test_same_paid_renewal_order_renews_once(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 10.0, 'renew' => 12.5, 'transfer' => 9.0, 'restricted' => false, 'price' => 20 ) ) );
		$product = $this->service_product( 'domain' );
		$user = self::factory()->user->create();
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$this->clear_items( $sub );
		$sub->add_product( $product, 1 );
		$sub->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'example.com', 'remote_id' => '123', 'expires_at' => '2027-10-26 00:00:00', 'state' => 'ready' ) );
		$order = wcs_create_renewal_order( $sub );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->domain_line( $order, $product, 50 );
		$order->set_status( 'processing' );
		$order->save();
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2028-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$first = Renewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $first->is_done(), (string) $first->error() );
		$r->set( $first->meta() );
		$this->assertTrue( Renewals::renew( $r, (string) $order->get_id() )->is_done() );
		$this->assertCount( 3, $fake->calls() );
	}

	public function test_dk_transfer_prepays_year_and_schedules_allowed_window(): void {
		update_option( Prices::OPTION, array( 'dk' => array( 'price' => 13, 'transfer' => 0.0, 'restricted' => true ) ) );
		$product = $this->service_product( 'domain' );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'prepaid-example.dk', 'mode' => 'transfer', 'remote_id' => '789', 'expires_at' => '2027-10-26 00:00:00', 'transfer_expiry_before' => '2027-10-26 00:00:00' ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/789', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = Renewals::transfer_year( $r );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( '2028-10-26 00:00:00', $result->meta()['prepaid_expiry'] );
		$this->assertCount( 1, $fake->calls(), 'The registrar is only read before the allowed renewal window.' );
		$this->assertNotFalse( as_next_scheduled_action( Renewals::TRANSFER_HOOK, array( $r->id() ), 'beltoft-services' ) );
	}
}
