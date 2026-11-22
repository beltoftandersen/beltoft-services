<?php
/**
 * What a feature gives the MCP server: its tools and everything that belongs to them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * A feature's McpTools class implements this and joins the server through the filter beltoft_services_mcp_toolsets (see
 * ToolSets). All methods are static, like Drivers\Driver: nothing is stored, a class name is the whole registration.
 *
 * tools() has the entries of Definitions::all() for its own tools (use Definitions::read(), act() and full()). The tables
 * are the pieces of ActionMap that belong to the tools. A tool is owned by the set whose tools() names it: Tools::run() asks
 * that set to run it (run()), and a call that needs the customer's confirmation asks it for the summary (summary()). The
 * order tools/list shows is Definitions::ORDER, not the order of the sets.
 *
 * A set may not add a tool that shows a secret: Secrets::NAMES is the platform's own list, and a test holds the two
 * together.
 */
interface ToolSet {

	/**
	 * The tools of this feature, name => entry as in Definitions::all().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array;

	/**
	 * Tool => the REST routes it may call (ActionMap::routes()).
	 *
	 * @return array<string, string[]>
	 */
	public static function routes(): array;

	/**
	 * Action name => the tool that posts it (ActionMap::actions()).
	 *
	 * @return array<string, string>
	 */
	public static function actions(): array;

	/**
	 * Action name => why no tool runs it (ActionMap::excluded()).
	 *
	 * @return array<string, string>
	 */
	public static function excluded(): array;

	/**
	 * Capability => the tool that does it (ActionMap::capabilities()).
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array;

	/**
	 * Capability => why it has no tool (ActionMap::capabilities_excluded()).
	 *
	 * @return array<string, string>
	 */
	public static function capabilities_excluded(): array;

	/**
	 * Does the work of one of the tools of tools(). The confirmation, if the tool needs one, was accepted before this.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id );

	/**
	 * The summary of a call that needs a confirmation, or null when the platform's own summary of the action is the one
	 * (Irreversible::summary() then builds it from the action the tool posts).
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id );

	/**
	 * Does this call make things worse than the tool usually does (needs Full access and a confirmation)? Null when the
	 * set has no opinion.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments (not yet validated).
	 */
	public static function risky( string $name, array $args ): ?bool;
}
