<?php
/**
 * The usage engine (src/Usage) does not name a product: drivers declare their own hourly items.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Pay as you go is an engine that drivers hook into (Drivers\UsageItems). A driver id or an activation kind written as a literal in
 * src/Usage is a product named by the engine. Today's leaks are listed in ALLOWED (file => literals); the list only shrinks, and a
 * stale entry fails the test.
 */
class UsageEngineNeutralTest extends TestCase {

	private const LITERALS = array( 'proxmox', 'proxmox-db', 'proxmox-disk', 'proxmox-ipv4', 'vm', 'db', 'disk', 'ipv4' );

	/**
	 * Not yet moved into the drivers: the wording of the customer's delete by kind, the disk-only grow, and the plan parts of a
	 * server or database in the cart breakdown (the quote of an extra disk).
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOWED = array(
		'src/Usage/CustomerDelete.php' => array( 'vm', 'db', 'disk', 'ipv4' ),
		'src/Usage/Breakdown.php'      => array( 'disk' ),
	);

	/**
	 * @return array<string, string[]>
	 */
	private function found(): array {
		$root = dirname( __DIR__, 2 );
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src/Usage', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$rel  = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = (string) file_get_contents( $file->getPathname() );

			foreach ( self::LITERALS as $literal ) {
				if ( preg_match( '/[\'"]' . preg_quote( $literal, '/' ) . '[\'"]/', $code ) ) {
					$out[ $rel ][] = $literal;
				}
			}
		}

		ksort( $out );

		return $out;
	}

	public function test_the_engine_names_no_driver_or_kind(): void {
		$found = $this->found();

		foreach ( self::ALLOWED as $file => $literals ) {
			$this->assertSame( $literals, $found[ $file ] ?? array(), "$file no longer matches its allowance: shrink ALLOWED." );
			unset( $found[ $file ] );
		}

		$this->assertSame( array(), $found, 'The usage engine names a driver or a kind: let the driver declare it (Drivers\UsageItems).' );
	}
}
