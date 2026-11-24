<?php
/**
 * With hosting there, the central MCP lists name exactly the registered tools (the strict half of tests/Mcp/ToolSetsTest.php and MoneyToolsTest.php).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\MoneyTools;
use BeltoftServices\Tests\TestCase;

/**
 * The lists in src/Mcp name hosting tools by id.
 */
final class McpListsTest extends TestCase {

	public function test_the_order_list_names_every_tool_once_and_nothing_else(): void {
		$names = array_keys( Definitions::all() );

		$this->assertSame( Definitions::ORDER, $names, 'tools/list shows the tools in the order of the list, and every tool is in it.' );
	}

	public function test_every_listed_money_tool_exists(): void {
		$listed = array_merge( MoneyTools::MONEY, MoneyTools::NO_MONEY );

		$this->assertSame( array(), array_values( array_diff( $listed, array_keys( Definitions::all() ) ) ), 'A tool that does not exist any more.' );
	}

	public function test_deleting_a_hosting_backup_has_no_tool_and_says_why(): void {
		$this->assertArrayNotHasKey( 'delete_hosting_backup', ActionMap::capabilities() );
		$this->assertStringStartsWith( 'never', ActionMap::capabilities_excluded()['delete_hosting_backup'] );
	}
}
