<?php
/**
 * The feature sets of the MCP server, and the one place that reads them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * The filter beltoft_services_mcp_toolsets collects the class names of the ToolSet implementations (a feature adds its
 * McpTools in its register()). Definitions::all(), the tables of ActionMap, Tools (run, risky) and Irreversible (summary)
 * read the sets only here. The core never names a feature: a tool of a set that is not registered does not exist.
 */
final class ToolSets {

	/**
	 * The filter.
	 */
	public const FILTER = 'beltoft_services_mcp_toolsets';

	/**
	 * The registered sets, in the order of the filter.
	 *
	 * @return string[] Class names.
	 */
	public static function all(): array {
		$out = array();

		foreach ( (array) apply_filters( self::FILTER, array() ) as $class ) {
			if ( is_string( $class ) && ! in_array( $class, $out, true ) && is_a( $class, ToolSet::class, true ) ) {
				$out[] = $class;
			}
		}

		return $out;
	}

	/**
	 * The tools of every set.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		$out = array();

		foreach ( self::all() as $set ) {
			$out = array_merge( $out, $set::tools() );
		}

		return $out;
	}

	/**
	 * One table of ActionMap, put together from the sets.
	 *
	 * @param string $table routes, actions, excluded, capabilities or capabilities_excluded.
	 * @return array<string, mixed>
	 */
	public static function table( string $table ): array {
		$out = array();

		foreach ( self::all() as $set ) {
			$out = array_merge( $out, (array) call_user_func( array( $set, $table ) ) );
		}

		return $out;
	}

	/**
	 * The set that owns a tool.
	 *
	 * @param string $name Tool.
	 * @return string|null Class name.
	 */
	public static function owner( string $name ): ?string {
		foreach ( self::all() as $set ) {
			if ( isset( $set::tools()[ $name ] ) ) {
				return $set;
			}
		}

		return null;
	}

	/**
	 * Runs a tool of a set.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		$set = self::owner( $name );

		return null === $set
			? new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) )
			: $set::run( $name, $args, $user_id );
	}

	/**
	 * The summary of a set's own, or null when no set has one.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		$set = self::owner( $name );

		return null === $set ? null : $set::summary( $name, $args, $user_id );
	}

	/**
	 * Does a set say this call needs no confirmation, whatever the tool asks (an order that only gives a link)?
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function exempt_from_confirmation( string $name, array $args ): bool {
		foreach ( self::all() as $set ) {
			if ( false === $set::needs_confirmation( $name, $args ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does any set say this call is worse than the tool usually is?
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function risky( string $name, array $args ): bool {
		foreach ( self::all() as $set ) {
			if ( true === $set::risky( $name, $args ) ) {
				return true;
			}
		}

		return false;
	}
}
