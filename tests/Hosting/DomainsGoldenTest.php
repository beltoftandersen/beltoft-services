<?php
/**
 * The hosting part of the domains golden pages (fixtures/domains-golden.json, domains-table-golden.json); the rest is in tests/Account/DomainsGoldenTest.php.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Tests\Support\DomainsGolden;
use BeltoftServices\Tests\TestCase;

require_once BELTOFT_SERVICES_TESTS_DIR . '/Support/DomainsGolden.php';

/**
 * The hosting area's pages as the domains test renders them, and the menu order lists that name the hosting tab.
 */
final class DomainsGoldenTest extends TestCase {

	use DomainsGolden;

	/**
	 * Asserts the entries of a fixture, in its order.
	 *
	 * @param string               $file Fixture.
	 * @param array<string, mixed> $now  What the code gives now.
	 */
	private function assert_part( string $file, array $now ): void {
		$golden = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/' . $file ), true );
		$got    = array_intersect_key( $now, $golden );

		$this->assertSame( array_keys( $golden ), array_keys( $got ) );

		foreach ( $golden as $key => $text ) {
			$this->assertSame( $text, $got[ $key ], $key );
		}
	}

	public function test_the_hosting_pages_print_what_they_printed_before_the_move(): void {
		$this->assert_part( 'domains-golden.json', $this->render_all( array( 'hosting' ) ) );
	}

	public function test_the_hosting_tab_sits_where_it_sat_in_the_order_lists(): void {
		$this->assert_part( 'domains-table-golden.json', $this->table() );
	}
}
