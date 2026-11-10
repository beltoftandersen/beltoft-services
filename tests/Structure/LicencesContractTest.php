<?php
/**
 * What this plugin relies on in beltoft-licenses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftLicenses\Import;
use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Tests\TestCase;

/**
 * Asserts, against the installed beltoft-licenses, every part of its API the services plugin depends on.
 * A licences release that drops or changes one of them fails here, not in a customer's service.
 */
final class LicencesContractTest extends TestCase {

	public function test_the_installed_version_is_at_least_the_one_the_plugin_requires(): void {
		$this->assertTrue( defined( 'BELTOFT_LICENSES_VERSION' ) );
		$this->assertGreaterThanOrEqual( 0, version_compare( (string) BELTOFT_LICENSES_VERSION, '0.8.5' ) );
	}

	public function test_the_dependency_gate_names_the_same_version(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );

		$this->assertStringContainsString( "'0.8.5', '<'", $source );
		$this->assertStringContainsString( 'Beltoft Licenses 0.8.5 or newer', $source );
	}

	public function test_install_tables_has_the_keys_the_plugin_reads(): void {
		$tables = Install::tables();

		foreach ( array( 'licenses', 'activations', 'events' ) as $key ) {
			$this->assertArrayHasKey( $key, $tables );
		}
	}

	public function test_statuses_hold_the_four_the_plugin_branches_on(): void {
		$this->assertEqualsCanonicalizing( array( 'active', 'expired', 'revoked', 'suspended' ), Licenses::STATUSES );
	}

	public function test_import_can_say_whether_it_is_running(): void {
		$this->assertTrue( method_exists( Import::class, 'is_running' ) );
		$this->assertIsBool( Import::is_running() );
	}

	public function test_the_query_and_update_methods_exist(): void {
		foreach ( array( 'live_resource_ids', 'resource_ids_by_meta', 'resource_ids_by_meta_any', 'activations', 'product_holders', 'resource_ids_having_meta', 'resources_of_source', 'update', 'where', 'query', 'activation', 'add_resource', 'close_resource' ) as $method ) {
			$this->assertTrue( method_exists( Licenses::class, $method ), $method );
		}
	}

	public function test_add_resource_accepts_every_registered_kind(): void {
		$product = $this->service_product( 'fake', array(), array( 'max' => 200 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'max_activations' => 200 ) );
		$kinds   = Registry::kinds();

		$this->assertNotEmpty( $kinds );

		foreach ( $kinds as $kind ) {
			$result = Licenses::add_resource( $license, (string) $kind, 'contract-' . $kind );

			$this->assertTrue( $result['success'], "kind $kind: " . ( $result['message'] ?? '' ) );
		}
	}

	public function test_one_update_fires_status_then_expiry_then_updated(): void {
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$seen    = array();
		$record  = static function ( string $name ) use ( &$seen ): \Closure {
			return static function () use ( $name, &$seen ): void {
				$seen[] = $name;
			};
		};

		add_action( 'beltoft_licenses_status_changed', $record( 'status' ), 99 );
		add_action( 'beltoft_licenses_expiry_changed', $record( 'expiry' ), 99 );
		add_action( 'beltoft_licenses_license_updated', $record( 'updated' ), 99 );

		$ok = Licenses::update(
			(int) $license['id'],
			array(
				'status'     => Licenses::STATUS_SUSPENDED,
				'expires_at' => time() + DAY_IN_SECONDS,
			)
		);

		$this->assertTrue( $ok );
		$this->assertSame( array( 'status', 'expiry', 'updated' ), $seen );
	}

	public function test_update_changes_the_source_and_the_row_shows_it(): void {
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product, array( 'fire' => false, 'source' => 'order' ) );

		$this->assertTrue( Licenses::update( (int) $license['id'], array( 'source' => 'usage' ) ) );
		$this->assertSame( 'usage', Licenses::find( (int) $license['id'] )['source'] );
	}

	public function test_resource_ids_by_meta_tells_12_from_112(): void {
		$product = $this->service_product( 'fake', array(), array( 'max' => 5 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$short   = Licenses::add_resource( $license, 'fake', 'twelve', array( 'n' => 12 ) );
		$long    = Licenses::add_resource( $license, 'fake', 'hundred-twelve', array( 'n' => 112 ) );

		$this->assertSame( array( (int) $short['activation_id'] ), Licenses::resource_ids_by_meta( 'fake', 'n', 12 ) );
		$this->assertSame( array( (int) $long['activation_id'] ), Licenses::resource_ids_by_meta( 'fake', 'n', 112 ) );
	}
}
