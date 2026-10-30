<?php
/** DNS record REST routes: one RRset per request, owner only, FakeDns only. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class RecordRoutesTest extends TestCase {
	private FakeDns $dns;
	private int $user  = 0;
	private int $other = 0;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		$this->user  = self::factory()->user->create();
		$this->other = self::factory()->user->create();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( 'beltoft_services_zone_lock_wait' );
		remove_all_filters( 'beltoft_services_dns_write_limit' );
		parent::tear_down();
	}

	private function domain( int $user, string $name, array $meta = array() ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		return Resource::find( $r->id() );
	}

	private function call( string $method, string $route, int $user, array $body = array(), array $query = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, '/beltoft-services/v1/resources/' . $route );
		if ( $query ) { $request->set_query_params( $query ); }
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function writes(): array {
		return array_values( array_filter( $this->dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] ) );
	}

	private function last_write(): array {
		$writes = $this->writes();
		return $writes[ count( $writes ) - 1 ][2];
	}

	private function a( string $ip, int $ttl = 300 ): array {
		return array( 'ttl' => $ttl, 'records' => array( array( 'value' => $ip ) ) );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/beltoft-services/v1/resources/(?P<id>\d+)/dns-records', $routes );
		$this->assertArrayHasKey( '/beltoft-services/v1/resources/(?P<id>\d+)/dns-records/(?P<type>[A-Za-z]+)/(?P<name>[^/]+)', $routes );
	}

	public function test_get_lists_the_customer_records(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->assertTrue( Records::change( 'example.com', 'www', 'A', array( array( 'value' => '192.0.2.1' ) ) ) );

		$response = $this->call( 'GET', $r->id() . '/dns-records', $this->user );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertCount( 1, $data['rows'] );
		$this->assertSame( 'www', $data['rows'][0]['name'] );
		$this->assertSame( 3600, $data['ttl_default'] );
		$this->assertFalse( $data['paused'] );
		$this->assertIsInt( $data['serial'] );
	}

	public function test_put_creates_an_rrset_and_returns_its_rows_with_one_write(): void {
		$r = $this->domain( $this->user, 'example.com' );
		Records::change( 'example.com', 'keep', 'TXT', array( array( 'value' => 'hello' ) ) );
		$before = count( $this->writes() );

		$response = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.7' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'www' ), array_column( $response->get_data()['rows'], 'name' ) );
		$this->assertSame( '192.0.2.7', $response->get_data()['rows'][0]['value'] );
		$this->assertSame( 300, $response->get_data()['rows'][0]['ttl'] );
		$this->assertCount( $before + 1, $this->writes() );
		$this->assertCount( 1, $this->last_write(), 'One RRset in the write.' );
		$this->assertSame( array(), $this->http_calls(), 'No HTTP call is made.' );
	}

	public function test_put_with_a_wildcard_name_and_the_apex(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->assertSame( 200, $this->call( 'PUT', $r->id() . '/dns-records/A/*', $this->user, $this->a( '192.0.2.8' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'PUT', $r->id() . '/dns-records/A/@', $this->user, $this->a( '192.0.2.9' ) )->get_status() );
		$names = array_column( Records::to_rows( 'example.com', $this->dns->zone( 'example.com' )['rrsets'] ), 'name' );
		sort( $names );
		$this->assertSame( array( '*', '@' ), $names );
	}

	public function test_a_rename_is_one_write_of_two_sets(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) );
		$before = count( $this->writes() );

		$response = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) + array( 'new_name' => 'web' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'web' ), array_column( $response->get_data()['rows'], 'name' ) );
		$this->assertCount( $before + 1, $this->writes() );
		$sets = $this->last_write();
		$this->assertSame( array( 'DELETE', 'REPLACE' ), array_column( $sets, 'changetype' ) );
		$this->assertSame( array( 'web' ), array_column( Records::to_rows( 'example.com', $this->dns->zone( 'example.com' )['rrsets'] ), 'name' ), 'The old name is gone.' );
	}

	public function test_a_type_change_moves_the_set_and_refuses_an_existing_target(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->call( 'PUT', $r->id() . '/dns-records/A/blog', $this->user, $this->a( '192.0.2.1' ) );
		$response = $this->call( 'PUT', $r->id() . '/dns-records/A/blog', $this->user, array( 'ttl' => 300, 'records' => array( array( 'value' => 'host.example.org' ) ), 'new_type' => 'CNAME' ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$rows = Records::to_rows( 'example.com', $this->dns->zone( 'example.com' )['rrsets'] );
		$this->assertSame( array( 'CNAME' ), array_column( $rows, 'type' ) );

		$this->call( 'PUT', $r->id() . '/dns-records/A/shop', $this->user, $this->a( '192.0.2.2' ) );
		$clash = $this->call( 'PUT', $r->id() . '/dns-records/A/shop', $this->user, $this->a( '192.0.2.2' ) + array( 'new_name' => 'blog', 'new_type' => 'CNAME' ) );
		$this->assertSame( 409, $clash->get_status() );
		$this->assertSame( 'exists', $clash->get_data()['code'] );
	}

	public function test_delete_removes_the_set_or_one_value(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->call( 'PUT', $r->id() . '/dns-records/A/rr', $this->user, array( 'ttl' => 300, 'records' => array( array( 'value' => '192.0.2.1' ), array( 'value' => '192.0.2.2' ) ) ) );

		$one = $this->call( 'DELETE', $r->id() . '/dns-records/A/rr', $this->user, array(), array( 'value' => '192.0.2.1' ) );
		$this->assertSame( 200, $one->get_status() );
		$this->assertSame( array( '192.0.2.2' ), array_column( $one->get_data()['rows'], 'value' ) );

		$missing = $this->call( 'DELETE', $r->id() . '/dns-records/A/rr', $this->user, array(), array( 'value' => '192.0.2.99' ) );
		$this->assertSame( 404, $missing->get_status() );

		$all = $this->call( 'DELETE', $r->id() . '/dns-records/A/rr', $this->user );
		$this->assertSame( 200, $all->get_status() );
		$this->assertSame( array(), $all->get_data()['rows'] );
		$this->assertSame( array(), Records::to_rows( 'example.com', $this->dns->zone( 'example.com' )['rrsets'] ) );
	}

	public function test_validation_errors_are_422_with_a_plain_message(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$bad = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( 'not-an-ip' ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'bad_ip', $bad->get_data()['code'] );
		$this->assertStringNotContainsString( "\u{2014}", $bad->get_data()['message'] );

		$this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) );
		$clash = $this->call( 'PUT', $r->id() . '/dns-records/CNAME/www', $this->user, array( 'ttl' => 300, 'records' => array( array( 'value' => 'a.example.org' ) ) ) );
		$this->assertSame( 422, $clash->get_status() );
		$this->assertSame( 'cname_conflict', $clash->get_data()['code'] );

		$this->assertSame( 422, $this->call( 'PUT', $r->id() . '/dns-records/NS/www', $this->user, $this->a( '192.0.2.1' ) )->get_status(), 'NS stays refused.' );
		$this->assertSame( 422, $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, array( 'ttl' => 300, 'records' => array() ) )->get_status() );
	}

	public function test_a_stale_write_is_409(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) );
		$stale = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.5' ) + array( 'expect' => array( array( 'value' => '192.0.2.99', 'ttl' => 300 ) ) ) );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'stale', $stale->get_data()['code'] );
		$ok = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.5' ) + array( 'expect' => array( array( 'value' => '192.0.2.1', 'ttl' => 300 ) ) ) );
		$this->assertSame( 200, $ok->get_status() );
	}

	public function test_a_busy_zone_is_409_and_makes_no_write(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$before = count( $this->writes() );
		// A second connection holds the lock.
		global $wpdb;
		$name = Locks::zone( 'example.com' );
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 1)" );
		try {
			$response = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) );
		} finally {
			$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
			$other->close();
		}
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'zone_busy', $response->get_data()['code'] );
		$this->assertCount( $before, $this->writes() );
	}

	public function test_a_foreign_resource_gets_the_generic_403_and_nothing_is_written(): void {
		$r = $this->domain( $this->user, 'example.com' );
		foreach ( array( array( 'GET', '' ), array( 'PUT', '/A/www' ), array( 'DELETE', '/A/www' ) ) as $case ) {
			$response = $this->call( $case[0], $r->id() . '/dns-records' . $case[1], $this->other, 'PUT' === $case[0] ? $this->a( '192.0.2.1' ) : array() );
			$this->assertSame( 403, $response->get_status(), $case[0] );
		}
		$this->assertSame( array(), $this->writes() );
	}

	public function test_nobody_logged_in_gets_401_or_403(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$response = $this->call( 'GET', $r->id() . '/dns-records', 0 );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_a_domain_with_custom_name_servers_or_external_dns_is_409_not_ours(): void {
		$custom   = $this->domain( $this->user, 'custom.example', array( 'ns_mode' => 'custom' ) );
		$external = $this->domain( $this->user, 'external.example', array( 'dns_external' => true ) );
		foreach ( array( $custom, $external ) as $r ) {
			foreach ( array( array( 'GET', '' ), array( 'PUT', '/A/www' ), array( 'DELETE', '/A/www' ) ) as $case ) {
				$response = $this->call( $case[0], $r->id() . '/dns-records' . $case[1], $this->user, 'PUT' === $case[0] ? $this->a( '192.0.2.1' ) : array() );
				$this->assertSame( 409, $response->get_status() );
				$this->assertSame( 'not_ours', $response->get_data()['code'] );
			}
		}
		$this->assertSame( array(), $this->writes() );
	}

	public function test_a_paused_service_is_403_paused_with_its_own_message(): void {
		$r = $this->domain( $this->user, 'paused.example', array( 'records_off' => true ) );
		foreach ( array( $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) ), $this->call( 'GET', $r->id() . '/dns-records', $this->user ), $this->call( 'DELETE', $r->id() . '/dns-records/A/www', $this->user ) ) as $response ) {
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'paused', $response->get_data()['code'] );
			$this->assertSame( 'DNS hosting is paused for this domain.', $response->get_data()['message'] );
		}
		$this->assertSame( array(), $this->writes() );
	}

	public function test_deleting_one_value_is_checked_against_the_rows_read(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->call( 'PUT', $r->id() . '/dns-records/A/rr', $this->user, array( 'ttl' => 300, 'records' => array( array( 'value' => '192.0.2.1' ), array( 'value' => '192.0.2.2' ) ) ) );
		$dns = $this->dns;
		// Another edit lands between the read and the write: the write must be refused as stale.
		$racing = new class( $dns ) extends FakeDns {
			public FakeDns $inner;
			public function __construct( FakeDns $inner ) { $this->inner = $inner; }
			public function zone( string $zone ): array|\WP_Error|null {
				$data = $this->inner->zone( $zone );
				if ( ! $this->done ) {
					$this->done = true;
					$this->inner->replace_rrsets( $zone, array( array( 'name' => 'rr.' . $zone . '.', 'type' => 'A', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '192.0.2.1', 'disabled' => false ), array( 'content' => '192.0.2.2', 'disabled' => false ), array( 'content' => '192.0.2.3', 'disabled' => false ) ) ) ) );
				}
				return $data;
			}
			public bool $done = false;
			public function replace_rrsets( string $zone, array $rrsets ): bool|\WP_Error { return $this->inner->replace_rrsets( $zone, $rrsets ); }
			public function calls(): array { return $this->inner->calls(); }
		};
		remove_all_filters( 'beltoft_services_domains_dns' );
		add_filter( 'beltoft_services_domains_dns', static fn() => $racing );
		$response = $this->call( 'DELETE', $r->id() . '/dns-records/A/rr', $this->user, array(), array( 'value' => '192.0.2.1' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'stale', $response->get_data()['code'] );
		$values = array_column( Records::to_rows( 'example.com', $dns->zone( 'example.com' )['rrsets'] ), 'value' );
		sort( $values );
		$this->assertSame( array( '192.0.2.1', '192.0.2.2', '192.0.2.3' ), $values );
	}

	public function test_a_folded_dns_service_is_refused(): void {
		$product = $this->service_product( 'dns', array(), array( 'slug' => 'dns-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$r       = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => 'folded.example', 'mode' => 'dns', 'folded_into' => 99 ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->dns->create_zone( 'folded.example', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->assertSame( 403, $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) )->get_status() );
		$this->assertSame( array(), $this->writes() );
	}

	public function test_a_service_that_is_not_a_domain_is_refused(): void {
		$this->fake_driver();
		$license = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false, 'user_id' => $this->user ) );
		$r = Resource::for_license( (int) $license['id'] ) ?? Resource::create( $license, new \BeltoftServices\Tests\Support\FakeDriver(), array(), array() );
		if ( ! $r ) { $this->markTestSkipped( 'No fake resource.' ); }
		$this->assertSame( 403, $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) )->get_status() );
	}

	public function test_the_zone_cannot_be_set_from_the_request(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$this->dns->create_zone( 'victim.example', array( 'ns1.example.net', 'ns2.example.net' ) );
		$response = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) + array( 'zone' => 'victim.example', 'domain' => 'victim.example' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->dns->zone( 'victim.example' )['rrsets'] );
		$outside = $this->call( 'PUT', $r->id() . '/dns-records/A/www.victim.example', $this->user, $this->a( '192.0.2.1' ) );
		$this->assertSame( 422, $outside->get_status() );
		$this->assertSame( array(), $this->dns->zone( 'victim.example' )['rrsets'] );
	}

	public function test_the_write_limit_returns_429(): void {
		$r = $this->domain( $this->user, 'example.com' );
		add_filter( 'beltoft_services_dns_write_limit', static fn() => 2 );
		$codes = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$codes[] = $this->call( 'PUT', $r->id() . '/dns-records/A/h' . $i, $this->user, $this->a( '192.0.2.' . $i ) )->get_status();
		}
		$this->assertSame( array( 200, 200, 429 ), $codes );
	}

	public function test_a_dns_failure_is_502_without_the_servers_words(): void {
		$r = $this->domain( $this->user, 'example.com' );
		$failing = new class() extends FakeDns {
			public function replace_rrsets( string $zone, array $rrsets ): bool|\WP_Error { return new \WP_Error( 'http_500', 'PowerDNS exploded at 127.0.0.1:8081' ); }
		};
		$failing->create_zone( 'example.com', array( 'ns1.example.net', 'ns2.example.net' ) );
		remove_all_filters( 'beltoft_services_domains_dns' );
		add_filter( 'beltoft_services_domains_dns', static fn() => $failing );
		$response = $this->call( 'PUT', $r->id() . '/dns-records/A/www', $this->user, $this->a( '192.0.2.1' ) );
		$this->assertSame( 502, $response->get_status() );
		$this->assertStringNotContainsString( 'PowerDNS', $response->get_data()['message'] );
		$this->assertStringNotContainsString( '127.0.0.1', $response->get_data()['message'] );
	}
}
