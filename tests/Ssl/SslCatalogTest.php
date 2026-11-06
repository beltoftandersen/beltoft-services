<?php
/** SSL catalog, guard, settings and the fake order model. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Ssl\SslCatalog;
use BeltoftServices\Ssl\SslGuard;
use BeltoftServices\Ssl\SslSettings;
use BeltoftServices\Tests\TestCase;

class SslCatalogTest extends TestCase {
	private function product( int $id, float $cost, string $category = 'domain_validation', bool $wildcard = false, string $currency = 'EUR' ): array {
		return array(
			'id' => $id, 'name' => 'Product ' . $id, 'brand_name' => 'Brand', 'category' => $category, 'is_wildcard_supported' => $wildcard,
			'is_extended_validation_supported' => 'extended_validation' === $category, 'delivery_time' => '10m', 'free_refund_period' => '30d', 'max_domains' => 1,
			'prices' => array(
				array( 'period' => 1, 'price' => array( 'reseller' => array( 'price' => $cost, 'currency' => $currency ) ), 'extra_domain_price' => array( 'reseller' => array( 'price' => 0, 'currency' => $currency ) ) ),
				array( 'period' => 2, 'price' => array( 'reseller' => array( 'price' => 1.0, 'currency' => $currency ) ) ),
			),
		);
	}

	public function test_sync_stores_the_one_year_reseller_cost_and_the_level(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/ssl/products', array( 'results' => array( $this->product( 41, 8.68 ), $this->product( 42, 77.2, 'domain_validation', true ), $this->product( 20, 33.77, 'organization_validation' ), $this->product( 24, 86.85, 'extended_validation' ) ), 'total' => 4 ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 4, SslCatalog::sync() );
		$this->assertSame( 8.68, SslCatalog::cost( 41 ) );
		$this->assertTrue( SslCatalog::is_wildcard( 42 ) );
		$this->assertFalse( SslCatalog::is_wildcard( 41 ) );
		$this->assertSame( 'dv', SslCatalog::level( 41 ) );
		$this->assertSame( 'ov', SslCatalog::level( 20 ) );
		$this->assertSame( 'ev', SslCatalog::level( 24 ) );
		$this->assertNull( SslCatalog::cost( 999 ) );
		$call = $fake->calls()[0];
		$this->assertSame( 'GET', $call['method'] );
		$this->assertSame( '/ssl/products', $call['path'] );
		$this->assertSame( 'true', $call['query']['with_price'] );
	}

	public function test_the_validation_method_decides_the_level_when_the_category_is_a_shape(): void {
		$wildcard_ov = $this->product( 23, 241.28, 'wildcard', true );
		$wildcard_ov['validation_method'] = 'organization';
		$multi_ev = $this->product( 33, 193.02, 'multi_domain' );
		$multi_ev['validation_method'] = 'extended';
		$wildcard_dv = $this->product( 42, 77.2, 'wildcard', true );
		$wildcard_dv['validation_method'] = 'domain';
		$fake = new FakeClient();
		$fake->reply( 'GET', '/ssl/products', array( 'results' => array( $wildcard_ov, $multi_ev, $wildcard_dv ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 3, SslCatalog::sync() );
		$this->assertSame( 'ov', SslCatalog::level( 23 ) );
		$this->assertSame( 'ev', SslCatalog::level( 33 ) );
		$this->assertSame( 'dv', SslCatalog::level( 42 ) );
	}

	public function test_a_bad_list_keeps_the_old_table(): void {
		update_option( SslCatalog::OPTION, array( 41 => array( 'id' => 41, 'cost_1y' => 8.68 ) ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/ssl/products', array( 'results' => array( $this->product( 41, 5.0, 'domain_validation', false, 'USD' ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertWPError( SslCatalog::sync() );
		$this->assertSame( 8.68, SslCatalog::cost( 41 ) );
		$fake2 = new FakeClient();
		$fake2->reply( 'GET', '/ssl/products', new \WP_Error( 'transport', 'x' ) );
		remove_all_filters( 'beltoft_services_domains_client' );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake2 );
		$this->assertWPError( SslCatalog::sync() );
		$this->assertSame( 8.68, SslCatalog::cost( 41 ) );
	}

	public function test_a_product_without_a_one_year_price_is_skipped(): void {
		$entry = $this->product( 50, 1.0 );
		$entry['prices'] = array( array( 'period' => 2, 'price' => array( 'reseller' => array( 'price' => 3, 'currency' => 'EUR' ) ) ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/ssl/products', array( 'results' => array( $entry, $this->product( 41, 8.68 ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 1, SslCatalog::sync() );
		$this->assertNull( SslCatalog::cost( 50 ) );
	}

	private function item( float $total, string $currency = 'EUR' ): \WC_Order_Item_Product {
		$order = wc_create_order();
		$order->set_currency( $currency );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'SSL', 'quantity' => 1, 'subtotal' => $total, 'total' => $total ) );
		$order->add_item( $line );
		$order->save();
		return $order->get_items()[ array_key_first( $order->get_items() ) ];
	}

	public function test_the_guard_covers_cost_unknown_cost_currency_and_missing_line(): void {
		update_option( SslCatalog::OPTION, array( 41 => array( 'id' => 41, 'cost_1y' => 8.68 ) ) );
		$this->assertNull( SslGuard::problem( 41, $this->item( 19.0 ) ) );
		$this->assertNull( SslGuard::problem( 41, $this->item( 8.67 ) ), 'A cent of rounding is allowed.' );
		$this->assertSame( SslGuard::MESSAGE, SslGuard::problem( 41, $this->item( 8.0 ) ) );
		$this->assertSame( SslGuard::MESSAGE, SslGuard::problem( 41, $this->item( 0.0 ) ) );
		$this->assertStringContainsString( 'unknown', (string) SslGuard::problem( 42, $this->item( 99.0 ) ) );
		$this->assertStringContainsString( 'not in EUR', (string) SslGuard::problem( 41, $this->item( 500.0, 'DKK' ) ) );
		$this->assertStringContainsString( 'unavailable', (string) SslGuard::problem( 41, null ) );
	}

	public function test_settings_have_safe_bounds(): void {
		$this->assertFalse( SslSettings::enabled() );
		$this->settings( array( 'ssl_renew_days_before' => 90, 'ssl_wait_days' => 0, 'ssl_enabled' => 1 ) );
		$this->assertSame( 30, SslSettings::renew_days() );
		$this->assertSame( 1, SslSettings::wait_days() );
		$this->assertTrue( SslSettings::enabled() );
		$this->assertArrayHasKey( 'ssl', \BeltoftServices\Settings::tabs() );
	}

	public function test_the_fake_model_lists_products_in_the_shape_sync_reads(): void {
		$fake = new FakeClient( true );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 10, SslCatalog::sync() );
		$this->assertSame( 8.68, SslCatalog::cost( 41 ) );
		$this->assertSame( 'ev', SslCatalog::level( 24 ) );
	}

	/** A CSR for a name, with the private key kept out of the test output. */
	private function csr( string $cn, array $dn = array() ): string {
		$conf = tempnam( sys_get_temp_dir(), 'cnf' );
		file_put_contents( $conf, "[req]\ndistinguished_name = dn\n[dn]\n" );
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'config' => $conf ) );
		$csr = openssl_csr_new( array_merge( array( 'commonName' => $cn, 'countryName' => 'DK' ), $dn ), $key, array( 'digest_alg' => 'sha256', 'config' => $conf ) );
		openssl_csr_export( $csr, $out );
		unlink( $conf );
		return $out;
	}

	private function csr_without_country( string $cn ): string {
		$conf = tempnam( sys_get_temp_dir(), 'cnf' );
		file_put_contents( $conf, "[req]\ndistinguished_name = dn\n[dn]\n" );
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'config' => $conf ) );
		$csr = openssl_csr_new( array( 'commonName' => $cn ), $key, array( 'digest_alg' => 'sha256', 'config' => $conf ) );
		openssl_csr_export( $csr, $out );
		unlink( $conf );
		return $out;
	}

	private function body( string $csr, int $product = 41, string $method = 'dns', string $host = 'example.com' ): array {
		return array( 'product_id' => $product, 'period' => 1, 'csr' => $csr, 'organization_handle' => 'AA1-XX', 'technical_handle' => 'AA1-XX', 'domain_validation_methods' => array( array( 'host_name' => $host, 'method' => $method ) ), 'start_provision' => false );
	}

	public function test_the_fake_model_runs_an_order_from_pai_to_a_signed_certificate(): void {
		$now = 1800000000;
		add_filter( 'beltoft_services_ssl_fake_now', static function () use ( &$now ) { return $now; } );
		$fake = new FakeClient( true );
		$csr  = $this->csr( 'example.com' );
		$id   = (int) $fake->request( 'POST', '/ssl/orders', $this->body( $csr ) )['id'];
		$this->assertSame( 'PAI', $fake->request( 'GET', '/ssl/orders/' . $id )['status'] );
		$this->assertSame( array( 'id' => $id ), $fake->request( 'PUT', '/ssl/orders/' . $id, array( 'start_provision' => true ) ) );
		$order = $fake->request( 'GET', '/ssl/orders/' . $id );
		$this->assertSame( 'REQ', $order['status'] );
		$this->assertSame( '', $order['certificate'] );
		$this->assertStringContainsString( '.example.com', $order['additional_data'][0]['dns_record'] );
		$now += 61;
		$order = $fake->request( 'GET', '/ssl/orders/' . $id );
		$this->assertSame( 'ACT', $order['status'] );
		$parsed = openssl_x509_parse( $order['certificate'] );
		$this->assertSame( 'example.com', $parsed['subject']['CN'] );
		$this->assertEqualsWithDelta( 199 * DAY_IN_SECONDS, $parsed['validTo_time_t'] - $parsed['validFrom_time_t'], 5 );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $now ) ), $order['expiration_date'] );
	}

	public function test_the_fake_model_enforces_what_the_sandbox_showed(): void {
		$fake = new FakeClient( true );
		$err  = $fake->request( 'GET', '/ssl/orders/99999' );
		$this->assertSame( 'op_1094', $err->get_error_code() );
		$this->assertSame( 400, $err->get_error_data()['status'] );
		$no_country = (int) $fake->request( 'POST', '/ssl/orders', $this->body( $this->csr_without_country( 'example.com' ) ) )['id'];
		$refused = $fake->request( 'PUT', '/ssl/orders/' . $no_country, array( 'start_provision' => true ) );
		$this->assertSame( 'op_1099', $refused->get_error_code(), 'A request without a country is refused, DV included.' );
		$this->assertStringContainsString( 'invalid country code', $refused->get_error_data()['desc'] );
		$id = (int) $fake->request( 'POST', '/ssl/orders', $this->body( '' ) )['id'];
		$this->assertSame( 'op_1003', $fake->request( 'PUT', '/ssl/orders/' . $id, array( 'start_provision' => true ) )->get_error_code() );
		$ov = (int) $fake->request( 'POST', '/ssl/orders', $this->body( $this->csr( 'example.com' ), 20 ) )['id'];
		$this->assertSame( 'op_1050', $fake->request( 'PUT', '/ssl/orders/' . $ov, array( 'start_provision' => true ) )->get_error_code() );
		$full = $this->csr( 'example.com', array( 'organizationName' => 'Acme', 'stateOrProvinceName' => 'Midtjylland' ) );
		$ov2 = (int) $fake->request( 'POST', '/ssl/orders', $this->body( $full, 20 ) )['id'];
		$this->assertSame( array( 'id' => $ov2 ), $fake->request( 'PUT', '/ssl/orders/' . $ov2, array( 'start_provision' => true ) ) );
		$this->assertSame( 'op_25003', $fake->request( 'POST', '/ssl/orders/' . $ov2 . '/renew' )->get_error_code(), 'A requested order cannot be renewed.' );
		$this->assertSame( 'op_1000', $fake->request( 'POST', '/ssl/orders', array( 'product_id' => 1 ) + $this->body( 'x' ) )->get_error_code() );
	}

	public function test_the_fake_model_cancels_renews_reissues_and_replaces_an_old_certificate(): void {
		$now = 1800000000;
		add_filter( 'beltoft_services_ssl_fake_now', static function () use ( &$now ) { return $now; } );
		$fake = new FakeClient( true );
		$pai = (int) $fake->request( 'POST', '/ssl/orders', $this->body( $this->csr( 'a.example.com' ), 41, 'dns', 'a.example.com' ) )['id'];
		$fake->request( 'POST', '/ssl/orders/' . $pai . '/cancel' );
		$this->assertSame( 'FAI', $fake->request( 'GET', '/ssl/orders/' . $pai )['status'] );
		$id = (int) $fake->request( 'POST', '/ssl/orders', $this->body( $this->csr( 'example.com' ) ) )['id'];
		$fake->request( 'PUT', '/ssl/orders/' . $id, array( 'start_provision' => true ) );
		$now += 120;
		$first = $fake->request( 'GET', '/ssl/orders/' . $id );
		$serial = openssl_x509_parse( $first['certificate'] )['serialNumber'];
		$now += 200 * DAY_IN_SECONDS;
		$second = $fake->request( 'GET', '/ssl/orders/' . $id );
		$this->assertNotSame( $serial, openssl_x509_parse( $second['certificate'] )['serialNumber'], 'The registrar reissues the old certificate in place.' );
		$this->assertSame( $first['expiration_date'], $second['expiration_date'] );
		$this->assertSame( 'op_25003', $fake->request( 'POST', '/ssl/orders/' . $id . '/renew' )->get_error_code(), 'Too early to renew.' );
		$now += 150 * DAY_IN_SECONDS;
		$fake->request( 'POST', '/ssl/orders/' . $id . '/renew' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', strtotime( $first['expiration_date'] . ' UTC' ) ) ), $fake->request( 'GET', '/ssl/orders/' . $id )['expiration_date'] );
		$this->assertSame( 'op_1003', $fake->request( 'POST', '/ssl/orders/' . $id . '/reissue', array( 'csr' => '' ) )->get_error_code() );
		$fake->request( 'POST', '/ssl/orders/' . $id . '/cancel' );
		$this->assertSame( 'REJ', $fake->request( 'GET', '/ssl/orders/' . $id )['status'] );
		$found = $fake->request( 'GET', '/ssl/orders', array(), array( 'common_name_pattern' => 'example.com', 'contact_handle' => 'AA1-XX' ) );
		$this->assertSame( 1, $found['total'] );
	}
}
