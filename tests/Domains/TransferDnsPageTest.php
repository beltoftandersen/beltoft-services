<?php
/** The customer's and the admin's view of a transfer that copies the DNS. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\Overview;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\TransferDns;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class TransferDnsPageTest extends TestCase {
	private FakeDns $dns;
	private int $user = 0;
	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'ns1.old-host.example', 'ns2.old-host.example' ) );
		add_filter( 'pre_wp_mail', function ( $pre, $atts ) { $this->mails[] = $atts; return true; }, 10, 2 );
		$this->mails = array();
		$this->user  = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $GLOBALS['wp']->query_vars['domains'] );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	/** A transfer that is part way: the zone is made and filled, the registrar has not been asked (first) or is still processing (after). */
	private function transfer( string $choice, array $meta = array() ): Resource {
		$name    = 'page-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$steps   = 'first' === $choice ? array( 'contact', 'ns_read', 'zone_prepared', 'dns_copy' ) : array( 'contact', 'ns_read', 'zone_prepared', 'dns_copy', 'order' );
		$r       = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'transfer', 'transfer_dns' => $choice, 'transfer_ns' => array( 'ns1.old-host.example', 'ns2.old-host.example' ), 'zone_claimed' => true, 'steps' => $steps, 'state' => 'provisioning', 'desired' => 'ready' ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'provisioning', 'desired' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->assertTrue( Records::replace( $name, array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		return Resource::find( $r->id() );
	}

	private function page( Resource $r, string $tab = '' ): string {
		$GLOBALS['wp']->query_vars['domains'] = (string) $r->license()['id'] . ( '' !== $tab ? '/' . $tab : '' );
		ob_start();
		Domains::render( array( View::license( $r->license() ) ) );
		return (string) ob_get_clean();
	}

	private function call( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/beltoft-services/v1/resources/' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_dns_tab_shows_the_copied_records_and_says_they_are_not_in_use_yet(): void {
		$r    = $this->transfer( 'first' );
		$html = $this->page( $r, 'dns' );
		$this->assertStringContainsString( 'data-tab-link="dns"', $html );
		$this->assertStringContainsString( 'Not in use yet: your domain still uses ns1.old-host.example, ns2.old-host.example.', $html );
		$this->assertStringContainsString( '192.0.2.10', $html, 'The copied record is listed.' );
	}

	public function test_the_name_server_tab_shows_ours_and_a_check_now_button_for_first(): void {
		$r    = $this->transfer( 'first' );
		$html = $this->page( $r, 'nameservers' );
		$this->assertStringContainsString( 'Your domain still uses these name servers:', $html );
		$this->assertStringContainsString( 'set these name servers at your current registrar', $html );
		$this->assertStringContainsString( 'ns1.example.net', $html );
		$this->assertStringContainsString( 'data-transfer-check', $html );
		$this->assertStringContainsString( 'Check now', $html );
	}

	public function test_after_has_no_check_button_and_says_when_the_switch_happens(): void {
		$r    = $this->transfer( 'after' );
		$html = $this->page( $r, 'nameservers' );
		$this->assertStringNotContainsString( 'data-transfer-check', $html );
		$this->assertStringContainsString( 'When it is done we move your domain to these name servers', $html );
		$this->assertStringContainsString( 'data-tab-link="dns"', $html );
	}

	public function test_a_keep_transfer_shows_nothing_new(): void {
		$r    = $this->transfer( 'keep', array( 'zone_claimed' => false, 'steps' => array( 'contact', 'zone_prepared' ) ) );
		$html = $this->page( $r, 'nameservers' );
		$this->assertStringNotContainsString( 'Not in use yet', $html );
		$this->assertStringNotContainsString( 'data-transfer-check', $html );
		$this->assertStringNotContainsString( 'data-tab-link="dns"', $html );
	}

	public function test_the_customer_can_review_and_change_the_copied_records_before_the_switch(): void {
		$r = $this->transfer( 'first' );
		$name = (string) $r->get( 'domain' );
		$read = $this->call( 'GET', $r->id() . '/dns-records' );
		$this->assertSame( 200, $read->get_status() );
		$this->assertNotEmpty( $read->get_data()['rows'] );
		$write = $this->call( 'PUT', $r->id() . '/dns-records/A/www', array( 'records' => array( array( 'value' => '192.0.2.77' ) ), 'ttl' => 300 ) );
		$this->assertSame( 200, $write->get_status(), (string) wp_json_encode( $write->get_data() ) );
		$this->assertStringContainsString( '192.0.2.77', (string) wp_json_encode( $this->dns->zone( $name ) ) );
		// Someone else's order is not available.
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->call( 'GET', $r->id() . '/dns-records' )->get_status() );
	}

	public function test_a_transfer_that_keeps_the_dns_cannot_be_edited_here(): void {
		$r = $this->transfer( 'keep', array( 'zone_claimed' => false, 'steps' => array( 'contact', 'zone_prepared' ) ) );
		$this->assertSame( 409, $this->call( 'GET', $r->id() . '/dns-records' )->get_status() );
	}

	public function test_the_check_now_route_is_rate_limited_to_once_a_minute(): void {
		$r = $this->transfer( 'first' );
		$first = $this->call( 'POST', $r->id() . '/transfer-dns-check' );
		$this->assertSame( 200, $first->get_status(), (string) wp_json_encode( $first->get_data() ) );
		$this->assertSame( 'other', $first->get_data()['status'] );
		$this->assertSame( 429, $this->call( 'POST', $r->id() . '/transfer-dns-check' )->get_status() );
		$after = $this->transfer( 'after' );
		$this->assertSame( 403, $this->call( 'POST', $after->id() . '/transfer-dns-check' )->get_status(), 'Only a transfer that waits for the name servers.' );
	}

	public function test_the_admin_panel_shows_the_choice_and_the_waiting_reason(): void {
		$r = $this->transfer( 'first', array( 'dns_wait_since' => time() - 3600, 'import_error' => '1 record could not be read in a form we can add' ) );
		ob_start();
		( new DomainDriver() )->render_admin( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Transfer DNS', $html );
		$this->assertStringContainsString( 'Move my DNS to Beltoft first', $html );
		$this->assertStringContainsString( 'Waiting until the domain uses our name servers', $html );
		$this->assertStringContainsString( 'ns1.old-host.example', $html );
		$this->assertStringContainsString( 'could not be read', $html );
	}

	public function test_the_recommendation_route_answers_with_the_current_name_servers(): void {
		add_filter( 'beltoft_services_transfer_registrar', static fn() => false );
		add_filter( 'beltoft_services_dns_proof_result', static fn( $pre, $call ) => 'delegation' === $call ? array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.domaincontrol.com', 'ns2.domaincontrol.com' ), 'ips' => array(), 'ds' => false, 'ours' => false ) : $pre, 10, 2 );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/domains/transfer-dns' );
		$request->set_param( 'domain', 'Example.com' );
		$request->set_header( 'X-WC-Nonce', wp_create_nonce( 'wc_store_api' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'first', $data['choice'] );
		$this->assertTrue( $data['at_risk'] );
		$this->assertSame( array( 'ns1.domaincontrol.com', 'ns2.domaincontrol.com' ), $data['hosts'] );
		$bad = new \WP_REST_Request( 'GET', '/beltoft-services/v1/domains/transfer-dns' );
		$bad->set_param( 'domain', 'example.com' );
		$this->assertSame( 403, rest_get_server()->dispatch( $bad )->get_status(), 'No nonce, no answer.' );
	}

	/** Mail text with the line breaks of the mail folded away. */
	private function flat( string $text ): string {
		return (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) );
	}

	private function ready_mail( Resource $r ): string {
		$this->mails = array();
		$r->set( array( 'state' => 'ready' ) );
		Emails::on_state_changed( Resource::find( $r->id() ), 'provisioning', 'ready' );
		return (string) ( $this->mails[0]['message'] ?? '' );
	}

	public function test_the_transferred_email_says_what_happened_to_the_dns_for_each_choice(): void {
		$first = $this->flat( $this->ready_mail( $this->transfer( 'first', array( 'transfer_ns' => array() ) ) ) );
		$this->assertStringContainsString( 'You moved your DNS to our name servers before the transfer', $first );
		$after = $this->flat( $this->ready_mail( $this->transfer( 'after', array( 'transfer_ns' => array() ) ) ) );
		$this->assertStringContainsString( 'have now moved your domain to our name servers', $after );
		$keep = $this->flat( $this->ready_mail( $this->transfer( 'keep', array( 'dns_external' => true ) ) ) );
		$this->assertStringContainsString( 'Your DNS stays where it is now. Your name servers do not change.', $keep );
		$this->assertStringContainsString( 'may switch it off', $keep );
		foreach ( array( $first, $after, $keep ) as $text ) {
			$this->assertStringNotContainsString( "\u{2014}", $text );
			$this->assertStringNotContainsString( "\u{00B7}", $text );
		}
	}

	public function test_the_plain_transferred_email_says_the_same(): void {
		foreach ( array( 'first' => 'before the transfer', 'after' => 'have now moved your domain' ) as $choice => $words ) {
			$r                 = $this->transfer( $choice, array( 'transfer_ns' => array() ) );
			$email             = Emails::get( 'beltoft_services_domain_transferred' );
			$email->email_type = 'plain';
			$this->mails       = array();
			$this->assertTrue( $email->trigger( $r ) );
			$this->assertStringContainsString( $words, $this->flat( $this->mails[0]['message'] ) );
		}
	}

	public function test_the_move_dns_email_names_our_name_servers_in_both_versions(): void {
		$r = $this->transfer( 'first' );
		foreach ( array( 'html', 'plain' ) as $type ) {
			$email             = Emails::get( 'beltoft_services_domain_action_needed' );
			$email->email_type = $type;
			$this->mails       = array();
			$this->assertTrue( $email->trigger( $r, array( 'domain_action_reason' => 'move_dns' ) ) );
			$message = $this->flat( $this->mails[0]['message'] );
			$this->assertStringContainsString( 'starts when the domain uses our name servers', $message );
			$this->assertStringContainsString( 'ns1.example.net', $message );
			$this->assertStringContainsString( 'ns2.example.net', $message );
			$this->assertStringContainsString( 'turn off DNSSEC', $message );
		}
	}

	public function test_the_first_wait_sends_the_move_dns_email_once(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 't', 32 ) ) );
		$r = $this->transfer( 'first', array( 'steps' => array( 'contact', 'ns_read', 'zone_prepared', 'dns_copy' ), 'contact_handle' => 'AA123-GB' ) );
		$this->mails = array();
		$fake = new \BeltoftServices\Domains\FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->assertTrue( ( new DomainDriver() )->provision( Resource::find( $r->id() ) )->is_retry() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'starts when the domain uses our name servers', $this->flat( $this->mails[0]['message'] ) );
	}
}
