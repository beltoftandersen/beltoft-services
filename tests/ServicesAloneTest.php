<?php
/**
 * Beltoft Services alone: no Beltoft Hosting installed, and a service that still names the driver `hosting`.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Account\Overview;
use BeltoftServices\Admin\Panel;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

/**
 * The plugin that owned Hosting before 0.62.0 has to keep working, and keep what hosting stored, while the hosting plugin is off.
 */
class ServicesAloneTest extends TestCase {

	/**
	 * A ready service of an existing customer whose driver is `hosting`.
	 *
	 * @return array{0: Resource, 1: array<string, mixed>, 2: int} Resource, license, user id.
	 */
	private function hosting_service(): array {
		$this->fake_driver();
		$user     = self::factory()->user->create();
		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'alone-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
		$resource->set( array( 'driver' => 'hosting' ) );

		return array( Resource::find( $resource->id() ), $license, $user );
	}

	public function test_nothing_of_hosting_is_loaded_and_the_core_booted(): void {
		$this->assertFalse( class_exists( 'BeltoftServices\\Hosting\\Boot', false ) );
		$this->assertFalse( class_exists( 'BeltoftServices\\Hosting\\HostingDriver' ) );
		$this->assertNull( Registry::get( 'hosting' ) );
		$this->assertNotNull( Registry::get( 'proxmox' ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( \BeltoftServices\Plugin::class, 'boot' ) ) );
		$this->assertFalse( has_action( 'plugins_loaded', 'beltoft_hosting_load' ) );
	}

	public function test_a_settings_save_keeps_the_hosting_settings(): void {
		update_option(
			Settings::OPTION,
			array(
				'hosting_label'     => 'Web hosting',
				'hosting_order_url' => 'https://example.test/hosting/',
			)
		);

		$clean = Settings::sanitize( array( 'deletion_mode' => 'report' ) );

		$this->assertSame( 'Web hosting', $clean['hosting_label'] );
		$this->assertSame( 'https://example.test/hosting/', $clean['hosting_order_url'] );
		$this->assertSame( 'report', $clean['deletion_mode'] );
	}

	public function test_a_service_of_the_absent_driver_waits_and_nothing_is_lost(): void {
		list( $resource ) = $this->hosting_service();

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ), 'A ready service is left as it is.' );
		$this->assertSame( 0, (int) $fresh->get( 'attempts' ), 'No attempt is counted for a driver that is not there.' );
		$this->assertSame( 'hosting', $fresh->get( 'driver' ) );
	}

	public function test_a_requested_service_of_the_absent_driver_keeps_waiting(): void {
		list( $resource ) = $this->hosting_service();
		$resource->set( array( 'state' => 'requested' ) );

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'requested', $fresh->get( 'state' ) );
		$this->assertSame( 0, (int) $fresh->get( 'attempts' ) );
	}

	public function test_the_account_pages_render_for_a_customer_of_the_absent_driver(): void {
		list( , , $user ) = $this->hosting_service();
		wp_set_current_user( $user );
		Overview::reset();

		$menu = apply_filters( 'woocommerce_account_menu_items', array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
		$this->assertArrayNotHasKey( 'hosting', $menu );
		$this->assertArrayHasKey( 'domains', $menu );

		$tabs = \BeltoftLicenses\Account\Endpoint::groups();
		$this->assertArrayHasKey( 'domains', $tabs );

		$html = Overview::all();
		$this->assertStringContainsString( 'beltoft-services-ov', $html );
		$this->assertStringNotContainsString( 'Fatal', $html );

		wp_set_current_user( 0 );
		Overview::reset();
	}

	public function test_the_admin_license_panel_and_column_do_not_fatal_for_the_absent_driver(): void {
		list( , $license ) = $this->hosting_service();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_get_current_user()->add_cap( Panel::CAPABILITY );

		ob_start();
		Panel::render( $license );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Fatal', $html );
		$this->assertStringNotContainsString( 'Warning', $html );

		do_action( 'beltoft_licenses_list_page_rows', array( $license ) );
		$cell = apply_filters( 'beltoft_licenses_list_column_service', '', $license );
		$this->assertTrue( is_string( $cell ) || is_array( $cell ) );

		wp_set_current_user( 0 );
	}
}
