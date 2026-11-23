<?php
/** The search in two parts: the quick extensions first, the other featured ones after. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\TestCase;

class SearchPartsTest extends TestCase {
	/** A registrar that says every name it is asked about is free, and records what it was asked. */
	private function registrar(): FakeClient {
		$tlds = array( 'com', 'net', 'org', 'io', 'me' );
		update_option( Prices::OPTION, array_fill_keys( $tlds, array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new class() extends FakeClient {
			public function request( string $method, string $path, array $body = array(), array $query = array() ): array|\WP_Error {
				parent::request( $method, $path, $body, $query );
				$results = array();
				foreach ( (array) ( $body['domains'] ?? array() ) as $d ) { $results[] = array( 'domain' => $d['name'] . '.' . $d['extension'], 'status' => 'free', 'is_premium' => false ); }
				return array( 'results' => $results );
			}
		};
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->settings( array( 'domain_featured_tlds' => 'com,net,org,io', 'domain_quick_tlds' => 'io,com' ) );
		return $fake;
	}

	private function domains( array $found ): array {
		return array_map( static fn( $r ) => $r['domain'], $found['results'] );
	}

	private function name( string $prefix ): string {
		return $prefix . strtolower( wp_generate_password( 8, false, false ) );
	}

	public function test_the_first_part_has_the_quick_extensions_in_featured_order_and_the_rest_the_others(): void {
		$this->registrar();
		$n = $this->name( 'parts' );
		$this->assertSame( array( "$n.com", "$n.io" ), $this->domains( Search::find( $n, false, 'first' ) ) );
		$this->assertSame( array( "$n.net", "$n.org" ), $this->domains( Search::find( $n, false, 'rest' ) ) );
	}

	public function test_a_typed_extension_leads_the_first_part_and_is_not_asked_again(): void {
		$this->registrar();
		$n = $this->name( 'typed' );
		$this->assertSame( array( "$n.me", "$n.com", "$n.io" ), $this->domains( Search::find( "$n.me", false, 'first' ) ) );
		$this->assertSame( array( "$n.net", "$n.org" ), $this->domains( Search::find( "$n.me", false, 'rest' ) ) );
		$this->assertSame( array( "$n.net", "$n.com", "$n.io" ), $this->domains( Search::find( "$n.net", false, 'first' ) ), 'A typed featured extension moves to the front.' );
		$this->assertSame( array( "$n.org" ), $this->domains( Search::find( "$n.net", false, 'rest' ) ) );
	}

	public function test_each_part_is_one_registrar_lookup_with_only_its_own_names(): void {
		$fake = $this->registrar();
		$n    = $this->name( 'calls' );
		Search::find( $n, false, 'first' );
		Search::find( $n, false, 'rest' );
		$asked = array_values( array_map( static fn( $c ) => array_map( static fn( $d ) => $d['extension'], $c['body']['domains'] ), array_filter( $fake->calls(), static fn( $c ) => '/domains/check' === $c['path'] ) ) );
		$this->assertSame( array( array( 'com', 'io' ), array( 'net', 'org' ) ), $asked );
	}

	public function test_without_a_part_the_answer_is_unchanged(): void {
		$this->registrar();
		$n = $this->name( 'whole' );
		$this->assertSame( array( "$n.com", "$n.net", "$n.org", "$n.io" ), $this->domains( Search::find( $n ) ) );
	}

	public function test_no_quick_extensions_puts_everything_in_the_first_part(): void {
		$this->registrar();
		$this->settings( array( 'domain_featured_tlds' => 'com,net,org,io', 'domain_quick_tlds' => '' ) );
		$n = $this->name( 'empty' );
		$this->assertSame( array( "$n.com", "$n.net", "$n.org", "$n.io" ), $this->domains( Search::find( $n, false, 'first' ) ) );
		$this->assertSame( array(), Search::find( $n, false, 'rest' )['results'] );
	}

	public function test_the_route_takes_the_part_and_each_part_has_its_own_visitor_limit(): void {
		$this->registrar();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
		add_filter( 'beltoft_services_domain_search_limit', static fn() => 1 );
		$n = $this->name( 'route' );
		$request = static function ( string $part ) use ( $n ): \WP_REST_Request { $r = new \WP_REST_Request( 'GET', '/beltoft-services/v1/domains/check' ); $r->set_param( 'name', $n ); if ( '' !== $part ) { $r->set_param( 'part', $part ); } return $r; };

		$first = Search::rest_check( $request( 'first' ) );
		$rest  = Search::rest_check( $request( 'rest' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $first );
		$this->assertInstanceOf( \WP_REST_Response::class, $rest, 'The second half of one search does not use up the first half\'s allowance.' );
		$this->assertSame( array( "$n.com", "$n.io" ), $this->domains( $first->get_data() ) );
		$this->assertSame( array( "$n.net", "$n.org" ), $this->domains( $rest->get_data() ) );
		$this->assertWPError( Search::rest_check( $request( 'first' ) ), 'A second search is still limited.' );
		$this->assertWPError( Search::rest_check( $request( 'rest' ) ) );
	}

	public function test_an_unknown_part_is_refused_by_the_route(): void {
		$routes = rest_get_server()->get_routes();
		$args   = $routes['/beltoft-services/v1/domains/check'][0]['args']['part'] ?? array();
		$this->assertSame( array( 'first', 'rest' ), $args['enum'] ?? null );
	}
}
