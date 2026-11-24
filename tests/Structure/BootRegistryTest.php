<?php
/**
 * Every class with a public static register() is actually booted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Tests\Support\Scan;

use PHPUnit\Framework\TestCase;

/**
 * A register() that nothing calls silently does nothing. This scans src/ and fails on such a class.
 */
class BootRegistryTest extends TestCase {

	/**
	 * Classes whose register() is deliberately not called from Plugin::boot() or another booted class.
	 * Each entry needs a reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		// Empty today: every register() is reached from Plugin::boot(). Add an entry only with a reason,
		// e.g. a class that Bricks or WP-CLI loads itself.
	);

	/**
	 * @return array<string, string> class FQCN => file
	 */
	private function classes_with_register(): array {
		$found  = array();
		$files  = array();
		$traits = array();
		$it     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( Scan::root() . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$code                          = (string) file_get_contents( $file->getPathname() );
			$files[ $file->getPathname() ] = $code;

			// A trait that brings register() along: a class using it has a register() too.
			if ( preg_match( '/public static function register\(/', $code ) && preg_match( '/^trait (\w+)/m', $code, $tr ) ) {
				$traits[] = $tr[1];
			}
		}

		foreach ( $files as $path => $code ) {
			$has = (bool) preg_match( '/public static function register\(/', $code );

			foreach ( $traits as $trait ) {
				$has = $has || (bool) preg_match( '/^\s+use [^;(]*\b' . $trait . '\b[^;(]*;/m', $code );
			}

			if ( ! $has || ! preg_match( '/^namespace ([\w\\\\]+);/m', $code, $ns ) || ! preg_match( '/^(?:final |abstract )?class (\w+)/m', $code, $cl ) ) {
				continue;
			}

			$found[ $ns[1] . '\\' . $cl[1] ] = $path;
		}

		ksort( $found );

		return $found;
	}

	/**
	 * Resolves the targets of `Name::register(` calls in a file to FQCNs.
	 *
	 * @return string[]
	 */
	private function calls_in( string $path ): array {
		$code = (string) file_get_contents( $path );
		preg_match( '/^namespace ([\w\\\\]+);/m', $code, $ns );
		preg_match_all( '/^use ([\w\\\\]+)(?: as (\w+))?;/m', $code, $uses, PREG_SET_ORDER );
		$imports = array();

		foreach ( $uses as $u ) {
			$short             = $u[2] ?? substr( strrchr( '\\' . $u[1], '\\' ), 1 );
			$imports[ $short ] = $u[1];
		}

		$out = array();

		if ( preg_match_all( '/(\\\\?[\w\\\\]+)::register\(/', $code, $m ) ) {
			foreach ( $m[1] as $name ) {
				if ( 0 === strpos( $name, '\\' ) ) {
					$out[] = ltrim( $name, '\\' );
					continue;
				}

				$first = explode( '\\', $name )[0];

				if ( in_array( $name, array( 'self', 'static', 'parent' ), true ) ) {
					continue;
				}

				if ( isset( $imports[ $first ] ) ) {
					$out[] = $imports[ $first ] . substr( $name, strlen( $first ) );
				} else {
					$out[] = ( $ns[1] ?? '' ) . '\\' . $name;
				}
			}
		}

		return $out;
	}

	public function test_every_register_is_called_from_boot(): void {
		$classes = $this->classes_with_register();
		$this->assertGreaterThan( 100, count( $classes ), 'the scan finds the classes' );

		$plugin  = Scan::boot_file();
		$booted  = array();
		$pending = $this->calls_in( $plugin );

		// A booted class may register others from its own register(). That covers a feature's Boot class (Hosting\Boot):
		// Plugin::boot() registers it and it names the register() calls of its stages.
		while ( $pending ) {
			$class = array_shift( $pending );

			if ( isset( $booted[ $class ] ) ) {
				continue;
			}

			$booted[ $class ] = true;

			if ( isset( $classes[ $class ] ) ) {
				$pending = array_merge( $pending, $this->calls_in( $classes[ $class ] ) );
			}
		}

		$missed = array();

		foreach ( array_keys( $classes ) as $class ) {
			if ( isset( $booted[ $class ] ) || isset( self::ALLOWED[ $class ] ) ) {
				continue;
			}

			$missed[] = $class;
		}

		$this->assertSame( array(), $missed, "These classes have register() but are never booted:\n" . implode( "\n", $missed ) );
	}

	public function test_allowlist_entries_are_real_and_still_unbooted(): void {
		$classes = $this->classes_with_register();

		$this->assertIsArray( self::ALLOWED );

		foreach ( array_keys( self::ALLOWED ) as $class ) {
			$this->assertArrayHasKey( $class, $classes, "$class is on the allowlist but has no register()" );
		}
	}

	public function test_feature_boot_classes_are_called_from_the_boot_file_and_their_registers_are_followed(): void {
		$classes = $this->classes_with_register();
		$boots   = array_filter( array_keys( $classes ), static fn( $class ) => 1 === preg_match( '/^BeltoftServices\\\\\w+\\\\Boot$/', $class ) );
		$plugin  = $this->calls_in( Scan::boot_file() );

		foreach ( $boots as $boot ) {
			$this->assertContains( $boot, $plugin, $boot . ' is a feature Boot class that the boot file does not register.' );
			$this->assertNotEmpty( $this->calls_in( $classes[ $boot ] ), $boot . ' names no register() of its stages.' );
		}
	}
}
