<?php
/**
 * The per-request memo of licenses and live resources (My Account pages).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Resource;

/**
 * Query counts and staleness of the memo.
 */
final class ResourceMemoTest extends TestCase {

	public function tear_down(): void {
		Resource::memo_end();
		parent::tear_down();
	}

	private function resource(): Resource {
		$driver  = $this->fake_driver();
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );

		return Resource::create( $license, $driver, array() );
	}

	public function test_license_is_read_once_while_the_memo_is_on(): void {
		global $wpdb;

		$r = $this->resource();
		Resource::memo_begin();

		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 20; $i++ ) {
			$r->license();
			$r->shows_status();
			$r->accepts_actions();
			Resource::for_license( (int) $r->license()['id'] );
			$r->pending_desire();
		}

		// One license row, one live-resource read, one pending option read.
		$this->assertLessThanOrEqual( 4, $wpdb->num_queries - $before );
	}

	public function test_memo_off_reads_every_time(): void {
		global $wpdb;

		$r      = $this->resource();
		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 5; $i++ ) {
			$r->license();
		}

		$this->assertGreaterThanOrEqual( 5, $wpdb->num_queries - $before );
	}

	public function test_license_update_is_seen_with_the_memo_on(): void {
		$r = $this->resource();
		Resource::memo_begin();

		$id = (int) $r->license()['id'];
		$this->assertSame( 'active', $r->license()['status'] );

		\BeltoftLicenses\Licenses::update( $id, array( 'status' => 'revoked' ) );

		$this->assertSame( 'revoked', $r->license()['status'] );
	}

	public function test_resource_write_and_close_are_seen_with_the_memo_on(): void {
		$r = $this->resource();
		Resource::memo_begin();

		$id = (int) $r->license()['id'];
		$this->assertSame( $r->id(), Resource::for_license( $id )->id() );
		$this->assertSame( 'requested', Resource::for_license( $id )->get( 'state' ) );

		$r->set( array( 'state' => 'ready' ) );
		$this->assertSame( 'ready', Resource::for_license( $id )->get( 'state' ) );

		$r->close();
		$this->assertNull( Resource::for_license( $id ) );
	}

	public function test_queued_desire_is_seen_with_the_memo_on(): void {
		$r = $this->resource();
		Resource::memo_begin();

		$this->assertNull( $r->pending_desire() );
		$r->queue_desire( 'paused', 'test' );
		$this->assertSame( 'paused', $r->pending_desire()['state'] );
	}

	public function test_preload_reads_the_resources_of_many_licenses_in_one_query(): void {
		global $wpdb;

		$ids = array();
		for ( $n = 0; $n < 4; $n++ ) {
			$ids[] = (int) $this->resource()->license()['id'];
		}
		$ids[] = 999999; // A license with no resource.
		Resource::memo_begin();

		$before = $wpdb->num_queries;
		Resource::preload( $ids );
		$found = array();
		foreach ( $ids as $id ) {
			$found[ $id ] = Resource::for_license( $id );
		}

		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before, 'One query for all, none per license.' );
		$this->assertNull( $found[999999] );
		$this->assertSame( 4, count( array_filter( $found ) ) );
	}

	public function test_preloaded_resources_are_not_stale_after_a_write_or_a_close(): void {
		$r  = $this->resource();
		$id = (int) $r->license()['id'];
		Resource::memo_begin();
		Resource::preload( array( $id ) );

		$this->assertSame( 'requested', Resource::for_license( $id )->get( 'state' ) );
		$r->set( array( 'state' => 'ready' ) );
		$this->assertSame( 'ready', Resource::for_license( $id )->get( 'state' ) );

		Resource::preload( array( $id ) );
		$r->close();
		$this->assertNull( Resource::for_license( $id ) );
	}

	public function test_preload_with_the_memo_off_reads_nothing(): void {
		global $wpdb;

		$before = $wpdb->num_queries;
		Resource::preload( array( 1, 2, 3 ) );

		$this->assertSame( 0, $wpdb->num_queries - $before );
	}
}
