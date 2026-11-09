<?php
/**
 * The queries on live resources: live_ids() and find_by_meta().
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Resource;

/**
 * Resource query tests.
 */
final class ResourceQueryTest extends TestCase {

	/**
	 * Makes one live resource of the fake kind with extra meta.
	 *
	 * @param array<string, mixed> $meta Extra meta.
	 */
	private function make( array $meta, string $kind = 'fake' ): Resource {
		$driver   = $this->fake_driver( $kind );
		$product  = $this->service_product( $kind, array(), array( 'max' => 3 ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, $driver, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	public function test_find_by_meta_number_matches_exactly(): void {
		$short = $this->make( array( 'server_license' => 12 ) );
		$long  = $this->make( array( 'server_license' => 112 ) );
		$last  = $this->make( array( 'other' => 1, 'server_license' => 12 ) );

		$this->assertSame( array( $short->id(), $last->id() ), Resource::find_by_meta( 'fake', 'server_license', 12 ) );
		$this->assertSame( array( $long->id() ), Resource::find_by_meta( 'fake', 'server_license', 112 ) );
		$this->assertSame( array(), Resource::find_by_meta( 'fake', 'server_license', 1 ) );
		$this->assertSame( array(), Resource::find_by_meta( 'fake', 'server_license', 2 ) );
	}

	public function test_a_server_licence_stored_as_a_string_is_found_with_the_number_and_string_list(): void {
		$int = $this->make( array( 'server_license' => 12 ) );
		$str = $this->make( array( 'server_license' => '12' ) );
		$this->make( array( 'server_license' => '112' ) );

		$this->assertSame( array( $int->id(), $str->id() ), Resource::find_by_meta( 'fake', 'server_license', array( 12, '12' ) ) );
	}

	public function test_find_by_meta_string_matches_the_whole_value(): void {
		$a = $this->make( array( 'lic_ip' => '10.0.0.1' ) );
		$this->make( array( 'lic_ip' => '10.0.0.12' ) );

		$this->assertSame( array( $a->id() ), Resource::find_by_meta( 'fake', 'lic_ip', '10.0.0.1' ) );
	}

	public function test_find_by_meta_escapes_quotes_backslashes_and_like_wildcards(): void {
		$quote = $this->make( array( 'note' => 'say "hi" now' ) );
		$slash = $this->make( array( 'note' => 'C:\\dir\\file' ) );
		$wild  = $this->make( array( 'note' => '50%_off' ) );
		$other = $this->make( array( 'note' => '50xyoff' ) );

		$this->assertSame( array( $quote->id() ), Resource::find_by_meta( 'fake', 'note', 'say "hi" now' ) );
		$this->assertSame( array( $slash->id() ), Resource::find_by_meta( 'fake', 'note', 'C:\\dir\\file' ) );
		$this->assertSame( array( $wild->id() ), Resource::find_by_meta( 'fake', 'note', '50%_off' ) );
		$this->assertSame( array( $other->id() ), Resource::find_by_meta( 'fake', 'note', '50xyoff' ) );
	}

	public function test_find_by_meta_any_of_a_list_order_and_kind(): void {
		$a = $this->make( array( 'app_state' => 'installing' ) );
		$b = $this->make( array( 'app_state' => 'unknown' ) );
		$this->make( array( 'app_state' => 'ready' ) );
		$this->make( array( 'app_state' => 'unknown' ), 'fake2' );

		$this->assertSame( array( $a->id(), $b->id() ), Resource::find_by_meta( 'fake', 'app_state', array( 'installing', 'unknown' ) ) );
		$this->assertSame( array( $b->id(), $a->id() ), Resource::find_by_meta( 'fake', 'app_state', array( 'installing', 'unknown' ), 'DESC' ) );
		$this->assertSame( array(), Resource::find_by_meta( 'fake', 'app_state', array() ) );
	}

	public function test_find_by_meta_skips_closed_resources(): void {
		$a = $this->make( array( 'mark' => 'x' ) );
		$b = $this->make( array( 'mark' => 'x' ) );

		$a->close();

		$this->assertSame( array( $b->id() ), Resource::find_by_meta( 'fake', 'mark', 'x' ) );
		$this->assertSame( array( $b->id() ), Resource::live_ids( 'fake' ) );
	}

	public function test_find_with_list_and_any_meta_set(): void {
		$none = $this->make( array() );
		$list = $this->make( array( 'old' => array( array( 'at' => 5 ) ) ) );

		$this->assertSame( array( $list->id() ), Resource::find_with_list( 'fake', 'old' ) );
		$this->assertFalse( Resource::any_meta_set( 'breach_at' ) );

		$none->set( array( 'breach_at' => null ) );
		$this->assertFalse( Resource::any_meta_set( 'breach_at' ) );

		$list->set( array( 'breach_at' => 1700000000 ) );
		$this->assertTrue( Resource::any_meta_set( 'breach_at' ) );
	}

	public function test_live_ids_pages_orders_and_limits(): void {
		$ids = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = $this->make( array() )->id();
		}

		$this->assertSame( $ids, Resource::live_ids( 'fake' ) );
		$this->assertSame( array_reverse( $ids ), Resource::live_ids( 'fake', 0, 0, 'DESC' ) );
		$this->assertSame( array( $ids[2], $ids[3] ), Resource::live_ids( 'fake', $ids[1] ) );
		$this->assertSame( array( $ids[2] ), Resource::live_ids( 'fake', $ids[1], 1 ) );
	}
}
