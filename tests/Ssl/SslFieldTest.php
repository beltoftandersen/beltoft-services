<?php
/** The checkout choices of a certificate, and the names and keys behind them. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Resource;
use BeltoftServices\Ssl\Csr;
use BeltoftServices\Ssl\Keys;
use BeltoftServices\Ssl\SslField;
use BeltoftServices\Ssl\SslNames;

require_once __DIR__ . '/SslTestCase.php';

class SslFieldTest extends SslTestCase {

	public function tear_down(): void {
		unset( $_POST[ SslField::POST_KEY ] );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		parent::tear_down();
	}

	/** @param array<string, string> $input */
	private function resolve( array $input, int $user = 0, string $level = 'dv', bool $wildcard = false, string $company = '' ) {
		return SslField::resolve( $input + array( 'domain' => 'example.com', 'validation' => 'dns', 'approver' => '', 'key_mode' => 'generate', 'csr' => '' ), $user, $level, $wildcard, $company );
	}

	public function test_a_valid_choice_is_returned_for_the_order_line(): void {
		$found = $this->resolve( array( 'domain' => ' WWW.Example.COM ' ) );
		$this->assertSame( array( 'domain' => 'www.example.com', 'name' => 'www.example.com', 'validation' => 'dns', 'approver' => '', 'key_mode' => 'generate', 'csr' => '' ), $found );
	}

	public function test_a_wildcard_product_orders_the_star_name_and_a_single_product_refuses_a_star(): void {
		$found = $this->resolve( array( 'domain' => 'example.com' ), 0, 'dv', true );
		$this->assertSame( '*.example.com', $found['name'] );
		$this->assertSame( 'example.com', $found['domain'] );
		$this->assertSame( '*.example.com', $this->resolve( array( 'domain' => '*.example.com' ), 0, 'dv', true )['name'] );
		$this->assertWPError( $this->resolve( array( 'domain' => '*.example.com' ) ) );
	}

	public function test_bad_names_are_refused(): void {
		foreach ( array( '', 'localhost', 'no_underscore.com', 'a b.com', 'example', 'https://example.com' ) as $bad ) {
			$this->assertWPError( $this->resolve( array( 'domain' => $bad ) ), $bad );
		}
	}

	public function test_wildcards_cannot_use_file_validation_and_email_needs_an_approved_address(): void {
		$this->assertWPError( $this->resolve( array( 'validation' => 'file' ), 0, 'dv', true ) );
		$this->assertIsArray( $this->resolve( array( 'validation' => 'file' ) ) );
		$this->assertWPError( $this->resolve( array( 'validation' => 'email', 'approver' => 'boss' ) ) );
		$this->assertSame( 'admin@example.com', $this->resolve( array( 'validation' => 'email', 'approver' => 'admin' ) )['approver'] );
		$this->assertWPError( $this->resolve( array( 'validation' => 'magic' ) ) );
	}

	public function test_automatic_validation_needs_a_domain_on_our_name_servers(): void {
		$user = (int) self::factory()->user->create();
		$this->assertWPError( $this->resolve( array( 'validation' => 'auto' ), $user ) );
		$this->our_zone( $user, 'example.com' );
		$this->assertSame( 'auto', $this->resolve( array( 'validation' => 'auto', 'domain' => 'shop.example.com' ), $user )['validation'], 'A name below a zone of ours.' );
		$other = (int) self::factory()->user->create();
		$this->assertWPError( $this->resolve( array( 'validation' => 'auto' ), $other ), 'Another customer has no such zone.' );
		$this->assertSame( 'example.com', SslNames::zone_for( 'shop.example.com', $user ) );
		$this->assertNull( SslNames::zone_for( 'example.org', $user ) );
	}

	public function test_an_own_request_is_checked_against_name_strength_and_level(): void {
		$good = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) )['csr'];
		$this->assertSame( trim( $good ), $this->resolve( array( 'key_mode' => 'own', 'csr' => $good ) )['csr'] );
		$this->assertWPError( $this->resolve( array( 'key_mode' => 'own', 'csr' => 'garbage' ) ) );
		$no_country = $this->weak_csr( 2048 );
		$this->assertSame( 'ssl_csr_country', $this->resolve( array( 'key_mode' => 'own', 'csr' => $no_country ) )->get_error_code(), 'A request without a country.' );
		$this->assertWPError( $this->resolve( array( 'key_mode' => 'own', 'csr' => Csr::generate( 'other.com', array( 'countryName' => 'DK' ) )['csr'] ) ) );
		$this->assertWPError( $this->resolve( array( 'key_mode' => 'own', 'csr' => $good ) , 0, 'ov', false, 'Acme' ), 'An OV request needs the organization.' );
		$weak = $this->weak_csr( 1024 );
		$this->assertWPError( $this->resolve( array( 'key_mode' => 'own', 'csr' => $weak ) ), 'A 1024 bit key is too weak.' );
		$this->assertWPError( $this->resolve( array( 'key_mode' => 'own', 'csr' => $good . str_repeat( 'A', 9000 ) ) ) );
	}

	private function weak_csr( int $bits = 1024 ): string {
		$conf = tempnam( sys_get_temp_dir(), 'cnf' );
		file_put_contents( $conf, "[req]\ndistinguished_name = dn\n[dn]\n" );
		$key = openssl_pkey_new( array( 'private_key_bits' => $bits, 'config' => $conf ) );
		$csr = openssl_csr_new( array( 'commonName' => 'example.com' ), $key, array( 'config' => $conf, 'digest_alg' => 'sha256' ) );
		openssl_csr_export( $csr, $out );
		unlink( $conf );
		return $out;
	}

	public function test_an_organization_certificate_needs_a_company(): void {
		$this->assertWPError( $this->resolve( array(), 0, 'ov' ) );
		$this->assertIsArray( $this->resolve( array(), 0, 'ov', false, 'Acme ApS' ) );
		$this->assertIsArray( $this->resolve( array(), 0, 'dv' ) );
	}

	public function test_without_the_encryption_key_only_an_own_request_is_offered(): void {
		remove_all_filters( 'beltoft_services_key' );
		if ( \BeltoftServices\Crypto::available() ) {
			$this->markTestSkipped( 'A key is set in this environment.' );
		}
		$this->assertWPError( $this->resolve( array( 'key_mode' => 'generate' ) ) );
	}

	public function test_nothing_is_ordered_while_selling_is_switched_off(): void {
		$this->settings( array( 'ssl_enabled' => 0 ) );
		$this->assertWPError( $this->resolve( array() ) );
	}

	public function test_a_customer_cannot_order_a_second_live_certificate_for_a_name_or_flood_orders(): void {
		$r = $this->built();
		$user = (int) $r->license()['user_id'];
		$this->assertWPError( $this->resolve( array(), $user ) );
		$this->assertIsArray( $this->resolve( array( 'domain' => 'other.example.com' ), $user ) );
		$this->assertNull( SslNames::refusal( $user, 'other.example.com' ) );
		$this->assertNull( SslNames::refusal( $user, 'example.com', $r->id() ), 'The service itself is left out.' );
		$this->assertNull( SslNames::refusal( 0, 'example.com' ) );
	}

	public function test_the_block_checkout_is_refused_for_a_certificate_cart(): void {
		$order = new \WC_Order();
		$req = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		WC()->cart->empty_cart();
		SslField::block_store_api( $order, $req );
		$product = $this->service_product( 'ssl', array( 'ssl_product' => 41, 'ssl_level' => 'dv' ), array( 'slug' => 'ssl-cart-' . wp_generate_password( 4, false ) ) );
		if ( ! WC()->cart->add_to_cart( $product->get_id() ) ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		SslField::block_store_api( $order, $req );
	}

	public function test_the_checkout_validates_a_cart_line_and_saves_it_on_the_order_line(): void {
		$product = $this->service_product( 'ssl', array( 'ssl_product' => 41, 'ssl_level' => 'dv' ), array( 'slug' => 'ssl-cart2-' . wp_generate_password( 4, false ) ) );
		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart( $product->get_id() );
		if ( ! $key ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}
		do_action( 'woocommerce_checkout_process' );
		$_POST[ SslField::POST_KEY ] = array( $key => array( 'domain' => '', 'validation' => 'dns', 'key_mode' => 'generate' ) );
		$errors = new \WP_Error();
		SslField::validate( array(), $errors );
		$this->assertNotEmpty( $errors->get_error_messages() );
		$_POST[ SslField::POST_KEY ] = array( $key => array( 'domain' => 'example.com', 'validation' => 'dns', 'key_mode' => 'generate' ) );
		$errors = new \WP_Error();
		SslField::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_messages() );
		$item = new \WC_Order_Item_Product();
		SslField::save_on_item( $item, (string) $key, WC()->cart->get_cart()[ $key ], new \WC_Order() );
		$saved = $item->get_meta( SslField::ITEM_META );
		$this->assertSame( 'example.com', $saved['name'] );
		$this->assertArrayNotHasKey( 'key', $saved );
		ob_start();
		SslField::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . SslField::POST_KEY . '[' . $key . '][domain]"', $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
	}

	public function test_the_choices_reach_the_resource_through_the_initial_meta(): void {
		$user = (int) self::factory()->user->create();
		$product = $this->service_product( 'ssl', array( 'ssl_product' => 41 ), array( 'slug' => 'ssl-meta-' . wp_generate_password( 4, false ) ) );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item = new \WC_Order_Item_Product();
		$item->set_props( array( 'product_id' => $product->get_id(), 'quantity' => 1, 'total' => 19 ) );
		$item->add_meta_data( SslField::ITEM_META, array( 'domain' => 'example.com', 'name' => 'example.com', 'validation' => 'email', 'approver' => 'admin@example.com', 'key_mode' => 'own', 'csr' => 'CSR TEXT' ) );
		$order->add_item( $item );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$meta = SslField::initial_meta( array(), $license, new \BeltoftServices\Ssl\SslDriver() );
		$this->assertSame( 'example.com', $meta['ssl_name'] );
		$this->assertSame( 'email', $meta['ssl_validation'] );
		$this->assertSame( 'admin@example.com', $meta['ssl_approver'] );
		$this->assertSame( 'CSR TEXT', $meta['ssl_csr'] );
		$this->assertSame( (int) $item->get_id(), $meta['order_item_id'] );
		$this->assertSame( array( 'x' => 1 ), SslField::initial_meta( array( 'x' => 1 ), $license, \BeltoftServices\Drivers\Registry::get( 'mail' ) ), 'Other drivers are untouched.' );
	}

	public function test_the_driver_is_registered_and_its_kind_is_known(): void {
		$this->assertInstanceOf( \BeltoftServices\Ssl\SslDriver::class, \BeltoftServices\Drivers\Registry::get( 'ssl' ) );
		$this->assertContains( 'ssl', \BeltoftServices\Drivers\Registry::kinds() );
		$this->assertInstanceOf( Resource::class, $this->ssl_resource() );
		$this->assertNull( Keys::existing( $this->ssl_resource() ), 'A new service holds no key.' );
	}
}
