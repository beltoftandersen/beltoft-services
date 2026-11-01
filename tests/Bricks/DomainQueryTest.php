<?php
/**
 * The Domain availability query type's rows and the one-click add handler (no Bricks classes needed).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Bricks;

use BeltoftServices\Bricks\DomainQuery;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Tests\TestCase;

/**
 * Tests.
 */
final class DomainQueryTest extends TestCase {

	public function tear_down(): void {
		unset( $_GET['domain'] );
		DomainQuery::reset();
		parent::tear_down();
	}

	private function registrar( array $items ): FakeClient {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ), 'net' => array( 'price' => 16, 'restricted' => false ) ) );
		update_option( 'beltoft_services_settings', array_merge( (array) get_option( 'beltoft_services_settings', array() ), array( 'domain_featured_tlds' => 'net' ) ) );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', array( 'results' => $items ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		return $fake;
	}

	private function name(): string {
		return 'q' . strtolower( wp_generate_password( 8, false, false ) );
	}

	public function test_the_query_type_is_added_to_the_builder_options(): void {
		$options = DomainQuery::query_type( array( 'queryTypes' => array( 'post' => 'Posts' ) ) );
		$this->assertArrayHasKey( DomainQuery::TYPE, $options['queryTypes'] );
		$this->assertSame( array( 'other' => 1 ), DomainQuery::query_type( array( 'other' => 1 ) ) );
	}

	public function test_rows_come_from_the_search_for_the_url_term(): void {
		$name = $this->name();
		$this->registrar( array(
			array( 'domain' => $name . '.com', 'status' => 'free', 'is_premium' => false ),
			array( 'domain' => $name . '.net', 'status' => 'taken', 'is_premium' => false ),
		) );
		$_GET['domain'] = $name . '.com';
		$query          = (object) array( 'object_type' => DomainQuery::TYPE );
		$rows           = DomainQuery::run( array(), $query );
		$this->assertCount( 2, $rows );
		$this->assertSame( $name . '.com', $rows[0]['domain'] );
		$this->assertSame( 'available', $rows[0]['status'] );
		$this->assertSame( 1, $rows[0]['available'] );
		$this->assertSame( 1, $rows[0]['primary'] );
		$this->assertSame( '€14 / year', $rows[0]['price'] );
		$this->assertSame( 'Renews at €14 / year', $rows[0]['renewal_price'] );
		$this->assertStringContainsString( 'action=' . DomainQuery::ACTION, $rows[0]['add_url'] );
		$this->assertStringContainsString( 'domain=' . $name . '.com', $rows[0]['add_url'] );
		$this->assertSame( 'taken', $rows[1]['status'] );
		$this->assertSame( 0, $rows[1]['available'] );
		$this->assertSame( '', $rows[1]['add_url'], 'A taken name has no add link.' );
		$this->assertSame( 'net', $rows[1]['tld'] );
		$this->assertSame( 0, $rows[1]['primary'] );
	}

	public function test_another_query_type_is_left_alone(): void {
		$_GET['domain'] = 'whatever.com';
		$this->assertSame( array( 'x' ), DomainQuery::run( array( 'x' ), (object) array( 'object_type' => 'post' ) ) );
	}

	public function test_no_term_gives_no_rows_and_no_error(): void {
		$this->assertSame( array(), DomainQuery::run( array(), (object) array( 'object_type' => DomainQuery::TYPE ) ) );
		$this->assertSame( '', DomainQuery::value( 'bds_error' ) );
		$this->assertSame( '', DomainQuery::value( 'bds_search_term' ) );
	}

	public function test_an_invalid_term_gives_no_rows_and_an_error_message(): void {
		$_GET['domain'] = '-bad-.com';
		$this->assertSame( array(), DomainQuery::run( array(), (object) array( 'object_type' => DomainQuery::TYPE ) ) );
		$this->assertNotSame( '', DomainQuery::value( 'bds_error' ) );
		$this->assertSame( '-bad-.com', DomainQuery::value( 'bds_search_term' ) );
	}

	public function test_the_visitor_rate_limit_applies(): void {
		$name = $this->name();
		$fake = $this->registrar( array( array( 'domain' => $name . '.com', 'status' => 'free', 'is_premium' => false ) ) );
		add_filter( 'beltoft_services_domain_search_limit', static fn() => 1 );
		$_GET['domain'] = $name . '.com';
		$query          = (object) array( 'object_type' => DomainQuery::TYPE );
		$first = DomainQuery::run( array(), $query );
		$this->assertSame( $name . '.com', $first[0]['domain'], 'The first search is allowed.' );
		DomainQuery::reset();
		$this->assertSame( array(), DomainQuery::run( array(), $query ) );
		$this->assertStringContainsString( 'Too many searches', DomainQuery::value( 'bds_error' ) );
		$this->assertCount( 1, array_filter( $fake->calls(), static fn( $c ) => '/domains/check' === $c['path'] ) );
	}

	public function test_the_site_wide_cap_applies(): void {
		$name = $this->name();
		$fake = $this->registrar( array( array( 'domain' => $name . '.com', 'status' => 'free', 'is_premium' => false ) ) );
		add_filter( 'beltoft_services_domain_lookup_limit', static fn() => 1 ); // 80% of 1 rounds up to 1 lookup.
		\BeltoftServices\RateLimit::exceeded( 0, 'domain-registrar-lookups', 1, HOUR_IN_SECONDS );
		\BeltoftServices\RateLimit::exceeded( 0, 'domain-registrar-lookups', 1, HOUR_IN_SECONDS );
		$_GET['domain'] = $name . '.com';
		$this->assertSame( array(), DomainQuery::run( array(), (object) array( 'object_type' => DomainQuery::TYPE ) ) );
		$this->assertStringContainsString( 'busy', DomainQuery::value( 'bds_error' ) );
		$this->assertCount( 0, array_filter( $fake->calls(), static fn( $c ) => '/domains/check' === $c['path'] ) );
	}

	public function test_unsold_extensions_are_left_out_unless_typed(): void {
		$results = array(
			array( 'domain' => 'a.com', 'status' => 'not_sold', 'price' => null ),
			array( 'domain' => 'a.net', 'status' => 'not_sold', 'price' => null ),
			array( 'domain' => 'a.org', 'status' => 'premium', 'price' => null ),
		);
		$this->assertSame( array( 'a.com', 'a.org' ), array_column( DomainQuery::rows( $results, 'a.com' ), 'domain' ) );
		$this->assertSame( array( 'a.org' ), array_column( DomainQuery::rows( $results, 'a' ), 'domain' ) );
		$premium = DomainQuery::rows( $results, 'a' )[0];
		$this->assertSame( 'unknown', $premium['status'] );
		$this->assertSame( '', $premium['price'] );
	}

	public function test_the_parameter_can_be_changed_with_a_filter(): void {
		add_filter( 'beltoft_services_domain_search_param', static fn() => 'q' );
		$_GET['q'] = 'x.com';
		$this->assertSame( 'q', DomainQuery::param() );
		$this->assertSame( 'x.com', DomainQuery::term() );
		unset( $_GET['q'] );
	}

	private function die_with_exception(): void {
		add_filter( 'wp_die_handler', static fn() => static function ( $message, $title = '', $args = array() ) {
			throw new \RuntimeException( wp_strip_all_tags( (string) $message ) . '|' . (int) ( $args['response'] ?? 0 ) );
		} );
	}

	public function test_the_add_link_needs_its_nonce(): void {
		$this->die_with_exception();
		$_GET = array( 'domain' => 'example.com', '_wpnonce' => 'nope' );
		try {
			DomainQuery::handle_add();
			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringEndsWith( '|403', $e->getMessage() );
		}
		// A nonce made for another domain does not work either.
		$_GET = array( 'domain' => 'example.com', '_wpnonce' => wp_create_nonce( DomainQuery::ACTION . '_other.com' ) );
		$this->expectException( \RuntimeException::class );
		DomainQuery::handle_add();
	}

	public function test_a_name_that_is_not_free_is_refused_and_nothing_is_added(): void {
		$name = $this->name() . '.com';
		$this->registrar( array( array( 'domain' => $name, 'status' => 'taken', 'is_premium' => false ) ) );
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		$this->service_product( 'domain' );
		wc_load_cart();
		WC()->cart->empty_cart();
		$this->die_with_exception();
		$_GET = array( 'domain' => $name, '_wpnonce' => wp_create_nonce( DomainQuery::ACTION . '_' . $name ) );
		try {
			DomainQuery::handle_add();
			$this->fail( 'A taken name must be refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringEndsWith( '|409', $e->getMessage() );
		}
		$this->assertCount( 0, WC()->cart->get_cart() );
		$_GET = array();
	}

	public function test_a_free_name_is_added_through_the_shared_cart_code(): void {
		$name = $this->name() . '.com';
		$this->registrar( array( array( 'domain' => $name, 'status' => 'free', 'is_premium' => false ) ) );
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		$this->service_product( 'domain' );
		wc_load_cart();
		WC()->cart->empty_cart();
		$result = \BeltoftServices\Domains\Cart::add_line( $name, 'register' );
		$this->assertTrue( $result['added'] );
		$this->assertSame( wc_get_checkout_url(), $result['checkout_url'] );
		$this->assertCount( 1, WC()->cart->get_cart() );
		WC()->cart->empty_cart();
	}
}
