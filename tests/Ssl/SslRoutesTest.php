<?php
/** The REST routes, the one-time key, the pages and the emails of a certificate. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Ssl;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Crypto;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\Keys;
use BeltoftServices\Ssl\Panels;
use BeltoftServices\Ssl\SslJobs;

require_once __DIR__ . '/SslTestCase.php';

class SslRoutesTest extends SslTestCase {

	private const BASE = '/beltoft-services/v1/resources/';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		parent::tear_down();
	}

	/** @param array<string, mixed> $params */
	private function call( string $method, string $path, int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, self::BASE . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_the_certificate_route_returns_the_public_parts_only_to_the_owner(): void {
		$r = $this->built();
		$user = (int) $r->license()['user_id'];
		$full = $this->call( 'GET', $r->id() . '/ssl-certificate', $user );
		$this->assertSame( 200, $full->get_status() );
		$data = $full->get_data();
		$this->assertSame( 'example.com', $data['name'] );
		$this->assertSame( 2, substr_count( $data['pem'], 'BEGIN CERTIFICATE' ), 'The certificate and the intermediate.' );
		$this->assertStringNotContainsString( 'PRIVATE', (string) wp_json_encode( $data ) );
		$cert = $this->call( 'GET', $r->id() . '/ssl-certificate', $user, array( 'part' => 'cert' ) )->get_data();
		$this->assertSame( 1, substr_count( $cert['pem'], 'BEGIN CERTIFICATE' ) );
		$this->assertSame( 403, $this->call( 'GET', $r->id() . '/ssl-certificate', (int) self::factory()->user->create() )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', $r->id() . '/ssl-certificate', 0 )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', $r->id() . '/ssl-certificate', $user, array( 'part' => 'key' ) )->get_status() );
	}

	public function test_there_is_no_certificate_route_answer_before_issue(): void {
		$r = $this->ssl_resource();
		( new \BeltoftServices\Ssl\SslDriver() )->provision( $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->assertSame( 404, $this->call( 'GET', $r->id() . '/ssl-certificate', (int) $r->license()['user_id'] )->get_status() );
	}

	public function test_a_service_that_is_not_a_certificate_is_refused(): void {
		$mail = $this->ready_mail( 'route.example', array(), (int) self::factory()->user->create() );
		$this->assertSame( 404, $this->call( 'GET', $mail->id() . '/ssl-certificate', (int) $mail->license()['user_id'] )->get_status() );
	}

	public function test_the_key_reveal_is_one_time(): void {
		$r = $this->built();
		$user = (int) $r->license()['user_id'];
		$plain = (string) Keys::existing( $r );
		$other = (int) self::factory()->user->create();
		$this->assertNull( Keys::take( Resource::find( $r->id() ), $other ), 'Not the owner.' );
		$this->assertNotNull( Keys::existing( Resource::find( $r->id() ) ), 'The wrong customer did not wipe it.' );
		$first = $this->call( 'POST', $r->id() . '/ssl-key', $user );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( $plain, $first->get_data()['key'] );
		$this->assertStringContainsString( 'no-store', (string) $first->get_headers()['Cache-Control'] );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( Keys::META_KEY ), 'Wiped before it was returned.' );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/ssl-key', $user )->get_status() );
		$this->assertFalse( Keys::revealable( Resource::find( $r->id() ) ) );
	}

	public function test_the_key_is_not_given_before_the_certificate_exists_or_after_its_days(): void {
		$r = $this->ssl_resource();
		( new \BeltoftServices\Ssl\SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$this->assertNotNull( Keys::existing( $fresh ) );
		$this->assertNull( Keys::take( $fresh, (int) $fresh->license()['user_id'] ), 'Not before issue.' );
		$built = $this->built( array( 'meta' => array( 'ssl_name' => 'late.example.com' ) ) );
		$built->set( array( Keys::META_UNTIL => time() - 10 ) );
		$this->assertNull( Keys::take( Resource::find( $built->id() ), (int) $built->license()['user_id'] ) );
		$this->assertSame( '', (string) Resource::find( $built->id() )->get( Keys::META_KEY ), 'An expired key is wiped when asked for.' );
	}

	public function test_the_daily_job_wipes_keys_past_their_days_and_queues_refreshes(): void {
		$old = $this->built( array( 'meta' => array( 'ssl_name' => 'old.example.com' ) ) );
		$old->set( array( Keys::META_UNTIL => time() - 10 ) );
		$fresh = $this->built( array( 'meta' => array( 'ssl_name' => 'new.example.com' ) ) );
		$queued = SslJobs::run();
		$this->assertGreaterThanOrEqual( 2, $queued );
		$this->assertSame( '', (string) Resource::find( $old->id() )->get( Keys::META_KEY ) );
		$this->assertNotSame( '', (string) Resource::find( $fresh->id() )->get( Keys::META_KEY ) );
	}

	public function test_a_deleted_service_keeps_no_key(): void {
		$r = $this->built();
		Keys::on_state_changed( $r, 'ready', 'deleted' );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( Keys::META_KEY ) );
	}

	public function test_the_panel_link_is_made_at_click_time_and_not_stored(): void {
		$r = $this->built();
		$user = (int) $r->license()['user_id'];
		$response = $this->call( 'POST', $r->id() . '/ssl-panel', $user );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'https://', $response->get_data()['url'] );
		$this->assertStringNotContainsString( 'ssl-panel.example.invalid', (string) wp_json_encode( Resource::find( $r->id() )->get( 'plan' ) ) );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/ssl-panel', (int) self::factory()->user->create() )->get_status() );
	}

	public function test_action_arguments_are_refused_at_once(): void {
		$r = $this->built();
		$user = (int) $r->license()['user_id'];
		$bad = $this->call( 'POST', $r->id() . '/actions/ssl_cancel', $user, array( 'args' => array( 'confirm' => 'nope' ) ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/actions/ssl_reissue', $user, array( 'args' => array( 'csr' => 'garbage' ) ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/actions/ssl_change_validation', $user, array( 'args' => array( 'method' => 'dns' ) ) )->get_status(), 'Not offered once issued.' );
		$ok = $this->call( 'POST', $r->id() . '/actions/ssl_reissue', $user, array( 'args' => array() ) );
		$this->assertSame( 202, $ok->get_status() );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/actions/ssl_cancel', $user, array( 'args' => array( 'confirm' => 'CANCEL' ) ) )->get_status() );
	}

	public function test_a_waiting_order_refuses_an_unusable_validation_method_at_once(): void {
		$r = $this->ssl_resource();
		( new \BeltoftServices\Ssl\SslDriver() )->provision( $r );
		$r->set( array( 'state' => 'ready' ) );
		$user = (int) $r->license()['user_id'];
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/actions/ssl_change_validation', $user, array( 'args' => array( 'method' => 'x' ) ) )->get_status() );
		$this->assertSame( 422, $this->call( 'POST', $r->id() . '/actions/ssl_change_validation', $user, array( 'args' => array( 'method' => 'email', 'approver' => 'boss' ) ) )->get_status() );
		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/actions/ssl_change_validation', $user, array( 'args' => array( 'method' => 'email', 'approver' => 'admin' ) ) )->get_status() );
	}

	public function test_a_token_reaches_the_public_chain_and_never_the_key_or_cancel(): void {
		$this->assertTrue( Tokens::reachable( '/beltoft-services/v1/resources/5/ssl-certificate' ) );
		$this->assertFalse( Tokens::reachable( '/beltoft-services/v1/resources/5/ssl-key' ) );
		$this->assertFalse( Tokens::reachable( '/beltoft-services/v1/resources/5/ssl-panel' ) );
	}

	// -- Pages -----------------------------------------------------------------------------------

	public function test_the_area_lists_certificates_and_a_row_shows_name_state_and_end(): void {
		$r = $this->built();
		$this->assertSame( 'ssl-certificates', ServiceArea::get( Ssl::class )->slug() );
		$this->assertTrue( ServiceArea::get( Ssl::class )->is_member( $r->license() ) );
		$view = array( 'license' => $r->license(), 'product' => 'SSL certificate' );
		$row = ServiceArea::get( Ssl::class )->row( $view, $r );
		$this->assertSame( 'example.com', $row['name'] );
		$this->assertStringStartsWith( 'Active', $row['state'] );
		$this->assertStringStartsWith( 'Valid to', $row['extra'] );
		$this->assertSame( 'ssl', \BeltoftServices\Account\Slugs::type_of( $r->license() ) );
		$this->assertSame( 'example.com', \BeltoftServices\Account\Slugs::for_license( $r->license() ) );
	}

	public function test_the_page_shows_the_certificate_facts_and_never_a_secret(): void {
		$r = $this->built();
		$plain_key = (string) Keys::existing( $r );
		ob_start();
		Panels::account( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'example.com', $html );
		$this->assertStringContainsString( 'data-ssl-download="fullchain"', $html );
		$this->assertStringContainsString( 'data-ssl-key', $html );
		$this->assertStringContainsString( 'ssl-certificate', $html );
		$this->assertStringContainsString( '199 days', $html );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $html );
		$this->assertStringNotContainsString( trim( explode( "\n", $plain_key )[1] ), $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
		Keys::purge( $r );
		ob_start();
		Panels::account( Resource::find( $r->id() ) );
		$after = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-ssl-key', $after );
		$this->assertStringContainsString( 'We keep no copy', $after );
	}

	public function test_a_waiting_page_shows_the_validation_values_to_copy(): void {
		$r = $this->ssl_resource();
		( new \BeltoftServices\Ssl\SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$row = $fresh->get( 'ssl_validation_rows' )[0];
		ob_start();
		Panels::account( $fresh );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( $row['name'], $html );
		$this->assertStringContainsString( $row['value'], $html );
		$this->assertStringContainsString( 'Waiting for validation', $html );
		ob_start();
		Panels::admin( $fresh );
		$admin = (string) ob_get_clean();
		$this->assertStringContainsString( 'Registrar cost per year', $admin );
		$this->assertStringNotContainsString( 'PRIVATE', $admin );
	}

	public function test_the_generic_buttons_of_this_driver_are_not_printed(): void {
		$r = $this->built();
		$this->assertSame( array(), Panels::buttons( array( 'ssl_reissue' => 'x' ), $r ) );
		$this->assertSame( array( 'a' => 'b' ), Panels::buttons( array( 'a' => 'b' ), $this->ready_mail( 'x.example' ) ) );
	}

	// -- Emails ----------------------------------------------------------------------------------

	public function test_the_emails_are_registered_and_carry_no_secret(): void {
		$r = $this->built();
		$this->mails = array();
		foreach ( array( 'beltoft_services_ssl_issued', 'beltoft_services_ssl_reissued', 'beltoft_services_ssl_expiring' ) as $id ) {
			$this->assertContains( $id, Emails::ids() );
			$this->assertTrue( Emails::send( $id, $r ), $id );
		}
		$r->set( array( 'ssl_validation_rows' => array( array( 'type' => 'CNAME', 'name' => '_abc.example.com', 'value' => 'x.y.fakeca.example', 'status' => 'manual' ) ) ) );
		foreach ( array( 'dns', 'file', 'email' ) as $reason ) {
			$this->assertTrue( Emails::send( 'beltoft_services_ssl_action_needed', Resource::find( $r->id() ), array( 'ssl_reason' => $reason ) ), $reason );
		}
		$this->assertCount( 6, $this->mails );
		$key = (string) Keys::existing( $r );
		foreach ( $this->mails as $mail ) {
			$all = $mail['subject'] . $mail['message'];
			$this->assertStringNotContainsString( 'PRIVATE KEY', $all );
			$this->assertStringNotContainsString( trim( explode( "\n", $key )[1] ), $all );
			$this->assertStringNotContainsString( '—', $all );
			$this->assertStringNotContainsString( '·', $all );
			$this->assertStringContainsString( 'example.com', $all );
		}
		$this->assertStringContainsString( 'x.y.fakeca.example', $this->mails[3]['message'] );
		$this->assertStringContainsString( 'ssl-certificate', $this->mails[0]['message'] );
		$this->assertTrue( Crypto::available() );
	}
}
