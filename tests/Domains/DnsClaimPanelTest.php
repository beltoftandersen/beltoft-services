<?php
/** My Account → Domains: the DNS claim section. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Tests\TestCase;

class DnsClaimPanelTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		wp_set_current_user( self::factory()->user->create() );
	}

	public function tear_down(): void {
		wp_dequeue_script( 'beltoft-services-dns-claim' );
		wp_dequeue_style( 'beltoft-services-dns-claim' );
		unset( $GLOBALS['wp']->query_vars['domains'] );
		parent::tear_down();
	}

	private function menu( array $items ): array {
		return \BeltoftServices\Domains\DnsClaimPanel::menu( $items );
	}

	public function test_the_tab_is_added_before_licenses_and_logout(): void {
		$items = $this->menu( array( 'dashboard' => 'Dashboard', Endpoint::slug() => 'Licenses', 'customer-logout' => 'Log out' ) );

		$this->assertSame( array( 'dashboard', 'domains', Endpoint::slug(), 'customer-logout' ), array_keys( $items ) );
		$this->assertSame( 'Domains', $items['domains'] );
		$this->assertSame( 20, has_filter( 'woocommerce_account_menu_items', array( \BeltoftServices\Domains\DnsClaimPanel::class, 'menu' ) ) );
	}

	public function test_the_tab_goes_before_logout_when_there_is_no_licenses_item(): void {
		$items = $this->menu( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );

		$this->assertSame( array( 'dashboard', 'domains', 'customer-logout' ), array_keys( $items ) );
	}

	public function test_no_tab_when_the_feature_is_off(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );

		$this->assertArrayNotHasKey( 'domains', $this->menu( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) ) );
	}

	public function test_an_existing_tab_is_not_duplicated_or_moved(): void {
		$given = array( 'dashboard' => 'Dashboard', 'domains' => 'My domains', Endpoint::slug() => 'Licenses', 'customer-logout' => 'Log out' );
		$items = $this->menu( $given );

		$this->assertSame( $given, $items );
	}

	public function test_the_section_prints_before_the_license_list(): void {
		add_action( 'woocommerce_account_domains_endpoint', static function (): void { echo '<p id="list">LIST</p>'; } );

		ob_start();
		do_action( 'woocommerce_account_domains_endpoint' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Add a domain for DNS hosting', $html );
		$this->assertStringContainsString( 'Keep your domain where it is registered and host its DNS here.', $html );
		$this->assertStringContainsString( 'placeholder="example.com"', $html );
		$this->assertLessThan( strpos( $html, 'id="list"' ), strpos( $html, 'beltoft-dns-claim' ) );
		$this->assertSame( 5, has_action( 'woocommerce_account_domains_endpoint', array( \BeltoftServices\Domains\DnsClaimPanel::class, 'render' ) ) );
	}

	public function test_nothing_is_printed_when_the_feature_is_off(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );

		ob_start();
		\BeltoftServices\Domains\DnsClaimPanel::render();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_texts_for_the_script_have_no_em_dash_and_are_complete(): void {
		$strings = \BeltoftServices\Domains\DnsClaimPanel::strings();

		foreach ( $strings as $text ) {
			$this->assertStringNotContainsString( "\u{2014}", $text );
		}
		$this->assertSame( 'Continue to checkout', $strings['checkout'] );
		$this->assertStringContainsString( 'Remove the DS record there', $strings['dnssec'] );
	}

	public function test_the_script_builds_every_text_with_textcontent(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/dns-claim.js' );

		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringNotContainsString( "\u{2014}", $js );
		$this->assertStringNotContainsString( 'border-radius: 1', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/dns-claim.css' ) );
	}

	public function test_assets_load_only_on_the_domains_endpoint(): void {
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( 'beltoft-services-dns-claim', 'enqueued' ) );

		$GLOBALS['wp']->query_vars['domains'] = '';
		$this->assertTrue( is_wc_endpoint_url( 'domains' ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( 'beltoft-services-dns-claim', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'beltoft-services-dns-claim', 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( 'beltoft-services-dns-claim', 'data' );
		$this->assertStringContainsString( 'beltoftDnsClaims', $data );
		$this->assertStringContainsString( 'dns-claims', $data );
	}

	public function test_assets_do_not_load_when_the_feature_is_off(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		$GLOBALS['wp']->query_vars['domains'] = '';
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( 'beltoft-services-dns-claim', 'enqueued' ) );
	}
}
