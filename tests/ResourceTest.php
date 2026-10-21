<?php
/**
 * Resources and their state machine.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Product;
use BeltoftServices\Resource;

/**
 * Resource tests.
 */
final class ResourceTest extends TestCase {

	/**
	 * A license for a fake-driver service product, plus the driver.
	 *
	 * @return array{0: array<string, mixed>, 1: \BeltoftServices\Tests\Support\FakeDriver}
	 */
	private function setup_license(): array {
		$driver  = $this->fake_driver();
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );

		return array( $this->service_license( $product, array( 'fire' => false ) ), $driver );
	}

	public function test_create_makes_one_resource_per_license(): void {
		list( $license, $driver ) = $this->setup_license();

		$first  = Resource::create( $license, $driver, array( 'ram' => 2 ) );
		$second = Resource::create( $license, $driver, array( 'ram' => 4 ) );

		$this->assertInstanceOf( Resource::class, $first );
		$this->assertInstanceOf( Resource::class, $second );
		$this->assertSame( $first->id(), $second->id() );
		$this->assertCount( 1, \BeltoftLicenses\Licenses::resources( (int) $license['id'] ) );
		$this->assertSame( 'requested', $first->get( 'state' ) );
		$this->assertSame( 'ready', $first->get( 'desired' ) );
		$this->assertSame( array( 'ram' => 2 ), $first->get( 'plan' ) );
		$this->assertSame( 'fake', $first->get( 'driver' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $first->get( 'uuid' ) );
		$this->assertSame( array(), $first->get( 'steps' ) );
		$this->assertSame( 0, $first->get( 'attempts' ) );
		$this->assertFalse( $first->get( 'protected' ) );
		$this->assertSame( '', $first->get( 'remote_id' ) );
	}

	public function test_medusa_resource_is_always_found_from_its_license(): void {
		list( $license, $driver ) = $this->setup_license();

		$created = Resource::create( $license, $driver, array() );
		$found   = Resource::for_license( (int) $license['id'] );

		$this->assertNotNull( $found );
		$this->assertSame( $created->id(), $found->id() );
		$this->assertSame( $created->get( 'uuid' ), $found->get( 'uuid' ) );
		$this->assertSame( (int) $license['id'], (int) $found->license()['id'] );
		$this->assertSame( $created->id(), Resource::find( $created->id() )->id() );
		$this->assertNull( Resource::for_license( 999999 ) );
		$this->assertNull( Resource::find( 999999 ) );
	}

	public function test_desire_ready_clears_paused_at(): void {
		list( $license, $driver ) = $this->setup_license();
		$resource                 = Resource::create( $license, $driver, array() );

		$resource->desire( 'paused', 'Suspended' );
		$this->assertSame( 'paused', $resource->get( 'desired' ) );
		$this->assertIsInt( $resource->get( 'paused_at' ) );

		$resource->desire( 'ready', 'Lifted' );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertNull( $fresh->get( 'paused_at' ) );
		$this->assertNotSame( 'deleted', $fresh->get( 'desired' ) );
	}

	public function test_transition_writes_history(): void {
		list( $license, $driver ) = $this->setup_license();
		$resource                 = Resource::create( $license, $driver, array() );

		$resource->transition( 'provisioning', 'Provisioning started' );

		$this->assertSame( 'provisioning', Resource::find( $resource->id() )->get( 'state' ) );

		$messages = array_map(
			static fn( $e ) => $e['message'],
			array_filter( \BeltoftLicenses\Events::for_license( (int) $license['id'] ), static fn( $e ) => 'service' === $e['type'] )
		);
		$this->assertContains( 'Provisioning started', $messages );
	}

	public function test_meta_survives_round_trip(): void {
		list( $license, $driver ) = $this->setup_license();
		$resource                 = Resource::create( $license, $driver, array() );

		$this->assertTrue(
			$resource->set(
				array(
					'steps'           => array( 'a', 'b' ),
					'attempts'        => 3,
					'next_attempt_at' => 12345,
					'last_error'      => 'Oops "quoted" ø',
					'remote_id'       => 'vm-9',
					'protected'       => true,
					'plan_dirty'      => true,
					'warned_at'       => 99,
				)
			)
		);

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( array( 'a', 'b' ), $fresh->get( 'steps' ) );
		$this->assertSame( 3, $fresh->get( 'attempts' ) );
		$this->assertSame( 12345, $fresh->get( 'next_attempt_at' ) );
		$this->assertSame( 'Oops "quoted" ø', $fresh->get( 'last_error' ) );
		$this->assertSame( 'vm-9', $fresh->get( 'remote_id' ) );
		$this->assertTrue( $fresh->get( 'protected' ) );
		$this->assertTrue( $fresh->get( 'plan_dirty' ) );
		$this->assertSame( 99, $fresh->get( 'warned_at' ) );
		$this->assertSame( $resource->get( 'uuid' ), $fresh->get( 'uuid' ) );
	}

	public function test_create_accepts_initial_meta(): void {
		list( $license, $driver ) = $this->setup_license();

		$resource = Resource::create(
			$license,
			$driver,
			array(),
			array(
				'state'     => 'ready',
				'protected' => true,
				'remote_id' => 'vm-1',
			)
		);

		$this->assertSame( 'ready', $resource->get( 'state' ) );
		$this->assertTrue( $resource->get( 'protected' ) );
		$this->assertSame( 'vm-1', $resource->get( 'remote_id' ) );
		$this->assertSame( 'ready', $resource->get( 'desired' ) );
	}

	public function test_create_returns_error_when_license_refuses(): void {
		list( $license, $driver ) = $this->setup_license();
		\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'status' => 'revoked' ) );

		$result = Resource::create( \BeltoftLicenses\Licenses::find( (int) $license['id'] ), $driver, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'license_not_active', $result->get_error_code() );
	}

	public function test_close_ends_the_live_resource(): void {
		list( $license, $driver ) = $this->setup_license();
		$resource                 = Resource::create( $license, $driver, array() );

		$resource->close();

		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertNotNull( Resource::find( $resource->id() ) );
	}

	public function test_driver_meta_set_by_any_route_remembers_the_slug(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Plain' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'meta-route-slug' );
		$product->save();

		$this->assertNotContains( 'meta-route-slug', Product::service_slugs() );

		update_post_meta( $product->get_id(), Product::META_DRIVER, 'fake' );

		$this->assertContains( 'meta-route-slug', Product::service_slugs() );
	}

	public function test_empty_driver_meta_does_not_remember_a_slug(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Plain' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'empty-driver-slug' );
		$product->save();

		update_post_meta( $product->get_id(), Product::META_DRIVER, '' );

		$this->assertNotContains( 'empty-driver-slug', Product::service_slugs() );
	}
}
