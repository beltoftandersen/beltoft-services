<?php
/**
 * The MCP server and the OpenAPI document with the hosting tool set not registered.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Api\OpenApi;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Hosting\McpTools;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\MoneyTools;
use BeltoftServices\Mcp\Told;
use BeltoftServices\Mcp\ToolSets;
use BeltoftServices\Tests\Mcp\McpTestCase;

require_once BELTOFT_SERVICES_TESTS_DIR . '/Mcp/McpTestCase.php';

/**
 * The central lists (order, money, told) name hosting tools by id and tolerate them being absent.
 */
final class McpAbsentTest extends McpTestCase {

	private const IDS = array( 'hosting_info', 'hosting_usage', 'list_hosting_backups', 'add_site_domain', 'set_php_version', 'clear_site_cache', 'create_hosting_backup', 'restore_hosting_backup', 'delete_site' );

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	private function without_hosting(): void {
		add_filter(
			ToolSets::FILTER,
			static function ( $sets ) {
				return array_values( array_diff( (array) $sets, array( McpTools::class ) ) );
			},
			99
		);
	}

	public function test_with_hosting_the_nine_tools_are_listed_in_the_order_list(): void {
		$names = array_keys( Definitions::all() );

		foreach ( self::IDS as $id ) {
			$this->assertContains( $id, $names, $id );
		}
	}

	public function test_without_hosting_no_hosting_tool_exists_and_the_rest_keeps_its_order(): void {
		$before = array_keys( Definitions::all() );
		$this->without_hosting();
		$after = array_keys( Definitions::all() );

		$this->assertSame( array_values( array_diff( $before, self::IDS ) ), $after );
		$this->assertNull( ToolSets::owner( 'delete_site' ) );
	}

	public function test_without_hosting_listing_calling_and_the_api_document_work(): void {
		$this->without_hosting();
		$server = $this->server();
		$user   = $this->owner( $server );
		$token  = $this->oauth( $user, Tokens::FULL );
		$names  = array_column( $this->rpc( 'tools/list', array(), $token )['result']['tools'], 'name' );

		$this->assertSame( array(), array_values( array_intersect( $names, self::IDS ) ) );
		$this->assertContains( 'server_status', $names );

		$ok = $this->tool( 'list_services', array(), $token );
		$this->assertFalse( $ok['isError'] );

		// A hosting tool that is not there is refused like any unknown tool.
		$gone = $this->rpc( 'tools/call', array( 'name' => 'delete_site', 'arguments' => array( 'id' => 1, 'site' => 'x' ) ), $token );
		$this->assertTrue( isset( $gone['error'] ) || ! empty( $gone['result']['isError'] ) );

		// The money check and the mail phrase need no registered tool.
		$this->assertFalse( MoneyTools::is_money( 'delete_site' ) );
		$this->assertIsString( Told::phrase( 'delete_site', array( 'site' => 'x' ) ) );

		$doc = json_decode( (string) wp_json_encode( OpenApi::document() ), true );
		$this->assertIsArray( $doc['paths'] );
	}
}
