<?php
/** Domain account panel rendering. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Panels;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class PanelsTest extends TestCase {
	private function render( $driver, array $meta, bool $ready = false ): string {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, $driver, array(), $meta );
		$this->assertInstanceOf( Resource::class, $r );
		if ( $ready ) { $r->set( array( 'state' => 'ready' ) ); }
		ob_start();
		Panels::account( $r );
		return (string) ob_get_clean();
	}

	public function test_domain_panel_renders_with_our_name_servers(): void {
		$html = $this->render( new DomainDriver(), array( 'domain' => 'panel-test.com', 'mode' => 'register' ) );
		$this->assertStringNotContainsString( '<details', $html, 'DNS settings are not folded away.' );
		$this->assertStringContainsString( '<li>ns1.example.net</li>', $html );
		$this->assertStringContainsString( '<li>ns2.example.net</li>', $html );
	}

	public function test_domain_name_is_not_repeated_in_the_panel(): void {
		$html = $this->render( new DomainDriver(), array( 'domain' => 'only-in-heading.com', 'mode' => 'register' ), true );
		$this->assertStringNotContainsString( 'only-in-heading.com', $html, 'The card heading shows the name.' );
	}

	public function test_card_heading_is_the_domain_name_for_domains_and_dns_hosting_only(): void {
		$product  = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'heading-test.com', 'mode' => 'register' ) );
		$this->assertInstanceOf( Resource::class, $resource );

		$this->assertSame( 'heading-test.com', apply_filters( 'beltoft_licenses_account_title', 'Domain name', $license ) );

		$this->fake_driver();
		$other = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) );
		$this->assertSame( 'Product name', apply_filters( 'beltoft_licenses_account_title', 'Product name', $other ) );
	}

	public function test_lock_and_name_server_actions_live_in_the_panel_not_below_it(): void {
		$r = $this->render( new DomainDriver(), array( 'domain' => 'lock-test.com', 'mode' => 'register', 'is_locked' => false ), true );
		$this->assertStringContainsString( 'data-domain-action="lock"', $r );
		$this->assertStringNotContainsString( 'data-domain-action="unlock"', $r, 'Only the action that applies is offered.' );

		$this->assertStringContainsString( 'beltoft-services-domain__copy', $r, 'The transfer code comes with a Copy button.' );
		$this->assertMatchesRegularExpression( '/<input[^>]*readonly[^>]*beltoft-services-domain-code|<input[^>]*beltoft-services-domain-code[^>]*readonly/', $r );
		$locked = $this->render( new DomainDriver(), array( 'domain' => 'lock-test-2.com', 'mode' => 'register', 'is_locked' => true ), true );
		$this->assertStringContainsString( 'data-domain-action="unlock"', $locked );
		$this->assertStringNotContainsString( 'data-domain-action="lock"', $locked );

		$resource = Resource::create( $this->service_license( $this->service_product( 'domain' ), array( 'fire' => false ) ), new DomainDriver(), array(), array( 'domain' => 'btn-test.com', 'mode' => 'register' ) );
		$buttons  = Panels::buttons( array( 'lock' => 'Lock', 'unlock' => 'Unlock', 'set_nameservers' => 'NS', 'set_records' => 'R', 'check_delegation' => 'C', 'other' => 'Other' ), $resource );
		$this->assertSame( array( 'other' ), array_keys( $buttons ), 'The generic button row keeps nothing the panel shows itself.' );
	}

	public function test_dns_hosting_panel_renders_the_records_section(): void {
		$html = $this->render( new DnsDriver(), array( 'domain' => 'dns-panel-test.com', 'mode' => 'dns' ), true );
		$this->assertStringContainsString( '<li>ns1.example.net</li>', $html );
		$this->assertStringContainsString( '<li>ns2.example.net</li>', $html );
		$this->assertStringContainsString( 'beltoft-dns-editor', $html, 'Records are edited row by row, not as JSON.' );
		$this->assertStringContainsString( 'data-rows="[', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
	}
}
