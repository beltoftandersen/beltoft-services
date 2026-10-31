<?php
/**
 * The Email settings tab.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Crypto;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailSettings;
use BeltoftServices\Settings;
use BeltoftServices\Tests\TestCase;

final class MailSettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', static fn() => $key );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		parent::tear_down();
	}

	public function test_the_tab_and_fields_exist_with_safe_defaults(): void {
		$this->assertSame( 'Email', Settings::tabs()['mail'] );
		$fields = Settings::fields();

		foreach ( array( 'mail_client', 'mail_api_url', 'mail_api_key', 'mail_host', 'mail_webmail_url', 'mail_reserved_domains', 'mail_allow_external_forward', 'mail_spf_include', 'mail_dmarc_rua', 'mail_rl_minute', 'mail_rl_day', 'mail_quota_alert_percent' ) as $key ) {
			$this->assertSame( 'mail', $fields[ $key ]['tab'], $key );
		}

		$this->assertSame( 'fake', Settings::get( 'mail_client' ) );
		$this->assertSame( 'secret', $fields['mail_api_key']['type'] );
		$this->assertSame( array( 30, 500, 90 ), array( Settings::get( 'mail_rl_minute' ), Settings::get( 'mail_rl_day' ), Settings::get( 'mail_quota_alert_percent' ) ) );
		foreach ( array( 'mail_api_url', 'mail_host', 'mail_spf_include', 'mail_reserved_domains', 'mail_webmail_url' ) as $key ) {
			$this->assertSame( '', Settings::get( $key ), 'No site value in code: ' . $key );
		}
	}

	public function test_the_api_key_is_saved_encrypted_and_not_printed(): void {
		$clean = Settings::sanitize( array( 'mail_api_key' => 'KEY-VALUE-123' ) );
		$this->assertStringStartsWith( 'bs1:', $clean['mail_api_key'] );
		$this->assertStringNotContainsString( 'KEY-VALUE-123', (string) wp_json_encode( $clean ) );

		update_option( Settings::OPTION, $clean );
		$this->assertSame( 'KEY-VALUE-123', MailSettings::api_key() );

		ob_start();
		Settings::render_field( array( 'key' => 'mail_api_key' ) );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'KEY-VALUE-123', $html );
		$this->assertStringNotContainsString( $clean['mail_api_key'], $html );
		$this->assertStringContainsString( 'type="password"', $html );

		// A page save that leaves the field empty keeps it.
		$again = Settings::sanitize( array( 'mail_api_key' => '' ) );
		$this->assertSame( $clean['mail_api_key'], $again['mail_api_key'] );
		$this->assertNull( Crypto::decrypt( 'garbage' ) );
	}

	public function test_the_host_is_validated(): void {
		foreach ( array( 'mail.example.com' => 'mail.example.com', 'MAIL.Example.com.' => 'mail.example.com', 'nodots' => '', 'a b.example.com' => '', 'https://mail.example.com' => '' ) as $in => $out ) {
			update_option( Settings::OPTION, array( 'mail_host' => $in ) );
			$this->assertSame( $out, MailSettings::host(), $in );
		}
	}

	public function test_reserved_domains_cover_the_name_and_everything_under_it(): void {
		update_option( Settings::OPTION, array( 'mail_reserved_domains' => "Owner.Example\n, other.example.\nmail.example.net", 'mail_host' => 'mail.example.net' ) );
		$this->assertSame( array( 'owner.example', 'other.example', 'mail.example.net' ), MailSettings::reserved() );

		foreach ( array( 'owner.example', 'a.b.owner.example', 'other.example', 'mail.example.net' ) as $name ) {
			$this->assertTrue( MailDomain::reserved( $name ), $name );
		}
		foreach ( array( 'notowner.example', 'example', 'owner.example.org', 'x.example.net' ) as $name ) {
			$this->assertFalse( MailDomain::reserved( $name ), $name );
		}
		$this->assertNotNull( MailDomain::refusal( 'a.owner.example' ) );
		$this->assertNotNull( MailDomain::refusal( 'bad name' ) );
		$this->assertNull( MailDomain::refusal( 'fine.example' ) );

		add_filter( 'beltoft_services_mail_reserved_domains', static fn( $list ) => array_merge( $list, array( 'filtered.example' ) ) );
		$this->assertTrue( MailDomain::reserved( 'filtered.example' ) );
	}

	public function test_the_reserved_text_has_no_em_dash(): void {
		$this->assertStringNotContainsString( "\u{2014}", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Mail/MailDomain.php' ) );
	}
}
