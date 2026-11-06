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
		add_filter( 'beltoft_services_transfer_registrar', '__return_false' );
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
		$r    = $this->transfer( 'first', array( 'dns_reviewed_at' => time() ) );
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
		$r = $this->transfer( 'first', array( 'dns_reviewed_at' => time() ) );
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
		$this->assertStringContainsString( 'Move the DNS to Beltoft first', $html );
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
		$r = $this->transfer( 'first', array( 'dns_reviewed_at' => time(), 'steps' => array( 'contact', 'ns_read', 'zone_prepared', 'dns_copy' ), 'contact_handle' => 'AA123-GB' ) );
		$this->mails = array();
		$fake = new \BeltoftServices\Domains\FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_retry() );
		$this->assertTrue( ( new DomainDriver() )->provision( Resource::find( $r->id() ) )->is_retry() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'starts when the domain uses our name servers', $this->flat( $this->mails[0]['message'] ) );
	}

	/** Steps of a transfer of choice first whose name servers are ours and whose order is with the registry. */
	private function with_registry( array $meta = array() ): Resource {
		return $this->transfer( 'first', array_merge( array( 'dns_wait_since' => time() - 600, 'remote_id' => '123', 'pending_since' => time() - 60, 'steps' => array( 'contact', 'ns_read', 'zone_prepared', 'dns_copy', 'ns_ours', 'order' ) ), $meta ) );
	}

	public function test_progress_asks_for_the_name_servers_after_the_review(): void {
		$r = $this->transfer( 'first', array( 'dns_wait_since' => time() ) );
		$p = \BeltoftServices\Progress::build( $r );
		$this->assertSame( 'Set our name servers at your current registrar', array_column( $p['steps'], 'label', 'key' )['order'] );
		$this->assertSame( array( 'ns1.example.net', 'ns2.example.net' ), $p['hosts'] );
		$this->assertStringContainsString( 'It can take a few hours after you change them, and we check automatically.', $p['note'] );
		$this->assertSame( 'We are waiting for you. Set our name servers at your current registrar, then we send the transfer.', $p['text'] );
		$this->assertStringNotContainsString( '5 minutes', wp_json_encode( $p ) );
	}

	public function test_progress_says_the_registry_wait_takes_days_and_names_the_registrar_when_known(): void {
		$r = $this->with_registry();
		$p = \BeltoftServices\Progress::build( $r );
		$this->assertSame( 'active', $p['current'] );
		$this->assertSame( 'Your current registrar can take up to 5 days to release the domain. Approve the transfer at your current registrar, or in the email they send, to finish sooner. Your website and email keep working.', $p['note'] );
		$this->assertSame( 'The transfer is with the registry. This can take up to 5 days.', $p['text'] );
		$this->assertStringNotContainsString( 'about 5 minutes', wp_json_encode( $p ) );
		add_filter( 'beltoft_services_transfer_registrar', static fn() => array( 'name' => 'Acme Registrar', 'url' => '' ) );
		$this->assertStringStartsWith( 'Acme Registrar can take up to 5 days to release the domain. Approve the transfer at Acme Registrar,', \BeltoftServices\Progress::build( $r )['note'] );
	}

	public function test_a_register_or_other_build_still_says_minutes(): void {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'reg-' . wp_generate_password( 5, false, false ) . '.com', 'mode' => 'register', 'state' => 'provisioning', 'desired' => 'ready' ) );
		$this->assertStringContainsString( 'Usually about 5 minutes.', \BeltoftServices\Progress::build( $r )['eta'] );
	}

	public function test_check_now_after_the_name_servers_moved_says_what_is_happening(): void {
		$r = $this->transfer( 'first', array( 'dns_reviewed_at' => time(), 'steps' => array( 'contact', 'ns_read', 'zone_prepared', 'dns_copy', 'ns_ours', 'order' ) ) );
		$reply = $this->call( 'POST', $r->id() . '/transfer-dns-check' );
		$this->assertSame( 200, $reply->get_status() );
		$this->assertSame( 'Your domain already uses our name servers. The transfer is with the registry now.', $reply->get_data()['message'] );
		$html = $this->page( $r, 'nameservers' );
		$this->assertStringNotContainsString( 'data-transfer-check', $html );
		$this->assertStringNotContainsString( 'set these name servers at your current registrar', $html );
		$this->assertStringContainsString( 'The transfer is with the registry now.', $html );
		$this->assertStringContainsString( 'data-transfer-registry', $html );
	}

	public function test_the_registry_poll_schedule(): void {
		$this->assertSame( 300, DomainDriver::registry_delay( 0 ) );
		$this->assertSame( 300, DomainDriver::registry_delay( 2 * HOUR_IN_SECONDS - 1 ) );
		$this->assertSame( 900, DomainDriver::registry_delay( 2 * HOUR_IN_SECONDS ) );
		$this->assertSame( 900, DomainDriver::registry_delay( DAY_IN_SECONDS - 1 ) );
		$this->assertSame( 3600, DomainDriver::registry_delay( DAY_IN_SECONDS ) );
		$this->assertSame( 3600, DomainDriver::registry_delay( 10 * DAY_IN_SECONDS ) );
	}

	public function test_check_now_at_the_registry_asks_the_registry_and_answers_in_a_sentence(): void {
		$fake = new \BeltoftServices\Domains\FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'REQ' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$r     = $this->with_registry();
		$reply = $this->call( 'POST', $r->id() . '/transfer-registry-check' );
		$this->assertSame( 200, $reply->get_status(), (string) wp_json_encode( $reply->get_data() ) );
		$this->assertSame( 'waiting', $reply->get_data()['status'] );
		$this->assertSame( 'The registry has not finished the transfer yet. Your current registrar can take up to 5 days.', $reply->get_data()['message'] );
		$this->assertNotEmpty( $fake->calls(), 'The registry was asked at once.' );
		$this->assertSame( 429, $this->call( 'POST', $r->id() . '/transfer-registry-check' )->get_status(), 'Once a minute.' );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/transfer-registry-check' )->get_status(), 'Only the owner.' );
	}

	public function test_check_now_at_the_registry_moves_on_when_the_transfer_is_done(): void {
		$fake = new \BeltoftServices\Domains\FakeClient();
		$fake->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$r     = $this->with_registry();
		$reply = $this->call( 'POST', $r->id() . '/transfer-registry-check' );
		$this->assertSame( 200, $reply->get_status(), (string) wp_json_encode( $reply->get_data() ) );
		$this->assertSame( 'done', $reply->get_data()['status'] );
		$this->assertSame( 'The transfer is done. We are setting up the DNS now.', $reply->get_data()['message'] );
		$this->assertContains( 'active', (array) Resource::find( $r->id() )->get( 'steps' ) );
	}

	public function test_check_now_at_the_registry_refuses_when_nothing_waits_for_the_registry(): void {
		$r = $this->transfer( 'first' );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/transfer-registry-check' )->get_status() );
	}

	private function zone_text( string $name ): string {
		return '$ORIGIN ' . $name . ".\n@ 300 IN A 192.0.2.99\nwww 300 IN A 192.0.2.50\n@ 300 IN MX 10 mail.example.org.\n@ 300 IN SPF something\nsame.example 300 IN TXT \"x\"\n";
	}

	private function import( Resource $r, bool $apply ): \WP_REST_Response {
		return $this->call( 'POST', $r->id() . '/dns-import', array( 'text' => $this->zone_text( (string) $r->get( 'domain' ) ), 'apply' => $apply ) );
	}

	public function test_a_zone_file_preview_says_what_is_added_replaced_and_skipped(): void {
		$r    = $this->transfer( 'first' );
		$name = (string) $r->get( 'domain' );
		$out  = $this->import( $r, false );
		$this->assertSame( 200, $out->get_status(), (string) wp_json_encode( $out->get_data() ) );
		$sets = array();
		foreach ( $out->get_data()['sets'] as $set ) { $sets[ $set['name'] . ' ' . $set['type'] ] = $set['status']; }
		$status = array_values( $sets );
		$this->assertContains( 'replace', $status, 'The A set at the apex is in the zone with another value.' );
		$this->assertContains( 'new', $status );
		$this->assertNotEmpty( $out->get_data()['skipped'], 'The unsupported SPF record is reported.' );
		$this->assertStringContainsString( 'SPF', (string) wp_json_encode( $out->get_data()['skipped'] ) );
		$this->assertStringNotContainsString( '192.0.2.99', (string) wp_json_encode( $this->dns->zone( $name ) ), 'A preview writes nothing.' );
	}

	public function test_adding_a_zone_file_merges_it_into_the_zone(): void {
		$r    = $this->transfer( 'first' );
		$name = (string) $r->get( 'domain' );
		$out  = $this->import( $r, true );
		$this->assertSame( 200, $out->get_status(), (string) wp_json_encode( $out->get_data() ) );
		$this->assertSame( array(), $out->get_data()['result']['failed'] );
		$this->assertGreaterThanOrEqual( 1, $out->get_data()['result']['replaced'] );
		$this->assertGreaterThanOrEqual( 1, $out->get_data()['result']['added'] );
		$zone = (string) wp_json_encode( $this->dns->zone( $name ) );
		$this->assertStringContainsString( '192.0.2.99', $zone );
		$this->assertStringNotContainsString( '192.0.2.10', $zone, 'The set in the file replaced the old set.' );
		$this->assertStringContainsString( '192.0.2.50', $zone );
		$this->assertStringContainsString( 'mail.example.org', $zone );
		$again = $this->import( $r, false );
		foreach ( $again->get_data()['sets'] as $set ) { $this->assertSame( 'same', $set['status'] ); }
	}

	public function test_the_zone_file_import_is_refused_for_someone_else_and_is_rate_limited(): void {
		$r = $this->transfer( 'first' );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->import( $r, false )->get_status() );
		$this->assertSame( 403, $this->import( $r, true )->get_status() );
		wp_set_current_user( $this->user );
		add_filter( 'beltoft_services_dns_write_limit', static fn() => 1 );
		$this->assertSame( 200, $this->import( $r, true )->get_status() );
		$this->assertSame( 429, $this->import( $r, true )->get_status() );
	}

	public function test_a_zone_file_that_is_too_large_is_refused(): void {
		$r   = $this->transfer( 'first' );
		$out = $this->call( 'POST', $r->id() . '/dns-import', array( 'text' => str_repeat( "; x\n", 30000 ), 'apply' => false ) );
		$this->assertSame( 400, $out->get_status() );
	}

	public function test_the_dns_tab_offers_the_import_and_the_old_name_server_note_goes_when_the_domain_moved(): void {
		$r    = $this->transfer( 'first' );
		$html = $this->page( $r, 'dns' );
		$this->assertStringContainsString( 'data-zone-import', $html );
		$this->assertStringContainsString( 'In most DNS providers this is called Export or Download zone file.', $html );
		$this->assertStringContainsString( 'Not in use yet: your domain still uses', $html );
		$this->assertStringNotContainsString( 'In use: your domain uses our name servers.', $html );
		$moved = Resource::find( $r->id() );
		$moved->set( array( 'transfer_dns_state' => 'ours' ) );
		$html = $this->page( Resource::find( $r->id() ), 'dns' );
		$this->assertStringNotContainsString( 'Not in use yet', $html );
		$this->assertStringContainsString( 'In use: your domain uses our name servers.', $html );
		$this->assertStringNotContainsString( 'data-transfer-check', $this->page( Resource::find( $r->id() ), 'nameservers' ) );
	}

	public function test_the_dns_tab_asks_for_an_import_first_and_the_name_server_instructions_wait(): void {
		$r    = $this->transfer( 'first', array( 'import_error' => '2 records could not be read in a form we can add' ) );
		$dns  = $this->page( $r, 'dns' );
		$this->assertStringContainsString( 'Import your DNS records', $dns );
		$this->assertStringContainsString( 'No zone file? The list below is what we copied.', $dns );
		$this->assertStringContainsString( 'Some records could not be added: 2 records could not be read in a form we can add.', $dns );
		$this->assertStringContainsString( 'I did not import a zone file. I understand that records we could not find are missing until I add them.', $dns );
		$this->assertStringContainsString( 'I have checked the records', $dns );
		$ns = $this->page( $r, 'nameservers' );
		$this->assertStringContainsString( 'First import or check your DNS records.', $ns );
		$this->assertStringNotContainsString( 'data-transfer-check', $ns );
		$this->assertStringNotContainsString( 'set these name servers at your current registrar', $ns );
	}

	public function test_progress_asks_for_the_import_first_then_the_name_servers(): void {
		$r = $this->transfer( 'first' );
		$p = \BeltoftServices\Progress::build( $r );
		$this->assertSame( 'Import your DNS records', array_column( $p['steps'], 'label', 'key' )['order'] );
		$this->assertSame( array(), $p['hosts'] );
		$this->assertStringContainsString( 'Action needed:', $p['text'] );
		$this->assertStringNotContainsString( 'Usually about', wp_json_encode( $p ) );
		$r->set( array( 'dns_reviewed_at' => time(), 'dns_wait_since' => time() ) );
		$p = \BeltoftServices\Progress::build( Resource::find( $r->id() ) );
		$this->assertSame( 'Set our name servers at your current registrar', array_column( $p['steps'], 'label', 'key' )['order'] );
		$this->assertSame( array( 'ns1.example.net', 'ns2.example.net' ), $p['hosts'] );
	}

	public function test_the_confirmation_needs_an_import_or_an_explicit_acknowledgement_and_records_which(): void {
		$r = $this->transfer( 'first' );
		$no = $this->call( 'POST', $r->id() . '/transfer-dns-confirm' );
		$this->assertSame( 422, $no->get_status(), 'Neither an import nor the box.' );
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( 'dns_reviewed_at' ) );
		$ack = $this->call( 'POST', $r->id() . '/transfer-dns-confirm', array( 'ack' => true ) );
		$this->assertSame( 200, $ack->get_status(), (string) wp_json_encode( $ack->get_data() ) );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'acknowledged', $fresh->get( 'dns_reviewed_path' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'dns_reviewed_at' ) );
		ob_start();
		( new DomainDriver() )->render_admin( $fresh );
		$this->assertStringContainsString( 'Acknowledged that records we could not find are missing', (string) ob_get_clean() );

		$other = $this->transfer( 'first' );
		$this->assertSame( 200, $this->import( $other, true )->get_status() );
		$done = $this->call( 'POST', $other->id() . '/transfer-dns-confirm' );
		$this->assertSame( 200, $done->get_status(), (string) wp_json_encode( $done->get_data() ) );
		$this->assertSame( 'imported', Resource::find( $other->id() )->get( 'dns_reviewed_path' ) );
		$this->assertStringNotContainsString( 'I did not import a zone file', $this->page( $this->transfer( 'first', array( 'dns_imported_at' => time() ) ), 'dns' ) );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/transfer-dns-confirm', array( 'ack' => true ) )->get_status(), 'Only the owner.' );
	}
}
