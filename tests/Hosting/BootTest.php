<?php
/**
 * The hosting feature boots from the stages Plugin::boot() announces.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\Addons;
use BeltoftServices\Hosting\Boot;
use BeltoftServices\Tests\TestCase;

/**
 * Plugin.php names one Hosting class; the order of the registrations stays what it was.
 */
class BootTest extends TestCase {

	/**
	 * Names the callbacks of a hook in call order, as "priority class::method".
	 *
	 * @param string $hook Hook name.
	 * @return string[]
	 */
	private function callbacks( string $hook ): array {
		global $wp_filter;
		$out = array();

		foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $priority => $list ) {
			foreach ( $list as $cb ) {
				$f     = $cb['function'];
				$out[] = $priority . ' ' . ( is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . '::' . $f[1] : 'closure' );
			}
		}

		return $out;
	}

	public function test_plugin_names_only_the_boot_class_of_hosting(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
		preg_match_all( '/\bHosting\\\\(\w+)/', $code, $m );

		$this->assertSame( array( 'Boot' ), array_values( array_unique( $m[1] ) ) );
	}

	public function test_boot_listens_to_each_stage(): void {
		$seen = array();
		add_action(
			'beltoft_services_boot',
			function ( $stage ) use ( &$seen ) {
				$seen[] = $stage;
			},
			1
		);
		\BeltoftServices\Plugin::boot();

		$this->assertSame(
			array( 'schema', 'features', 'email_values', 'routes', 'account_assets', 'activity', 'areas', 'order_target', 'cli', 'mcp', 'booted' ),
			$seen
		);
		$this->assertContains( '10 ' . Boot::class . '::stage', $this->callbacks( 'beltoft_services_boot' ) );
	}

	public function test_an_unknown_stage_does_nothing(): void {
		Boot::stage( 'no_such_stage' );
		Boot::stage( array() );
		$this->assertTrue( true );
	}

	public function test_driver_order_is_unchanged(): void {
		$drivers = $this->callbacks( 'beltoft_services_drivers' );

		$this->assertSame( '7 BeltoftServices\Hosting\HostingDriver::add', $drivers[6] );
		$this->assertCount( 10, $drivers );
	}

	public function test_account_areas_keep_their_order(): void {
		$this->assertSame(
			array( '10', '20', '30', '40', '50', '60', '70', '80', '90' ),
			array_map(
				static function ( string $line ): string {
					return strtok( $line, ' ' );
				},
				$this->callbacks( 'beltoft_services_account_areas' )
			)
		);
	}

	public function test_deactivation_action_unschedules_hosting_sweep(): void {
		$this->assertContains( '10 ' . Addons::class . '::unschedule', $this->callbacks( 'beltoft_services_deactivate' ) );
		$this->assertSame( 10, has_action( 'beltoft_services_deactivate', array( Addons::class, 'unschedule' ) ) );
	}
}
