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

	public function test_a_clicked_button_gets_one_message_not_two(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/status.js' );

		// One loader, in one place (owner 2026-11-15): nothing is written under the clicked button, the button is only disabled.
		$this->assertStringNotContainsString( 'noteSay', $js );
		$this->assertStringNotContainsString( 'noteFor', $js );
		$this->assertStringNotContainsString( 'bs-note', $js );
		$this->assertStringNotContainsString( "classList.add( 'is-busy' )", $js );
		$this->assertMatchesRegularExpression( '/show\( \'busy\', message, handle \);/', $js );

		// Hosting: clearing the cache keeps its done line (no reload); other actions show it again after the reload.
		$hosting = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/hosting.js' );
		$this->assertMatchesRegularExpression( "/if \\( 'cache' === action \\) \\{\\s*return;\\s*\\}/", $hosting );
		$this->assertStringContainsString( 'remember( action,', $hosting );
		$this->assertStringContainsString( 'recall();', $hosting );
	}

	public function test_the_progress_line_has_one_place_a_phone_breakpoint_and_a_progress_api(): void {
		$js  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/status.js' );
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$own = substr( $css, (int) strpos( $css, '==== The progress line' ) );

		$this->assertStringContainsString( 'progress: progress', $js );
		$this->assertStringContainsString( "'(max-width: 767px)'", $js );
		$this->assertStringContainsString( '@media (max-width: 767px)', $own );
		$this->assertMatchesRegularExpression( '/\.bs-loader \{ position: fixed;[^}]*env\(safe-area-inset-bottom/', $own );
		$this->assertMatchesRegularExpression( '/@media \(prefers-reduced-motion: reduce\) \{[^}]*\.bs-loader[^}]*animation: none/', $own );
		$this->assertMatchesRegularExpression( '/\.bs-loader\.is-stuck \{ position: fixed;[^}]*top: var\(--bs-header-offset/', $own );
		$this->assertStringNotContainsString( '!important', $own );
		$this->assertStringNotContainsString( '—', $own );
		$this->assertStringNotContainsString( '·', $own );
		$this->assertStringContainsString( 'stepOf', implode( ' ', array_keys( Status::strings() ) ) );
		$this->assertStringContainsString( 'handle: handle', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain.js' ) );
		$this->assertStringContainsString( 'window.beltoftStatus.progress( settings.handle', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/api.js' ) );
	}

	/**
	 * Drives status.js in a real browser (Playwright), with its clock under test control: busy, progress, done and the wait, fail,
	 * the fixed line on a phone with no layout shift. Skipped where Playwright is not installed.
	 */
	public function test_the_line_works_in_a_browser(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );
		$pw   = getenv( 'BELTOFT_PLAYWRIGHT_DIR' ) ? (string) getenv( 'BELTOFT_PLAYWRIGHT_DIR' ) : '/root/e2e-tools/node_modules/playwright';

		if ( '' === $node || ! is_dir( $pw ) ) {
			$this->environment_missing( 'node or Playwright is not installed.' );
		}

		$cmd = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/loader-check.js' ) . ' ' . escapeshellarg( $pw ) . ' ' . escapeshellarg( BELTOFT_SERVICES_PATH ) . ' 2>&1';
		$out = (string) shell_exec( $cmd );

		$this->assertStringContainsString( 'LOADER OK', $out, $out );
		$this->assertStringNotContainsString( 'FAIL', $out, $out );
	}
}
