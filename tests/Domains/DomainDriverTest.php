<?php
/** Domain provisioning tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Crypto;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DomainDriverTest extends TestCase {
	/** Registrar costs for the extensions these tests use; a row a test already stored keeps its own values. */
	private function prices(): void {
		$table = get_option( Prices::OPTION, array() );
		$table = is_array( $table ) ? $table : array();
		foreach ( array( 'com', 'dk', 'nl' ) as $tld ) {
			$table[ $tld ] = ( $table[ $tld ] ?? array() ) + array( 'create' => 10.0, 'renew' => 10.0, 'transfer' => 9.0, 'restricted' => false, 'price' => 16 );
		}
		update_option( Prices::OPTION, $table );
	}

	private function resource( string $mode = 'register', string $domain = 'example.com', float $total = 50.0 ): Resource {
		$this->prices();
		if ( 'example.com' === $domain ) { $domain = 'example-' . wp_generate_password( 10, false, false ) . '.com'; }
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Domain', 'quantity' => 1, 'subtotal' => $total, 'total' => $total ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$meta = array( 'domain' => $domain, 'mode' => $mode, 'order_item_id' => (int) $line->get_id() );
		if ( 'transfer' === $mode ) { $meta['auth'] = Crypto::encrypt( 'transfer-secret' ); }
		$r = Resource::create( $license, new DomainDriver(), array(), $meta );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	private function posts( FakeClient $fake ): int {
		return count( array_filter( $fake->calls(), static fn( $call ) => 'POST' === $call['method'] ) );
	}

	public function test_a_free_order_places_no_registrar_order_and_alerts(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$r = $this->resource( 'register', 'example.com', 0.0 );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed(), (string) $result->error() );
		$this->assertSame( 'The order was paid below the registrar cost.', $result->error() );
		$this->assertSame( 0, $this->posts( $fake ) );
		$this->assertNotSame( '', (string) $r->get( 'alerted' ) );
	}

	public function test_an_order_paid_exactly_the_registrar_cost_is_registered(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 123, 'status' => 'REQ' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'REQ' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$this->assertTrue( ( new DomainDriver() )->provision( $this->resource( 'register', 'example.com', 10.0 ) )->is_retry() );
		$this->assertSame( 1, $this->posts( $fake ) );
	}

	public function test_a_transfer_is_checked_against_the_transfer_cost_and_a_missing_cost_fails_closed(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 't', 32 ) ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains/transfer', array( 'id' => 124, 'status' => 'REQ' ) );
		$fake->reply( 'GET', '/domains/124', array( 'id' => 124, 'status' => 'REQ' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$this->assertTrue( ( new DomainDriver() )->provision( $this->resource( 'transfer', 'example.com', 8.0 ) )->is_failed(), 'Below the transfer cost of 9.' );
		$this->assertSame( 0, $this->posts( $fake ) );
		$this->assertTrue( ( new DomainDriver() )->provision( $this->resource( 'transfer', 'example.com', 9.0 ) )->is_retry(), 'Exactly the cost passes.' );
		$this->assertSame( 1, $this->posts( $fake ) );
		// The extension is gone from the price table: cost unknown.
		$r = $this->resource( 'register', 'gone-' . wp_generate_password( 6, false, false ) . '.xyz', 500.0 );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_failed() );
		$this->assertSame( 1, $this->posts( $fake ) );
	}

	public function test_a_resumed_order_is_not_blocked_by_the_spend_guard(): void {
		$name = 'resume-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array( array( 'id' => 555, 'full_name' => $name, 'owner_handle' => 'AA123-GB' ) ) ) );
		$fake->reply( 'GET', '/domains/555', array( 'id' => 555, 'status' => 'REQ' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$result = ( new DomainDriver() )->provision( $this->resource( 'register', $name, 0.0 ) );
		$this->assertTrue( $result->is_retry(), $result->error() );
		$this->assertSame( 0, $this->posts( $fake ) );
	}

	public function test_a_domain_in_our_account_without_an_owner_or_with_another_owner_is_refused(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		foreach ( array( array(), array( 'owner_handle' => 'ZZ999-GB' ), array( 'owner_handle' => '' ) ) as $owner ) {
			$name = 'held-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
			$fake = new FakeClient();
			$fake->reply( 'GET', '/domains', array( 'results' => array( array( 'id' => 556, 'full_name' => $name ) + $owner ) ) );
			remove_all_filters( 'beltoft_services_domains_client' );
			add_filter( 'beltoft_services_domains_client', static fn() => $fake );
			$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
			$r = $this->resource( 'register', $name );
			$result = ( new DomainDriver() )->provision( $r );
			$this->assertTrue( $result->is_failed(), (string) $result->error() );
			$this->assertSame( 'This domain is held by a different registrant.', $result->error() );
			$this->assertNotSame( '', (string) $r->get( 'alerted' ) );
			$this->assertSame( 0, $this->posts( $fake ) );
		}
	}

	/** @return array<string, array{0:string,1:int,2:string}> */
	public static function error_cases(): array {
		return array(
			'429 with a registrar code' => array( 'op_100', 429, 'retry' ),
			'408 timeout'               => array( 'op_101', 408, 'retry' ),
			'503 with a registrar code' => array( 'op_102', 503, 'retry' ),
			'400 with a registrar code' => array( 'op_399', 400, 'fail' ),
			'plain 502'                 => array( 'http_502', 502, 'retry' ),
		);
	}

	/** @dataProvider error_cases */
	public function test_registrar_errors_retry_only_when_temporary( string $code, int $status, string $expected ): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', new \WP_Error( $code, 'Registrar request failed.', array( 'status' => $status, 'desc' => 'x' ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$result = ( new DomainDriver() )->provision( $this->resource() );
		$this->assertTrue( 'retry' === $expected ? $result->is_retry() : $result->is_failed() );
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
		$order = wc_get_order( (int) $r->license()['order_id'] );
		$order->update_meta_data( '_beltoft_services_dk_agreement', array( 'version' => 1 ) );
		$order->save();
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

	public function test_new_registration_is_locked_against_transfer(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$fake->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$r = $this->resource();

		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_done() );

		$locks = array_filter( $fake->calls(), static fn( $c ) => 'PUT' === $c['method'] && ! empty( $c['body']['is_locked'] ) );
		$this->assertCount( 1, $locks, 'The domain is locked once, after it is active.' );
		$this->assertTrue( (bool) $r->get( 'is_locked' ) );
	}

	public function test_a_refused_order_records_the_registrars_reason_for_staff(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400, 'desc' => 'Nameserver parent domain is not in your account' ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = ( new DomainDriver() )->provision( $this->resource() );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'op_399 (Nameserver parent domain is not in your account)', $result->error() );
	}

	public function test_a_dk_order_sends_the_registrys_acceptance_and_needs_the_recorded_agreement(): void {
		$setup = function () {
			$fake = new FakeClient();
			$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
			$fake->reply( 'POST', '/domains', array( 'id' => 321, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
			add_filter( 'beltoft_services_domains_client', static fn() => $fake );
			return $fake;
		};
		$fake = $setup();
		$r = $this->resource( 'register', 'agreed-' . wp_generate_password( 6, false, false ) . '.dk' );
		$order = wc_get_order( (int) $r->license()['order_id'] );
		$order->update_meta_data( '_beltoft_services_dk_agreement', array( 'version' => 1 ) );
		$order->save();
		( new DomainDriver() )->provision( $r );
		$posts = array_values( array_filter( $fake->calls(), static fn( $c ) => 'POST' === $c['method'] && '/domains' === $c['path'] ) );
		$this->assertSame( array( 'dk_acceptance' => 1 ), $posts[0]['body']['additional_data'] );

		remove_all_filters( 'beltoft_services_domains_client' );
		$fake = $setup();
		$r2 = $this->resource( 'register', 'unagreed-' . wp_generate_password( 6, false, false ) . '.dk' );
		$result = ( new DomainDriver() )->provision( $r2 );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'agreement was not accepted', $result->error() );
		$this->assertCount( 0, array_filter( $fake->calls(), static fn( $c ) => 'POST' === $c['method'] ), 'Nothing is ordered without the agreement.' );
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

	/** Item rows outlive the test transaction and can share a reused subscription id: start from none. */
	private function clear_items( \WC_Subscription $sub ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_order_items', array( 'order_id' => $sub->get_id() ) );
	}

	private function register_fakes(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
	}

	public function test_registering_a_name_hosted_here_adopts_the_assignment_and_cancels_dns_only(): void {
		$this->register_fakes();
		$name = 'adopt-' . wp_generate_password( 6, false, false ) . '.com';
		$r = $this->resource( 'register', $name );
		$user = (int) $r->license()['user_id'];
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->clear_items( $sub );
		$item_id = $sub->add_product( $this->service_product( 'dns' ), 1 );
		wc_add_order_item_meta( $item_id, '_beltoft_services_domain', array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => 1 ) );
		$sub->save();
		$dns_license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$claim = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, $claim['id'], $name ) );
		$old = Resource::create( $dns_license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertTrue( DnsAssignments::bind_order( $name, $user, 1, 1 ) );
		$this->assertTrue( DnsAssignments::activate( $name, $user, (int) $dns_license['id'], $old->id() ) );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$row = DnsAssignments::get( $name );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( $r->id(), (int) $row['resource_id'] );
		$this->assertSame( (int) $r->license()['id'], (int) $row['license_id'] );
		$this->assertSame( 'cancelled', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_another_customers_assignment_fails_closed(): void {
		$this->register_fakes();
		$name = 'other-' . wp_generate_password( 6, false, false ) . '.com';
		$r = $this->resource( 'register', $name );
		$this->assertTrue( DnsAssignments::reserve( (int) $r->license()['user_id'] + 1000, 9, $name ) );
		$dns = new FakeDns();
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertFalse( $result->is_done() );
		$this->assertNull( ( new FakeDns() )->zone( $name ) );
	}

	/** A DNS-only service for the domain's customer: subscription with $lines lines, resource, assignment (active). */
	private function dns_only( Resource $r, string $name, int $lines = 1, string $line_domain = '' ): array {
		$user = (int) $r->license()['user_id'];
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->clear_items( $sub );
		for ( $i = 0; $i < $lines; $i++ ) {
			$item_id = $sub->add_product( $this->service_product( 'dns' ), 1 );
			wc_add_order_item_meta( $item_id, '_beltoft_services_domain', array( 'domain' => '' !== $line_domain ? $line_domain : $name, 'mode' => 'dns', 'claim_id' => 1 ) );
		}
		$sub->save();
		$license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$claim = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, $claim['id'], $name ) );
		$old = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		DnsAssignments::bind_order( $name, $user, 1, 1 );
		DnsAssignments::activate( $name, $user, (int) $license['id'], $old->id() );
		return array( $sub, $old );
	}

	public function test_adoption_switches_records_back_on_after_the_dns_only_pause(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$name = 'reenable-' . wp_generate_password( 6, false, false ) . '.com';
		$r = $this->resource( 'register', $name );
		list( $sub, $old ) = $this->dns_only( $r, $name );
		$dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		$dns->replace_rrsets( $name, array( array( 'name' => $name . '.', 'type' => 'A', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '192.0.2.10', 'disabled' => true ) ) ) ) );
		$old->set( array( 'zone_claimed' => true, 'records_off' => true ) );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertFalse( $dns->zone( $name )['rrsets'][0]['records'][0]['disabled'] );
		$this->assertFalse( (bool) Resource::find( $r->id() )->get( 'adopt_enable' ) );
	}

	public function test_a_subscription_with_two_lines_is_not_cancelled_but_the_row_moves(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$name = 'twolines-' . wp_generate_password( 6, false, false ) . '.com';
		$r = $this->resource( 'register', $name );
		list( $sub ) = $this->dns_only( $r, $name, 2 );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
		$this->assertSame( $r->id(), (int) DnsAssignments::get( $name )['resource_id'] );
		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'other lines' ) ) );
	}

	public function test_a_single_line_for_another_domain_is_not_cancelled(): void {
		$this->register_fakes();
		$name = 'lineother-' . wp_generate_password( 6, false, false ) . '.com';
		$r = $this->resource( 'register', $name );
		list( $sub ) = $this->dns_only( $r, $name, 1, 'someother-' . wp_generate_password( 6, false, false ) . '.com' );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
		$this->assertSame( $r->id(), (int) DnsAssignments::get( $name )['resource_id'] );
		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'could not be tied' ) ) );
	}
}
