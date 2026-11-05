<?php
/** Reissue, validation change, cancel, refresh and renewal of a built certificate. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\Csr;
use BeltoftServices\Ssl\Keys;
use BeltoftServices\Ssl\Operations;
use BeltoftServices\Ssl\SslDriver;
use BeltoftServices\Ssl\SslRenewals;

require_once __DIR__ . '/SslTestCase.php';

class SslOperationsTest extends SslTestCase {

	public function test_a_customer_reissue_makes_a_new_key_and_the_new_certificate_is_delivered_by_refresh(): void {
		$r = $this->built();
		$old = (string) $r->get( 'ssl_serial' );
		$old_key = Keys::existing( $r );
		$result = Operations::reissue( $r, array() );
		$this->assertTrue( $result->is_done(), $result->error() );
		$r = Resource::find( $r->id() );
		$this->assertTrue( (bool) $r->get( 'ssl_reissue_pending' ) );
		$this->assertNotSame( $old_key, Keys::existing( $r ), 'A new key was made.' );
		$this->assertCount( 1, $this->calls( 'POST', '#/reissue$#' ) );
		$this->assertStringNotContainsString( 'PRIVATE KEY', (string) wp_json_encode( $this->calls( 'POST', '#/reissue$#' ) ) );

		$this->now += 120;
		$refresh = Operations::refresh( $r );
		$this->assertTrue( $refresh->is_done(), $refresh->error() );
		$r = Resource::find( $r->id() );
		$this->assertFalse( (bool) $r->get( 'ssl_reissue_pending' ) );
		$this->assertNotSame( $old, (string) $r->get( 'ssl_serial' ) );
		$this->assertTrue( Keys::revealable( $r ), 'The new key can be taken.' );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'new certificate' ) ) );
	}

	public function test_a_reissue_with_the_customers_own_request_is_checked_and_makes_no_key(): void {
		$r = $this->built();
		$this->assertTrue( Operations::reissue( $r, array( 'csr' => 'not a request' ) )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#/reissue$#' ) );
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$this->assertTrue( Operations::reissue( $r, array( 'csr' => $made['csr'] ) )->is_done() );
		$r = Resource::find( $r->id() );
		$this->assertSame( '', (string) $r->get( Keys::META_KEY ) );
		$this->assertSame( 'own', $r->get( 'ssl_key_mode' ) );
		$this->now += 120;
		$this->assertTrue( Operations::refresh( $r )->is_done() );
		$cert = Csr::certificate( (string) Resource::find( $r->id() )->get( 'ssl_cert' ) );
		$this->assertSame( Csr::parse( $made['csr'] )['public'], $cert['public'] );
	}

	public function test_reissues_are_limited_per_day(): void {
		$this->settings( array( 'ssl_reissues_per_day' => 2 ) );
		$r = $this->built();
		$this->assertTrue( Operations::reissue( $r, array() )->is_done() );
		$this->now += 120;
		Operations::refresh( Resource::find( $r->id() ) );
		$this->assertTrue( Operations::reissue( Resource::find( $r->id() ), array() )->is_done() );
		$this->now += 120;
		Operations::refresh( Resource::find( $r->id() ) );
		$third = Operations::reissue( Resource::find( $r->id() ), array() );
		$this->assertTrue( $third->is_failed() );
		$this->assertCount( 2, $this->calls( 'POST', '#/reissue$#' ) );
	}

	public function test_an_automatic_reissue_by_the_authority_is_found_and_delivered(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->built();
		$old = (string) $r->get( 'ssl_serial' );
		$this->now += 200 * DAY_IN_SECONDS;
		$result = Operations::refresh( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertNotSame( $old, (string) $fresh->get( 'ssl_serial' ) );
		$this->assertGreaterThanOrEqual( strtotime( (string) $r->get( 'ssl_valid_to' ) . ' UTC' ), strtotime( (string) $fresh->get( 'ssl_valid_to' ) . ' UTC' ) );
		$this->assertStringContainsString( 'reissued by the certificate authority', $result->event() );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'ready to install' ) ) );
		// Nothing newer the next time: nothing changes and nothing is sent twice.
		$count = count( $this->mails );
		$this->assertTrue( Operations::refresh( $fresh )->is_done() );
		$this->assertCount( $count, $this->mails );
	}

	public function test_a_certificate_about_to_end_without_a_replacement_alerts_staff_and_the_customer_once(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->built();
		// The registrar answers with the same certificate while its end is near.
		$r->set( array( 'ssl_valid_to' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		$this->assertTrue( Operations::refresh( Resource::find( $r->id() ) )->is_done() );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( (bool) $fresh->get( 'ssl_expiry_alerted' ) );
		$sent = count( $this->mails );
		$this->assertGreaterThan( 0, $sent );
		Operations::refresh( $fresh );
		$this->assertCount( $sent, $this->mails, 'Once.' );
	}

	public function test_a_certificate_the_registrar_ended_is_noted_and_staff_are_told(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->built();
		$this->registrar->request( 'POST', '/ssl/orders/' . $r->get( 'remote_id' ) . '/cancel' );
		$result = Operations::refresh( $r );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( 'REJ', $this->apply( $r, $result )->get( 'ssl_status' ) );
	}

	public function test_the_validation_method_can_be_changed_while_the_order_waits(): void {
		$r = $this->ssl_resource();
		( new SslDriver() )->provision( $r );
		$r = Resource::find( $r->id() );
		$result = Operations::change_validation( $r, array( 'method' => 'email', 'approver' => 'webmaster' ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'email', $fresh->get( 'ssl_validation' ) );
		$this->assertSame( 'webmaster@example.com', $fresh->get( 'ssl_approver' ) );
		$put = $this->calls( 'PUT', '#^/ssl/orders/\d+$#' );
		$this->assertSame( 'email', end( $put )['body']['domain_validation_methods'][0]['method'] );
		$this->assertTrue( Operations::change_validation( $fresh, array( 'method' => 'email', 'approver' => 'nobody' ) )->is_failed() );
		$this->assertTrue( Operations::change_validation( $fresh, array( 'method' => 'auto' ) )->is_failed(), 'Not a domain of ours.' );
	}

	public function test_a_wildcard_cannot_use_file_validation(): void {
		$r = $this->ssl_resource( array( 'product' => 42, 'wildcard' => true ) );
		( new SslDriver() )->provision( $r );
		$this->assertTrue( Operations::change_validation( Resource::find( $r->id() ), array( 'method' => 'file' ) )->is_failed() );
	}

	public function test_the_approval_email_can_be_sent_again(): void {
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'email', 'ssl_approver' => 'admin@example.com' ) ) );
		( new SslDriver() )->provision( $r );
		$this->assertTrue( Operations::resend( Resource::find( $r->id() ) )->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '#approver-email/resend$#' ) );
	}

	public function test_cancel_needs_the_word_and_a_cancellable_order_and_cancels_the_subscription(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->built();
		$this->assertTrue( Operations::cancel( $r, array() )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#/cancel$#' ) );
		$this->assertTrue( Operations::cancel( $r, array( 'confirm' => 'CANCEL' ) )->is_done() );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( (bool) $fresh->get( 'ssl_cancelled' ) );
		$this->assertSame( '', (string) $fresh->get( Keys::META_KEY ), 'The key is wiped.' );
		$this->assertCount( 1, $this->calls( 'POST', '#/cancel$#' ) );
		$this->assertSame( array(), Operations::offered( $fresh ) );
	}

	public function test_after_the_refund_window_the_order_cannot_be_cancelled(): void {
		$r = $this->built();
		$this->assertArrayHasKey( 'ssl_cancel', Operations::offered( $r ) );
		$r->set( array( 'ssl_issued_at' => time() - 31 * DAY_IN_SECONDS ) );
		$fresh = Resource::find( $r->id() );
		$this->assertArrayNotHasKey( 'ssl_cancel', Operations::offered( $fresh ) );
		$this->assertTrue( Operations::cancel( $fresh, array( 'confirm' => 'CANCEL' ) )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#/cancel$#' ) );
	}

	public function test_delete_cancels_an_order_inside_the_window_and_wipes_the_key_but_leaves_an_old_one(): void {
		$r = $this->built();
		$this->assertTrue( ( new SslDriver() )->delete( $r )->is_done() );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( Keys::META_KEY ) );
		$this->assertCount( 1, $this->calls( 'POST', '#/cancel$#' ) );
		$old = $this->built( array( 'meta' => array( 'ssl_name' => 'old.example.com' ) ) );
		$old->set( array( 'ssl_issued_at' => time() - 60 * DAY_IN_SECONDS ) );
		$before = count( $this->calls( 'POST', '#/cancel$#' ) );
		$this->assertTrue( ( new SslDriver() )->delete( Resource::find( $old->id() ) )->is_done() );
		$this->assertCount( $before, $this->calls( 'POST', '#/cancel$#' ), 'Outside the window nothing is cancelled.' );
	}

	public function test_offered_actions_follow_the_state(): void {
		$r = $this->ssl_resource();
		$this->assertSame( array(), Operations::offered( $r ), 'Nothing before the order exists.' );
		( new SslDriver() )->provision( $r );
		$waiting = Operations::offered( Resource::find( $r->id() ) );
		$this->assertArrayHasKey( 'ssl_change_validation', $waiting );
		$this->assertArrayHasKey( 'ssl_cancel', $waiting );
		$this->assertArrayNotHasKey( 'ssl_reissue', $waiting );
		$built = $this->built( array( 'meta' => array( 'ssl_name' => 'built.example.com' ) ) );
		$this->assertArrayHasKey( 'ssl_reissue', Operations::offered( $built ) );
	}

	public function test_the_driver_routes_actions_and_the_renew_and_refresh_are_internal(): void {
		$driver = new SslDriver();
		$this->assertSame( array( 'renew', 'refresh' ), $driver->internal_actions() );
		$this->assertTrue( $driver->act( $this->built(), 'ssl_nonsense', array() )->is_failed() );
	}

	// -- Renewal ---------------------------------------------------------------------------------

	/**
	 * A built certificate on a subscription with a paid renewal order.
	 *
	 * @return array{0: Resource, 1: \WC_Order}
	 */
	private function renewal( float $total = 19.0 ): array {
		remove_action( 'woocommerce_subscription_renewal_payment_complete', array( SslRenewals::class, 'on_paid' ), 20 );
		$r = $this->built();
		$product = wc_get_product( (int) $r->license()['product_id'] );
		$user = (int) $r->license()['user_id'];
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1 ) );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_order_items', array( 'order_id' => $sub->get_id() ) );
		$sub->add_product( $product, 1 );
		$sub->save();
		$this->assertTrue( Licenses::update( (int) $r->license()['id'], array( 'subscription_id' => $sub->get_id() ) ) );
		$order = wcs_create_renewal_order( $sub );
		$order->set_currency( 'EUR' );
		$order->remove_order_items( 'line_item' );
		$order->add_product( $product, 1, array( 'subtotal' => $total, 'total' => $total ) );
		$order->set_status( 'processing' );
		$order->save();
		$this->now = (int) strtotime( (string) $r->get( 'ssl_order_expires' ) . ' UTC' ) - 20 * DAY_IN_SECONDS;

		return array( Resource::find( $r->id() ), $order );
	}

	public function test_a_paid_renewal_renews_the_order_and_moves_the_expiry(): void {
		list( $r, $order ) = $this->renewal();
		$before = (string) $r->get( 'ssl_order_expires' );
		$result = SslRenewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $result->is_done(), $result->error() );
		$fresh = $this->apply( $r, $result );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', strtotime( $before . ' UTC' ) ) ), $fresh->get( 'ssl_order_expires' ) );
		$this->assertSame( array( (string) $order->get_id() ), $fresh->get( 'renewed_orders' ) );
		$this->assertCount( 1, $this->calls( 'POST', '#/renew$#' ) );
		// The same paid order again changes nothing.
		$this->assertTrue( SslRenewals::renew( $fresh, (string) $order->get_id() )->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '#/renew$#' ) );
	}

	public function test_a_renewal_paid_below_the_cost_asks_the_registrar_for_nothing(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		list( $r, $order ) = $this->renewal( 3.0 );
		$result = SslRenewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#/renew$#' ) );
	}

	public function test_a_renewal_that_is_too_early_is_refused_and_asks_again_later(): void {
		list( $r, $order ) = $this->renewal();
		$this->now -= 60 * DAY_IN_SECONDS;
		$result = SslRenewals::renew( $r, (string) $order->get_id() );
		$this->assertTrue( $result->is_retry() );
		$this->assertArrayNotHasKey( (string) $order->get_id(), (array) Resource::find( $r->id() )->get( 'renew_started' ), 'Nothing was bought, so the marker is free.' );
	}

	public function test_a_timeout_on_the_renewal_keeps_the_marker_and_never_asks_twice(): void {
		list( $r, $order ) = $this->renewal();
		$fake = new FakeClient( true );
		$fake->reply( 'GET', '/ssl/orders/' . $r->get( 'remote_id' ), array( 'expiration_date' => (string) $r->get( 'ssl_order_expires' ) ) );
		$fake->reply( 'POST', '/ssl/orders/' . $r->get( 'remote_id' ) . '/renew', new \WP_Error( 'transport', 'x' ) );
		$fake->reply( 'GET', '/ssl/orders/' . $r->get( 'remote_id' ), array( 'expiration_date' => (string) $r->get( 'ssl_order_expires' ) ) );
		remove_all_filters( 'beltoft_services_domains_client' );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( SslRenewals::renew( $r, (string) $order->get_id() )->is_retry() );
		$this->assertTrue( SslRenewals::renew( Resource::find( $r->id() ), (string) $order->get_id() )->is_retry() );
		$posts = array_filter( $fake->calls(), static fn( $c ) => 'POST' === $c['method'] );
		$this->assertCount( 1, $posts );
	}

	public function test_an_unpaid_order_is_not_renewed(): void {
		list( $r, $order ) = $this->renewal();
		$order->set_status( 'pending' );
		$order->save();
		$this->assertTrue( SslRenewals::renew( $r, (string) $order->get_id() )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#/renew$#' ) );
	}

	public function test_the_next_payment_is_dated_before_the_orders_expiry_and_capped_at_30_days(): void {
		$this->settings( array( 'ssl_renew_days_before' => 28 ) );
		$this->assertSame( '2027-09-28 00:00:00', gmdate( 'Y-m-d H:i:s', SslRenewals::next_payment_at( '2027-10-26 00:00:00', strtotime( '2026-10-26 00:00:00 UTC' ) ) ) );
		$this->settings( array( 'ssl_renew_days_before' => 90 ) );
		$this->assertSame( '2027-09-26 00:00:00', gmdate( 'Y-m-d H:i:s', SslRenewals::next_payment_at( '2027-10-26 00:00:00', strtotime( '2026-10-26 00:00:00 UTC' ) ) ) );
		$now = strtotime( '2027-10-25 00:00:00 UTC' );
		$this->assertSame( $now + HOUR_IN_SECONDS, SslRenewals::next_payment_at( '2027-10-26 00:00:00', $now ) );
	}

	public function test_the_sweep_queues_a_paid_renewal_no_job_finished_once(): void {
		list( $r, $order ) = $this->renewal();
		$this->assertGreaterThanOrEqual( 1, SslRenewals::sweep() );
		$waiting = as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Jobs::ACT, 'args' => array( $r->id(), 'renew', array( 'order' => (string) $order->get_id() ) ), 'group' => \BeltoftServices\Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' );
		$this->assertCount( 1, $waiting );
		SslRenewals::sweep();
		$waiting = as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Jobs::ACT, 'args' => array( $r->id(), 'renew', array( 'order' => (string) $order->get_id() ) ), 'group' => \BeltoftServices\Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' );
		$this->assertCount( 1, $waiting, 'A job is already waiting, so none is added.' );
	}
}
