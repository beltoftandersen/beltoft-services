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
		remove_all_filters( 'beltoft_services_database_server_info' );
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

	/**
	 * Database server versions and whether their GET_LOCK is re-entrant.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function database_versions(): array {
		return array(
			'MySQL 5.6'                   => array( '5.6.51-log', false ),
			'MySQL 5.7.0'                 => array( '5.7.0', false ),
			'MySQL 5.7.4'                 => array( '5.7.4-log', false ),
			'MySQL 5.7.5'                 => array( '5.7.5', true ),
			'MySQL 5.7 without a patch'   => array( '5.7', false ),
			'MySQL 8'                     => array( '8.0.36-0ubuntu0.22.04.1', true ),
			'MariaDB 10.0.1 with prefix'  => array( '5.5.5-10.0.1-MariaDB', false ),
			'MariaDB 10.0.2 with prefix'  => array( '5.5.5-10.0.2-MariaDB-log', true ),
			'MariaDB 10.11 without'       => array( '10.11.14-MariaDB-0ubuntu0.24.04.1', true ),
			'MariaDB 5.5'                 => array( '5.5.68-MariaDB', false ),
			'MariaDB 11'                  => array( '11.4.2-MariaDB', true ),
			'unknown server info'         => array( '', true ),
		);
	}

	/**
	 * @dataProvider database_versions
	 *
	 * @param string $info      Server info.
	 * @param bool   $supported Expected.
	 */
	public function test_database_version_gate( string $info, bool $supported ): void {
		$this->assertSame( $supported, null === Plugin::unsupported_database( $info ) );
	}

	public function test_old_database_refuses_to_boot_with_the_reason(): void {
		add_filter(
			'beltoft_services_database_server_info',
			static function () {
				return '5.6.51-log';
			}
		);

		Plugin::boot();

		$this->assertFalse( Plugin::booted() );
		$this->assertSame( array( 'MySQL 5.7.5 or MariaDB 10.0.2 or newer, for re-entrant named locks (this server runs MySQL 5.6.51)' ), Plugin::missing() );
	}

	public function test_this_database_is_supported(): void {
		global $wpdb;

		$this->assertNull( Plugin::unsupported_database( (string) $wpdb->db_server_info() ) );
	}

	public function test_dependencies_are_really_loaded(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( function_exists( 'wcs_get_subscription' ) );
		$this->assertTrue( method_exists( \BeltoftLicenses\Licenses::class, 'add_resource' ) );
	}

	public function test_version_constant_matches_header(): void {
		$data = get_file_data( BELTOFT_SERVICES_FILE, array( 'Version' => 'Version' ) );

		$this->assertSame( BELTOFT_SERVICES_VERSION, $data['Version'] );
		$this->assertSame( '0.2.0', BELTOFT_SERVICES_VERSION );
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
