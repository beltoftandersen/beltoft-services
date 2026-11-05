<?php
/** The shared status script. @package BeltoftServices */
namespace BeltoftServices\Tests;

use BeltoftServices\Status;

class StatusTest extends TestCase {
	public function test_the_script_is_registered_for_others_to_depend_on(): void {
		Status::assets();
		$this->assertTrue( wp_script_is( Status::HANDLE, 'registered' ) );
		$this->assertStringContainsString( 'beltoftStatusConfig', (string) wp_scripts()->get_data( Status::HANDLE, 'data' ) );
	}

	public function test_the_api_and_the_rules_are_in_place(): void {
		$js  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/status.js' );
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		foreach ( array( 'busy:', 'done:', 'fail:', 'info:', 'confirm:', 'data-bs-scope', 'role', 'prefers-reduced-motion' ) as $needle ) {
			$this->assertTrue( str_contains( $js . $css, $needle ), $needle );
		}
		foreach ( array( '--bs-status-ok', '--bs-status-fail', '--bs-status-warn' ) as $var ) {
			$this->assertStringContainsString( $var . ':', $css );
		}
		$this->assertStringNotContainsString( '—', $js );
		$this->assertStringNotContainsString( '·', $js );
	}

	public function test_there_is_no_overlay_only_a_state_line_of_a_fixed_height(): void {
		$js  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/status.js' );
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$this->assertStringNotContainsString( 'bs-status-slot', $js . $css );
		$this->assertStringNotContainsString( 'bs-status__', $js . $css );
		$this->assertStringContainsString( 'data-bs-inline', $js );
		$this->assertMatchesRegularExpression( '/\.bs-inline \{[^}]*height: 20px;/', $css );
		$this->assertMatchesRegularExpression( '/\.bs-inline__text \{[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap;/', $css );
		$this->assertMatchesRegularExpression( '/\.bs-pagehead \{[^}]*height: 20px;/', $css );
		// An empty line takes no room while idle; status.js opens it for a message (is-live) and closes it after.
		$this->assertMatchesRegularExpression( '/\.bs-pagehead\.is-empty:not\(\.is-live\) \{[^}]*height: 0;[^}]*margin-bottom: 0;[^}]*overflow: hidden;/', $css );
		$this->assertMatchesRegularExpression( '/@media \(prefers-reduced-motion: reduce\) \{[^}]*\.bs-pagehead \{? ?[^}]*transition: none|\.bs-inline, \.bs-pagehead \{ transition: none/', $css );
		$this->assertStringContainsString( "classList.toggle( 'is-live', on )", $js );
		$this->assertStringContainsString( "'bs-pagehead is-empty'", $js );
		$this->assertStringContainsString( 'scroll-margin-top: var(--bs-overlay-clear', $css );
		$this->assertStringContainsString( "'alert'", $js, 'A failure is announced as an alert.' );
	}
}
