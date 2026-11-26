<?php
/** Owner 2026-11-26: code values in My Account keep their padding (the v9 dashboard draws them as fields). @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

class CodePaddingTest extends TestCase {
	public function test_no_my_account_stylesheet_takes_the_padding_off_a_code_value(): void {
		$files = array( 'account.css', 'mail.css', 'ssl.css', 'mail-setup.css' ); // the API documentation keeps its own plain code
		$found = array();
		foreach ( $files as $file ) {
			$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/' . $file );
			if ( preg_match_all( '/([^{}]*\bcode\b[^{}]*)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $rule ) {
					if ( preg_match( '/(^|;)\s*padding:\s*0\s*(;|$)/', $rule[2] ) && false === strpos( $rule[1], 'pre code' ) ) {
						$found[] = $file . ': ' . trim( $rule[1] );
					}
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
