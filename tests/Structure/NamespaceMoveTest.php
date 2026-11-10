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
	 * Moved class => array( old namespace, new namespace ), both under BeltoftServices (C8a: Proxmox\Pve; C8b: Vm, Disk, Snapshot, Backup).
	 */
	private const MOVED = array(
		'Client'          => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Clients'         => array( 'Proxmox', 'Proxmox\\Pve' ),
		'LiveClient'      => array( 'Proxmox', 'Proxmox\\Pve' ),
		'FakeClient'      => array( 'Proxmox', 'Proxmox\\Pve' ),
		'FakePve'         => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Tasks'           => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Steps'           => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Prepares'        => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Ownership'       => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Clusters'        => array( 'Proxmox', 'Proxmox\\Pve' ),
		'ClustersScreen'  => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Capacity'        => array( 'Proxmox', 'Proxmox\\Pve' ),
		'Placement'       => array( 'Proxmox', 'Proxmox\\Pve' ),
		'DiskCapacity'    => array( 'Proxmox', 'Proxmox\\Pve' ),
		'ProxmoxSettings' => array( 'Proxmox', 'Proxmox\\Pve' ),
		'JobScope'        => array( 'Proxmox\\Jobs', 'Proxmox\\Pve' ),
		'ProxmoxDriver' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Provision' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Operations' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Actions' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'RestoreSteps' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'VmRecords' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Profiles' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'BuildProfile' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'VpsProfile' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Access' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'RevealRoute' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'LinkVm' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Panels' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'EmailDetails' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'ServerName' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'SshKeyField' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'OsField' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Switches' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Upgrades' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Stock' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Offers' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Picked' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Tiles' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'GuestDisk' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Usage' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Credentials' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'CoreHooks' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'PasswordJobs' => array( 'Proxmox\\Jobs', 'Proxmox\\Vm' ),
		'PowerJobs' => array( 'Proxmox\\Jobs', 'Proxmox\\Vm' ),
		'ServerSyncJobs' => array( 'Proxmox\\Jobs', 'Proxmox\\Vm' ),
		'AbuseJobs' => array( 'Proxmox\\Jobs', 'Proxmox\\Vm' ),
		'DiskJobs' => array( 'Proxmox\\Jobs', 'Proxmox\\Vm' ),
		'ProxmoxJobs' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'Schema' => array( 'Proxmox', 'Proxmox\\Vm' ),
		'DiskDriver' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'DiskSteps' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'DiskMeta' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'DiskCart' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'DiskField' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'DiskPanels' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'DiskGuide' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'DiskCascade' => array( 'Proxmox', 'Proxmox\\Disk' ),
		'Snapshots' => array( 'Proxmox', 'Proxmox\\Snapshot' ),
		'SnapshotDriver' => array( 'Proxmox', 'Proxmox\\Snapshot' ),
		'SnapshotMeta' => array( 'Proxmox', 'Proxmox\\Snapshot' ),
		'SnapshotCart' => array( 'Proxmox', 'Proxmox\\Snapshot' ),
		'SnapshotExpiry' => array( 'Proxmox', 'Proxmox\\Snapshot' ),
		'SnapshotJobs' => array( 'Proxmox\\Jobs', 'Proxmox\\Snapshot' ),
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

			foreach ( self::MOVED as $class => $namespaces ) {
				$old = 'BeltoftServices\\' . $namespaces[0] . '\\' . $class;

				// The plugin file's alias map names the old name of a class that outside code still calls.
				if ( isset( BELTOFT_SERVICES_CLASS_ALIASES[ $old ] ) && 'beltoft-services.php' === basename( $file ) ) {
					continue;
				}

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
		foreach ( self::MOVED as $class => $namespaces ) {
			$new = 'BeltoftServices\\' . $namespaces[1] . '\\' . $class;
			$this->assertTrue( class_exists( $new ) || trait_exists( $new ) || interface_exists( $new ), $new );
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
