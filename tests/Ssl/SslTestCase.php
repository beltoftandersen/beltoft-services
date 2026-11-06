<?php
/**
 * Shared setup for the SSL tests. Documentation names only: nothing here reaches a network, and the registrar is the fake model.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\SslCatalog;
use BeltoftServices\Ssl\SslDriver;
use BeltoftServices\Tests\Mail\MailTestCase;

require_once dirname( __DIR__ ) . '/Mail/MailTestCase.php';

abstract class SslTestCase extends MailTestCase {

	/** @var int The fake clock. */
	protected int $now = 1800000000;

	protected FakeClient $registrar;

	public function set_up(): void {
		parent::set_up();

		$this->now = 1800000000;
		add_filter( 'beltoft_services_ssl_fake_now', fn() => $this->now );
		delete_option( FakeClient::OPTION );
		$this->registrar = new FakeClient( true );
		add_filter( 'beltoft_services_domains_client', fn() => $this->registrar );
		$this->settings( array( 'ssl_enabled' => 1, 'domain_api_mode' => 'fake' ) );
		$this->assertSame( 10, SslCatalog::sync() );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_ssl_fake_now' );
		remove_all_filters( 'beltoft_services_domains_client' );
		delete_option( FakeClient::OPTION );
		delete_option( SslCatalog::OPTION );
		parent::tear_down();
	}

	/**
	 * A certificate resource, not yet provisioned, with a paid order and complete billing details.
	 *
	 * @param array<string, mixed> $opts product (registrar id), level, wildcard, total, meta, user, subscription.
	 */
	protected function ssl_resource( array $opts = array() ): Resource {
		$opts    += array( 'product' => 41, 'level' => 'dv', 'wildcard' => false, 'total' => 19.0, 'meta' => array(), 'user' => 0, 'subscription' => 0, 'company' => '' );
		$product = $this->service_product( 'ssl', array( 'ssl_product' => $opts['product'], 'ssl_level' => $opts['level'], 'ssl_wildcard' => $opts['wildcard'] ), array( 'slug' => 'ssl-' . wp_generate_password( 6, false ) ) );
		$user    = $opts['user'] > 0 ? (int) $opts['user'] : (int) self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_email( 'christian.beltoft@gmail.com' );
		$order->set_billing_country( 'DK' );
		$order->set_billing_address_1( 'Testvej 1' );
		$order->set_billing_postcode( '8000' );
		$order->set_billing_city( 'Aarhus' );
		$order->set_billing_phone( '+4512345678' );
		$order->set_billing_company( (string) $opts['company'] );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'SSL', 'quantity' => 1, 'subtotal' => $opts['total'], 'total' => $opts['total'] ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) + ( $opts['subscription'] ? array( 'subscription_id' => $opts['subscription'] ) : array() ) );
		$name    = (string) ( $opts['meta']['ssl_name'] ?? ( $opts['wildcard'] ? '*.example.com' : 'example.com' ) );
		$meta    = $opts['meta'] + array(
			'ssl_domain'     => ltrim( $name, '*.' ),
			'ssl_name'       => $name,
			'ssl_validation' => 'dns',
			'ssl_approver'   => '',
			'ssl_key_mode'   => 'generate',
			'order_item_id'  => (int) $line->get_id(),
		);
		$r = Resource::create( $license, new SslDriver(), Product::plan( $product->get_id() ), $meta );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	/**
	 * Runs the build to its end, moving the fake clock past the registrar's delay between runs.
	 *
	 * @param Resource $r Resource.
	 * @return \BeltoftServices\Drivers\StepResult
	 */
	protected function build( Resource $r ) {
		$result = ( new SslDriver() )->provision( Resource::find( $r->id() ) );

		for ( $i = 0; $i < 6 && ! $result->is_done() && ! $result->is_failed(); $i++ ) {
			$this->now += 120;
			$result = ( new SslDriver() )->provision( Resource::find( $r->id() ) );
		}

		return $result;
	}

	/**
	 * A built, ready certificate.
	 *
	 * @param array<string, mixed> $opts See ssl_resource().
	 */
	protected function built( array $opts = array() ): Resource {
		$r      = $this->ssl_resource( $opts );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertTrue( $r->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Stores a result's meta as the job runner does after a driver call.
	 *
	 * @param Resource                           $r      Resource.
	 * @param \BeltoftServices\Drivers\StepResult $result Result.
	 */
	protected function apply( Resource $r, $result ): Resource {
		if ( array() !== $result->meta() ) {
			$this->assertTrue( $r->set( $result->meta() ) );
		}

		return Resource::find( $r->id() );
	}

	/**
	 * The calls the fake registrar saw, filtered by method and path pattern.
	 *
	 * @param string $method Method.
	 * @param string $path   Regex for the path.
	 * @return array<int, array<string, mixed>>
	 */
	protected function calls( string $method, string $path = '#.#' ): array {
		return array_values( array_filter( $this->registrar->calls(), static fn( $c ) => $method === $c['method'] && 1 === preg_match( $path, $c['path'] ) ) );
	}
}
