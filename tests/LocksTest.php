<?php
/**
 * Lock names.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Locks;

/**
 * MySQL lock names are global to the server, so they carry the site's database and prefix.
 */
final class LocksTest extends TestCase {

	public function test_name_carries_scope_id_and_this_site(): void {
		global $wpdb;

		$name = Locks::name( 'resource', 42 );

		$this->assertSame( 'bs_' . Locks::site_hash( DB_NAME, $wpdb->prefix ) . '_resource_42', $name );
		$this->assertMatchesRegularExpression( '/^bs_[0-9a-f]{10}_resource_42$/', $name );
		$this->assertNotSame( Locks::name( 'license', 42 ), $name );
	}

	public function test_other_prefix_or_database_gives_another_name(): void {
		$this->assertNotSame( Locks::site_hash( 'shop', 'wp_' ), Locks::site_hash( 'shop', 'wpstaging_' ) );
		$this->assertNotSame( Locks::site_hash( 'shop', 'wp_' ), Locks::site_hash( 'shop_staging', 'wp_' ) );
		$this->assertSame( Locks::site_hash( 'shop', 'wp_' ), Locks::site_hash( 'shop', 'wp_' ) );
	}

	public function test_names_fit_mysql_limit(): void {
		$this->assertLessThanOrEqual( 64, strlen( Locks::name( 'license', PHP_INT_MAX ) ) );
	}
}
