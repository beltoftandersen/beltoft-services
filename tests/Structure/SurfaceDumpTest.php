<?php
/**
 * Helper for refactors that must not change behaviour: dumps the plugin's outside surface to files, so a tree before and a
 * tree after can be compared with diff. Runs only with SURFACE_DUMP=<directory>.
 *
 * Writes rest-routes.txt (every route of the plugin's namespaces: methods and arguments), openapi.json (the OpenAPI document),
 * hooks.txt (every hook, priority and callback method, no class names) and bricks.txt (the elements handed to Bricks, in order).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

/**
 * Not an assertion test.
 */
final class SurfaceDumpTest extends \WP_UnitTestCase {

	private function dir(): string {
		$dir = (string) getenv( 'SURFACE_DUMP' );

		if ( '' === $dir ) {
			$this->markTestSkipped( 'Only runs with SURFACE_DUMP=<directory>.' );
		}

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		return rtrim( $dir, '/' );
	}

	private function put( string $dir, string $name, string $text ): void {
		$this->assertNotFalse( file_put_contents( $dir . '/' . $name, $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * A value without callables or objects, stable between trees.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function plain( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->plain( $v );
			}
			return $out;
		}

		if ( is_object( $value ) || ( is_string( $value ) && false !== strpos( $value, 'BeltoftServices\\' ) ) ) {
			return '(callable)';
		}

		return $value;
	}

	private function routes_text(): string {
		$lines = array();
		$all   = rest_get_server()->get_routes();
		ksort( $all );

		foreach ( $all as $route => $handlers ) {
			if ( false === stripos( $route, 'beltoft' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$methods = array_keys( (array) ( $handler['methods'] ?? array() ) );
				sort( $methods );
				$args = $this->plain( (array) ( $handler['args'] ?? array() ) );
				ksort( $args );
				$lines[] = $route . ' [' . implode( ',', $methods ) . '] args=' . wp_json_encode( $args ) . ' perm=' . ( isset( $handler['permission_callback'] ) ? 'yes' : 'no' ) . ' show_in_index=' . wp_json_encode( $handler['show_in_index'] ?? null );
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	private function hooks_text(): string {
		global $wp_filter;
		$lines = array();
		$names = array_keys( $wp_filter );
		sort( $names );

		foreach ( $names as $name ) {
			foreach ( $wp_filter[ $name ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];

					if ( is_array( $fn ) ) {
						$label = 'method:' . $fn[1];
					} elseif ( is_string( $fn ) ) {
						$label = 'function:' . $fn;
					} else {
						$label = 'closure';
					}

					$lines[] = $name . ' ' . $priority . ' ' . $label . ' args=' . $callback['accepted_args'];
				}
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	private function bricks_text(): string {
		if ( ! class_exists( '\Bricks\Element', false ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
				'namespace Bricks {
					class Element {
						public $settings = array();
						public $controls = array();
						public $control_groups = array();
						public function render_attributes( $key ) { return \'class="brxe-test"\'; }
					}
					class Elements {
						public static $registered = array();
						public static function register_element( $file, $name = "", $class = "" ) { self::$registered[] = array( $file, $name, $class ); }
					}
				}'
			);
		}

		global $wp_filter;
		// What init priority 11 does, for the plugin's Bricks callbacks only, in the order WordPress runs them.
		foreach ( $wp_filter['init']->callbacks[11] ?? array() as $callback ) {
			$fn = $callback['function'];

			if ( is_array( $fn ) && is_string( $fn[0] ) && 0 === strpos( $fn[0], 'BeltoftServices\\' ) && false !== strpos( $fn[0], 'Bricks' ) ) {
				call_user_func( $fn );
			}
		}

		$lines = array();

		foreach ( \Bricks\Elements::$registered as $row ) {
			$lines[] = $row[1] . ' file=' . basename( $row[0] ) . ' class=' . substr( strrchr( '\\' . $row[2], '\\' ), 1 );
		}

		return implode( "\n", $lines ) . "\n";
	}

	public function test_dump(): void {
		$dir = $this->dir();

		do_action( 'rest_api_init' );
		$this->put( $dir, 'rest-routes.txt', $this->routes_text() );
		$this->put( $dir, 'openapi.json', (string) wp_json_encode( \BeltoftServices\Api\OpenApi::document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$this->put( $dir, 'hooks.txt', $this->hooks_text() );
		$this->put( $dir, 'bricks.txt', $this->bricks_text() );
		$this->put( $dir, 'hooks-after-bricks.txt', $this->hooks_text() );
	}
}
