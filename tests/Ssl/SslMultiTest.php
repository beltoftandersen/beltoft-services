<?php
/** Website certificates of any registrar product, and multi domain certificates. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftLicenses\Licenses;
use BeltoftServices\Cli\SslCommand;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\Csr;
use BeltoftServices\Ssl\Operations;
use BeltoftServices\Ssl\SslCatalog;
use BeltoftServices\Ssl\SslDriver;
use BeltoftServices\Ssl\SslField;
use BeltoftServices\Ssl\SslGuard;
use BeltoftServices\Ssl\SslRenewals;
use BeltoftServices\Ssl\Validation;

require_once __DIR__ . '/SslTestCase.php';

class SslMultiTest extends SslTestCase {

	private const NAMES = array( 'example.com', 'www.example.com', 'shop.example.com' );

	/** Meta of a multi domain service. */
	private function multi_meta( array $methods = array( 'dns', 'dns', 'dns' ) ): array {
		$names = array();

		foreach ( self::NAMES as $i => $n ) {
			$names[] = array( 'name' => $n, 'validation' => $methods[ $i ] );
		}

		return array( 'ssl_name' => 'example.com', 'ssl_domain' => 'example.com', 'ssl_names' => $names, 'ssl_validation' => $methods[0] );
	}

	/** Own CSR for the names. */
	private function own_csr( array $names, array $dn = array() ): string {
		return Csr::generate( $names[0], array( 'countryName' => 'DK' ) + $dn, $names )['csr'];
	}

	public function test_the_catalog_tells_website_products_from_the_rest_and_how_many_names_a_price_covers(): void {
		$this->assertSame( array( 41, 42, 20, 24, 31, 40, 33, 28 ), array_keys( SslCatalog::sellable() ) );
		$this->assertFalse( SslCatalog::is_website( 58 ) );
		$this->assertFalse( SslCatalog::is_website( 60 ) );
		$this->assertTrue( SslCatalog::is_multi( 40 ) );
		$this->assertFalse( SslCatalog::is_multi( 41 ) );
		$this->assertSame( 3, SslCatalog::included( 40 ) );
		$this->assertSame( 3, SslCatalog::included( 28 ) );
		$this->assertSame( 1, SslCatalog::included( 42 ) );
		$this->assertSame( 0, SslCatalog::included( 58 ) );
		$this->assertSame( 9.64, SslCatalog::get( 40 )['extra_domain'] );
		$this->assertSame( 'ev', SslCatalog::level( 33 ) );
		$this->assertSame( 'ov', SslCatalog::level( 28 ) );
	}

	public function test_a_variation_for_another_registrar_product_is_added_with_its_plan_fields(): void {
		$this->assertWPError( SslCommand::add_variation( 40, '49' ), 'No certificate product yet.' );
		SslCommand::create( array( 'dv' => '19' ) );

		$this->assertSame( 'unknown', SslCommand::check_variation( 999, '49' )->get_error_code() );
		$this->assertSame( 'not_website', SslCommand::check_variation( 58, '119' )->get_error_code() );
		$this->assertIsArray( SslCommand::check_variation( 60, '29' ), 'S/MIME Personal can be sold.' );
		$this->assertSame( 'below_cost', SslCommand::check_variation( 60, '10' )->get_error_code() );
		$this->assertSame( 'below_cost', SslCommand::check_variation( 40, '19.28' )->get_error_code() );
		$this->assertSame( 'price', SslCommand::check_variation( 40, 'free' )->get_error_code() );
		$this->assertSame( 'exists', SslCommand::check_variation( 41, '19' )->get_error_code() );

		$vid = SslCommand::add_variation( 40, '49' );
		$this->assertIsInt( $vid );
		$parent = SslCommand::existing();
		$plan   = Product::plan( $parent, $vid );
		$this->assertSame( 40, (int) $plan['ssl_product'] );
		$this->assertSame( 'dv', $plan['ssl_level'] );
		$this->assertSame( 3, (int) $plan['ssl_domains'] );
		$this->assertFalse( (bool) $plan['ssl_wildcard'] );
		$this->assertSame( '49', (string) wc_get_product( $vid )->get_regular_price() );
		$this->assertSame( 'exists', SslCommand::check_variation( 40, '49' )->get_error_code() );

		// A second one, and the four launch variations stay as they were.
		$this->assertIsInt( SslCommand::add_variation( 31, '19' ) );
		$this->assertCount( 6, wc_get_product( $parent )->get_children() );
		$seen = array();

		foreach ( wc_get_product( $parent )->get_children() as $child ) {
			$seen[] = (int) Product::plan( $parent, (int) $child )['ssl_product'];
		}

		sort( $seen );
		$this->assertSame( array( 20, 24, 31, 40, 41, 42 ), $seen );
		$this->assertSame( 1, (int) Product::plan( $parent, (int) wc_get_product( $parent )->get_children()[0] )['ssl_domains'] );
	}

	public function test_the_cost_guard_uses_the_registrar_product_of_the_variation(): void {
		$r = $this->ssl_resource( array( 'product' => 40, 'total' => 15.0, 'meta' => $this->multi_meta() ) );
		$this->assertSame( SslGuard::MESSAGE, SslGuard::problem( 40, SslGuard::item_of_resource( $r ) ), '15 is above the cost of product 41 (8.68) but below that of 40 (19.29).' );
		$this->assertNull( SslGuard::problem( 41, SslGuard::item_of_resource( $r ) ) );
		$this->assertTrue( ( new SslDriver() )->provision( $r )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_the_checkout_takes_up_to_the_included_names_each_with_a_method(): void {
		$rows = array( array( 'domain' => 'example.com', 'validation' => 'dns' ), array( 'domain' => 'www.example.com', 'validation' => 'file' ), array( 'domain' => '', 'validation' => 'dns' ) );
		$ok   = SslField::resolve( array( 'names' => $rows, 'key_mode' => 'generate' ), 0, 'dv', false, '', 3 );
		$this->assertIsArray( $ok );
		$this->assertSame( 'example.com', $ok['name'] );
		$this->assertSame( array( array( 'name' => 'example.com', 'base' => 'example.com', 'validation' => 'dns' ), array( 'name' => 'www.example.com', 'base' => 'www.example.com', 'validation' => 'file' ) ), $ok['names'] );

		$four = array_merge( $rows, array( array( 'domain' => 'a.example.com', 'validation' => 'dns' ), array( 'domain' => 'b.example.com', 'validation' => 'dns' ) ) );
		$this->assertSame( 'ssl_too_many', SslField::resolve( array( 'names' => $four ), 0, 'dv', false, '', 3 )->get_error_code() );
		$this->assertSame( 'ssl_domain', SslField::resolve( array( 'names' => array( array( 'domain' => '*.example.com', 'validation' => 'dns' ) ) ), 0, 'dv', false, '', 3 )->get_error_code() );
		$this->assertSame( 'ssl_domain', SslField::resolve( array( 'names' => array( array( 'domain' => '', 'validation' => 'dns' ) ) ), 0, 'dv', false, '', 3 )->get_error_code() );
		$this->assertSame( 'ssl_validation', SslField::resolve( array( 'names' => array( array( 'domain' => 'example.com', 'validation' => 'email' ) ) ), 0, 'dv', false, '', 3 )->get_error_code() );
		$this->assertSame( 'ssl_duplicate', SslField::resolve( array( 'names' => array( array( 'domain' => 'example.com', 'validation' => 'dns' ), array( 'domain' => 'EXAMPLE.com', 'validation' => 'dns' ) ) ), 0, 'dv', false, '', 3 )->get_error_code() );
		$this->assertSame( 'ssl_auto', SslField::resolve( array( 'names' => array( array( 'domain' => 'example.com', 'validation' => 'auto' ) ) ), 0, 'dv', false, '', 3 )->get_error_code(), 'Automatic needs a zone of ours.' );
	}

	public function test_the_first_name_row_is_required_and_holds_nothing_else(): void {
		$product = $this->service_product( 'ssl', array( 'ssl_product' => 40, 'ssl_level' => 'dv', 'ssl_domains' => 3 ), array( 'slug' => 'ssl-multi-' . wp_generate_password( 4, false ) ) );
		WC()->cart->empty_cart();
		if ( ! WC()->cart->add_to_cart( $product->get_id() ) ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		ob_start();
		SslField::render();
		$html = (string) ob_get_clean();
		WC()->cart->empty_cart();

		// A select in the same row has a value, which made an empty required name pass the checkout step.
		$this->assertMatchesRegularExpression( '#<p class="form-row form-row-wide validate-required"><label for="[^"]*_name_0">Name 1&nbsp;<abbr class="required"#', $html );
		$this->assertSame( 1, preg_match( '#<p class="form-row form-row-wide validate-required"><label for="[^"]*_name_0">.*?</p>#s', $html, $row ) );
		$this->assertStringNotContainsString( '<select', $row[0] );
		$this->assertStringContainsString( '[names][0][validation]', $html );
	}

	public function test_an_own_request_must_stay_within_the_ordered_names(): void {
		$names = array( 'example.com', 'www.example.com' );
		$rows  = array( array( 'domain' => 'example.com', 'validation' => 'dns' ), array( 'domain' => 'www.example.com', 'validation' => 'dns' ) );
		$input = array( 'names' => $rows, 'key_mode' => 'own', 'csr' => $this->own_csr( $names ) );
		$this->assertIsArray( SslField::resolve( $input, 0, 'dv', false, '', 3 ) );

		$input['csr'] = $this->own_csr( array( 'example.com', 'www.example.com', 'evil.example.net' ) );
		$this->assertSame( 'ssl_csr_san', SslField::resolve( $input, 0, 'dv', false, '', 3 )->get_error_code() );
		$this->assertSame( array( 'example.com', 'www.example.com' ), Csr::sans( $this->own_csr( $names ) ) );
	}

	public function test_a_multi_domain_build_orders_every_name_with_its_method_and_delivers(): void {
		$r      = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'meta' => $this->multi_meta( array( 'dns', 'file', 'dns' ) ) ) );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertTrue( $r->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) ) );

		$post = $this->calls( 'POST', '#^/ssl/orders$#' );
		$this->assertCount( 1, $post );
		$body = $post[0]['body'];
		$this->assertSame( 3, $body['domain_amount'] );
		$this->assertSame( 40, $body['product_id'] );
		$this->assertSame(
			array( array( 'host_name' => 'example.com', 'method' => 'dns' ), array( 'host_name' => 'www.example.com', 'method' => 'https' ), array( 'host_name' => 'shop.example.com', 'method' => 'dns' ) ),
			$body['domain_validation_methods']
		);
		$this->assertSame( self::NAMES, Csr::sans( $body['csr'] ), 'The generated request names every name.' );

		$fresh = Resource::find( $r->id() );
		$rows  = $fresh->get( 'ssl_validation_rows' );
		$this->assertSame( self::NAMES, array_column( $rows, 'domain' ) );
		$this->assertSame( array( 'CNAME', 'file', 'CNAME' ), array_column( $rows, 'type' ) );
		$this->assertNotEmpty( $fresh->get( 'ssl_cert' ) );
		$this->assertNotSame( '', (string) $fresh->get( 'ssl_action_sent' ), 'The customer was told what to add.' );
	}

	public function test_a_name_validated_automatically_is_published_in_its_zone_and_the_others_are_left_to_the_customer(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$meta = $this->multi_meta( array( 'auto', 'dns', 'auto' ) );
		$r    = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'user' => $user, 'meta' => $meta ) );
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$rows  = $fresh->get( 'ssl_validation_rows' );
		$this->assertSame( array( 'published', 'manual', 'published' ), array_column( $rows, 'status' ) );
		$this->assertFalse( (bool) $fresh->get( 'ssl_validation_auto' ) );
		$this->assertSame( array( 'auto', 'dns', 'auto' ), array_column( $fresh->get( 'ssl_names' ), 'validation' ) );
		$zone  = \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' );
		$names = array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ) . '|' . $s['type'], (array) $zone['rrsets'] );
		$this->assertContains( $rows[0]['name'] . '|CNAME', $names );
		$this->assertContains( $rows[2]['name'] . '|CNAME', $names );
		$this->assertNotContains( $rows[1]['name'] . '|CNAME', $names );

		( new SslDriver() )->delete( Resource::find( $r->id() ) );
		$after = array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ) . '|' . $s['type'], (array) \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' )['rrsets'] );
		$this->assertNotContains( $rows[0]['name'] . '|CNAME', $after, 'Our records go with the service.' );
	}

	public function test_more_names_than_the_product_includes_fail_before_anything_is_ordered(): void {
		$meta   = $this->multi_meta();
		$meta['ssl_names'][] = array( 'name' => 'four.example.com', 'validation' => 'dns' );
		$r      = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'meta' => $meta ) );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );

		$single = $this->ssl_resource( array( 'product' => 41, 'meta' => $this->multi_meta() ) );
		$this->assertTrue( ( new SslDriver() )->provision( $single )->is_failed(), 'Several names on a single name product.' );
	}

	public function test_a_reissue_keeps_every_name(): void {
		$r = $this->built( array( 'product' => 40, 'total' => 49.0, 'meta' => $this->multi_meta() ) );
		$this->assertTrue( Operations::reissue( $r, array() )->is_done() );
		$call = $this->calls( 'POST', '#/reissue$#' )[0]['body'];
		$this->assertSame( self::NAMES, array_column( $call['domain_validation_methods'], 'host_name' ) );
		$this->assertSame( self::NAMES, Csr::sans( $call['csr'] ) );

		// A customer's own request for fewer or other names is refused.
		$this->now += 120;
		Operations::refresh( Resource::find( $r->id() ) );
		$fresh = Resource::find( $r->id() );
		$bad   = Operations::reissue( $fresh, array( 'csr' => $this->own_csr( array( 'example.com', 'other.example.net' ) ) ) );
		$this->assertTrue( $bad->is_failed() );
	}

	public function test_changing_the_method_of_one_name_leaves_the_others(): void {
		$r = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'meta' => $this->multi_meta() ) );
		( new SslDriver() )->provision( $r );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'REQ', $r->get( 'ssl_status' ) );
		$this->assertTrue( Operations::change_validation( $r, array( 'method' => 'file', 'name' => 'shop.example.com' ) )->is_done() );
		$this->assertSame( array( 'dns', 'dns', 'file' ), array_column( Resource::find( $r->id() )->get( 'ssl_names' ), 'validation' ) );
		$this->assertTrue( Operations::change_validation( Resource::find( $r->id() ), array( 'method' => 'email', 'approver' => 'admin' ) )->is_failed(), 'No email for several names.' );
		$this->assertTrue( Operations::change_validation( Resource::find( $r->id() ), array( 'method' => 'dns', 'name' => 'nope.example.com' ) )->is_failed() );
		$put = $this->calls( 'PUT', '#^/ssl/orders/\d+$#' );
		$this->assertSame( array( 'dns', 'dns', 'https' ), array_column( end( $put )['body']['domain_validation_methods'], 'method' ) );
	}

	public function test_a_renewal_of_a_multi_domain_certificate_changes_no_names(): void {
		remove_action( 'woocommerce_subscription_renewal_payment_complete', array( SslRenewals::class, 'on_paid' ), 20 );
		$r       = $this->built( array( 'product' => 40, 'total' => 49.0, 'meta' => $this->multi_meta() ) );
		$product = wc_get_product( (int) $r->license()['product_id'] );
		$sub     = wcs_create_subscription( array( 'customer_id' => (int) $r->license()['user_id'], 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$sub->add_product( $product, 1 );
		$sub->save();
		$this->assertTrue( Licenses::update( (int) $r->license()['id'], array( 'subscription_id' => $sub->get_id() ) ) );
		$order = wcs_create_renewal_order( $sub );
		$order->set_currency( 'EUR' );
		$order->remove_order_items( 'line_item' );
		$order->add_product( $product, 1, array( 'subtotal' => 49, 'total' => 49 ) );
		$order->set_status( 'processing' );
		$order->save();
		$this->now = (int) strtotime( (string) $r->get( 'ssl_order_expires' ) . ' UTC' ) - 20 * DAY_IN_SECONDS;

		$result = SslRenewals::renew( Resource::find( $r->id() ), (string) $order->get_id() );
		$this->assertTrue( $result->is_done(), $result->error() );
		$renew = $this->calls( 'POST', '#/renew$#' );
		$this->assertCount( 1, $renew );
		$this->assertSame( array( 'id' ), array_keys( $renew[0]['body'] ) );
		$this->assertSame( self::NAMES, array_column( Resource::find( $r->id() )->get( 'ssl_names' ), 'name' ) );
	}

	public function test_the_account_page_lists_the_names_and_each_names_validation(): void {
		$r = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'meta' => $this->multi_meta( array( 'dns', 'file', 'dns' ) ) ) );
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		ob_start();
		( new SslDriver() )->render_account( $fresh );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'example.com, www.example.com, shop.example.com', $html );
		$this->assertStringContainsString( 'colgroup', $html );
		$this->assertStringContainsString( 'Validation', $html );
		$this->assertStringNotContainsString( 'approver', $html );
		$this->assertTrue( Validation::method_of( 'file' ) === 'https' );
	}

	public function test_a_record_we_wrote_is_still_ours_after_a_validation_change_and_a_reissue(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto' ) ) );
		( new SslDriver() )->provision( $r );
		$r    = Resource::find( $r->id() );
		$row  = $r->get( 'ssl_validation_rows' )[0];
		$has  = static fn( string $name ) => in_array( $name . '|CNAME', array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ) . '|' . $s['type'], (array) \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' )['rrsets'] ), true );
		$this->assertTrue( $row['written'] );

		// The change resets the rows; the same record is published again and is still ours.
		$this->assertTrue( $this->apply( $r, Operations::change_validation( $r, array( 'method' => 'dns' ) ) ) instanceof Resource );
		$this->apply( Resource::find( $r->id() ), Operations::change_validation( Resource::find( $r->id() ), array( 'method' => 'auto' ) ) );
		$again = Resource::find( $r->id() )->get( 'ssl_validation_rows' )[0];
		$this->assertSame( 'published', $again['status'] );
		$this->assertTrue( $again['written'], 'Our own earlier record is not mistaken for one that was there before.' );

		( new SslDriver() )->delete( Resource::find( $r->id() ) );
		$this->assertFalse( $has( $row['name'] ), 'It goes with the service.' );
	}

	public function test_a_reissue_that_publishes_a_second_record_leaves_both_known_as_ours(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->built( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto' ) ) );
		$first = $r->get( 'ssl_validation_rows' )[0];
		$this->assertTrue( Operations::reissue( $r, array() )->is_done() );
		$this->now += 120;
		$fresh = Resource::find( $r->id() );
		$this->assertSame( array(), $fresh->get( 'ssl_validation_rows' ), 'The rows are reset by a reissue.' );
		Operations::refresh( $fresh );
		( new SslDriver() );
		$names = array_column( (array) Resource::find( $r->id() )->get( 'ssl_written' ), 'name' );
		$this->assertContains( $first['name'], $names, 'The first record stays known.' );
		( new SslDriver() )->delete( Resource::find( $r->id() ) );
		$zone = array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ), (array) \BeltoftServices\Domains\Clients::dns()->zone( 'example.com' )['rrsets'] );
		$this->assertNotContains( $first['name'], $zone );
	}

	public function test_records_written_before_a_temporary_failure_are_kept_as_ours(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$dns = new class( true ) extends \BeltoftServices\Domains\FakeDns {
			public int $reads = 0;
			public bool $fail = true;

			public function zone( string $name ): array|\WP_Error|null {
				++$this->reads;

				// Row one reads the zone twice (is it there, then the write); the second row's write read fails.
				return $this->fail && $this->reads >= 4 ? new \WP_Error( 'down', 'DNS is down.' ) : parent::zone( $name );
			}
		};
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$r      = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'user' => $user, 'meta' => $this->multi_meta( array( 'auto', 'auto', 'auto' ) ) ) );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertFalse( $result->is_done() );
		$this->assertFalse( $result->is_failed(), 'A temporary failure retries.' );
		$written = (array) Resource::find( $r->id() )->get( 'ssl_written' );
		$this->assertCount( 1, $written, 'The record written before the failure is known as ours.' );

		// Once DNS is back the build goes on (the order is already active at the authority), and delete removes the record we wrote.
		$dns->fail = false;
		$this->now += 120;
		$again = ( new SslDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertFalse( $again->is_failed(), $again->error() );
		$this->apply( Resource::find( $r->id() ), $again );
		$this->assertCount( 1, (array) Resource::find( $r->id() )->get( 'ssl_written' ) );
		$this->assertCount( 1, array_filter( (array) $dns->zone( 'example.com' )['rrsets'], static fn( $set ) => 'CNAME' === $set['type'] ) );
		( new SslDriver() )->delete( Resource::find( $r->id() ) );
		$left = array_filter( (array) $dns->zone( 'example.com' )['rrsets'], static fn( $set ) => 'CNAME' === $set['type'] );
		$this->assertSame( array(), $left );
		remove_all_filters( 'beltoft_services_domains_dns' );
	}

	public function test_a_one_name_list_on_a_service_takes_a_method_change(): void {
		$meta = array( 'ssl_names' => array( array( 'name' => 'example.com', 'validation' => 'auto' ) ), 'ssl_validation' => 'auto' );
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->ssl_resource( array( 'user' => $user, 'meta' => $meta ) );
		( new SslDriver() )->provision( $r );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'REQ', $r->get( 'ssl_status' ) );
		$this->assertTrue( Operations::change_validation( $r, array( 'method' => 'file' ) )->is_done() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'file', $fresh->get( 'ssl_names' )[0]['validation'] );
		$put = $this->calls( 'PUT', '#^/ssl/orders/\d+$#' );
		$this->assertSame( 'https', end( $put )['body']['domain_validation_methods'][0]['method'] );
	}

	public function test_the_automatic_fallback_to_dns_lands_in_the_name_list_too(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$made = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$name = '_' . substr( md5( $made['csr'] ), 0, 32 ) . '.example.com';
		\BeltoftServices\Domains\Clients::dns()->replace_rrsets( 'example.com', array( array( 'name' => $name . '.', 'type' => 'TXT', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '"other"', 'disabled' => false ) ) ) ) );
		$meta = array( 'ssl_names' => array( array( 'name' => 'example.com', 'validation' => 'auto' ) ), 'ssl_validation' => 'auto', 'ssl_key_mode' => 'own', 'ssl_csr' => $made['csr'] );
		$r    = $this->ssl_resource( array( 'user' => $user, 'meta' => $meta ) );
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'dns', $fresh->get( 'ssl_validation' ) );
		$this->assertSame( 'dns', $fresh->get( 'ssl_names' )[0]['validation'] );
	}

	public function test_a_multi_domain_order_with_fewer_names_than_included_says_the_included_amount(): void {
		$meta = $this->multi_meta();
		$meta['ssl_names'] = array_slice( $meta['ssl_names'], 0, 2 );
		$r = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'meta' => $meta ) );
		$this->assertTrue( $this->build( $r )->is_done() );
		$body = $this->calls( 'POST', '#^/ssl/orders$#' )[0]['body'];
		$this->assertSame( 3, $body['domain_amount'] );
		$this->assertCount( 2, $body['domain_validation_methods'] );
	}

	public function test_a_multi_domain_product_is_never_ordered_with_one_name(): void {
		$meta = $this->multi_meta();
		$meta['ssl_names'] = array_slice( $meta['ssl_names'], 0, 1 );
		$r = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'meta' => $meta ) );
		$this->assertTrue( ( new SslDriver() )->provision( $r )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
		$one = SslField::resolve( array( 'names' => array( array( 'domain' => 'example.com', 'validation' => 'dns' ) ) ), 0, 'dv', false, '', 3 );
		$this->assertSame( 'ssl_few', $one->get_error_code() );
	}

	public function test_trailing_dots_and_case_in_checkout_rows_are_cleaned(): void {
		$rows = array( array( 'domain' => 'Example.COM.', 'validation' => 'dns' ), array( 'domain' => 'www.example.com.', 'validation' => 'dns' ) );
		$ok   = SslField::resolve( array( 'names' => $rows ), 0, 'dv', false, '', 3 );
		$this->assertIsArray( $ok );
		$this->assertSame( array( 'example.com', 'www.example.com' ), array_column( $ok['names'], 'name' ) );
		$dup = SslField::resolve( array( 'names' => array( array( 'domain' => 'example.com', 'validation' => 'dns' ), array( 'domain' => 'example.com.', 'validation' => 'dns' ) ) ), 0, 'dv', false, '', 3 );
		$this->assertSame( 'ssl_duplicate', $dup->get_error_code() );
	}

	public function test_san_bytes_in_the_subject_are_not_read_as_names(): void {
		// The subject carries the bytes of the SAN identifier as text; there is no extension.
		$subject_trick = Csr::generate( "x\x55\x1d\x11y.example.com", array( 'countryName' => 'DK' ) );
		$this->assertSame( array(), Csr::sans( $subject_trick['csr'] ) );
		$this->assertSame( array( 'example.com', 'www.example.com' ), Csr::sans( $this->own_csr( array( 'example.com', 'www.example.com' ) ) ) );
		$this->assertSame( array( '#other' ), Csr::sans( "-----BEGIN CERTIFICATE REQUEST-----\nMAMCAQA=\n-----END CERTIFICATE REQUEST-----" ) );
	}

	public function test_adopting_an_order_for_a_multi_domain_certificate_needs_the_same_names(): void {
		$r = $this->ssl_resource( array( 'product' => 40, 'total' => 49.0, 'meta' => $this->multi_meta() ) );
		$make = function ( array $hosts ) {
			$csr = $this->own_csr( $hosts );
			$id  = $this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 40, 'period' => 1, 'csr' => $csr, 'organization_handle' => 'H1', 'domain_amount' => 3, 'domain_validation_methods' => array_map( static fn( $h ) => array( 'host_name' => $h, 'method' => 'dns' ), $hosts ) ) );

			return (int) $id['id'];
		};
		$wrong = $make( array( 'example.com', 'www.example.com', 'other.example.com' ) );
		$this->assertSame( 'mismatch', \BeltoftServices\Ssl\Provision::resolve_marker( $r, $wrong )->get_error_code() );
		$right = $make( self::NAMES );
		$this->assertTrue( \BeltoftServices\Ssl\Provision::resolve_marker( Resource::find( $r->id() ), $right ) );
	}
}
