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
}
