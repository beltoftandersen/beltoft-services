<?php
/**
 * The namespace moves of the 2026-11 refactor left nothing behind.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Pins the C8 moves: the old names of moved classes are gone from every folder that holds code, the hooks in the
 * plugin file name classes that exist, and Hosting reaches into Proxmox only through the Pve platform layer.
 */
class NamespaceMoveTest extends TestCase {

	/**
	 * Moved class => its old namespace under BeltoftServices (C8a: Proxmox\Pve).
	 */
	private const MOVED = array(
		'Client'          => 'Proxmox',
		'Clients'         => 'Proxmox',
		'LiveClient'      => 'Proxmox',
		'FakeClient'      => 'Proxmox',
		'FakePve'         => 'Proxmox',
		'Tasks'           => 'Proxmox',
		'Steps'           => 'Proxmox',
		'Prepares'        => 'Proxmox',
		'Ownership'       => 'Proxmox',
		'Clusters'        => 'Proxmox',
		'ClustersScreen'  => 'Proxmox',
		'Capacity'        => 'Proxmox',
		'Placement'       => 'Proxmox',
		'DiskCapacity'    => 'Proxmox',
		'ProxmoxSettings' => 'Proxmox',
		'JobScope'        => 'Proxmox\\Jobs',
	);

	/**
	 * @return string[] every PHP file under src, tests and templates, plus the plugin file
	 */
	private function code_files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/beltoft-services.php' );

		foreach ( array( 'src', 'tests', 'templates' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $it as $file ) {
				if ( 'php' === $file->getExtension() && __FILE__ !== $file->getPathname() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	public function test_no_old_name_of_a_moved_class_is_left(): void {
		$left = array();

		foreach ( $this->code_files() as $file ) {
			$code = (string) file_get_contents( $file );

			foreach ( self::MOVED as $class => $old_ns ) {
				$old = 'BeltoftServices\\' . $old_ns . '\\' . $class;
				// Single and double backslash spellings (the second one is a name inside a quoted string).
				foreach ( array( $old, str_replace( '\\', '\\\\', $old ) ) as $spelling ) {
					if ( preg_match( '/' . preg_quote( $spelling, '/' ) . '(?![A-Za-z0-9_\\\\])/', $code ) ) {
						$left[] = basename( dirname( $file ) ) . '/' . basename( $file ) . ' -> ' . $old;
					}
				}
			}
		}

		$this->assertSame( array(), $left );
	}

	public function test_every_moved_class_exists_in_its_new_place(): void {
		foreach ( array_keys( self::MOVED ) as $class ) {
			$this->assertTrue( class_exists( 'BeltoftServices\\Proxmox\\Pve\\' . $class ) || trait_exists( 'BeltoftServices\\Proxmox\\Pve\\' . $class ) || interface_exists( 'BeltoftServices\\Proxmox\\Pve\\' . $class ), $class );
		}
	}

	public function test_every_class_named_in_the_plugin_file_exists(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/beltoft-services.php' );

		preg_match_all( '/\\\\(BeltoftServices\\\\[A-Za-z0-9_\\\\]+)::class/', $code, $m );
		$this->assertNotEmpty( $m[1] );

		foreach ( array_unique( $m[1] ) as $name ) {
			$this->assertTrue( class_exists( $name ) || interface_exists( $name ) || trait_exists( $name ), $name );
		}
	}

	public function test_hosting_reaches_into_proxmox_only_through_pve(): void {
		$bad = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src/Hosting', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$name = ltrim( $token[1], '\\' );

					if ( 0 === strpos( $name, 'BeltoftServices\\Proxmox\\' ) && 0 !== strpos( $name, 'BeltoftServices\\Proxmox\\Pve\\' ) ) {
						$bad[] = $file->getFilename() . ' -> ' . $name;
					}
				}
			}
		}

		$this->assertSame( array(), $bad );
	}
}
