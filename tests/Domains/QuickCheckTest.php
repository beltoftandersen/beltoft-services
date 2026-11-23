<?php
/** The quick availability answer from public DNS and the registries' RDAP, before the registrar answers. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\PublicDns;
use BeltoftServices\Domains\QuickCheck;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\TestCase;

class QuickCheckTest extends TestCase {
	private FakeClient $fake;

	protected function setUp(): void {
		parent::setUp();
		delete_transient( QuickCheck::BOOTSTRAP_KEY );
		update_option( Prices::OPTION, array_fill_keys( array( 'com', 'org', 'io' ), array( 'price' => 14, 'restricted' => false ) ) );
		$this->settings( array( 'domain_featured_tlds' => 'com,org,io', 'domain_quick_tlds' => 'com' ) );
		$this->fake = new FakeClient();
		$fake       = $this->fake;
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		// Public DNS: only taken-ns.com and quick-taken.io have name servers.
		PublicDns::$reader = static fn( string $fqdn, int $flag ) => in_array( $fqdn, array( 'quick-taken.io.' ), true ) ? array( array( 'type' => 'NS', 'target' => 'ns1.example.net' ) ) : array();
		$this->respond_with( static function ( string $url ) {
			if ( QuickCheck::BOOTSTRAP === $url ) {
				$body = wp_json_encode( array( 'services' => array( array( array( 'com', 'net' ), array( 'https://rdap.example/com/' ) ), array( array( 'org' ), array( 'https://rdap.example/org/' ) ) ) ) );
				return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
			}
			$code = str_contains( $url, 'quick-taken' ) ? 200 : ( str_contains( $url, 'broken' ) ? 503 : 404 );
			return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		} );
	}

	protected function tearDown(): void {
		PublicDns::$reader = null;
		parent::tearDown();
	}

	private function statuses( array $found ): array {
		$out = array();
		foreach ( $found['results'] as $r ) { $out[ $r['domain'] ] = $r['status']; }
		return $out;
	}

	public function test_dns_name_servers_mean_taken_rdap_answers_free_or_taken_and_no_rdap_is_checking(): void {
		$this->assertSame(
			array( 'quick-taken.com' => 'taken', 'quick-taken.org' => 'taken', 'quick-taken.io' => 'taken' ),
			$this->statuses( Search::find( 'quick-taken', false, 'quick' ) )
		);
		$this->assertSame(
			array( 'quick-free.com' => 'free', 'quick-free.org' => 'free', 'quick-free.io' => 'checking' ),
			$this->statuses( Search::find( 'quick-free', false, 'quick' ) )
		);
	}

	public function test_a_registry_that_fails_gives_checking_not_free(): void {
		$this->assertSame( 'checking', $this->statuses( Search::find( 'broken', false, 'quick' ) )['broken.com'] );
	}

	public function test_the_quick_answer_never_asks_the_registrar_and_carries_the_prices(): void {
		$found = Search::find( 'quick-free.com', false, 'quick' );
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertSame( 14, $found['results'][0]['price'] );
		$this->assertNull( $found['results'][2]['price'], 'A row still being checked has no price yet.' );
	}

	public function test_the_bootstrap_is_fetched_once_and_kept(): void {
		Search::find( 'quick-free', false, 'quick' );
		Search::find( 'quick-other', false, 'quick' );
		$this->assertCount( 1, array_filter( $this->http_calls(), static fn( $c ) => QuickCheck::BOOTSTRAP === $c['url'] ) );
		$rdap = array_values( array_filter( $this->http_calls(), static fn( $c ) => str_starts_with( $c['url'], 'https://rdap.example/' ) ) );
		$this->assertSame( 'https://rdap.example/com/domain/quick-free.com', $rdap[0]['url'] );
	}

	public function test_the_route_takes_quick_with_its_own_visitor_limit(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 1, 250 );
		add_filter( 'beltoft_services_domain_search_limit', static fn() => 1 );
		$request = static function ( string $part ): \WP_REST_Request { $r = new \WP_REST_Request( 'GET', '/beltoft-services/v1/domains/check' ); $r->set_param( 'name', 'quick-free' ); $r->set_param( 'part', $part ); return $r; };
		$this->assertInstanceOf( \WP_REST_Response::class, Search::rest_check( $request( 'quick' ) ) );
		$this->assertWPError( Search::rest_check( $request( 'quick' ) ) );
		$routes = rest_get_server()->get_routes();
		$this->assertSame( array( 'first', 'rest', 'quick' ), $routes['/beltoft-services/v1/domains/check'][0]['args']['part']['enum'] );
	}
}
