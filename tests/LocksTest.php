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

	public function test_get_lock_is_reentrant_on_this_server(): void {
		global $wpdb;

		$name  = Locks::name( 'resource', 987654 );
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection watches the lock.
		$free  = static function () use ( $other, $name ) {
			return $other->query( "SELECT IS_FREE_LOCK( '" . $other->real_escape_string( $name ) . "' )" )->fetch_row()[0];
		};

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ), 'The same connection takes it again.' );

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
		$this->assertSame( '0', $free(), 'One release keeps the outer hold.' );

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
		$this->assertSame( '1', $free() );
		// phpcs:enable

		$other->close();
	}

	public function test_with_returns_false_and_null_when_the_lock_is_busy(): void {
		$name  = Locks::name( 'with', 1 );
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );
		$ran = false;

		$this->assertSame( array( false, null ), Locks::with( $name, 0, function () use ( &$ran ) {
			$ran = true;
			return 'x';
		} ) );
		$this->assertFalse( $ran, 'the callback does not run without the lock' );
		$other->close();

		$this->assertSame( array( true, 'x' ), Locks::with( $name, 0, fn() => 'x' ) );
	}

	public function test_with_releases_the_lock_when_the_callback_throws(): void {
		global $wpdb;

		$name = Locks::name( 'with', 2 );

		try {
			Locks::with( $name, 0, function () {
				throw new \LogicException( 'boom' );
			} );
			$this->fail( 'the exception must reach the caller' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', $name ) ) );
	}
}
