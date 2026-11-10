<?php
/**
 * Resource::transition() is called only where it is listed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Resource::transition() stores a state and fires nothing. A caller that bypasses Reconciler::move() skips every
 * listener of beltoft_services_state_changed, so each one stays silent on purpose, for the reason below.
 * A new caller fails here: use Reconciler::move() or Reconciler::notify(), or list it with its reason.
 */
class TransitionCallersTest extends TestCase {

	/**
	 * Files that call ->transition() and why none of them notifies.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		'Reconciler.php'            => 'move() is the path that notifies: it calls transition() and then notify(); mark_deleted() calls notify() itself right after the transition and the close.',
		'Apps/AppLicence.php'       => 'Announces "cancelled" itself; a notify would add the generic deleted email on top.',
		'Proxmox/Addon/AddonCascade.php' => 'Ends the add-ons of a deleted server; each kind\'s after_parent_deleted does its own notice (a notify would double it or release capacity twice).',
		'Proxmox/Vm/RestoreSteps.php'  => 'A failed disk keeps its volume; a notify would release capacity for a volume left in place. It alerts the admin.',
		'Proxmox/Vm/Reinstall.php'   => 'The reinstall moves to provisioning; no listener acts on it and the later ready does not mail twice.',
	);

	public function test_transition_is_called_only_where_listed(): void {
		$root  = dirname( __DIR__, 2 ) . '/src/';
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( 1 === preg_match( '/->transition\(/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) );
			}
		}

		sort( $found );
		$allowed = array_keys( self::ALLOWED );
		sort( $allowed );

		$this->assertSame( $allowed, $found, 'Resource::transition() fires nothing: new callers must use Reconciler::move() or list their reason here.' );
	}
}
