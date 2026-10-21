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
}
