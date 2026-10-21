<?php
/**
 * Plugin wiring and the dependency gate.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Plugin;

/**
 * The plugin registers its parts only when its dependencies are present.
 */
class PluginTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_dependencies' );
		Plugin::boot();

		parent::tear_down();
	}

	public function test_boot_registers_nothing_when_a_dependency_is_missing(): void {
		add_filter(
			'beltoft_services_dependencies',
			static function ( $missing ) {
				$missing[] = 'Imaginary Plugin';
				return $missing;
			}
		);

		Plugin::boot();

		$this->assertFalse( Plugin::requirements_met() );
		$this->assertSame( array( 'Imaginary Plugin' ), Plugin::missing() );
		$this->assertFalse( Plugin::booted() );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_missing' ) ) );
	}

	public function test_boot_registers_when_everything_is_present(): void {
		Plugin::boot();

		$this->assertSame( array(), Plugin::missing() );
		$this->assertTrue( Plugin::requirements_met() );
		$this->assertTrue( Plugin::booted() );
		$this->assertFalse( has_action( 'admin_notices', array( Plugin::class, 'notice_missing' ) ) );
	}

	public function test_dependencies_are_really_loaded(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( function_exists( 'wcs_get_subscription' ) );
		$this->assertTrue( method_exists( \BeltoftLicenses\Licenses::class, 'add_resource' ) );
	}

	public function test_version_constant_matches_header(): void {
		$data = get_file_data( BELTOFT_SERVICES_FILE, array( 'Version' => 'Version' ) );

		$this->assertSame( BELTOFT_SERVICES_VERSION, $data['Version'] );
		$this->assertSame( '0.1.0', BELTOFT_SERVICES_VERSION );
	}

	public function test_run_jobs_and_http_recorder_work(): void {
		$this->respond_with(
			static function () {
				return new \WP_Error( 'x', 'nope' );
			}
		);
		$this->assertTrue( is_wp_error( wp_remote_get( 'https://example.org/a' ) ) );
		$this->assertCount( 1, $this->http_calls() );

		as_enqueue_async_action( 'beltoft_services_test_job', array(), 'beltoft-services' );
		$ran = 0;
		add_action(
			'beltoft_services_test_job',
			static function () use ( &$ran ) {
				++$ran;
			}
		);
		$this->run_jobs();
		$this->assertSame( 1, $ran );
	}
}
