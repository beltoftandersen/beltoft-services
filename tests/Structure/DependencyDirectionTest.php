<?php
/**
 * Dependency directions between the plugin's layers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Core (src/*.php) must not depend on feature namespaces; Mail not on Proxmox; Proxmox not on Mcp;
 * Domains not on Proxmox, Mail or Mcp. Today's violations are listed in ALLOWED so the test passes now
 * and stops new ones (Plugin.php, the composition root, is exempt); remove an entry when the leak is fixed (a stale entry fails the test).
 *
 * FEATURES lists every feature folder (widened 2026-11 to Hosting, Ssl, Licences, Credit, Card, Admin and Cli; the widening found no new leak).
 * Billing and Emails stay out on purpose: they are core-side infrastructure (core imports Billing\CartFlow and the email registry).
 */
class DependencyDirectionTest extends TestCase {

	private const FEATURES = array( 'Proxmox', 'Domains', 'Mail', 'Mcp', 'Account', 'Usage', 'Http', 'Oauth', 'Api', 'Bricks', 'Hosting', 'Ssl', 'Licences', 'Credit', 'Card', 'Admin', 'Cli' );

	/**
	 * Layer => layers it must not use.
	 */
	private const FORBIDDEN = array(
		'core'    => self::FEATURES,
		'Mail'    => array( 'Proxmox', 'Hosting' ),
		'Ssl'     => array( 'Mail' ),
		'Proxmox' => array( 'Mcp' ),
		'Domains' => array( 'Proxmox', 'Mail', 'Mcp' ),
	);

	/**
	 * Layer => single classes it must not use (finer than FORBIDDEN): Proxmox shares ServerLinks with Account, never Servers.
	 * An entry ending in a backslash is a namespace prefix: Hosting reaches into Proxmox only through Proxmox\Pve, so every
	 * other Proxmox sub-namespace is out of bounds for it.
	 */
	private const FORBIDDEN_CLASSES = array(
		'Proxmox' => array( 'Account\\Servers' ),
		'Hosting' => array(
			'Proxmox\\Vm\\',
			'Proxmox\\Disk\\',
			'Proxmox\\Snapshot\\',
			'Proxmox\\Backup\\',
			'Proxmox\\Network\\',
			'Proxmox\\Console\\',
			'Proxmox\\Db\\',
			'Proxmox\\Addon\\',
		),
	);

	/**
	 * Today's violations, "relative/file.php -> Target\Class". Shrink only.
	 *
	 * @return string[]
	 */
	private static function allowed(): array {
		return require __DIR__ . '/dependency-allowlist.php';
	}

	/**
	 * @return array<string, true> "file -> class" => true
	 */
	private function violations(): array {
		$root = dirname( __DIR__, 2 ) . '/src';
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$rel    = substr( $file->getPathname(), strlen( $root ) + 1 );
			$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$ns     = '';
			$import = array();
			$names  = array();
			$in_use = false;
			$count  = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				$t = $tokens[ $i ];

				if ( ! is_array( $t ) ) {
					continue;
				}

				if ( T_NAMESPACE === $t[0] && '' === $ns ) { // The declaration only: `Routes::NAMESPACE` is the same token.
					for ( $j = $i + 1; $j < $count; $j++ ) {
						if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_NAME_QUALIFIED, T_STRING ), true ) ) {
							$ns = $tokens[ $j ][1];
							$i  = $j;
							break;
						}
					}
					continue;
				}

				if ( T_NAME_QUALIFIED !== $t[0] && T_NAME_FULLY_QUALIFIED !== $t[0] && T_STRING !== $t[0] ) {
					continue;
				}

				// Top-level "use X\Y;" / "use X\Y as Z;" statements (not closures or traits).
				$prev = $i - 1;

				while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
					--$prev;
				}

				$is_use = $prev >= 0 && is_array( $tokens[ $prev ] ) && T_USE === $tokens[ $prev ][0] && '' !== $ns && $this->at_top_level( $tokens, $prev );

				if ( $is_use ) {
					$full = ltrim( $t[1], '\\' );
					$as   = substr( strrchr( '\\' . $full, '\\' ), 1 );

					for ( $j = $i + 1; $j < $count && ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE === $tokens[ $j ][0] || T_AS === $tokens[ $j ][0] || ( isset( $tokens[ $j - 1 ] ) && is_array( $tokens[ $j - 1 ] ) && T_AS === $tokens[ $j - 1 ][0] ) ) ; $j++ ) {
						if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
							$as = $tokens[ $j ][1];
							break;
						}
						if ( ! is_array( $tokens[ $j ] ) ) {
							break;
						}
					}

					$import[ $as ] = $full;
					$names[]       = $full;
					continue;
				}

				if ( T_STRING === $t[0] ) {
					continue;
				}

				if ( T_NAME_FULLY_QUALIFIED === $t[0] ) {
					$names[] = ltrim( $t[1], '\\' );
					continue;
				}

				$first   = explode( '\\', $t[1] )[0];
				$names[] = isset( $import[ $first ] ) ? $import[ $first ] . substr( $t[1], strlen( $first ) ) : $ns . '\\' . $t[1];
			}

			// Plugin.php is the composition root: it exists to boot every feature.
			if ( 'Plugin.php' === $rel ) {
				continue;
			}

			$from = $this->layer( $ns . '\\X' );

			if ( ! isset( self::FORBIDDEN[ $from ] ) && ! isset( self::FORBIDDEN_CLASSES[ $from ] ) ) {
				continue;
			}

			foreach ( array_unique( $names ) as $name ) {
				$to = $this->layer( $name );

				if ( in_array( $to, self::FORBIDDEN[ $from ] ?? array(), true ) || $this->in_forbidden_classes( $this->class_of( $name ), self::FORBIDDEN_CLASSES[ $from ] ?? array() ) ) {
					$out[ $rel . ' -> ' . $this->class_of( $name ) ] = true;
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Whether a `use` token is a namespace import (not a closure use or a trait use inside a class).
	 */
	private function at_top_level( array $tokens, int $at ): bool {
		$depth = 0;

		for ( $i = 0; $i < $at; $i++ ) {
			if ( '{' === $tokens[ $i ] || ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $tokens[ $i ] ) {
				--$depth;
			}
		}

		return 0 === $depth;
	}

	/**
	 * "core" for BeltoftServices\Foo, the first sub-namespace segment otherwise, '' outside the plugin.
	 */
	private function layer( string $name ): string {
		if ( 0 !== strpos( $name, 'BeltoftServices\\' ) ) {
			return '';
		}

		$parts = explode( '\\', $name );

		// BeltoftServices\Foo (class in the root namespace) or the root namespace itself.
		if ( count( $parts ) <= 2 ) {
			return 'core';
		}

		return $parts[1];
	}

	/**
	 * Whether a class is listed, exactly or by namespace prefix (an entry that ends in a backslash).
	 *
	 * @param string[] $list Entries.
	 */
	private function in_forbidden_classes( string $class_name, array $list ): bool {
		foreach ( $list as $entry ) {
			if ( $class_name === $entry || ( '\\' === substr( $entry, -1 ) && 0 === strpos( $class_name, $entry ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function class_of( string $name ): string {
		return substr( $name, strlen( 'BeltoftServices\\' ) );
	}

	public function test_no_forbidden_dependency_directions(): void {
		$found   = array_keys( $this->violations() );
		$allowed = self::allowed();
		$new     = array_values( array_diff( $found, $allowed ) );
		$stale   = array_values( array_diff( $allowed, $found ) );

		$this->assertSame( array(), $new, sprintf( 'New forbidden dependencies (allowlist has %d entries):', count( $allowed ) ) . "\n" . implode( "\n", $new ) );
		$this->assertSame( array(), $stale, 'Allowlist entries that no longer exist, remove them:' . "\n" . implode( "\n", $stale ) );
	}

	public function test_allowlist_size(): void {
		$this->addToAssertionCount( 1 );
		fwrite( STDERR, sprintf( "\n[dependency allowlist: %d entries]\n", count( self::allowed() ) ) );
	}

	/**
	 * Helper for regenerating the allowlist: run with DEP_DUMP=1.
	 */
	public function test_dump_when_asked(): void {
		if ( ! getenv( 'DEP_DUMP' ) ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		file_put_contents( getenv( 'DEP_DUMP' ), implode( "\n", array_keys( $this->violations() ) ) . "\n" );
		$this->addToAssertionCount( 1 );
	}
}
