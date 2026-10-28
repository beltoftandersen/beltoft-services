<?php
/** DNS claim REST routes. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaimJobs;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DnsClaimRoutesTest extends TestCase {
	private int $user  = 0;
	private int $other = 0;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'dns_claims_max_per_user' => 50 ) );
		$this->user  = self::factory()->user->create();
		$this->other = self::factory()->user->create();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function call( string $method, string $route, int $user, array $body = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, '/beltoft-services/v1/domains/dns-claims' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function claim( string $status = 'pending', string $domain = 'example.com', ?int $user = null ): array {
		$claim = DnsClaims::create( $user ?? $this->user, $domain );
		if ( 'pending' !== $status ) {
			DnsClaims::set_status( $claim['id'], $status );
		}
		return (array) DnsClaims::get( $claim['id'] );
	}

	private function queued( string $hook ): array {
		return as_get_scheduled_actions( array( 'hook' => $hook, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( array( '', '/(?P<id>\d+)', '/(?P<id>\d+)/check', '/(?P<id>\d+)/scan', '/(?P<id>\d+)/zone-file', '/(?P<id>\d+)/records' ) as $path ) {
			$this->assertArrayHasKey( '/beltoft-services/v1/domains/dns-claims' . $path, $routes );
		}
	}

	public function test_feature_off_answers_404_everywhere(): void {
		$claim = $this->claim();
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		$calls = array(
			array( 'POST', '', array( 'domain' => 'example.org' ) ),
			array( 'GET', '', array() ),
			array( 'GET', '/' . $claim['id'], array() ),
			array( 'POST', '/' . $claim['id'] . '/check', array() ),
			array( 'POST', '/' . $claim['id'] . '/scan', array() ),
			array( 'POST', '/' . $claim['id'] . '/zone-file', array( 'text' => 'a IN A 192.0.2.1' ) ),
			array( 'POST', '/' . $claim['id'] . '/records', array( 'records' => array() ) ),
			array( 'DELETE', '/' . $claim['id'], array() ),
		);
		foreach ( $calls as $c ) {
			$response = $this->call( $c[0], $c[1], $this->user, $c[2] );
			$this->assertSame( 404, $response->get_status(), $c[0] . $c[1] );
			$this->assertSame( 'dns_claims_disabled', $response->get_data()['code'] );
		}
		$this->assertSame( 'pending', DnsClaims::get( $claim['id'] )['status'] );
	}

	public function test_logged_out_visitors_get_401(): void {
		$this->assertSame( 401, $this->call( 'GET', '', 0 )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', '', 0, array( 'domain' => 'example.com' ) )->get_status() );
	}

	public function test_another_customers_claim_is_a_plain_404(): void {
		$claim = $this->claim( 'verified' );
		$gone  = $this->call( 'GET', '/999999', $this->other );
		foreach ( array( array( 'GET', '' ), array( 'POST', '/check' ), array( 'POST', '/scan' ), array( 'POST', '/zone-file' ), array( 'POST', '/records' ), array( 'DELETE', '' ) ) as $c ) {
			$response = $this->call( $c[0], '/' . $claim['id'] . $c[1], $this->other, array( 'text' => 'x', 'records' => array() ) );
			$this->assertSame( 404, $response->get_status(), $c[0] . $c[1] );
			$this->assertSame( 'dns_claim_not_found', $response->get_data()['code'] );
			$this->assertSame( $gone->get_data()['message'], $response->get_data()['message'] );
		}
		$this->assertSame( 'verified', DnsClaims::get( $claim['id'] )['status'] );
		$this->assertSame( array(), $this->call( 'GET', '', $this->other )->get_data() );
	}

	public function test_create_returns_the_view_and_never_leaks_internals(): void {
		$response = $this->call( 'POST', '', $this->user, array( 'domain' => 'Example.COM' ) );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array( 'id', 'domain', 'domain_display', 'status', 'txt_name', 'txt_value', 'expires_at', 'verified_at', 'checked_at', 'ds_present', 'servers', 'reason', 'message', 'scan', 'zone_file', 'draft_records' ),
			array_keys( $data )
		);
		$this->assertSame( 'example.com', $data['domain'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( '_beltoft-verify.example.com', $data['txt_name'] );
		$this->assertStringStartsWith( 'beltoft-verify=v1:', $data['txt_value'] );
		$this->assertSame( 'Add this TXT record at your current DNS provider, then check.', $data['message'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['expires_at'] );
		$this->assertNull( $data['scan'] );
		$this->assertNull( $data['zone_file'] );
		$this->assertSame( array(), $data['draft_records'] );
		$json = wp_json_encode( $data );
		$this->assertStringNotContainsString( 'user_id', $json );
		$this->assertStringNotContainsString( 'check_failures', $json );
	}

	public function test_idn_domain_is_shown_in_unicode(): void {
		$data = $this->call( 'POST', '', $this->user, array( 'domain' => 'bücher.de' ) )->get_data();
		$this->assertSame( 'xn--bcher-kva.de', $data['domain'] );
		$this->assertSame( 'bücher.de', $data['domain_display'] );
	}

	public function test_names_that_are_not_a_registrable_domain_are_refused(): void {
		foreach ( array( 'www.example.com', 'com', 'not a domain', '', 'co.uk' ) as $name ) {
			$response = $this->call( 'POST', '', $this->user, array( 'domain' => $name ) );
			$this->assertSame( 400, $response->get_status(), $name );
			$this->assertSame( 'unsupported_name', $response->get_data()['code'] );
			$this->assertSame( 'Use the main domain name, for example example.com.', $response->get_data()['message'] );
		}
	}

	public function test_creating_again_rotates_the_token(): void {
		$first  = $this->call( 'POST', '', $this->user, array( 'domain' => 'example.com' ) )->get_data();
		$second = $this->call( 'POST', '', $this->user, array( 'domain' => 'example.com' ) )->get_data();
		$this->assertNotSame( $first['txt_value'], $second['txt_value'] );
		$this->assertSame( 'cancelled', DnsClaims::get( $first['id'] )['status'] );
		$this->assertNull( $this->call( 'GET', '/' . $first['id'], $this->user )->get_data()['txt_value'] );
	}

	public function test_claim_limit_message(): void {
		$this->settings( array( 'dns_claims_max_per_user' => 1 ) );
		$this->call( 'POST', '', $this->user, array( 'domain' => 'one.net' ) );
		$response = $this->call( 'POST', '', $this->user, array( 'domain' => 'two.net' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'claims_limit', $response->get_data()['code'] );
		$this->assertSame( 'You have reached the number of domains for DNS hosting. Contact support.', $response->get_data()['message'] );
	}

	public function test_create_is_limited_to_five_an_hour(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertSame( 201, $this->call( 'POST', '', $this->user, array( 'domain' => "d{$i}.net" ) )->get_status() );
		}
		$this->assertSame( 429, $this->call( 'POST', '', $this->user, array( 'domain' => 'd6.net' ) )->get_status() );
		$this->assertSame( 201, $this->call( 'POST', '', $this->other, array( 'domain' => 'd1.net' ) )->get_status() );
	}

	public function test_a_domain_the_customer_hosts_already_links_to_the_resource(): void {
		$product  = $this->service_product( 'dns' );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => 'mine.net' ) );

		$response = $this->call( 'POST', '', $this->user, array( 'domain' => 'mine.net' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'already_yours', $response->get_data()['code'] );
		$this->assertSame( $resource->id(), $response->get_data()['data']['resource_id'] );

		$theirs = $this->call( 'POST', '', $this->other, array( 'domain' => 'mine.net' ) );
		$this->assertSame( 409, $theirs->get_status() );
		$this->assertSame( 'domain_taken', $theirs->get_data()['code'] );
		$this->assertSame( 'This domain cannot be added here. Contact support.', $theirs->get_data()['message'] );
		$this->assertArrayNotHasKey( 'resource_id', $theirs->get_data()['data'] );
	}

	public function test_a_domain_assigned_to_someone_else_is_refused_generically(): void {
		$claim = $this->claim( 'verified', 'held.net', $this->other );
		$this->assertTrue( DnsAssignments::reserve( $this->other, $claim['id'], 'held.net' ) );

		$response = $this->call( 'POST', '', $this->user, array( 'domain' => 'held.net' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'domain_taken', $response->get_data()['code'] );
		$this->assertSame( 'This domain cannot be added here. Contact support.', $response->get_data()['message'] );
		$this->assertSame( array( 'status' => 409 ), $response->get_data()['data'] );
	}

	public function test_listing_returns_only_my_views(): void {
		$mine = $this->claim( 'pending', 'mine.net' );
		$this->claim( 'pending', 'theirs.net', $this->other );
		$data = $this->call( 'GET', '', $this->user )->get_data();
		$this->assertSame( array( $mine['id'] ), array_column( $data, 'id' ) );
	}

	public function test_check_queues_one_job_and_sets_checking(): void {
		$claim = $this->claim();
		$one   = $this->call( 'POST', '/' . $claim['id'] . '/check', $this->user );
		$this->assertSame( 202, $one->get_status() );
		$this->assertSame( 'checking', $one->get_data()['status'] );
		$this->assertSame( 'Checking your name servers…', $one->get_data()['message'] );
		$this->assertSame( 'no-store', $one->get_headers()['Cache-Control'] );
		$this->assertCount( 1, $this->queued( DnsClaimJobs::CHECK ) );

		$again = $this->call( 'POST', '/' . $claim['id'] . '/check', $this->user );
		$this->assertSame( 202, $again->get_status() );
		$this->assertCount( 1, $this->queued( DnsClaimJobs::CHECK ) );
	}

	public function test_check_of_a_verified_claim_keeps_it_verified_and_queues_a_job(): void {
		$claim    = $this->claim( 'verified' );
		$response = $this->call( 'POST', '/' . $claim['id'] . '/check', $this->user );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'verified', $response->get_data()['status'] );
		$this->assertCount( 1, $this->queued( DnsClaimJobs::CHECK ) );
	}

	public function test_check_is_refused_for_a_finished_claim(): void {
		foreach ( array( 'cancelled', 'expired', 'support', 'consumed' ) as $status ) {
			$claim    = $this->claim( $status, $status . '.net' );
			$response = $this->call( 'POST', '/' . $claim['id'] . '/check', $this->user );
			$this->assertSame( 409, $response->get_status(), $status );
			$this->assertSame( 'dns_claim_state', $response->get_data()['code'] );
		}
		$this->assertCount( 0, $this->queued( DnsClaimJobs::CHECK ) );
	}

	public function test_check_is_limited_to_five_per_ten_minutes_per_domain(): void {
		$claim = $this->claim();
		for ( $i = 1; $i <= 5; $i++ ) {
			DnsClaims::set_status( $claim['id'], 'pending' );
			$this->assertSame( 202, $this->call( 'POST', '/' . $claim['id'] . '/check', $this->user )->get_status() );
		}
		DnsClaims::set_status( $claim['id'], 'pending' );
		$this->assertSame( 429, $this->call( 'POST', '/' . $claim['id'] . '/check', $this->user )->get_status() );
		$this->assertSame( 'pending', DnsClaims::get( $claim['id'] )['status'] );

		$other = $this->claim( 'pending', 'other.net' );
		$this->assertSame( 202, $this->call( 'POST', '/' . $other['id'] . '/check', $this->user )->get_status() );
	}

	public function test_scan_zone_file_and_records_need_a_verified_claim(): void {
		foreach ( array( 'pending', 'checking', 'cancelled' ) as $status ) {
			$claim = $this->claim( $status, $status . '.net' );
			foreach ( array(
				array( '/scan', array() ),
				array( '/zone-file', array( 'text' => 'a IN A 192.0.2.1' ) ),
				array( '/records', array( 'records' => array() ) ),
			) as $c ) {
				$response = $this->call( 'POST', '/' . $claim['id'] . $c[0], $this->user, $c[1] );
				$this->assertSame( 409, $response->get_status(), $status . $c[0] );
				$this->assertSame( 'dns_claim_state', $response->get_data()['code'] );
			}
		}
		$this->assertCount( 0, $this->queued( DnsClaimJobs::SCAN ) );
	}

	public function test_a_claim_past_its_time_is_expired_when_read(): void {
		global $wpdb;
		$claim = $this->claim( 'verified' );
		$wpdb->update( \BeltoftServices\Domains\DnsSchema::tables()['claims'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $claim['id'] ) );
		$response = $this->call( 'POST', '/' . $claim['id'] . '/records', $this->user, array( 'records' => array() ) );
		$this->assertSame( 409, $response->get_status() );
		$view = $this->call( 'GET', '/' . $claim['id'], $this->user )->get_data();
		$this->assertSame( 'expired', $view['status'] );
		$this->assertNull( $view['txt_value'] );
		$this->assertSame( 'This check has expired. Start again.', $view['message'] );
	}

	public function test_scan_queues_a_job_clears_the_old_scan_and_is_limited(): void {
		$claim = $this->claim( 'verified' );
		DnsClaims::set_status( $claim['id'], 'verified', array( 'last_result' => array( 'scan' => array( array( 'name' => '@' ) ), 'zone_file' => array( 'records' => array(), 'skipped' => array() ) ) ) );

		$response = $this->call( 'POST', '/' . $claim['id'] . '/scan', $this->user );
		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data()['scan'] );
		$this->assertNotNull( $response->get_data()['zone_file'] );
		$this->assertCount( 1, $this->queued( DnsClaimJobs::SCAN ) );

		for ( $i = 2; $i <= 5; $i++ ) {
			$this->assertSame( 202, $this->call( 'POST', '/' . $claim['id'] . '/scan', $this->user )->get_status() );
		}
		$this->assertSame( 429, $this->call( 'POST', '/' . $claim['id'] . '/scan', $this->user )->get_status() );
		$this->assertCount( 1, $this->queued( DnsClaimJobs::SCAN ) );
	}

	public function test_zone_file_is_parsed_and_stored(): void {
		$claim    = $this->claim( 'verified' );
		$text     = "\$ORIGIN example.com.\n@ 300 IN A 192.0.2.1\n@ IN NS ns1.old.net.\n";
		$response = $this->call( 'POST', '/' . $claim['id'] . '/zone-file', $this->user, array( 'text' => $text ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '192.0.2.1', $data['zone_file']['records'][0]['value'] );
		$this->assertSame( 'replaced by our name servers', $data['zone_file']['skipped'][0]['reason'] );
		$this->assertSame( array(), $data['draft_records'] );
		$this->assertSame( $data['zone_file'], DnsClaims::get( $claim['id'] )['last_result']['zone_file'] );
	}

	public function test_a_zone_file_that_is_too_large_is_a_400(): void {
		$claim    = $this->claim( 'verified' );
		$response = $this->call( 'POST', '/' . $claim['id'] . '/zone-file', $this->user, array( 'text' => str_repeat( 'a', 102401 ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'zone_file_too_large', $response->get_data()['code'] );
	}

	public function test_records_are_validated_and_stored_as_rows(): void {
		$claim = $this->claim( 'verified' );
		$rows  = array(
			array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.1', 'junk' => 'dropped' ),
			array( 'name' => '@', 'type' => 'MX', 'ttl' => 300, 'value' => 'mail.example.com', 'priority' => 10 ),
		);

		$response = $this->call( 'POST', '/' . $claim['id'] . '/records', $this->user, array( 'records' => $rows ) );
		$this->assertSame( 200, $response->get_status() );
		unset( $rows[0]['junk'] );
		$this->assertEquals( $rows, $response->get_data()['draft_records'] );
		$this->assertEquals( $rows, DnsClaims::get( $claim['id'] )['draft_records'] );

		$bad = $this->call( 'POST', '/' . $claim['id'] . '/records', $this->user, array( 'records' => array( array( 'name' => 'www', 'type' => 'A', 'value' => 'nope' ) ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'bad_ip', $bad->get_data()['code'] );
		$this->assertSame( 'Enter a valid IP address.', $bad->get_data()['message'] );
		$this->assertEquals( $rows, DnsClaims::get( $claim['id'] )['draft_records'], 'a refused save keeps the draft' );

		$many = $this->call( 'POST', '/' . $claim['id'] . '/records', $this->user, array( 'records' => array_fill( 0, 201, $rows[0] ) ) );
		$this->assertSame( 400, $many->get_status() );
		$this->assertSame( 'too_many_records', $many->get_data()['code'] );
	}

	public function test_cancel_ends_an_open_claim_and_leaves_assignments_alone(): void {
		$claim = $this->claim( 'verified' );
		$this->assertTrue( DnsAssignments::reserve( $this->user, $claim['id'], 'example.com' ) );

		$response = $this->call( 'DELETE', '/' . $claim['id'], $this->user );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $response->get_data()['status'] );
		$this->assertSame( 'Cancelled.', $response->get_data()['message'] );
		$this->assertNull( $response->get_data()['txt_value'] );
		$this->assertSame( 'reserved', DnsAssignments::get( 'example.com' )['status'] );

		$again = $this->call( 'DELETE', '/' . $claim['id'], $this->user );
		$this->assertSame( 409, $again->get_status() );
	}

	public function test_support_claims_can_be_cancelled(): void {
		$claim = $this->claim( 'support' );
		$this->assertSame( 200, $this->call( 'DELETE', '/' . $claim['id'], $this->user )->get_status() );
	}

	public function test_view_shows_the_token_only_while_the_claim_is_open_and_never_ips(): void {
		foreach ( array( 'pending' => true, 'checking' => true, 'verified' => true, 'support' => false, 'expired' => false, 'cancelled' => false ) as $status => $shown ) {
			$claim = $this->claim( $status, $status . '.net' );
			$view  = $this->call( 'GET', '/' . $claim['id'], $this->user )->get_data();
			$this->assertSame( $shown, null !== $view['txt_value'], $status );
			if ( ! $shown ) {
				$this->assertStringNotContainsString( $claim['token'], wp_json_encode( $view ) );
			}
		}
	}

	public function test_the_message_follows_the_last_check(): void {
		$claim = $this->claim();
		$cases = array(
			array( array( 'status' => 'missing', 'reason' => 'not_found', 'servers' => array( 'ns1.host.net' => 'missing' ) ), 'The record was not found on every name server yet. DNS changes can take a while; check again in a few minutes.' ),
			array( array( 'status' => 'missing', 'reason' => 'cname', 'servers' => array() ), 'The TXT record must not be a CNAME.' ),
			array( array( 'status' => 'unknown', 'reason' => 'incomplete', 'servers' => array() ), 'Your name servers did not answer. Check again in a few minutes.' ),
		);
		foreach ( $cases as $case ) {
			DnsClaims::set_status( $claim['id'], 'pending', array( 'last_result' => $case[0] ) );
			$view = $this->call( 'GET', '/' . $claim['id'], $this->user )->get_data();
			$this->assertSame( $case[1], $view['message'] );
			$this->assertSame( $case[0]['reason'], $view['reason'] );
		}
		DnsClaims::set_status( $claim['id'], 'pending', array( 'last_result' => array( 'status' => 'missing', 'reason' => 'not_found', 'servers' => array( 'ns1.host.net' => 'missing', 'ns2.host.net' => 'found' ) ) ) );
		$this->assertSame( array( 'ns1.host.net' => 'missing', 'ns2.host.net' => 'found' ), $this->call( 'GET', '/' . $claim['id'], $this->user )->get_data()['servers'] );
		DnsClaims::set_status( $claim['id'], 'verified', array( 'ds_present' => 1 ) );
		$view = $this->call( 'GET', '/' . $claim['id'], $this->user )->get_data();
		$this->assertSame( 'Verified. You can bring your records and continue.', $view['message'] );
		$this->assertTrue( $view['ds_present'] );
		DnsClaims::set_status( $claim['id'], 'support' );
		$this->assertSame( 'This domain needs a manual check. Contact support.', $this->call( 'GET', '/' . $claim['id'], $this->user )->get_data()['message'] );
	}
}
