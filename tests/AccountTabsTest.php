<?php
/** Domains and VPS tabs in My Account. @package BeltoftServices */
namespace BeltoftServices\Tests;

use BeltoftLicenses\Account\Endpoint;

class AccountTabsTest extends TestCase {
	private function license_for( string $driver ): array {
		$this->fake_driver();
		return $this->service_license( $this->service_product( $driver ), array( 'fire' => false ) );
	}

	public function test_domains_and_servers_get_their_own_tabs(): void {
		$domain = $this->license_for( 'domain' );
		$dns    = $this->license_for( 'dns' );
		$vps    = $this->license_for( 'proxmox' );
		$other  = $this->license_for( 'fake' );

		$tabs = Endpoint::split( array( $domain, $dns, $vps, $other ) );

		$this->assertSame( array( $domain['id'], $dns['id'] ), array_column( $tabs['domains'], 'id' ) );
		$this->assertSame( array( $vps['id'] ), array_column( $tabs['vps'], 'id' ) );
		$this->assertSame( array( $other['id'] ), array_column( $tabs[''], 'id' ), 'Other services stay on the licenses tab.' );
		$this->assertSame( wc_get_account_endpoint_url( 'domains' ), Endpoint::url_for_license( $domain ) );
	}

	public function test_a_plugin_license_stays_on_the_licenses_tab(): void {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->save();
		$license = $this->service_license( $product, array( 'fire' => false ) );

		$this->assertSame( wc_get_account_endpoint_url( Endpoint::slug() ), Endpoint::url_for_license( $license ) );
	}

	public function test_a_site_can_rename_or_drop_the_tabs(): void {
		add_filter( 'beltoft_services_account_tabs', static fn( $tabs ) => array( array_merge( $tabs[0], array( 'label' => 'My domains' ) ) ) );

		$groups = Endpoint::groups();

		$this->assertSame( array( 'domains' ), array_keys( $groups ) );
		$this->assertSame( 'My domains', $groups['domains']['label'] );

		add_filter( 'beltoft_services_account_tabs', '__return_empty_array', 20 );
		$this->assertSame( array(), Endpoint::groups() );
	}

	public function test_the_account_scripts_load_on_every_tab(): void {
		$this->assertContains( 'domains', Endpoint::slugs() );
		$this->assertContains( 'vps', Endpoint::slugs() );
	}
}
