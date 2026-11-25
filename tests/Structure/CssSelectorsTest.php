<?php
/**
 * Stylesheets keep their round brackets balanced.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Tests\Support\Scan;

use PHPUnit\Framework\TestCase;

/**
 * A selector list cut in the middle (an `:is(` without its `)`) makes the browser drop that rule and can swallow the
 * rules after it; that happened once when unused selectors were removed.
 */
class CssSelectorsTest extends TestCase {

	public function test_every_stylesheet_has_balanced_round_brackets_in_each_rule(): void {
		$bad = array();

		foreach ( glob( Scan::root() . '/assets/*.css' ) as $file ) {
			$css = preg_replace( '~/\*.*?\*/~s', '', (string) file_get_contents( $file ) );

			foreach ( preg_split( '/[{}]/', (string) $css ) as $part ) {
				if ( substr_count( $part, '(' ) !== substr_count( $part, ')' ) ) {
					$bad[] = basename( $file ) . ': ' . substr( trim( $part ), 0, 80 );
				}
			}
		}

		$this->assertSame( array(), $bad );
	}

	/**
	 * Curly brackets: every block closes, none closes twice, and no selector list ends in a comma right before a declaration
	 * (a rule without its opening bracket). Both happened in account.css: a media query left open put the rules after it
	 * inside the phone-only block, and a rule without its bracket dropped itself and the block after it (2026-11-25).
	 */
	public function test_every_stylesheet_has_balanced_curly_brackets_and_no_rule_without_its_opening_bracket(): void {
		$bad = array();

		foreach ( glob( Scan::root() . '/assets/*.css' ) as $file ) {
			$css = preg_replace( '~/\*.*?\*/~s', '', (string) file_get_contents( $file ) );
			$css = preg_replace( '~"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'~', '""', (string) $css );
			$depth = 0;

			foreach ( str_split( (string) $css ) as $char ) {
				if ( '{' === $char ) {
					++$depth;
				} elseif ( '}' === $char && --$depth < 0 ) {
					$bad[] = basename( $file ) . ': a closing bracket too many';
					$depth = 0;
				}
			}

			if ( 0 !== $depth ) {
				$bad[] = basename( $file ) . ': ' . $depth . ' block(s) left open';
			}

			if ( preg_match( '~,\s*\n\s*[a-z-]+\s*:\s*[^;{}]+;~', (string) $css, $m ) ) {
				$bad[] = basename( $file ) . ': a selector list ends in a comma before a declaration: ' . substr( trim( $m[0] ), 0, 60 );
			}
		}

		$this->assertSame( array(), $bad );
	}
}
