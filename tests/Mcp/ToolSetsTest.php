<?php
/**
 * The registry of the MCP tools: sets from the filter, the order of tools/list, the secret allowlist.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Secrets;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;
use BeltoftServices\Mcp\ToolSets;
use BeltoftServices\Mcp\Tools;

require_once __DIR__ . '/McpTestCase.php';

/**
 * A set that a test registers.
 */
final class ProbeToolSet implements ToolSet {

	use ToolSetDefaults;

	public static function tools(): array {
		return array(
			'zz_probe' => Definitions::act( 'Probe.', Definitions::by_id(), false ),
		);
	}

	public static function routes(): array {
		return array( 'zz_probe' => array( 'GET #^/probe$#D' ) );
	}

	public static function actions(): array {
		return array( 'zz_probe_action' => 'zz_probe' );
	}

	public static function run( string $name, array $args, int $user_id ) {
		return array( 'probe' => (int) $args['id'], 'user' => $user_id );
	}

	public static function needs_confirmation( string $name, array $args ): ?bool {
		return 'zz_probe' === $name && ! empty( $args['link_only'] ) ? false : null;
	}
}

final class ToolSetsTest extends McpTestCase {

	public function tear_down(): void {
		remove_all_filters( ToolSets::FILTER );
		parent::tear_down();
	}

	public function test_the_order_list_names_every_tool_once_and_nothing_else(): void {
		$names = array_keys( Definitions::all() );

		$this->assertSame( array_values( array_unique( Definitions::ORDER ) ), Definitions::ORDER, 'No name twice.' );
		// The order list also names the tools of an optional feature (hosting), which exist only when it is there: what is
		// registered is in the list, in its order. That the list names nothing else is checked by tests/Hosting/McpListsTest.php.
		$this->assertSame( array(), array_values( array_diff( $names, Definitions::ORDER ) ), 'Every tool is in the order list.' );
		$this->assertSame( $names, array_values( array_intersect( Definitions::ORDER, $names ) ), 'tools/list shows the tools in the order of the list.' );
	}

	public function test_the_secret_tools_are_the_platform_list(): void {
		$secret = array();

		foreach ( Definitions::all() as $name => $tool ) {
			if ( ! empty( $tool['secret'] ) ) {
				$secret[] = $name;
			}
		}

		foreach ( $secret as $name ) {
			$this->assertContains( $name, Secrets::NAMES, $name . ' shows a secret and is not in Secrets::NAMES.' );
		}

		foreach ( Secrets::NAMES as $name ) {
			$this->assertArrayHasKey( $name, Definitions::all(), $name );
		}
	}

	public function test_a_registered_set_adds_a_tool_its_tables_and_its_run(): void {
		$this->assertArrayNotHasKey( 'zz_probe', Definitions::all() );
		$this->assertFalse( Tools::exists( 'zz_probe' ) );
		// Only the probe: the real sets are left out of the list, and the tools of their features do not exist for this test.
		remove_all_filters( ToolSets::FILTER );

		add_filter(
			ToolSets::FILTER,
			static function ( $sets ) {
				$sets[] = ProbeToolSet::class;
				$sets[] = 'Not\\A\\Class';
				$sets[] = ProbeToolSet::class;

				return $sets;
			}
		);

		$this->assertSame( array( ProbeToolSet::class ), ToolSets::all() );
		$this->assertTrue( Tools::exists( 'zz_probe' ) );
		$this->assertSame( 'zz_probe', array_key_last( Definitions::all() ), 'A tool outside the order list comes last.' );
		$this->assertSame( 'zz_probe', ActionMap::actions()['zz_probe_action'] );
		$this->assertTrue( ActionMap::permits( 'zz_probe', 'GET', '/probe' ) );
		$this->assertFalse( ActionMap::permits( 'zz_probe', 'POST', '/probe' ) );
		$this->assertTrue( ActionMap::permits( 'zz_probe', 'POST', '/resources/1/actions/zz_probe_action' ) );
		$this->assertFalse( ActionMap::permits( 'other', 'POST', '/resources/1/actions/zz_probe_action' ) );
		$this->assertSame( ProbeToolSet::class, ToolSets::owner( 'zz_probe' ) );
		$this->assertNull( ToolSets::summary( 'zz_probe', array(), 1 ) );
		$this->assertFalse( ToolSets::risky( 'zz_probe', array() ) );

		$user = (int) self::factory()->user->create();
		$tok  = $this->oauth( $user, Tokens::ACTIONS );
		$this->assertSame( array( 'probe' => 7, 'user' => $user ), $this->json( $this->tool( 'zz_probe', array( 'id' => 7 ), $tok ) ) );
	}

	public function test_a_set_can_say_a_call_needs_no_confirmation(): void {
		remove_all_filters( ToolSets::FILTER );
		add_filter(
			ToolSets::FILTER,
			static function ( $sets ) {
				$sets[] = ProbeToolSet::class;

				return $sets;
			}
		);

		$this->assertTrue( ToolSets::exempt_from_confirmation( 'zz_probe', array( 'link_only' => true ) ) );
		$this->assertFalse( ToolSets::exempt_from_confirmation( 'zz_probe', array() ) );
		$this->assertFalse( ToolSets::exempt_from_confirmation( 'other', array( 'link_only' => true ) ) );
		$this->assertFalse( Tools::confirmation_needed( 'zz_probe', array( 'id' => 1 ) ), 'An ordinary action of the probe asks for none.' );
	}
}
