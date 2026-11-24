<?php
/**
 * Every MCP tool is classified as returning or moving money, or not.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\MoneyTools;
use BeltoftServices\Tests\TestCase;

/**
 * A new tool has to be put in one of the two lists of Mcp\MoneyTools.
 */
final class MoneyToolsTest extends TestCase {

	public function test_every_tool_is_in_exactly_one_list(): void {
		$all     = array_keys( Definitions::all() );
		$listed  = array_merge( MoneyTools::MONEY, MoneyTools::NO_MONEY );
		$missing = array_values( array_diff( $all, $listed ) );
		
		$this->assertSame( array(), $missing, 'A new tool: add it to MONEY (it returns or moves an amount) or NO_MONEY in src/Mcp/MoneyTools.php.' );
		// The lists also name the tools of an optional feature (hosting), which exist only when it is there. That a listed tool
		// still exists is checked for every tool by the hosting tests when it is there (tests/Hosting/McpListsTest.php).
		$this->assertSame( array(), array_values( array_intersect( MoneyTools::MONEY, MoneyTools::NO_MONEY ) ) );
		$this->assertCount( count( $listed ), array_unique( $listed ) );
	}
}
