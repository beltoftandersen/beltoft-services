<?php
/**
 * The number of services on each My Account menu item.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\MenuCounts;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;
use BeltoftLicenses\Licenses;

final class MenuCountsTest extends TestCase {

	public function test_active_and_paused_services_count_and_ended_ones_do_not(): void {
		$user    = self::factory()->user->create();
		$product = $this->service_product();
		$ids     = array();

		foreach ( array( 'active', 'suspended', 'expired', 'revoked' ) as $status ) {
			$row   = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
			$ids[] = (int) $row['id'];
			Licenses::update( (int) $row['id'], array( 'status' => $status ) );
		}

		MenuCounts::reset();
		$counts = MenuCounts::of( $user );

		$this->assertSame( array( 2 ), array_values( $counts ), 'Active and suspended count, expired and revoked do not.' );
		$this->assertSame( array(), MenuCounts::of( 0 ) );
	}

	private function db( int $user, string $state = 'ready', bool $closed = false ): Resource {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 20 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array( 'state' => $state, 'desired' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $r );

		if ( $closed ) {
			$r->close();
		}

		return Resource::find( $r->id() );
	}

	public function test_a_licence_whose_service_was_deleted_is_not_counted_on_the_databases_tab(): void {
		$user = self::factory()->user->create();
		$this->db( $user );
		$this->db( $user, 'deleted', true );
		$this->db( $user, 'deleted' );

		MenuCounts::reset();

		$this->assertSame( 1, MenuCounts::of( $user )['databases'] ?? 0, 'Only the live database is listed on the page, so only it counts.' );
	}

	public function test_a_service_being_made_or_failed_still_counts_and_no_service_at_all_does_not(): void {
		$user = self::factory()->user->create();
		$this->db( $user, 'provisioning' );
		$this->db( $user, 'failed' );
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'slug' => 'db-none' ) );
		$this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );

		MenuCounts::reset();

		$this->assertSame( 2, MenuCounts::of( $user )['databases'] ?? 0, 'Provisioning and failed are listed; a licence with no resource is not.' );
	}

	public function test_the_count_is_the_list_of_the_page(): void {
		$user = self::factory()->user->create();
		$this->db( $user );
		$this->db( $user, 'deleted', true );

		MenuCounts::reset();
		$area  = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Databases::class );
		$views = array_map( static fn( $l ) => array( 'id' => (int) $l['id'], 'license' => $l ), \BeltoftLicenses\Account\Endpoint::licenses_for_user( $user ) );

		$this->assertCount( MenuCounts::of( $user )['databases'] ?? 0, $area->sort( $views )['members'] );
	}

	public function test_the_counter_adds_few_queries_for_five_services(): void {
		global $wpdb;

		$user = self::factory()->user->create();

		for ( $n = 0; $n < 5; $n++ ) {
			$this->db( $user );
		}

		\BeltoftLicenses\Account\Endpoint::licenses_for_user( $user );
		MenuCounts::reset();
		Resource::memo_begin();
		$before = $wpdb->num_queries;
		$counts = MenuCounts::of( $user );
		$after  = $wpdb->num_queries;
		Resource::memo_end();

		$this->assertSame( 5, $counts['databases'] ?? 0 );
		$this->assertLessThanOrEqual( 6, $after - $before, 'One bulk read for the resources, not one per licence.' );
	}
}
