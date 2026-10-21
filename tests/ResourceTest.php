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

	public function test_uuid_in_meta_is_the_identifier(): void {
		list( $license, $driver ) = $this->setup_license();

		$resource = Resource::create( $license, $driver, array(), array( 'uuid' => 'import-0001' ) );
		$row      = \BeltoftLicenses\Licenses::activation( $resource->id() );

		$this->assertSame( 'import-0001', $resource->get( 'uuid' ) );
		$this->assertSame( 'import-0001', $row['domain'] );
		$this->assertSame( 'import-0001', $row['live_domain'] );
	}

	public function test_invalid_uuid_in_meta_is_refused(): void {
		list( $license, $driver ) = $this->setup_license();

		$result = Resource::create( $license, $driver, array(), array( 'uuid' => 'bad uuid!' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
	}

	public function test_race_under_the_lock_leaves_one_resource(): void {
		list( $license, $driver ) = $this->setup_license();

		// Another request gets its resource in between the first check and the insert.
		add_action(
			'beltoft_services_resource_locked',
			function () use ( $license ) {
				\BeltoftLicenses\Licenses::add_resource( $license, 'fake', 'other-request-uuid', array( 'uuid' => 'other-request-uuid' ) );
			}
		);

		$result = Resource::create( $license, $driver, array() );

		$this->assertInstanceOf( Resource::class, $result );
		$this->assertSame( 'other-request-uuid', $result->get( 'uuid' ) );
		$this->assertCount( 1, \BeltoftLicenses\Licenses::resources( (int) $license['id'] ) );
	}

	public function test_create_returns_locked_when_another_connection_holds_the_lock(): void {
		list( $license, $driver ) = $this->setup_license();

		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		global $wpdb;
		$held = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'license', (int) $license['id'] ) ) );
		$this->assertSame( '1', $held->fetch_row()[0], 'The second connection holds the lock.' );

		add_filter( 'beltoft_services_lock_timeout', '__return_zero' );
		$result = Resource::create( $license, $driver, array() );
		$other->close();

		$this->assertWPError( $result );
		$this->assertSame( 'locked', $result->get_error_code() );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
	}

	public function test_failed_write_returns_false_and_writes_no_event(): void {
		list( $license, $driver ) = $this->setup_license();
		$resource                 = Resource::create( $license, $driver, array() );

		$this->assertFalse( $resource->set( array( 'last_error' => "bad \xB1\x31 utf8" ) ) );

		global $wpdb;
		$wpdb->delete( \BeltoftLicenses\Install::tables()['activations'], array( 'id' => $resource->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$before = count( \BeltoftLicenses\Events::for_license( (int) $license['id'] ) );
		$this->assertFalse( $resource->transition( 'ready', 'Should not be written' ) );
		$this->assertFalse( $resource->desire( 'paused', 'Should not be written either' ) );
		$this->assertCount( $before, \BeltoftLicenses\Events::for_license( (int) $license['id'] ) );
	}

	public function test_unknown_states_are_refused(): void {
		list( $license, $driver ) = $this->setup_license();
		$resource                 = Resource::create( $license, $driver, array() );

		$this->setExpectedIncorrectUsage( 'BeltoftServices\Resource::transition' );
		$this->setExpectedIncorrectUsage( 'BeltoftServices\Resource::desire' );

		$this->assertFalse( $resource->transition( 'bogus', 'x' ) );
		$this->assertFalse( $resource->desire( 'bogus', 'x' ) );
		$this->assertSame( 'requested', Resource::find( $resource->id() )->get( 'state' ) );
	}

	public function test_unstorable_plan_closes_nothing_and_errors(): void {
		list( $license, $driver ) = $this->setup_license();

		$result = Resource::create( $license, $driver, array( 'name' => "bad \xB1\x31 utf8" ) );

		$this->assertWPError( $result );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
	}

	public function test_row_without_meta_reads_as_failed_and_invalid(): void {
		list( $license, $driver ) = $this->setup_license();
		$added                    = \BeltoftLicenses\Licenses::add_resource( $license, 'fake', 'bare-row' );

		$resource = Resource::find( (int) $added['activation_id'] );

		$this->assertFalse( $resource->is_valid() );
		$this->assertSame( 'failed', $resource->get( 'state' ) );
		$this->assertSame( 'Resource metadata missing', $resource->get( 'last_error' ) );
		$this->assertSame( $resource->id(), Resource::for_license( (int) $license['id'] )->id() );

		// Never replaced by a new one.
		$this->assertSame( $resource->id(), Resource::create( $license, $driver, array() )->id() );
	}

	public function test_deleted_resource_is_replaced_by_create(): void {
		list( $license, $driver ) = $this->setup_license();
		$old                      = Resource::create( $license, $driver, array() );
		$old->transition( 'deleted', 'Deleted' );

		$new = Resource::create( $license, $driver, array() );

		$this->assertNotSame( $old->id(), $new->id() );
		$this->assertNotSame( $old->get( 'uuid' ), $new->get( 'uuid' ) );
		$this->assertCount( 1, \BeltoftLicenses\Licenses::resources( (int) $license['id'] ) );
		$this->assertFalse( Resource::find( $old->id() )->is_live() );
	}

	public function test_other_live_states_are_returned_as_they_are(): void {
		list( $license, $driver ) = $this->setup_license();
		$old                      = Resource::create( $license, $driver, array() );
		$old->transition( 'deleting', 'Deleting' );

		$this->assertSame( $old->id(), Resource::create( $license, $driver, array() )->id() );
	}

	public function test_create_after_close_makes_a_new_resource(): void {
		list( $license, $driver ) = $this->setup_license();
		$old                      = Resource::create( $license, $driver, array() );
		$old->close();

		$new = Resource::create( $license, $driver, array() );

		$this->assertNotSame( $old->id(), $new->id() );
		$this->assertNotSame( $old->get( 'uuid' ), $new->get( 'uuid' ) );
	}

	public function test_slug_is_remembered_in_either_write_order(): void {
		$a = new \WC_Product_Simple();
		$a->set_name( 'A' );
		$a->save();
		update_post_meta( $a->get_id(), Product::META_DRIVER, 'fake' );
		$this->assertNotContains( 'order-a', Product::service_slugs() );
		update_post_meta( $a->get_id(), \BeltoftLicenses\Software::META_SLUG, 'order-a' );
		$this->assertContains( 'order-a', Product::service_slugs() );

		$b = new \WC_Product_Simple();
		$b->set_name( 'B' );
		$b->save();
		update_post_meta( $b->get_id(), \BeltoftLicenses\Software::META_SLUG, 'order-b' );
		$this->assertNotContains( 'order-b', Product::service_slugs() );
		update_post_meta( $b->get_id(), Product::META_DRIVER, 'fake' );
		$this->assertContains( 'order-b', Product::service_slugs() );
	}
}
