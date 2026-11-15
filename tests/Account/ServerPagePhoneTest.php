<?php
/** The server page must not grow wider than a phone screen. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

class ServerPagePhoneTest extends TestCase {
	public function test_the_memory_help_sentence_does_not_use_the_axis_class(): void {
		$html = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/account/monitoring.php' );

		$this->assertMatchesRegularExpression( '/<p class="beltoft-vm-usage__note"><\?php esc_html_e\( \'Linux uses free memory/', $html );
		$this->assertDoesNotMatchRegularExpression( '/beltoft-vm-usage__axis"[^>]*>\s*<\?php esc_html_e\( \'Linux uses/', $html );
		$this->assertStringContainsString( 'beltoft-vm-usage__note" data-mem-hint', $html );
	}

	public function test_the_note_class_wraps_and_the_axis_class_does_not(): void {
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/metrics.css' );

		$this->assertMatchesRegularExpression( '/p\.beltoft-vm-usage__note \{[^}]*overflow-wrap: anywhere/', $css );
		$this->assertDoesNotMatchRegularExpression( '/p\.beltoft-vm-usage__note \{[^}]*nowrap/', $css );
	}
}
