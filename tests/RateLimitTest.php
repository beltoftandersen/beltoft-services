<?php
/**
 * The public rate limiter.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\RateLimit;

/**
 * RateLimit tests.
 */
final class RateLimitTest extends TestCase {

	public function test_limit_boundary(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertFalse( RateLimit::exceeded( 7, 'boundary', 3 ), 'Call ' . $i );
		}

		$this->assertTrue( RateLimit::exceeded( 7, 'boundary', 3 ) );
		$this->assertTrue( RateLimit::exceeded( 7, 'boundary', 3 ) );
	}

	public function test_zero_or_negative_limit_is_off(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( RateLimit::exceeded( 7, 'off', 0 ) );
			$this->assertFalse( RateLimit::exceeded( 7, 'off-negative', -1 ) );
		}
	}

	public function test_keys_count_separately(): void {
		$this->assertFalse( RateLimit::exceeded( 7, 'a', 1 ) );
		$this->assertTrue( RateLimit::exceeded( 7, 'a', 1 ) );
		$this->assertFalse( RateLimit::exceeded( 7, 'b', 1 ) );
	}

	public function test_users_count_separately(): void {
		$this->assertFalse( RateLimit::exceeded( 1, 'shared', 1 ) );
		$this->assertTrue( RateLimit::exceeded( 1, 'shared', 1 ) );
		$this->assertFalse( RateLimit::exceeded( 2, 'shared', 1 ) );
	}

	public function test_window_sets_how_long_a_count_lives(): void {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Counters live in the object cache.' );
		}

		RateLimit::exceeded( 7, 'window', 5, 3600 );
		RateLimit::exceeded( 7, 'window-default', 5 );

		$long    = $this->timeout_for( 3600 );
		$default = $this->timeout_for( MINUTE_IN_SECONDS );

		$this->assertEqualsWithDelta( time() + 3600, $long, 5 );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $default, 5 );
	}

	public function test_window_counts_are_independent(): void {
		$this->assertFalse( RateLimit::exceeded( 7, 'w', 1, 3600 ) );
		$this->assertTrue( RateLimit::exceeded( 7, 'w', 1, 3600 ) );
		$this->assertFalse( RateLimit::exceeded( 7, 'w', 1 ) );
	}

	/**
	 * Expiry of a counter transient whose lifetime matches the window.
	 *
	 * @param int    $window Window in seconds.
	 */
	private function timeout_for( int $window ): int {
		global $wpdb;

		$rows = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_beltoft\\_services\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $value ) {
			if ( abs( (int) $value - ( time() + $window ) ) <= 5 ) {
				return (int) $value;
			}
		}

		return 0;
	}
}
