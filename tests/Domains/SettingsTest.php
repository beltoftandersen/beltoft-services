<?php
/** Domain settings tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainSettings;
use BeltoftServices\Domains\Account;
use BeltoftServices\Settings;
use BeltoftServices\Tests\TestCase;

class SettingsTest extends TestCase {
	public function test_tab_and_fields_registered(): void {
		$this->assertArrayHasKey( 'domains', Settings::tabs() );
		$fields = Settings::fields();
		$this->assertSame( 'fake', $fields['domain_api_mode']['default'] );
		$this->assertSame( '', $fields['domain_nameservers']['default'] );
		$this->assertSame( 30, $fields['domain_markup_percent']['default'] );
		$this->assertSame( 4, $fields['domain_min_margin']['default'] );
	}

	public function test_secret_not_rendered(): void {
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'z', 32 ) ) );
		Account::save( array( 'username' => 'person', 'password' => 'secret-to-hide', 'dns_key' => 'other-secret' ) );
		ob_start();
		DomainSettings::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'secret-to-hide', $html );
		$this->assertStringNotContainsString( 'other-secret', $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}
}
