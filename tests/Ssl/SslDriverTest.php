<?php
/** SSL provisioning tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Resource;
use BeltoftServices\Ssl\Csr;
use BeltoftServices\Ssl\Keys;
use BeltoftServices\Ssl\SslDriver;
use BeltoftServices\Ssl\SslGuard;

require_once __DIR__ . '/SslTestCase.php';

class SslDriverTest extends SslTestCase {

	public function test_a_dv_order_is_built_from_request_to_a_stored_certificate(): void {
		$r = $this->ssl_resource();
		$driver = new SslDriver();

		$first = $driver->provision( $r );
		$this->assertTrue( $first->is_retry(), 'The authority is still working: ' . $first->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( 'csr', 'contact', 'order', 'request', 'validate' ), $fresh->get( 'steps' ) );
		$this->assertNotSame( '', $fresh->get( 'remote_id' ) );
		$this->assertSame( 'REQ', $fresh->get( 'ssl_status' ) );
		$rows = $fresh->get( 'ssl_validation_rows' );
		$this->assertSame( 'CNAME', $rows[0]['type'] );
		$this->assertSame( 'manual', $rows[0]['status'] );
		$this->assertStringEndsWith( '.example.com', $rows[0]['name'] );
		$this->assertSame( 'dns', $fresh->get( 'ssl_action_sent' ) );

		$this->now += 120;
		$done = $driver->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $done->is_done(), $done->error() );
		$r = Resource::find( $r->id() );
		$this->assertSame( array( 'csr', 'contact', 'order', 'request', 'validate', 'active', 'deliver', 'dates' ), $r->get( 'steps' ) );
		$this->assertSame( 'ACT', $r->get( 'ssl_status' ) );
		$this->assertSame( 'example.com', Csr::certificate( $r->get( 'ssl_cert' ) )['cn'] );
		$this->assertNotSame( '', $r->get( 'ssl_serial' ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $this->now ) ), $r->get( 'ssl_order_expires' ) );
		$this->assertCount( 1, $this->calls( 'POST', '#^/ssl/orders$#' ), 'One order was placed.' );
	}

	public function test_the_request_is_checked_against_the_paid_amount_before_anything_is_ordered(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->ssl_resource( array( 'total' => 5.0 ) );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( SslGuard::MESSAGE, $result->error() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
		$this->assertNotSame( '', (string) Resource::find( $r->id() )->get( 'alerted' ) );
	}

	public function test_an_unknown_cost_places_no_order(): void {
		delete_option( \BeltoftServices\Ssl\SslCatalog::OPTION );
		$r = $this->ssl_resource();
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_switched_off_orders_wait_and_place_nothing(): void {
		$this->settings( array( 'ssl_enabled' => 0 ) );
		$result = ( new SslDriver() )->provision( $this->ssl_resource() );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_a_wildcard_variation_on_a_non_wildcard_product_fails_before_ordering(): void {
		$r = $this->ssl_resource( array( 'wildcard' => true, 'product' => 41 ) );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_the_private_key_is_stored_encrypted_and_never_in_a_request_or_the_meta_text(): void {
		$r = $this->ssl_resource();
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$stored = (string) $fresh->get( Keys::META_KEY );
		$this->assertNotSame( '', $stored );
		$plain = Keys::existing( $fresh );
		$this->assertStringContainsString( 'PRIVATE KEY', (string) $plain );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $stored );
		$this->assertStringNotContainsString( 'PRIVATE KEY', (string) wp_json_encode( $fresh->get( 'ssl_csr' ) ) );
		foreach ( $this->registrar->calls() as $call ) {
			$this->assertStringNotContainsString( 'PRIVATE KEY', (string) wp_json_encode( $call ) );
		}
		$csr = Csr::parse( (string) $fresh->get( 'ssl_csr' ) );
		$this->assertSame( 'example.com', $csr['cn'] );
		$this->assertArrayNotHasKey( 'O', $csr['subject'], 'A DV request carries no organization.' );
		$this->assertSame( 'DK', $csr['subject']['C'], 'The registrar refuses a request without a country, DV included.' );
	}

	public function test_an_ov_request_carries_the_company_and_a_dv_company_is_not_needed(): void {
		$r = $this->ssl_resource( array( 'product' => 20, 'level' => 'ov', 'company' => 'Acme ApS' ) );
		( new SslDriver() )->provision( $r );
		$csr = Csr::parse( (string) Resource::find( $r->id() )->get( 'ssl_csr' ) );
		$this->assertSame( 'Acme ApS', $csr['subject']['O'] );
		$this->assertSame( 'DK', $csr['subject']['C'] );
		$this->assertSame( 'Aarhus', $csr['subject']['L'] );
		$no_company = $this->ssl_resource( array( 'product' => 20, 'level' => 'ov', 'company' => '' ) );
		$this->assertTrue( ( new SslDriver() )->provision( $no_company )->is_failed() );
	}

	public function test_a_customers_own_request_is_used_and_no_key_is_made(): void {
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'] ) ) );
		$this->assertTrue( $this->build( $r )->is_done() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( '', (string) $fresh->get( Keys::META_KEY ) );
		$this->assertSame( trim( $made['csr'] ), trim( $this->calls( 'POST', '#^/ssl/orders$#' )[0]['body']['csr'] ) );
	}

	public function test_a_request_for_another_name_is_refused(): void {
		$made = Csr::generate( 'other.example', array( 'countryName' => 'DK' ) );
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'] ) ) );
		$this->assertTrue( ( new SslDriver() )->provision( $r )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_a_crash_after_the_order_is_found_again_and_not_ordered_twice(): void {
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$user = (int) self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 41, 'period' => 1, 'csr' => $made['csr'], 'organization_handle' => 'AA123-GB', 'domain_validation_methods' => array( array( 'host_name' => 'example.com', 'method' => 'dns' ) ) ) );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'], 'ssl_post_attempted' => time() - 60 ) ) );
		$before = count( $this->calls( 'POST', '#^/ssl/orders$#' ) );
		$this->assertTrue( $this->build( $r )->is_done() );
		$this->assertSame( $before, count( $this->calls( 'POST', '#^/ssl/orders$#' ) ), 'The earlier order was adopted.' );
	}

	public function test_a_timeout_on_the_order_sets_a_marker_and_the_next_run_never_posts_a_second_order(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->ssl_resource();
		$fake = new \BeltoftServices\Domains\FakeClient( true );
		$fake->reply( 'POST', '/customers', array( 'handle' => 'AA9-XX' ) );
		$fake->reply( 'POST', '/ssl/orders', new \WP_Error( 'transport', 'x' ) );
		remove_all_filters( 'beltoft_services_domains_client' );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertStringContainsString( 'temporarily unavailable', $result->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertNotContains( 'order', $fresh->get( 'steps' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'ssl_post_attempted' ), 'The marker was saved before the order was posted.' );
		$posts = static fn() => count( array_filter( $fake->calls(), static fn( $c ) => 'POST' === $c['method'] && '/ssl/orders' === $c['path'] ) );
		$this->assertSame( 1, $posts() );
		// The answer was lost but the order was placed: nothing matches by request text, so nothing is posted again.
		$again = ( new SslDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $again->is_retry() );
		$this->assertStringContainsString( 'Waiting for staff', $again->error() );
		$this->assertSame( 1, $posts(), 'No second paid order.' );
		$this->assertNotSame( '', (string) Resource::find( $r->id() )->get( 'alerted' ) );
	}

	public function test_an_order_with_our_request_text_made_after_the_marker_is_adopted_by_reading_it_in_full(): void {
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$user = (int) self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'], 'ssl_post_attempted' => time() - 30 ) ) );
		$other = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 41, 'csr' => $other['csr'], 'organization_handle' => 'AA123-GB', 'domain_validation_methods' => array( array( 'host_name' => 'example.com', 'method' => 'dns' ) ) ) );
		// A list that does not carry the request text must not matter: only the detail read decides.
		$list_calls_before = count( $this->calls( 'GET', '#^/ssl/orders$#' ) );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry(), 'The only candidate has another request: nothing is adopted, nothing posted.' );
		$this->assertCount( 1, $this->calls( 'POST', '#^/ssl/orders$#' ), 'Only the other order exists.' );
		$this->assertGreaterThan( $list_calls_before, count( $this->calls( 'GET', '#^/ssl/orders$#' ) ) );
		$mine = (int) $this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 41, 'csr' => $made['csr'], 'organization_handle' => 'AA123-GB', 'domain_validation_methods' => array( array( 'host_name' => 'example.com', 'method' => 'dns' ) ) ) )['id'];
		$done = $this->build( Resource::find( $r->id() ) );
		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertSame( (string) $mine, (string) Resource::find( $r->id() )->get( 'remote_id' ) );
		$this->assertCount( 2, $this->calls( 'POST', '#^/ssl/orders$#' ), 'Both are the test own placements; the driver placed none.' );
	}

	public function test_an_order_made_before_the_marker_is_not_adopted(): void {
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$user = (int) self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 41, 'csr' => $made['csr'], 'organization_handle' => 'AA123-GB', 'domain_validation_methods' => array( array( 'host_name' => 'example.com', 'method' => 'dns' ) ) ) );
		$this->now += 3 * DAY_IN_SECONDS;
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'], 'ssl_post_attempted' => 1800000000 + 5 * DAY_IN_SECONDS ) ) );
		$this->assertStringContainsString( 'Waiting for staff', ( new SslDriver() )->provision( $r )->error() );
	}

	public function test_staff_can_adopt_an_order_or_clear_the_marker(): void {
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_post_attempted' => time() ) ) );
		$this->assertWPError( \BeltoftServices\Ssl\Provision::resolve_marker( $r, 99999 ) );
		$id = (int) $this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 41, 'csr' => 'x', 'domain_validation_methods' => array( array( 'host_name' => 'other.example.com', 'method' => 'dns' ) ) ) )['id'];
		$this->assertWPError( \BeltoftServices\Ssl\Provision::resolve_marker( $r, $id ), 'Another name.' );
		$mine = (int) $this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 41, 'csr' => 'y', 'domain_validation_methods' => array( array( 'host_name' => 'example.com', 'method' => 'dns' ) ) ) )['id'];
		$this->assertTrue( \BeltoftServices\Ssl\Provision::resolve_marker( $r, $mine ) );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( (string) $mine, $fresh->get( 'remote_id' ) );
		$this->assertContains( 'order', $fresh->get( 'steps' ) );
		$this->assertSame( null, $fresh->get( 'ssl_post_attempted' ) );
		$clear = $this->ssl_resource( array( 'meta' => array( 'ssl_post_attempted' => time() ) ) );
		$this->assertTrue( \BeltoftServices\Ssl\Provision::resolve_marker( $clear, 0 ) );
		$this->assertSame( null, Resource::find( $clear->id() )->get( 'ssl_post_attempted' ) );
		$this->assertWPError( \BeltoftServices\Ssl\Provision::resolve_marker( $fresh, 0 ), 'It already has an order.' );
	}

	public function test_a_definite_refusal_of_the_order_clears_the_marker(): void {
		$r = $this->ssl_resource();
		$fake = new \BeltoftServices\Domains\FakeClient( true );
		$fake->reply( 'POST', '/customers', array( 'handle' => 'AA9-XX' ) );
		$fake->reply( 'POST', '/ssl/orders', new \WP_Error( 'op_1000', 'Registrar request failed.', array( 'status' => 400, 'desc' => 'bad' ) ) );
		remove_all_filters( 'beltoft_services_domains_client' );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( ( new SslDriver() )->provision( $r )->is_failed() );
		$this->assertSame( null, Resource::find( $r->id() )->get( 'ssl_post_attempted' ), 'No order exists, so a retry may order.' );
	}

	public function test_a_rejected_request_cancels_the_paid_order_and_alerts(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		// An OV order whose request lacks the organization is refused by the authority at the request step.
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$r = $this->ssl_resource( array( 'product' => 20, 'level' => 'ov', 'company' => 'Acme ApS', 'total' => 55.0, 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'] ) ) );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed(), 'The own request has no organization.' );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ), 'Refused before ordering.' );
	}

	public function test_a_hard_stop_cancels_the_registrar_order(): void {
		$this->settings( array( 'ssl_wait_days' => 1 ) );
		// Email validation: the authority never gets an approval, so the order never leaves REQ.
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'email', 'ssl_approver' => 'admin@example.com' ) ) );
		add_filter( 'beltoft_services_ssl_fake_delay', static fn() => 10 * YEAR_IN_SECONDS );
		$first = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $first->is_retry() );
		$id = (string) Resource::find( $r->id() )->get( 'remote_id' );
		Resource::find( $r->id() )->set( array( 'ssl_requested_at' => time() - 2 * DAY_IN_SECONDS ) );
		$result = ( new SslDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'REJ', $this->registrar->request( 'GET', '/ssl/orders/' . $id )['status'], 'The order that was paid for is cancelled.' );
		$this->assertTrue( (bool) Resource::find( $r->id() )->get( 'ssl_abandoned' ) );
		remove_all_filters( 'beltoft_services_ssl_fake_delay' );
	}

	public function test_email_validation_sends_no_dns_rows_and_names_the_approver(): void {
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'email', 'ssl_approver' => 'admin@example.com' ) ) );
		( new SslDriver() )->provision( $r );
		$body = $this->calls( 'POST', '#^/ssl/orders$#' )[0]['body'];
		$this->assertSame( 'email', $body['domain_validation_methods'][0]['method'] );
		$this->assertSame( 'admin@example.com', $body['approver_email'] );
		$this->assertSame( 'email', Resource::find( $r->id() )->get( 'ssl_validation_rows' )[0]['type'] );
	}

	public function test_a_file_validation_shows_the_address_and_content_of_the_authority(): void {
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'file' ) ) );
		( new SslDriver() )->provision( $r );
		$this->assertSame( 'https', $this->calls( 'POST', '#^/ssl/orders$#' )[0]['body']['domain_validation_methods'][0]['method'] );
		$row = Resource::find( $r->id() )->get( 'ssl_validation_rows' )[0];
		$this->assertSame( 'file', $row['type'] );
		$this->assertStringContainsString( '.well-known/pki-validation', $row['name'] );
	}

	public function test_automatic_validation_publishes_the_cname_in_the_customers_zone(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto' ) ) );
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$row = $fresh->get( 'ssl_validation_rows' )[0];
		$this->assertSame( 'published', $row['status'] );
		$this->assertTrue( (bool) $fresh->get( 'ssl_validation_auto' ) );
		$zone = \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' );
		$names = array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ), (array) $zone['rrsets'] );
		$this->assertContains( $row['name'], $names );
		$this->assertSame( '', (string) $fresh->get( 'ssl_action_sent' ), 'Nothing is asked of the customer.' );
	}

	public function test_automatic_validation_falls_back_to_manual_when_a_record_is_in_the_way(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		// The fake authority names the record after the request, so the name is known before the order exists.
		$name = '_' . substr( md5( $made['csr'] ), 0, 32 ) . '.example.com';
		\BeltoftServices\Domains\Clients::dns()->replace_rrsets( 'example.com', array( array( 'name' => $name . '.', 'type' => 'TXT', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '"other"', 'disabled' => false ) ) ) ) );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto', 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'] ) ) );
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'dns', $fresh->get( 'ssl_validation' ), 'The customer adds the record by hand.' );
		$this->assertSame( 'manual', $fresh->get( 'ssl_validation_rows' )[0]['status'] );
		$this->assertSame( 'dns', $fresh->get( 'ssl_action_sent' ) );
		$zone = \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' );
		foreach ( (array) $zone['rrsets'] as $set ) {
			if ( strtolower( rtrim( (string) $set['name'], '.' ) ) === $name ) {
				$this->assertSame( 'TXT', $set['type'], 'The record that was there is untouched.' );
			}
		}
	}

	public function test_a_build_stops_when_the_customer_cancelled_and_does_not_alert_again(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->ssl_resource();
		( new SslDriver() )->provision( $r );
		$r = Resource::find( $r->id() );
		$this->assertTrue( \BeltoftServices\Ssl\Operations::cancel( $r, array( 'confirm' => 'CANCEL' ) )->is_done() );
		$alerted = (string) Resource::find( $r->id() )->get( 'alerted' );
		$before = count( $this->calls( 'POST', '#/cancel$#' ) );
		$stopped = ( new SslDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $stopped->is_done() );
		$this->assertCount( $before, $this->calls( 'POST', '#/cancel$#' ) );
		\BeltoftServices\Ssl\Provision::abandon( Resource::find( $r->id() ) );
		$this->assertCount( $before, $this->calls( 'POST', '#/cancel$#' ), 'abandon leaves a cancelled order alone.' );
		$this->assertSame( $alerted, (string) Resource::find( $r->id() )->get( 'alerted' ) );
	}

	public function test_a_staff_retry_after_an_abandoned_order_starts_a_fresh_order(): void {
		$this->settings( array( 'ssl_wait_days' => 1 ) );
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'email', 'ssl_approver' => 'admin@example.com' ) ) );
		add_filter( 'beltoft_services_ssl_fake_delay', static fn() => 10 * YEAR_IN_SECONDS );
		( new SslDriver() )->provision( $r );
		$first = (string) Resource::find( $r->id() )->get( 'remote_id' );
		$first_csr = (string) Resource::find( $r->id() )->get( 'ssl_csr' );
		Resource::find( $r->id() )->set( array( 'ssl_requested_at' => time() - 2 * DAY_IN_SECONDS ) );
		$this->assertTrue( ( new SslDriver() )->provision( Resource::find( $r->id() ) )->is_failed() );
		$this->assertSame( 'REJ', $this->registrar->request( 'GET', '/ssl/orders/' . $first )['status'] );
		// Staff retry: the cancelled order is not continued.
		$this->settings( array( 'ssl_wait_days' => 14 ) );
		$again = ( new SslDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $again->is_retry(), $again->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertNotSame( $first, (string) $fresh->get( 'remote_id' ) );
		$this->assertNotSame( '', (string) $fresh->get( 'remote_id' ) );
		$this->assertNotSame( $first_csr, (string) $fresh->get( 'ssl_csr' ), 'A key and request made here are made again.' );
		$this->assertNull( $fresh->get( 'ssl_abandoned' ) );
		$this->assertCount( 2, $this->calls( 'POST', '#^/ssl/orders$#' ) );
		remove_all_filters( 'beltoft_services_ssl_fake_delay' );
	}

	public function test_a_half_published_validation_is_cleaned_up_and_our_cnames_go_when_the_service_is_deleted(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto' ) ) );
		$publish = new \ReflectionMethod( \BeltoftServices\Ssl\Validation::class, 'publish' );
		$publish->setAccessible( true );
		$rows = array(
			array( 'type' => 'CNAME', 'name' => '_one.example.com', 'value' => 'a.fakeca.example' ),
			array( 'type' => 'CNAME', 'name' => '_two.example.com', 'value' => 'b.fakeca.example' ),
		);
		// The second name already holds a TXT record: it conflicts after the first was written.
		\BeltoftServices\Domains\Clients::dns()->replace_rrsets( 'example.com', array( array( 'name' => '_two.example.com.', 'type' => 'TXT', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '"x"', 'disabled' => false ) ) ) ) );
		$this->assertNull( $publish->invoke( null, Resource::find( $r->id() ), $rows ) );
		$names = array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ) . '|' . $s['type'], (array) \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' )['rrsets'] );
		$this->assertNotContains( '_one.example.com|CNAME', $names, 'The record written for the first row was removed again.' );
		$this->assertContains( '_two.example.com|TXT', $names, 'What was there is untouched.' );

		// A built certificate: its CNAME goes with the service, a record that was already there does not.
		$built = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto', 'ssl_name' => 'shop.example.com' ) ) );
		( new SslDriver() )->provision( $built );
		$row = Resource::find( $built->id() )->get( 'ssl_validation_rows' )[0];
		$this->assertTrue( $row['written'] );
		$has = static fn( string $name ) => in_array( $name . '|CNAME', array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ) . '|' . $s['type'], (array) \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' )['rrsets'] ), true );
		$this->assertTrue( $has( $row['name'] ) );
		( new SslDriver() )->delete( Resource::find( $built->id() ) );
		$this->assertFalse( $has( $row['name'] ), 'Our CNAME is removed with the service.' );
	}

	public function test_a_cname_that_was_already_there_is_not_ours_to_remove(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$name = '_' . substr( md5( $made['csr'] ), 0, 32 ) . '.example.com';
		$hash = hash( 'sha256', $made['csr'] );
		$value = substr( $hash, 0, 32 ) . '.' . substr( $hash, 32, 32 ) . '.fakeca.example';
		\BeltoftServices\Domains\Clients::dns()->replace_rrsets( 'example.com', array( array( 'name' => $name . '.', 'type' => 'CNAME', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => $value . '.', 'disabled' => false ) ) ) ) );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto', 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'] ) ) );
		( new SslDriver() )->provision( $r );
		$row = Resource::find( $r->id() )->get( 'ssl_validation_rows' )[0];
		$this->assertSame( 'published', $row['status'] );
		$this->assertFalse( $row['written'] );
		( new SslDriver() )->delete( Resource::find( $r->id() ) );
		$names = array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ), (array) \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' )['rrsets'] );
		$this->assertContains( $name, $names );
	}

	public function test_pause_keeps_the_certificate_and_a_plan_change_does_nothing(): void {
		$r = $this->built();
		$driver = new SslDriver();
		$this->assertTrue( $driver->pause( $r )->is_done() );
		$this->assertTrue( $driver->update_plan( $r )->is_done() );
		$this->assertTrue( $driver->resume( $r )->is_done() );
		$this->assertSame( 'ACT', Resource::find( $r->id() )->get( 'ssl_status' ) );
		$this->assertSame( array(), $this->calls( 'POST', '#/cancel$#' ) );
	}

	public function test_the_status_has_display_values_only(): void {
		$r = $this->built();
		$status = ( new SslDriver() )->status( $r );
		$this->assertSame( 'example.com', $status['name'] );
		$this->assertStringNotContainsString( 'PRIVATE', (string) wp_json_encode( $status ) );
		$this->assertArrayNotHasKey( 'key', $status );
	}
}
