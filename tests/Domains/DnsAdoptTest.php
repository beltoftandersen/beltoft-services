<?php
/** "Use Beltoft DNS": a domain registered here moves its DNS to our name servers once its records are here. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\Overview;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DnsAdoptTest extends TestCase {
	private FakeDns $dns;
	private FakeClient $registrar;
	private int $user = 0;
	private bool $answers = true;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		$this->dns       = new FakeDns();
		$this->registrar = new FakeClient();
		$dns             = $this->dns;
		$registrar       = $this->registrar;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_client', static fn() => $registrar );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		add_filter( 'beltoft_services_transfer_registrar', '__return_false' );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'ns1.old-host.example', 'ns2.old-host.example' ) );
		add_filter(
			'beltoft_services_dns_proof_result',
			static function ( $pre, $call ) {
				if ( 'delegation' === $call ) {
					return array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.old-host.example', 'ns2.old-host.example' ), 'ips' => array(), 'ds' => false, 'ours' => false );
				}
				if ( 'scan' === $call ) {
					return array( 'records' => array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ), 'server' => 'ns1.old-host.example', 'queries' => 1 );
				}
				return $pre;
			},
			10,
			2
		);
		// The zone answers on every name server unless a test says it does not.
		add_filter( 'beltoft_services_dns_query', fn( $value, $server, $domain, $type ) => ! $this->answers && 6 === $type ? new \WP_Error( 'dns_missing', 'Not yet.' ) : $value, 10, 4 );
		$this->user = self::factory()->user->create();
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

	/** A domain registered here whose DNS is hosted elsewhere. */
	private function domain( array $meta = array() ): Resource {
		$name    = 'adopt-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'transfer', 'remote_id' => '123', 'dns_external' => true, 'transfer_ns' => array( 'ns1.old-host.example', 'ns2.old-host.example' ), 'steps' => array( 'contact', 'order', 'active' ), 'state' => 'ready', 'desired' => 'ready' ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		return Resource::find( $r->id() );
	}

	private function call( Resource $r, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/dns-adopt' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function page( Resource $r, string $tab = 'nameservers' ): string {
		$GLOBALS['wp']->query_vars['domains'] = (string) $r->license()['id'] . '/' . $tab;
		ob_start();
		Domains::render( array( View::license( $r->license() ) ) );
		return (string) ob_get_clean();
	}

	private function import( Resource $r ): void {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/dns-import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'text' => '$ORIGIN ' . $r->get( 'domain' ) . ".\n@ 300 IN A 192.0.2.77\n", 'apply' => true ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_the_option_is_a_quiet_section_with_one_sentence_why(): void {
		$html = $this->page( $this->domain() );
		$this->assertStringContainsString( 'Use Beltoft DNS', $html );
		$this->assertStringContainsString( 'One place for your domain and its DNS. Name servers are the servers that answer for your domain.', $html );
		$this->assertStringContainsString( 'data-dns-adopt="start"', $html );
		$this->assertStringNotContainsString( 'data-dns-adopt="switch"', $html );
	}

	public function test_a_domain_that_already_uses_our_dns_is_not_offered_it(): void {
		$r = $this->domain( array( 'dns_external' => false ) );
		$this->assertStringNotContainsString( 'data-dns-adopt', $this->page( $r ) );
		$this->assertSame( 403, $this->call( $r, array( 'step' => 'start' ) )->get_status() );
	}

	public function test_start_makes_our_zone_and_copies_what_we_can_find(): void {
		$r   = $this->domain();
		$out = $this->call( $r, array( 'step' => 'start' ) );
		$this->assertSame( 200, $out->get_status(), (string) wp_json_encode( $out->get_data() ) );
		$this->assertStringContainsString( '192.0.2.10', (string) wp_json_encode( $this->dns->zone( (string) $r->get( 'domain' ) ) ) );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'I did not import a zone file. I understand that records we could not find are missing until I add them.', $html );
		$this->assertStringContainsString( 'data-dns-adopt="confirm"', $html );
		$this->assertStringContainsString( 'data-zone-import', $this->page( Resource::find( $r->id() ), 'dns' ), 'The import is in the DNS editor.' );
	}

	public function test_the_steps_come_in_order_and_the_acknowledgement_is_needed_without_an_import(): void {
		$r = $this->domain();
		$this->assertSame( 409, $this->call( $r, array( 'step' => 'confirm', 'ack' => true ) )->get_status(), 'Start first.' );
		$this->assertSame( 200, $this->call( $r, array( 'step' => 'start' ) )->get_status() );
		$this->assertSame( 422, $this->call( $r, array( 'step' => 'switch' ) )->get_status(), 'Not before the records are confirmed.' );
		$this->assertSame( 422, $this->call( $r, array( 'step' => 'confirm' ) )->get_status(), 'Neither an import nor the box.' );
		$this->assertSame( 200, $this->call( $r, array( 'step' => 'confirm', 'ack' => true ) )->get_status() );
		$this->assertSame( 'acknowledged', Resource::find( $r->id() )->get( 'dns_adopt' )['path'] );
		$this->assertStringContainsString( 'data-dns-adopt="switch"', $this->page( Resource::find( $r->id() ) ) );
	}

	public function test_an_imported_zone_file_confirms_without_the_box(): void {
		$r = $this->domain();
		$this->call( $r, array( 'step' => 'start' ) );
		$this->import( Resource::find( $r->id() ) );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'I did not import a zone file', $html );
		$this->assertSame( 200, $this->call( $r, array( 'step' => 'confirm' ) )->get_status() );
		$this->assertSame( 'imported', Resource::find( $r->id() )->get( 'dns_adopt' )['path'] );
	}

	public function test_the_switch_is_refused_until_the_zone_answers_on_both_name_servers_then_sets_ours(): void {
		$this->registrar->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$this->registrar->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$r = $this->domain();
		$this->call( $r, array( 'step' => 'start' ) );
		$this->call( $r, array( 'step' => 'confirm', 'ack' => true ) );
		$this->answers = false;
		$refused       = $this->call( $r, array( 'step' => 'switch' ) );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'Our name servers do not answer for your zone yet. Try again in a minute.', $refused->get_data()['message'] );
		$this->assertSame( array(), array_filter( $this->registrar->calls(), static fn( $c ) => 'PUT' === $c['method'] ), 'The registrar is not touched.' );
		$this->answers = true;
		$done          = $this->call( $r, array( 'step' => 'switch' ) );
		$this->assertSame( 200, $done->get_status(), (string) wp_json_encode( $done->get_data() ) );
		$this->assertSame( 'Your domain now uses Beltoft DNS. You can switch back in Name servers.', $done->get_data()['message'] );
		$put = array_values( array_filter( $this->registrar->calls(), static fn( $c ) => 'PUT' === $c['method'] ) );
		$this->assertSame( array( array( 'name' => 'ns1.example.net' ), array( 'name' => 'ns2.example.net' ) ), $put[0]['body']['name_servers'] );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ours', $fresh->get( 'ns_mode' ) );
		$this->assertFalse( (bool) $fresh->get( 'dns_external' ) );
		$this->assertStringNotContainsString( 'data-dns-adopt', $this->page( $fresh ) );
	}

	public function test_only_the_owner_may_use_it_and_it_is_rate_limited(): void {
		$r = $this->domain();
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->call( $r, array( 'step' => 'start' ) )->get_status() );
		wp_set_current_user( $this->user );
		$status = array();
		for ( $i = 0; $i < 12; ++$i ) {
			$status[] = $this->call( $r, array( 'step' => 'bogus' ) )->get_status();
		}
		$this->assertContains( 429, $status, 'At most 10 calls a minute.' );
	}
}
