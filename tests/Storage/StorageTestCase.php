<?php
/**
 * Shared setup for the backup storage tests. The provider is the fake client; nothing reaches a network or a real DNS.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Crypto;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Storage\FakeStorageClient;
use BeltoftServices\Storage\StorageDriver;
use BeltoftServices\Tests\TestCase;

abstract class StorageTestCase extends TestCase {

	protected FakeStorageClient $client;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', static fn() => $key );
		FakeStorageClient::reset();
		delete_option( FakeDns::OPTION );
		update_option( 'woocommerce_currency', 'EUR' );
		$this->client = new FakeStorageClient();
		add_filter( 'beltoft_services_storage_client', fn() => $this->client );
		$this->settings(
			array(
				'pause_all'                => 0,
				'storage_enabled'          => 1,
				'storage_cost_table'       => "bx11=3.20\nbx21=10.90",
				'storage_deletes_enabled'  => 0,
				'admin_email'              => 'christian.beltoft@gmail.com',
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_storage_client' );
		remove_all_filters( 'beltoft_services_key' );
		FakeStorageClient::reset();
		delete_option( FakeDns::OPTION );
		parent::tear_down();
	}

	/**
	 * A storage resource, not yet provisioned, with a paid order line.
	 *
	 * @param array<string, mixed> $opts type, total, user, meta, size_gb.
	 */
	protected function storage_resource( array $opts = array() ): Resource {
		$opts   += array( 'type' => 'bx11', 'total' => 8.0, 'user' => 0, 'meta' => array(), 'size_gb' => 1000, 'subscription' => 0 );
		$product = $this->service_product( StorageDriver::ID, array( 'storage_type' => $opts['type'], 'storage_size_gb' => $opts['size_gb'] ), array( 'slug' => 'storage-' . wp_generate_password( 6, false ) ) );
		$user    = $opts['user'] > 0 ? (int) $opts['user'] : (int) self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Storage', 'quantity' => 1, 'subtotal' => $opts['total'], 'total' => $opts['total'] ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) + ( $opts['subscription'] ? array( 'subscription_id' => $opts['subscription'] ) : array() ) );
		$r       = Resource::create( $license, new StorageDriver(), Product::plan( $product->get_id() ), $opts['meta'] + array( 'order_item_id' => (int) $line->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	/**
	 * Runs the build until it is done or failed (the fake box needs a second read to start).
	 *
	 * @param Resource $r Resource.
	 */
	protected function build( Resource $r ): \BeltoftServices\Drivers\StepResult {
		$driver = new StorageDriver();
		$result = $driver->provision( Resource::find( $r->id() ) );

		for ( $i = 0; $i < 6; $i++ ) {
			$fresh = Resource::find( $r->id() );
			$fresh->set( $result->meta() );

			if ( $result->is_done() || $result->is_failed() ) {
				break;
			}

			$result = $driver->provision( Resource::find( $r->id() ) );
		}

		return $result;
	}

	/**
	 * A built, ready box.
	 *
	 * @param array<string, mixed> $opts See storage_resource().
	 */
	protected function ready_storage( array $opts = array() ): Resource {
		$r      = $this->storage_resource( $opts );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready' ) ) );

		return Resource::find( $r->id() );
	}
}
