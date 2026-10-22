<?php
/**
 * Proxmox settings tab.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\ProxmoxSettings;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Settings;
use BeltoftServices\Tests\TestCase;

/**
 * The flat Proxmox fields: defaults and bounds.
 */
class ProxmoxSettingsTest extends TestCase {

	public function tear_down(): void {
		global $wpdb;

		delete_option( Settings::OPTION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		global $wp_settings_errors;
		$wp_settings_errors = array();

		parent::tear_down();
	}

	public function test_tabs_exist(): void {
		$tabs = Settings::tabs();

		$this->assertArrayHasKey( 'proxmox', $tabs );
		$this->assertArrayHasKey( 'proxmox-clusters', $tabs );
	}

	public function test_site_tag_default(): void {
		// Nothing is stored as "set": a form that saves the field empty stays unset.
		$this->assertSame( '', Settings::get( 'site_tag' ) );
		$this->assertSame( '', ProxmoxSettings::explicit_site_tag() );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), ProxmoxSettings::site_tag(), 'The host name serves fake clusters.' );
		$this->assertSame( '', ProxmoxSettings::sanitize( array( 'site_tag' => 'a:b' ) )['site_tag'], 'An unusable tag is unset, never replaced by the host.' );
		$this->assertSame( 'shop-main', ProxmoxSettings::sanitize( array( 'site_tag' => 'shop-main' ) )['site_tag'] );
	}

	public function test_other_defaults(): void {
		$this->assertSame( 120, Settings::get( 'shutdown_timeout' ) );
		$this->assertSame( 25, Settings::get( 'task_wait' ) );
		$this->assertSame( 'report', Settings::get( 'abuse_mode' ) );
		$this->assertSame( 90, Settings::get( 'abuse_cpu' ) );
		$this->assertSame( 2, Settings::get( 'abuse_hours' ) );
	}

	public function test_site_tag_cannot_break_the_ownership_line(): void {
		$default = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( array( 'a:b', "a\nb", 'a b', '', '   ', str_repeat( 'a', 101 ) ) as $bad ) {
			update_option( Settings::OPTION, array( 'site_tag' => $bad ) );
			$this->assertSame( $default, ProxmoxSettings::site_tag(), (string) wp_json_encode( $bad ) );
		}

		update_option( Settings::OPTION, array( 'site_tag' => 'shop.example.invalid' ) );
		$this->assertSame( 'shop.example.invalid', ProxmoxSettings::site_tag() );
		$this->assertSame( 'shop.example.invalid', Settings::get( 'site_tag' ) );
	}

	public function test_numbers_are_bounded_and_abuse_mode_validated(): void {
		update_option(
			Settings::OPTION,
			array(
				'shutdown_timeout' => 5,
				'task_wait'        => 5000,
				'abuse_cpu'        => 500,
				'abuse_hours'      => 0,
				'abuse_mode'       => 'destroy',
			)
		);

		$this->assertSame( 10, Settings::get( 'shutdown_timeout' ) );
		$this->assertSame( 120, Settings::get( 'task_wait' ) );
		$this->assertSame( 100, Settings::get( 'abuse_cpu' ) );
		$this->assertSame( 1, Settings::get( 'abuse_hours' ) );
		$this->assertSame( 'report', Settings::get( 'abuse_mode' ) );

		update_option( Settings::OPTION, array( 'abuse_mode' => 'pause', 'shutdown_timeout' => 300 ) );
		$this->assertSame( 'pause', Settings::get( 'abuse_mode' ) );
		$this->assertSame( 300, Settings::get( 'shutdown_timeout' ) );
	}

	public function test_the_site_tag_cannot_change_while_vm_records_exist(): void {
		global $wp_settings_errors;

		update_option( Settings::OPTION, array( 'site_tag' => 'shop-main' ) );

		// No VM yet: it can change.
		$this->assertSame( 'shop-two', ProxmoxSettings::sanitize( array( 'site_tag' => 'shop-two' ) )['site_tag'] );

		$this->assertTrue( VmRecords::adopt( 77, 'lab', 'node-a', 5000 ) );
		$wp_settings_errors = array();

		$out = ProxmoxSettings::sanitize( array( 'site_tag' => 'shop-two' ) );
		$this->assertSame( 'shop-main', $out['site_tag'], 'The saved tag is kept.' );
		$this->assertSame( 'beltoft_services_site_tag_locked', $wp_settings_errors[0]['code'] );
		$this->assertStringContainsString( 'every one of them', $wp_settings_errors[0]['message'] );

		// Clearing it is a change too; an unusable value as well.
		$this->assertSame( 'shop-main', ProxmoxSettings::sanitize( array( 'site_tag' => '' ) )['site_tag'] );
		$this->assertSame( 'shop-main', ProxmoxSettings::sanitize( array( 'site_tag' => 'a:b' ) )['site_tag'] );

		// Saving the same tag again, and a form that leaves it alone, work.
		$wp_settings_errors = array();
		$this->assertSame( 'shop-main', ProxmoxSettings::sanitize( array( 'site_tag' => 'shop-main' ) )['site_tag'] );
		$this->assertSame( array(), $wp_settings_errors );
		$this->assertSame( 20, ProxmoxSettings::sanitize( array( 'abuse_hours' => 20 ) )['abuse_hours'] );
	}
}
