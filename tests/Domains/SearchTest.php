<?php
/** Public domain search tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\TestCase;

class SearchTest extends TestCase {
	public function test_check_normalises_idn_and_case(): void {
		$this->assertSame( 'xn--bcher-kva.dk', Search::normalise( 'Bücher.DK' ) );
	}

	public function test_check_refuses_invalid_labels(): void {
		foreach ( array( '-a.com', str_repeat( 'a', 64 ) . '.com', 'a..com' ) as $bad ) {
			$this->assertWPError( Search::normalise( $bad ) );
		}
	}

	/** A fake registrar that answers one lookup for each name, in the order they will be asked. */
	private function client( array $names ): FakeClient {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new FakeClient();
		foreach ( $names as $name ) {
			$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => $name . '.com', 'status' => 'free', 'is_premium' => false ) ) ) );
		}
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		return $fake;
	}

	private function unique( string $prefix ): string {
		return $prefix . '-' . strtolower( wp_generate_password( 8, false, false ) );
	}

	private function lookups( FakeClient $fake ): int {
		return count( array_filter( $fake->calls(), static fn( $c ) => '/domains/check' === $c['path'] ) );
	}

	public function test_availability_is_cached_but_the_price_is_always_current(): void {
		$name = $this->unique( 'cache' );
		$fake = $this->client( array( $name ) );

		$first = Search::find( $name . '.com' );
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 19, 'restricted' => false ) ) );
		$second = Search::find( $name . '.com' );

		$this->assertSame( 1, $this->lookups( $fake ), 'The second search is answered from the cache.' );
		$this->assertSame( 14, $first['results'][0]['price'] );
		$this->assertSame( 19, $second['results'][0]['price'], 'A price change shows at once.' );
	}

	public function test_an_order_asks_the_registrar_again(): void {
		$name = $this->unique( 'fresh' );
		$fake = $this->client( array( $name, $name ) );

		Search::find( $name . '.com' );
		Search::find( $name . '.com', true );

		$this->assertSame( 2, $this->lookups( $fake ), 'A cached answer is never what a domain is sold on.' );
	}

	public function test_a_failed_lookup_is_not_cached(): void {
		$name = $this->unique( 'fail' );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', new \WP_Error( 'transport', 'down' ) );
		$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => $name . '.com', 'status' => 'free' ) ) ) );
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );

		$this->assertWPError( Search::find( $name . '.com' ) );
		$this->assertIsArray( Search::find( $name . '.com' ), 'The next try reaches the registrar again.' );
	}

	public function test_registrar_lookups_have_a_site_wide_hourly_cap_that_cached_answers_do_not_use(): void {
		list( $a, $b, $c, $d ) = array( $this->unique( 'capa' ), $this->unique( 'capb' ), $this->unique( 'capc' ), $this->unique( 'capd' ) );
		$fake = $this->client( array( $a, $b ) );
		add_filter( 'beltoft_services_domain_lookup_limit', static fn() => 2 );

		$this->assertIsArray( Search::find( $a . '.com' ) );
		$this->assertIsArray( Search::find( $b . '.com' ) );
		$busy = Search::find( $c . '.com' );

		$this->assertWPError( $busy );
		$this->assertSame( 'search_busy', $busy->get_error_code() );
		$this->assertIsArray( Search::find( $a . '.com' ), 'A cached search still works while the cap is reached.' );
		$fresh = Search::find( $d . '.com', true );
		$this->assertWPError( $fresh, 'A fresh lookup reaches the registrar too, so the cap applies to it.' );
		$this->assertSame( 'search_busy', $fresh->get_error_code() );
		$this->assertSame( 2, $this->lookups( $fake ), 'The refused and the cached searches made no registrar call.' );
	}

	public function test_a_visitor_is_limited_per_minute_and_the_answer_is_kept_privately(): void {
		$name = $this->unique( 'limit' );
		$this->client( array( $name ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		add_filter( 'beltoft_services_domain_search_limit', static fn() => 2 );
		$request = static function ( string $name ): \WP_REST_Request { $r = new \WP_REST_Request( 'GET', '/beltoft-services/v1/domains/check' ); $r->set_param( 'name', $name ); return $r; };

		$ok = Search::rest_check( $request( $name ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $ok );
		$this->assertSame( 'private, max-age=60', $ok->get_headers()['Cache-Control'] );
		Search::rest_check( $request( $name ) );
		$third = Search::rest_check( $request( $name ) );

		$this->assertWPError( $third );
		$this->assertSame( 429, $third->get_error_data()['status'] );
		$this->assertWPError( Search::rest_check( $request( str_repeat( 'a', 300 ) ) ) );
	}

	public function test_premium_shown_as_premium_not_sellable(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => 'premium.com', 'status' => 'free', 'is_premium' => true ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = Search::find( 'premium.com' );
		$this->assertSame( 'premium', $result['results'][0]['status'] );
		$this->assertNull( $result['results'][0]['price'] );
	}

	public function test_dk_is_not_offered_until_agreement_approval(): void {
		update_option( Prices::OPTION, array( 'dk' => array( 'price' => 13, 'restricted' => true ) ) );
		$this->settings( array( 'domain_featured_tlds' => 'dk' ) );
		$result = Search::find( 'example.dk' );
		$this->assertSame( 'not_sold', $result['results'][0]['status'] );
	}

	public function test_registered_domain_shows_yearly_transfer_price(): void {
		update_option( Prices::OPTION, array( 'org' => array( 'price' => 26, 'restricted' => false ) ) );
		$this->settings( array( 'domain_featured_tlds' => 'org' ) );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => 'owned.org', 'status' => 'taken' ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = Search::find( 'owned.org' );
		$this->assertSame( 'taken', $result['results'][0]['status'] );
		$this->assertSame( 26, $result['results'][0]['price'] );
	}

	public function test_a_registrar_error_on_the_check_route_is_generic(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', new \WP_Error( 'op_399', 'Registrar request failed.', array( 'status' => 400, 'desc' => 'secret registrar detail' ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/domains/check' );
		$request->set_query_params( array( 'name' => $this->unique( 'generic' ) . '.com' ) );
		$result = Search::rest_check( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'registrar_unavailable', $result->get_error_code() );
		$this->assertSame( 'Domain search is not available right now. Try again in a few minutes.', $result->get_error_message() );
		$this->assertSame( array( 'status' => 503 ), $result->get_error_data() );
		$json = wp_json_encode( rest_convert_error_to_response( $result )->get_data() );
		$this->assertStringNotContainsString( 'op_', $json );
		$this->assertStringNotContainsString( 'secret', $json );
		$this->assertStringNotContainsString( 'desc', $json );
	}

	public function test_the_plugins_own_errors_stay_as_they_are(): void {
		$this->assertSame( 'bad_domain', Search::find( '-bad-.com' )->get_error_code() );
	}
}
