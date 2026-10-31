<?php
/**
 * The levels of a connection and the gate every tool call goes through.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\Tools;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Level and gate tests.
 */
final class LevelsTest extends McpTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	public function test_the_three_levels_list_and_call_what_their_rank_allows(): void {
		$server = $this->server();
		$user   = $this->owner( $server );

		foreach ( array( Tokens::READ => 'read', Tokens::ACTIONS => 'actions', Tokens::FULL => 'full' ) as $level => $label ) {
			$token = $this->oauth( $user, $level );
			$names = array_column( $this->rpc( 'tools/list', array(), $token )['result']['tools'], 'name' );

			$this->assertSame( array_column( Tools::listing( $level ), 'name' ), $names, $label );
			$this->assertSame( Tokens::READ === $level, ! in_array( 'reboot', $names, true ), $label );

			// A tool above the level is refused when it is guessed, whatever the arguments.
			$result = $this->tool( 'reboot', array( 'id' => $server->id() ), $token );
			$this->assertSame( Tokens::READ === $level, $result['isError'], $label );
		}
	}

	public function test_a_connection_is_never_above_its_grant_and_a_personal_token_is_never_full(): void {
		$server = $this->server();
		$user   = $this->owner( $server );

		$this->assertWPError( Tokens::create( $user, 'Script', Tokens::FULL ) );

		// A stored personal scope that is not ours gives no access at all.
		$made = Tokens::create( $user, 'Script', Tokens::ACTIONS );
		$meta = get_user_meta( $user, Tokens::META, true );
		$meta[ array_key_first( $meta ) ] = Tokens::FULL;
		update_user_meta( $user, Tokens::META, $meta );
		$this->assertSame( 401, $this->post( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), array( $user, $made['password'] ) )->get_status() );
	}

	public function test_arguments_are_checked_deeply_before_a_tool_runs(): void {
		$domain = $this->domain( self::factory()->user->create() );
		$user   = (int) $domain->license()['user_id'];
		$token  = $this->oauth( $user, Tokens::ACTIONS );
		$base   = array( 'id' => $domain->id(), 'type' => 'A', 'name' => 'www' );
		$writes = fn() => count( array_filter( $this->dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] ) );
		$before = $writes();

		$cases = array(
			'a record without a value'      => array( 'records' => array( array( 'priority' => 1 ) ) ),
			'a record that is a string'     => array( 'records' => array( '192.0.2.1' ) ),
			'a record with the wrong type'  => array( 'records' => array( array( 'value' => 5 ) ) ),
			'a record with an unknown key'  => array( 'records' => array( array( 'value' => '192.0.2.1', 'delete_zone' => true ) ) ),
			'records that are an object'    => array( 'records' => array( 'value' => '192.0.2.1' ) ),
			'too many records'              => array( 'records' => array_fill( 0, 101, array( 'value' => '192.0.2.1' ) ) ),
			'a ttl that is a string'        => array( 'records' => array( array( 'value' => '192.0.2.1' ) ), 'ttl' => '300' ),
			'a value that is too long'      => array( 'records' => array( array( 'value' => str_repeat( 'a', 501 ) ) ) ),
		);

		foreach ( $cases as $label => $extra ) {
			$result = $this->tool( 'dns_upsert_record', $base + $extra, $token );
			$this->assertTrue( $result['isError'], $label );
		}

		$this->assertSame( $before, $writes(), 'Nothing was written.' );

		$this->assertStringContainsString( 'required', $this->text( $this->tool( 'dns_upsert_record', $base + array( 'records' => array( array( 'priority' => 1 ) ) ), $token ) ) );
		$this->assertStringContainsString( 'records[0]', $this->text( $this->tool( 'dns_upsert_record', $base + array( 'records' => array( 'x' ) ), $token ) ), 'The message names the argument.' );

		// Booleans, enums and limits of other tools.
		$server = $this->server( $user );
		foreach ( array(
			array( 'set_usage_alerts', array( 'id' => $server->id(), 'on' => 'yes' ) ),
			array( 'set_usage_alerts', array( 'id' => $server->id(), 'on' => 1 ) ),
			array( 'private_network', array( 'id' => $server->id(), 'action' => 'toggle' ) ),
			array( 'rename_server', array( 'id' => $server->id(), 'name' => str_repeat( 'a', 64 ) ) ),
			array( 'set_firewall', array( 'id' => $server->id(), 'on' => true, 'rules' => array( array( 'proto' => 'sctp' ) ) ) ),
			array( 'set_firewall', array( 'id' => $server->id(), 'on' => true, 'rules' => array( array( 'proto' => 'tcp', 'from' => 0 ) ) ) ),
			array( 'list_orders', array( 'limit' => 0 ) ),
		) as $case ) {
			$this->assertTrue( $this->tool( $case[0], $case[1], $token )['isError'], $case[0] . ' ' . wp_json_encode( $case[1] ) );
		}
	}

	public function test_a_tool_that_is_not_a_service_call_cannot_be_pointed_at_a_route_it_is_not_named_for(): void {
		$domain = $this->domain( self::factory()->user->create() );
		$token  = $this->oauth( (int) $domain->license()['user_id'], Tokens::ACTIONS );

		// The allow-list is checked in Tools::route() and Tools::action(): called outside a tool they answer nothing.
		$this->assertWPError( Tools::route( 'POST', '/resources/' . $domain->id() . '/actions/unlock', array(), array( 'args' => array() ), $domain->id(), 'x' ) );
		$this->assertWPError( Tools::action( $domain->id(), 'unlock', array(), 'x' ) );
		$this->assertEmpty( \BeltoftLicenses\Events::for_license( (int) $domain->license()['id'] ), 'A refused route writes nothing.' );
		$this->assertNotEmpty( $token );
	}

	public function test_no_read_tool_result_holds_anything_secret(): void {
		$db      = $this->database();
		$user    = $this->owner( $db );
		$server  = $this->server( $user );
		$domain  = $this->domain( $user );
		$token   = $this->oauth( $user, Tokens::FULL );
		$results = array();

		foreach ( Tools::listing( Tokens::READ ) as $tool ) {
			$required = (array) ( $tool['inputSchema']['required'] ?? array() );

			if ( in_array( 'id', $required, true ) ) {
				foreach ( array( $db, $server, $domain ) as $r ) {
					$results[] = $this->text( $this->tool( $tool['name'], $this->dummy( $tool['inputSchema'], $r->id() ), $token ) );
				}
			} elseif ( ! $required ) {
				$results[] = $this->text( $this->tool( $tool['name'], array(), $token ) );
			}
		}

		$all = implode( "\n", $results );
		$this->assertGreaterThan( 20, count( $results ) );

		foreach ( array( self::PASSWORD, 'BEGIN CERTIFICATE', 'PRIVATE KEY', 'MIIBsz', (string) $db->get( 'db_reveal' ), 'authcode', 'ticket', '_wpnonce', 'wp_rest', 'client_secret', 'access_token', 'refresh_token', 'bso_' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $all );
		}

		// Nothing password-shaped: a long mixed-case token that is not a name or a date.
		$this->assertDoesNotMatchRegularExpression( '/"[A-Za-z0-9]{28,}"/', $all );
	}

	public function test_reads_that_are_not_a_route_have_their_own_limit(): void {
		$db    = $this->database();
		$token = $this->oauth( $this->owner( $db ) );

		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertFalse( $this->tool( 'service_details', array( 'id' => $db->id() ), $token )['isError'] );
		}

		$this->assertTrue( $this->tool( 'service_details', array( 'id' => $db->id() ), $token )['isError'] );
		$this->assertFalse( $this->tool( 'get_account', array(), $token )['isError'], 'Each tool is counted apart.' );
	}
}
