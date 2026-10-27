<?php
/** Domain provisioning tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Crypto;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DomainDriverTest extends TestCase {
	private function resource( string $mode = 'register', string $domain = 'example.com' ): Resource {
		if ( 'example.com' === $domain ) { $domain = 'example-' . wp_generate_password( 10, false, false ) . '.com'; }
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$meta = array( 'domain' => $domain, 'mode' => $mode );
		if ( 'transfer' === $mode ) { $meta['auth'] = Crypto::encrypt( 'transfer-secret' ); }
		$r = Resource::create( $license, new DomainDriver(), array(), $meta );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	public function test_register_happy_path(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$result = ( new DomainDriver() )->provision( $this->resource() );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( '123', $result->meta()['remote_id'] );
		$this->assertSame( 'off', $fake->calls()[1]['body']['autorenew'] );
	}

	public function test_pending_registration_stays_retrying(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 123, 'status' => 'REQ' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'REQ' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = ( new DomainDriver() )->provision( $this->resource() );
		$this->assertTrue( $result->is_retry(), $result->error() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertTrue( $result->has_progress() );
		$this->assertGreaterThan( time() + 13 * DAY_IN_SECONDS, $result->wait_until() );
	}

	public function test_pending_dk_marks_action_notice_once(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 456, 'status' => 'REQ' ) );
		$fake->reply( 'GET', '/domains/456', array( 'id' => 456, 'status' => 'REQ' ) );
		$fake->reply( 'GET', '/domains/456', array( 'id' => 456, 'status' => 'REQ' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$r = $this->resource( 'register', 'pending-example.dk' );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->assertSame( 'registrant_check', $r->get( 'action_needed_sent' ) );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->assertSame( 'registrant_check', $r->get( 'action_needed_sent' ) );
	}

	public function test_balance_error_retries(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', new \WP_Error( 'op_920', 'insufficient' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = ( new DomainDriver() )->provision( $this->resource() );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
	}

	public function test_incomplete_billing_waits_instead_of_failing(): void {
		$r = $this->resource();
		delete_user_meta( (int) $r->license()['user_id'], '_beltoft_services_domain_handle' );
		$fake = new FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry(), 'An order with no billing address can be completed later.' );
		$this->assertSame( 3600, $result->delay() );
		$this->assertTrue( (bool) $result->meta()['billing_alerted'], 'The admin is told once; the reconciler stores the flag.' );
	}

	public function test_customer_is_told_when_a_domain_order_fails(): void {
		$r = $this->resource();
		$r->set( array( 'state' => 'failed', 'failed_op' => 'provision' ) );
		$this->assertTrue( \BeltoftServices\Emails\Emails::delay_applies( $r ), 'A paid domain that could not be registered is not silent.' );
		$r->set( array( 'failed_op' => 'delete' ) );
		$this->assertFalse( \BeltoftServices\Emails\Emails::delay_applies( $r ), 'A failed deletion is not the customer\'s business.' );
	}

	public function test_pause_and_resume_make_no_registrar_call(): void {
		$r = $this->resource();
		$driver = new DomainDriver();
		$this->assertTrue( $driver->pause( $r )->is_done() );
		$this->assertTrue( $driver->resume( $r )->is_done() );
		$this->assertSame( array(), $this->http_calls() );
	}

	public function test_transfer_without_added_year_renews_once(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 't', 32 ) ) );
		update_option( Prices::OPTION, array( 'nl' => array( 'price' => 9, 'transfer' => 0.0, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains/transfer', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/123/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/123', array( 'expiration_date' => '2028-10-26 00:00:00' ) );
		$fake->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$result = ( new DomainDriver() )->provision( $this->resource( 'transfer', 'example.nl' ) );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( '2028-10-26 00:00:00', $result->meta()['expires_at'] );
		$this->assertCount( 1, array_filter( $fake->calls(), static fn( $call ) => '/domains/123/renew' === $call['path'] ) );
	}
}
