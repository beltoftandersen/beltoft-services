<?php
/**
 * A minimal WP_CLI test double, used only when WP-CLI itself is absent.
 *
 * @package BeltoftServices
 */

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Records what the commands print; error() throws like WP-CLI exits.
		 */
		class WP_CLI {

			/**
			 * Printed lines as array( type, message ).
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public static $lines = array();

			/**
			 * Forgets everything recorded.
			 */
			public static function reset(): void {
				self::$lines = array();
			}

			/**
			 * Records a log line.
			 *
			 * @param string $message Message.
			 */
			public static function log( $message ): void {
				self::$lines[] = array( 'log', (string) $message );
			}

			/**
			 * Records a success line.
			 *
			 * @param string $message Message.
			 */
			public static function success( $message ): void {
				self::$lines[] = array( 'success', (string) $message );
			}

			/**
			 * Records a warning.
			 *
			 * @param string $message Message.
			 */
			public static function warning( $message ): void {
				self::$lines[] = array( 'warning', (string) $message );
			}

			/**
			 * Records an error and throws, as WP-CLI would exit.
			 *
			 * @param string $message Message.
			 * @throws \RuntimeException Always.
			 */
			public static function error( $message ): void {
				self::$lines[] = array( 'error', (string) $message );
				throw new \RuntimeException( (string) $message );
			}

			/**
			 * Everything recorded as one text.
			 */
			public static function text(): string {
				return implode( "\n", array_map( static fn( $line ) => $line[1], self::$lines ) );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		/**
		 * Prints items as JSON or as a plain table into the recorder.
		 *
		 * @param string                           $format Format.
		 * @param array<int, array<string, mixed>> $items  Items.
		 * @param string[]                         $fields Fields.
		 */
		function format_items( $format, $items, $fields ): void {
			if ( 'json' === $format ) {
				\WP_CLI::log( (string) wp_json_encode( $items ) );
				return;
			}

			foreach ( $items as $item ) {
				\WP_CLI::log( implode( "\t", array_map( static fn( $f ) => (string) $item[ $f ], $fields ) ) );
			}
		}
	}
}
