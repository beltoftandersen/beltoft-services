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
}
