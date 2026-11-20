<?php
/**
 * The usage item lookups build the driver list and the words once per request, and follow a change of drivers or language.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\Items;

require_once __DIR__ . '/UsageTestCase.php';

final class ItemsMemoTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		Items::reset();
	}

	public function tear_down(): void {
		Items::reset();
		parent::tear_down();
	}

	public function test_lookups_read_the_registry_once(): void {
		$runs = 0;
		$count = static function ( $drivers ) use ( &$runs ) {
			++$runs;
			return $drivers;
		};
		add_filter( 'beltoft_services_drivers', $count, 99 );

		Items::reset();
		$this->assertSame( 'IPv4 address', Items::words( 'ipv4', 'label' ) );
		$first = $runs;
		$this->assertGreaterThan( 0, $first );

		for ( $i = 0; $i < 5; $i++ ) {
			Items::hourly( 'server' );
			Items::delete_for( 'server' );
			Items::drivers();
			Items::is_plan( 'database' );
		}

		$this->assertSame( $first, $runs, 'No further build.' );
		remove_filter( 'beltoft_services_drivers', $count, 99 );
	}

	public function test_a_driver_added_later_is_seen(): void {
		$before = Items::drivers();
		$this->assertNotEmpty( $before );
		$only   = static fn( $drivers ) => array_diff_key( (array) $drivers, array( 'proxmox-ipv4' => 1 ) );
		add_filter( 'beltoft_services_drivers', $only, 99 );

		$this->assertArrayHasKey( 'proxmox-ipv4', $before );
		$this->assertArrayNotHasKey( 'proxmox-ipv4', Items::drivers(), 'A changed filter rebuilds.' );
		remove_filter( 'beltoft_services_drivers', $only, 99 );
		$this->assertSame( array_keys( $before ), array_keys( Items::drivers() ) );
	}

	public function test_a_language_change_rebuilds_the_words(): void {
		Items::drivers();
		$runs  = 0;
		add_filter( 'locale', static fn() => 'da_DK', 99 );
		add_filter( 'beltoft_services_drivers', static function ( $d ) use ( &$runs ) { ++$runs; return $d; }, 99 );
		Items::hourly( 'ipv4' );
		$this->assertGreaterThan( 0, $runs, 'Another locale builds the words again.' );
		remove_all_filters( 'locale' );
	}
}
