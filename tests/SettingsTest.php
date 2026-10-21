<?php
/**
 * Settings.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Settings;

/**
 * Defaults, sanitising and secret handling.
 */
class SettingsTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_settings_fields' );
		global $wp_settings_errors;
		$wp_settings_errors = array();

		parent::tear_down();
	}

	/**
	 * Adds a secret field the way a later task does.
	 */
	private function add_secret_field(): void {
		add_filter(
			'beltoft_services_settings_fields',
			static function ( $fields ) {
				$fields['demo_secret'] = array(
					'tab'     => 'general',
					'section' => 'general',
					'type'    => 'secret',
					'default' => '',
					'label'   => 'Demo secret',
				);
				return $fields;
			}
		);
	}

	public function test_defaults(): void {
		$this->assertSame( 'off', Settings::get( 'deletion_mode' ) );
		$this->assertSame( 0, Settings::get( 'pause_all' ) );
		$this->assertSame( 1, Settings::get( 'warning_days' ) );
		$this->assertSame( get_option( 'admin_email' ), Settings::get( 'admin_email' ) );
		$this->assertSame( 0, Settings::get( 'delete_data' ) );
	}

	public function test_sanitize_rejects_unknown_deletion_mode(): void {
		$this->assertSame( 'on', Settings::sanitize( array( 'deletion_mode' => 'on' ) )['deletion_mode'] );
		$this->assertSame( 'off', Settings::sanitize( array( 'deletion_mode' => 'everything' ) )['deletion_mode'] );
	}

	public function test_sanitize_keeps_values_of_fields_not_submitted(): void {
		$this->settings( array( 'deletion_mode' => 'report' ) );
		$this->assertSame( 'report', Settings::sanitize( array( 'warning_days' => 3 ) )['deletion_mode'] );
	}

	public function test_sanitize_cleans_numbers_and_email(): void {
		$clean = Settings::sanitize(
			array(
				'warning_days' => '-4',
				'admin_email'  => 'not an email',
				'pause_all'    => '1',
			)
		);
		$this->assertSame( 0, $clean['warning_days'] );
		$this->assertSame( get_option( 'admin_email' ), $clean['admin_email'] );
		$this->assertSame( 1, $clean['pause_all'] );
	}

	public function test_secret_is_stored_encrypted_and_empty_keeps_it(): void {
		$this->add_secret_field();
		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		$clean = Settings::sanitize( array( 'demo_secret' => 'hunter2' ) );
		$this->assertStringStartsWith( 'bs1:', $clean['demo_secret'] );
		$this->assertStringNotContainsString( 'hunter2', $clean['demo_secret'] );
		$this->assertSame( 'hunter2', \BeltoftServices\Crypto::decrypt( $clean['demo_secret'] ) );

		update_option( Settings::OPTION, $clean );
		$kept = Settings::sanitize( array( 'demo_secret' => '' ) );
		$this->assertSame( $clean['demo_secret'], $kept['demo_secret'] );
	}

	public function test_secret_refused_without_key(): void {
		$this->add_secret_field();
		$this->settings( array( 'demo_secret' => 'bs1:saved' ) );

		$clean = Settings::sanitize( array( 'demo_secret' => 'hunter2' ) );

		$this->assertSame( 'bs1:saved', $clean['demo_secret'] );
		$errors = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message' );
		$this->assertContains( 'Add BELTOFT_SERVICES_KEY to wp-config.php before saving secrets.', $errors );
	}

	public function test_page_never_echoes_a_saved_secret(): void {
		$this->add_secret_field();
		$this->settings( array( 'demo_secret' => 'bs1:AAAAsecretAAAA' ) );

		ob_start();
		Settings::render_field( array( 'key' => 'demo_secret' ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'AAAAsecretAAAA', $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	public function test_no_plaintext_password_field_type(): void {
		$this->assertStringNotContainsString( "case 'password'", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Settings.php' ) );
	}

	public function test_tabs_can_be_added_by_filter(): void {
		$this->assertArrayHasKey( 'general', Settings::tabs() );
		add_filter(
			'beltoft_services_settings_tabs',
			static function ( $tabs ) {
				$tabs['connections'] = 'Connections';
				return $tabs;
			}
		);
		$this->assertArrayHasKey( 'connections', Settings::tabs() );
		remove_all_filters( 'beltoft_services_settings_tabs' );
	}

	public function test_first_save_of_the_option_encrypts_a_secret_once(): void {
		$this->add_secret_field();
		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Settings::OPTION );
		register_setting( Settings::PAGE, Settings::OPTION, array( 'sanitize_callback' => array( Settings::class, 'sanitize' ), 'default' => array() ) );

		update_option( Settings::OPTION, array( 'demo_secret' => 'hunter2' ) );

		$this->assertSame( 'hunter2', \BeltoftServices\Crypto::decrypt( Settings::get( 'demo_secret' ) ) );
	}

	public function test_no_key_error_is_added_once_on_first_save(): void {
		$this->add_secret_field();
		delete_option( Settings::OPTION );
		register_setting( Settings::PAGE, Settings::OPTION, array( 'sanitize_callback' => array( Settings::class, 'sanitize' ), 'default' => array() ) );

		update_option( Settings::OPTION, array( 'demo_secret' => 'hunter2' ) );

		$errors = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message' );
		$this->assertCount( 1, $errors );
		$this->assertSame( '', (string) Settings::get( 'demo_secret' ) );
	}

	public function test_sections_without_fields_on_a_tab_are_not_registered(): void {
		global $wp_settings_sections;
		add_filter(
			'beltoft_services_settings_tabs',
			static function ( $tabs ) {
				$tabs['other'] = 'Other';
				return $tabs;
			}
		);
		add_filter(
			'beltoft_services_settings_sections',
			static function ( $sections ) {
				$sections['extra'] = 'Extra';
				return $sections;
			}
		);
		$wp_settings_sections = array();

		Settings::register_setting();

		$this->assertArrayHasKey( 'beltoft-services-general', $wp_settings_sections );
		$this->assertArrayNotHasKey( 'beltoft-services-other', $wp_settings_sections );
		$this->assertArrayNotHasKey( 'beltoft_services_general_extra', $wp_settings_sections['beltoft-services-general'] );
		remove_all_filters( 'beltoft_services_settings_tabs' );
		remove_all_filters( 'beltoft_services_settings_sections' );
	}

	public function test_notice_when_license_expiry_is_only_checked_daily(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( \BeltoftLicenses\Settings::OPTION, array( 'expiry_hourly' => 0 ) );
		$this->assertTrue( Settings::expiry_is_daily() );

		ob_start();
		Settings::render_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Services stop up to a day late after a subscription ends.', $html );
		$this->assertStringContainsString( 'options-general.php?page=beltoft-licenses', $html );

		update_option( \BeltoftLicenses\Settings::OPTION, array( 'expiry_hourly' => 1 ) );
		$this->assertFalse( Settings::expiry_is_daily() );

		ob_start();
		Settings::render_page();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'Services stop up to a day late', $html );
	}
}
