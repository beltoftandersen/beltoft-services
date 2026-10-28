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

		$long    = $this->timeout_for( 'window', 3600 );
		$default = $this->timeout_for( 'window-default', MINUTE_IN_SECONDS );

		$this->assertEqualsWithDelta( time() + 3600, $long, 5 );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $default, 5 );
	}

	public function test_window_counts_are_independent(): void {
		$this->assertFalse( RateLimit::exceeded( 7, 'w', 1, 3600 ) );
		$this->assertTrue( RateLimit::exceeded( 7, 'w', 1, 3600 ) );
		$this->assertFalse( RateLimit::exceeded( 7, 'w', 1 ) );
	}

	/**
	 * Expiry of the counter transient for one key and window.
	 *
	 * @param string $key    Limiter key.
	 * @param int    $window Window in seconds.
	 */
	private function timeout_for( string $key, int $window ): int {
		$name = 'rl_' . substr( hash( 'sha256', '7|' . $key . '|' . (int) floor( time() / $window ) ), 0, 40 );

		return (int) get_option( '_transient_timeout_beltoft_services_' . $name, 0 );
	}

	public function test_a_visitor_key_buckets_ipv6_by_its_64_and_ipv4_by_the_address(): void {
		$key = static function ( string $ip ): string {
			$_SERVER['REMOTE_ADDR'] = $ip;
			return RateLimit::visitor();
		};
		$this->assertSame( $key( '2001:db8:1:2::1' ), $key( '2001:db8:1:2:ffff:ffff:ffff:ffff' ), 'One /64 is one visitor.' );
		$this->assertNotSame( $key( '2001:db8:1:2::1' ), $key( '2001:db8:1:3::1' ), 'Another /64 is another visitor.' );
		$this->assertNotSame( $key( '203.0.113.5' ), $key( '203.0.113.6' ) );
		$this->assertSame( $key( '203.0.113.5' ), $key( '::ffff:203.0.113.5' ), 'A mapped address is its IPv4 address.' );
		$this->assertMatchesRegularExpression( '/^v_[0-9a-f]{64}$/', $key( '203.0.113.5' ), 'A hash, not a checksum.' );
		$this->assertMatchesRegularExpression( '/^v_[0-9a-f]{64}$/', $key( 'not-an-ip' ) );
		$this->assertMatchesRegularExpression( '/^v_[0-9a-f]{64}$/', $key( '' ) );
	}

	public function test_the_client_address_can_come_from_a_filter(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$proxy = RateLimit::visitor();
		add_filter( 'beltoft_services_client_ip', static fn() => '198.51.100.9' );
		$this->assertNotSame( $proxy, RateLimit::visitor() );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		$this->assertSame( RateLimit::visitor(), RateLimit::visitor() );
	}

	public function test_a_visitor_key_counts_like_a_user(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:9:9::1';
		$this->assertFalse( RateLimit::exceeded( RateLimit::visitor(), 'v', 1 ) );
		$_SERVER['REMOTE_ADDR'] = '2001:db8:9:9::2';
		$this->assertTrue( RateLimit::exceeded( RateLimit::visitor(), 'v', 1 ), 'Another address in the same /64.' );
	}
}
