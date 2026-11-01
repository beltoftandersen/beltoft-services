<?php
/**
 * Every class with a public static register() is actually booted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

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
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$code = (string) file_get_contents( $file->getPathname() );

			if ( ! preg_match( '/public static function register\(/', $code ) || ! preg_match( '/^namespace ([\w\\\\]+);/m', $code, $ns ) || ! preg_match( '/^(?:final |abstract )?class (\w+)/m', $code, $cl ) ) {
				continue;
			}

			$found[ $ns[1] . '\\' . $cl[1] ] = $file->getPathname();
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

		$plugin  = dirname( __DIR__, 2 ) . '/src/Plugin.php';
		$booted  = array();
		$pending = $this->calls_in( $plugin );

		// A booted class may register others from its own register().
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
}
