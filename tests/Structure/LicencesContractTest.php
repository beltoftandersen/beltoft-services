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

	/**
	 * The beltoft-licenses hooks the plugin listens to: hook => array( kind, services callback class, accepted args ).
	 * The same ten as in Product, Account\Panel, Admin\Panel and Account\Tabs. They decide that a service key is hidden,
	 * that no licence mail is sent for a service and how a service is labelled.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	private function listened_hooks(): array {
		return array(
			'beltoft_licenses_api_license'         => array( 'filter', \BeltoftServices\Product::class, 1 ),
			'beltoft_licenses_resource_label'      => array( 'filter', \BeltoftServices\Product::class, 4 ),
			'beltoft_licenses_account_show_key'    => array( 'filter', \BeltoftServices\Product::class, 2 ),
			'beltoft_licenses_account_show_usage'  => array( 'filter', \BeltoftServices\Product::class, 2 ),
			'beltoft_licenses_account_licenses'    => array( 'filter', \BeltoftServices\Product::class, 1 ),
			'beltoft_licenses_send_status_email'   => array( 'filter', \BeltoftServices\Product::class, 2 ),
			'beltoft_licenses_send_update_email'   => array( 'filter', \BeltoftServices\Product::class, 2 ),
			'beltoft_licenses_account_license'     => array( 'action', \BeltoftServices\Account\Panel::class, 2 ),
			'beltoft_licenses_admin_license'       => array( 'action', \BeltoftServices\Admin\Panel::class, 1 ),
			'beltoft_licenses_account_groups'      => array( 'filter', \BeltoftServices\Account\Tabs::class, 1 ),
		);
	}

	/** The number of arguments of the first call of a hook in the licences source (the value counts for a filter), or null when no call exists. */
	private function fired_arguments( string $kind, string $hook ): ?int {
		$root = dirname( dirname( (string) ( new \ReflectionClass( Licenses::class ) )->getFileName() ) );
		$call = 'filter' === $kind ? 'apply_filters' : 'do_action';
		$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iter as $file ) {
			if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/vendor/' ) || false !== strpos( $file->getPathname(), '/tests/' ) ) {
				continue;
			}

			$code = (string) file_get_contents( $file->getPathname() );
			$at   = strpos( $code, $call . "( '" . $hook . "'" );

			if ( false === $at ) {
				continue;
			}

			$depth = 0;
			$count = 0;
			$len   = strlen( $code );

			for ( $i = $at + strlen( $call ); $i < $len; $i++ ) {
				$c = $code[ $i ];

				if ( "'" === $c ) {
					$i = strpos( $code, "'", $i + 1 );
					$i = false === $i ? $len : $i;
					continue;
				}

				if ( '(' === $c || '[' === $c ) {
					++$depth;
				} elseif ( ')' === $c || ']' === $c ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $c && 1 === $depth ) {
					++$count;
				}
			}

			return $count; // Commas at the top level: the hook name plus N arguments has N commas.
		}

		return null;
	}

	public function test_every_licences_hook_the_plugin_listens_to_is_still_fired_with_enough_arguments(): void {
		foreach ( $this->listened_hooks() as $hook => $spec ) {
			$fired = $this->fired_arguments( $spec[0], $hook );

			$this->assertNotNull( $fired, "$hook is not fired by beltoft-licenses any more." );
			$this->assertGreaterThanOrEqual( $spec[2], $fired, "$hook is fired with fewer arguments than the plugin's listener takes." );
		}
	}

	public function test_the_plugin_listens_to_each_of_those_hooks_with_the_argument_count_the_test_expects(): void {
		global $wp_filter;

		foreach ( $this->listened_hooks() as $hook => $spec ) {
			$accepted = null;

			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && is_string( $callback['function'][0] ) && ltrim( $callback['function'][0], '\\' ) === $spec[1] ) {
						$accepted = (int) $callback['accepted_args'];
					}
				}
			}

			$this->assertSame( $spec[2], $accepted, "$hook: the listener of {$spec[1]} is missing or takes another argument count." );
		}
	}
}
