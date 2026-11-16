<?php
/**
 * Stylesheets keep their round brackets balanced.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * A selector list cut in the middle (an `:is(` without its `)`) makes the browser drop that rule and can swallow the
 * rules after it; that happened once when unused selectors were removed.
 */
class CssSelectorsTest extends TestCase {

	public function test_every_stylesheet_has_balanced_round_brackets_in_each_rule(): void {
		$bad = array();

		foreach ( glob( dirname( __DIR__, 2 ) . '/assets/*.css' ) as $file ) {
			$css = preg_replace( '~/\*.*?\*/~s', '', (string) file_get_contents( $file ) );

			foreach ( preg_split( '/[{}]/', (string) $css ) as $part ) {
				if ( substr_count( $part, '(' ) !== substr_count( $part, ')' ) ) {
					$bad[] = basename( $file ) . ': ' . substr( trim( $part ), 0, 80 );
				}
			}
		}

		$this->assertSame( array(), $bad );
	}
}
