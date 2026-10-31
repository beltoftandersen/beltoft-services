<?php
/**
 * The public mail setup guide shortcode and its link from the Connect tab.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailSetup;

require_once __DIR__ . '/MailTestCase.php';

final class MailSetupTest extends MailTestCase {

	public function tear_down(): void {
		wp_dequeue_style( MailSetup::HANDLE );
		wp_deregister_style( MailSetup::HANDLE );
		parent::tear_down();
	}

	public function test_the_shortcode_prints_the_guide_with_the_values_of_the_settings(): void {
		$this->settings( array( 'mail_host' => 'mail.example.net', 'mail_webmail_url' => 'https://webmail.example.net/' ) );

		$this->assertTrue( shortcode_exists( MailSetup::SHORTCODE ) );

		$html = do_shortcode( '[' . MailSetup::SHORTCODE . ']' );

		$this->assertStringContainsString( 'mail.example.net', $html );
		$this->assertStringContainsString( 'mail.example.net</strong>:993', $html );
		$this->assertStringContainsString( 'href="https://webmail.example.net/"', $html );
		foreach ( array( '993', '465', '587', '995', 'Settings for every app', 'iPhone and iPad', 'Mac Mail', 'Outlook', 'Thunderbird', 'Gmail app', 'Samsung Email', 'bsms-faq' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringNotContainsString( 'beltoft.net', strtolower( $html ), 'Nothing site specific is hardcoded.' );
		$this->assertStringNotContainsString( '<h1', $html );

		$this->settings( array( 'mail_host' => 'mail.other.test', 'mail_webmail_url' => '' ) );
		$html = do_shortcode( '[' . MailSetup::SHORTCODE . ']' );
		$this->assertStringContainsString( 'mail.other.test', $html );
		$this->assertStringNotContainsString( 'mail.example.net', $html );
		$this->assertStringNotContainsString( 'webmail.example.net', $html );
	}

	public function test_without_a_mail_host_nothing_is_printed_and_no_asset_loads(): void {
		$this->settings( array( 'mail_host' => '' ) );

		$html = do_shortcode( '[' . MailSetup::SHORTCODE . ']' );

		$this->assertStringNotContainsString( '<div', $html );
		$this->assertStringNotContainsString( 'Settings for every app', $html );
		$this->assertFalse( wp_style_is( MailSetup::HANDLE, 'enqueued' ) );
	}

	public function test_the_stylesheet_loads_only_with_the_shortcode(): void {
		$this->settings( array( 'mail_host' => 'mail.example.net' ) );

		$this->assertFalse( wp_style_is( MailSetup::HANDLE, 'enqueued' ) );

		do_shortcode( '[' . MailSetup::SHORTCODE . ']' );

		$this->assertTrue( wp_style_is( MailSetup::HANDLE, 'enqueued' ) );
	}

	public function test_the_files_have_no_em_dash_and_no_border_radius(): void {
		foreach ( array( 'templates/mail-setup.php', 'assets/mail-setup.css', 'src/Mail/MailSetup.php' ) as $file ) {
			$text = (string) file_get_contents( BELTOFT_SERVICES_PATH . $file );

			$this->assertNotSame( '', $text, $file );
			$this->assertStringNotContainsString( "\u{2014}", $text, $file . ' has no em dash.' );
			$this->assertStringNotContainsString( 'border-radius', $text, $file . ' has square corners.' );
		}
	}

	public function test_the_setting_is_a_url_field_and_refuses_other_schemes(): void {
		$this->assertArrayHasKey( 'mail_guide_url', \BeltoftServices\Settings::fields() );

		$clean = \BeltoftServices\Settings::sanitize( array( 'mail_guide_url' => 'javascript:alert(1)' ) );
		$this->assertStringNotContainsString( 'javascript', (string) ( $clean['mail_guide_url'] ?? '' ) );

		$clean = \BeltoftServices\Settings::sanitize( array( 'mail_guide_url' => 'https://example.com/mail-setup/' ) );
		$this->assertSame( 'https://example.com/mail-setup/', $clean['mail_guide_url'] );
	}
}
