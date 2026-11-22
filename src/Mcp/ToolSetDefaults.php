<?php
/**
 * The parts of ToolSet that most feature sets do not need.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Use it in a McpTools class and write only what the feature has. register() joins the set to the server (Plugin::boot
 * calls it).
 */
trait ToolSetDefaults {

	/**
	 * Joins the set to the MCP server.
	 */
	public static function register(): void {
		add_filter( ToolSets::FILTER, array( static::class, 'add' ) );
	}

	/**
	 * The filter beltoft_services_mcp_toolsets.
	 *
	 * @param mixed $sets Class names so far.
	 * @return array<int, string>
	 */
	public static function add( $sets ): array {
		$sets   = is_array( $sets ) ? $sets : array();
		$sets[] = static::class;

		return $sets;
	}

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
	public static function needs_confirmation( string $name, array $args ): ?bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- default.
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
