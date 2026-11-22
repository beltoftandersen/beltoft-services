<?php
/**
 * The parts of ToolSet that most feature sets do not need.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Use it in a McpTools class and write only what the feature has.
 */
trait ToolSetDefaults {

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array();
	}

	/**
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array();
	}

	/**
	 * @return array<string, string>
	 */
	public static function excluded(): array {
		return array();
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array();
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities_excluded(): array {
		return array();
	}

	/**
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		return null;
	}

	/**
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function risky( string $name, array $args ): ?bool {
		return null;
	}
}
