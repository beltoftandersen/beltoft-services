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
		'Backups' => array( 'Proxmox', 'Proxmox\\Backup' ),
		'BackupField' => array( 'Proxmox', 'Proxmox\\Backup' ),
		'CartSwap' => array( 'Proxmox', 'Proxmox\\Backup' ),
		'BackupJobs' => array( 'Proxmox\\Jobs', 'Proxmox\\Backup' ),
		'Flows' => array( 'Proxmox', 'Proxmox\\Network' ),
		'Firewall' => array( 'Proxmox', 'Proxmox\\Network' ),
		'FirewallDatabase' => array( 'Proxmox', 'Proxmox\\Network' ),
		'FirewallIpfilter' => array( 'Proxmox', 'Proxmox\\Network' ),
		'FirewallQuarantine' => array( 'Proxmox', 'Proxmox\\Network' ),
		'FirewallRoute' => array( 'Proxmox', 'Proxmox\\Network' ),
		'FirewallRules' => array( 'Proxmox', 'Proxmox\\Network' ),
		'FirewallTab' => array( 'Proxmox', 'Proxmox\\Network' ),
		'IpHistory' => array( 'Proxmox', 'Proxmox\\Network' ),
		'IpPool' => array( 'Proxmox', 'Proxmox\\Network' ),
		'IpPoolScreen' => array( 'Proxmox', 'Proxmox\\Network' ),
		'IpWho' => array( 'Proxmox', 'Proxmox\\Network' ),
		'IpfilterReport' => array( 'Proxmox', 'Proxmox\\Network' ),
		'NetConfig' => array( 'Proxmox', 'Proxmox\\Network' ),
		'NetworkTab' => array( 'Proxmox', 'Proxmox\\Network' ),
		'PrivateAttach' => array( 'Proxmox', 'Proxmox\\Network' ),
		'PrivateNets' => array( 'Proxmox', 'Proxmox\\Network' ),
		'PrivateNic' => array( 'Proxmox', 'Proxmox\\Network' ),
		'Quarantine' => array( 'Proxmox', 'Proxmox\\Network' ),
		'Traffic' => array( 'Proxmox', 'Proxmox\\Network' ),
		'Monitor' => array( 'Proxmox', 'Proxmox\\Network' ),
		'PowerWatch' => array( 'Proxmox', 'Proxmox\\Network' ),
		'Metrics' => array( 'Proxmox', 'Proxmox\\Network' ),
		'MetricsRoute' => array( 'Proxmox', 'Proxmox\\Network' ),
		'LiveUsage' => array( 'Proxmox', 'Proxmox\\Network' ),
		'Console' => array( 'Proxmox', 'Proxmox\\Console' ),
		'ConsoleAuth' => array( 'Proxmox', 'Proxmox\\Console' ),
		'ConsoleClient' => array( 'Proxmox', 'Proxmox\\Console' ),
		'ConsoleRoute' => array( 'Proxmox', 'Proxmox\\Console' ),
		'ConsoleSessions' => array( 'Proxmox', 'Proxmox\\Console' ),
		'ConsoleTab' => array( 'Proxmox', 'Proxmox\\Console' ),
		'DbAccess' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbAccessRoute' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbActions' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbBuild' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbCard' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbDisk' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbDiskField' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbDriver' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbEngine' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbEngineEviction' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbEngineTerms' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbEngines' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbGrow' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbGuest' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbIp' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbIpField' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbJob' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbMonitor' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbNeedsServer' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbNetGuard' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbPolicy' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbPower' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbProfile' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbRestore' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbRoutes' => array( 'Proxmox', 'Proxmox\\Db' ),
		'DbTermsField' => array( 'Proxmox', 'Proxmox\\Db' ),
		'MssqlEngine' => array( 'Proxmox', 'Proxmox\\Db' ),
		'PostgresEngine' => array( 'Proxmox', 'Proxmox\\Db' ),
		'SqlEngine' => array( 'Proxmox', 'Proxmox\\Db' ),
		'ValkeyEngine' => array( 'Proxmox', 'Proxmox\\Db' ),
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
			// The namespace line itself may equal an old class name (Proxmox\Console\Console moved out of the class named Proxmox\Console).
			$code = (string) preg_replace( '/^namespace\s+[^;]+;/m', '', (string) file_get_contents( $file ) );

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
