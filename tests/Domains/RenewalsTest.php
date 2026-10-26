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
	public function test_next_payment_set_before_expiry(): void {
		$this->settings( array( 'domain_renew_days_before' => 30 ) );
		$this->assertSame( '2027-09-26 00:00:00', gmdate( 'Y-m-d H:i:s', Renewals::next_payment_at( '2027-10-26 00:00:00', strtotime( '2026-10-26 00:00:00 UTC' ) ) ) );
	}

	public function test_past_next_payment_moves_to_one_hour_from_now(): void {
		$now = strtotime( '2027-10-25 00:00:00 UTC' );
		$this->assertSame( $now + HOUR_IN_SECONDS, Renewals::next_payment_at( '2027-10-26 00:00:00', $now ) );
	}

	public function test_same_paid_renewal_order_renews_once(): void {
		$product = $this->service_product( 'domain' );
		$user = self::factory()->user->create();
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$sub->add_product( $product, 1 );
		$sub->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'example.com', 'remote_id' => '123', 'expires_at' => '2027-10-26 00:00:00', 'state' => 'ready' ) );
		$order = wcs_create_renewal_order( $sub );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_status( 'processing' );
		$order->save();
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2028-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$first = Renewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $first->is_done() );
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
