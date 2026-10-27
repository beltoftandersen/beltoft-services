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
		$this->assertStringContainsString( 'panel-test.com', $html );
		$this->assertStringContainsString( 'ns1.example.net, ns2.example.net', $html );
	}

	public function test_dns_hosting_panel_renders_the_records_section(): void {
		$html = $this->render( new DnsDriver(), array( 'domain' => 'dns-panel-test.com', 'mode' => 'dns' ), true );
		$this->assertStringContainsString( 'dns-panel-test.com', $html );
		$this->assertStringContainsString( 'ns1.example.net, ns2.example.net', $html );
		$this->assertStringContainsString( 'beltoft-dns-editor', $html, 'Records are edited row by row, not as JSON.' );
		$this->assertStringContainsString( 'data-rows="[', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
	}
}
