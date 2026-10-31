<?php
/**
 * The two-step confirmation of the MCP tools: a summary first, the tool only with the token of that summary.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Jobs;
use BeltoftServices\Mcp\Confirmations;
use BeltoftServices\Oauth\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Confirmation tests.
 */
final class ConfirmationsTest extends McpTestCase {

	/**
	 * The driver of the last offering().
	 *
	 * @var FakeDriver
	 */
	private $driver;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	/**
	 * A fake-driver server that offers the given actions.
	 *
	 * @param string[] $actions Action names.
	 */
	private function offering( array $actions ): Resource {
		$this->driver                   = $this->fake_driver();
		$this->driver->customer_actions = array_combine( $actions, $actions );
		$row                            = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false ) );

		return Resource::create( $row, $this->driver, array(), array( 'state' => 'ready' ) );
	}

	/**
	 * @return string[]
	 */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', Schema::tables()['confirms'] ), ARRAY_A );
	}

	/**
	 * @param array<string, mixed> $set Columns.
	 */
	private function update_rows( array $set ): void {
		global $wpdb;

		foreach ( $set as $column => $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %d', Schema::tables()['confirms'], $column, $value ) );
		}
	}

	/**
	 * The args of a delete_snapshot call.
	 *
	 * @return array<string, mixed>
	 */
	private function args( Resource $r, string $snapshot = 'abcd1234' ): array {
		return array( 'id' => $r->id(), 'snapshot' => $snapshot );
	}

	public function test_the_first_call_describes_and_changes_nothing(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		$out = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );

		$this->assertTrue( $out['requires_confirmation'] );
		$this->assertStringContainsString( 'Delete the snapshot "abcd1234"', $out['summary'] );
		$this->assertStringContainsString( 'for good', $out['summary'] );
		$this->assertStringStartsWith( Confirmations::PREFIX, $out['confirm'] );
		$this->assertSame( 300, $out['expires_in_seconds'] );
		$this->assertStringContainsString( 'Show the summary to the customer', $out['instruction'] );
		$this->assertStringNotContainsString( '—', wp_json_encode( $out ) );

		// Nothing ran: no job, no call to the driver, no change in the history except the line that says it was asked.
		$this->assertFalse( Jobs::acting( $r->id() ) );
		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );
		$this->assertContains( 'Claude: asked to delete snapshot (waiting for confirmation)', $this->history( $r ) );
		$this->assertNotContains( 'Claude: delete snapshot requested', $this->history( $r ) );
	}

	public function test_the_token_is_stored_as_a_hash_with_its_bindings(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$user  = $this->owner( $r );
		$token = $this->oauth( $user, Tokens::FULL );
		$out   = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );
		$rows  = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( hash( 'sha256', $out['confirm'] ), $rows[0]['token_hash'] );
		$this->assertStringNotContainsString( $out['confirm'], (string) wp_json_encode( $rows ), 'The token itself is not stored.' );
		$this->assertSame( $user, (int) $rows[0]['user_id'] );
		$this->assertSame( 'delete_snapshot', $rows[0]['tool'] );
		$this->assertStringStartsWith( 'oauth_', $rows[0]['grant_id'] );
		$this->assertSame( Confirmations::args_hash( $this->args( $r ) ), $rows[0]['args_hash'] );
		$this->assertSame( 300, (int) $rows[0]['expires_at'] - (int) $rows[0]['created_at'] );
		$this->assertNull( $rows[0]['used_at'] );
	}

	public function test_the_token_runs_the_tool_once_and_a_second_use_does_nothing(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$first = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );

		$done = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertFalse( $done['isError'], $this->text( $done ) );
		$this->assertTrue( $this->json( $done )['requested'] );
		$this->assertTrue( Jobs::acting( $r->id() ) );
		$this->run_jobs();
		$this->assertCount( 1, $this->driver->calls( 'act' ) );
		$this->assertSame( array( 'snapshot_delete', array( 'id' => 'abcd1234' ) ), $this->driver->calls( 'act' )[0]['args'] );
		$this->assertContains( 'Claude: delete snapshot confirmed by the customer', $this->history( $r ) );
		$this->assertContains( 'Claude: delete snapshot requested', $this->history( $r ) );

		$again = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $again['isError'] );
		$this->assertStringContainsString( 'used already', $this->text( $again ) );
		$this->run_jobs();
		$this->assertCount( 1, $this->driver->calls( 'act' ), 'A replay runs nothing.' );
		$this->assertContains( 'Claude: delete snapshot refused (the confirmation was used already)', $this->history( $r ) );
	}

	public function test_a_wrong_or_made_up_token_is_refused_and_nothing_runs(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		foreach ( array( 'bsc_notatoken', 'x', str_repeat( 'a', 300 ), Confirmations::PREFIX . str_repeat( 'A', 43 ) ) as $bad ) {
			$result = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $bad ), $token );
			$this->assertTrue( $result['isError'], $bad );
		}

		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );
		$this->assertFalse( Jobs::acting( $r->id() ) );
	}

	public function test_the_token_is_bound_to_the_exact_arguments_and_the_tool(): void {
		$r     = $this->offering( array( 'snapshot_delete', 'snapshot_rollback' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$first = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );

		// One character different: refused, and the summary of the new call is asked for instead.
		$other = $this->tool( 'delete_snapshot', $this->args( $r, 'abcd1235' ) + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $other['isError'] );
		$this->assertStringContainsString( 'other arguments or another tool', $this->text( $other ) );

		// Another tool with the same arguments.
		$tool = $this->tool( 'rollback_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $tool['isError'] );
		$this->assertStringContainsString( 'other arguments or another tool', $this->text( $tool ) );

		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );

		// The refusals did not spend it: the call it was made for still works.
		$this->assertFalse( $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token )['isError'] );
		$this->run_jobs();
		$this->assertCount( 1, $this->driver->calls( 'act' ) );
	}

	public function test_the_order_of_the_arguments_does_not_matter(): void {
		$this->assertSame( Confirmations::args_hash( array( 'id' => 1, 'snapshot' => 'a', 'x' => array( 'b' => 1, 'a' => 2 ) ) ), Confirmations::args_hash( array( 'x' => array( 'a' => 2, 'b' => 1 ), 'snapshot' => 'a ', 'id' => 1 ) ) );
		$this->assertNotSame( Confirmations::args_hash( array( 'rules' => array( 1, 2 ) ) ), Confirmations::args_hash( array( 'rules' => array( 2, 1 ) ) ), 'A list keeps its order.' );
	}

	public function test_a_token_of_another_connection_or_customer_is_refused(): void {
		$r      = $this->offering( array( 'snapshot_delete' ) );
		$user   = $this->owner( $r );
		$first  = $this->oauth( $user, Tokens::FULL );
		$second = $this->oauth( $user, Tokens::FULL );
		$out    = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $first ) );

		// The same customer on another connection (another grant): the token is not theirs to use there.
		$result = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $out['confirm'] ), $second );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'not valid', $this->text( $result ) );
		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );

		// A token that belongs to another customer.
		$this->update_rows( array( 'user_id' => self::factory()->user->create() ) );
		$result = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $out['confirm'] ), $first );
		$this->assertTrue( $result['isError'] );
		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );

		// A stranger cannot even get a summary of someone else's service.
		$thief = $this->oauth( self::factory()->user->create(), Tokens::FULL );
		$this->assertTrue( $this->tool( 'delete_snapshot', $this->args( $r ), $thief )['isError'] );
		$this->assertCount( 1, $this->rows(), 'No token was made for the stranger.' );
	}

	public function test_an_expired_token_is_refused_and_a_new_summary_is_asked_for(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$first = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );

		$this->update_rows( array( 'expires_at' => time() - 1 ) );

		$result = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'expired', $this->text( $result ) );
		$this->assertContains( 'Claude: delete snapshot refused (the confirmation expired)', $this->history( $r ) );
		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );

		// A fresh summary works.
		$new = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );
		$this->assertNotSame( $first['confirm'], $new['confirm'] );
		$this->assertFalse( $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $new['confirm'] ), $token )['isError'] );
	}

	public function test_a_secret_confirmation_lives_two_minutes_and_expired_rows_are_purged(): void {
		$this->assertSame( 120, Confirmations::SECRET_TTL );
		$this->assertLessThan( Confirmations::TTL, Confirmations::SECRET_TTL );

		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );
		$this->update_rows( array( 'expires_at' => time() - 2 * HOUR_IN_SECONDS ) );

		$this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );
		$this->assertCount( 1, $this->rows(), 'The row that expired over an hour ago was removed when the next one was made.' );
	}

	public function test_only_a_limited_number_of_confirmations_can_wait(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		for ( $i = 0; $i < Confirmations::MAX_OPEN; $i++ ) {
			$this->json( $this->tool( 'delete_snapshot', $this->args( $r, sprintf( 'abcd%04d', $i ) ), $token ) );
		}

		$over = $this->tool( 'delete_snapshot', $this->args( $r, 'abcd9999' ), $token );
		$this->assertTrue( $over['isError'] );
		$this->assertStringContainsString( 'too many confirmations', $this->text( $over ) );
		$this->assertCount( Confirmations::MAX_OPEN, $this->rows() );
	}

	public function test_a_token_is_spent_even_when_the_tool_then_fails(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$first = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );

		// The dashboard's own refusal comes after the token was accepted: the tool fails, and the token does not come back.
		$refuse = static fn() => new \WP_Error( 'beltoft_services_refused', 'Refused for the test.', array( 'status' => 422 ) );
		add_filter( 'beltoft_services_action_refusal', $refuse );
		$failed = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		remove_filter( 'beltoft_services_action_refusal', $refuse );

		$this->assertTrue( $failed['isError'] );
		$this->assertStringContainsString( 'Refused for the test', $this->text( $failed ) );
		$this->assertContains( 'Claude: delete snapshot requested (refused)', $this->history( $r ) );

		$retry = $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $retry['isError'], $this->text( $retry ) );
		$this->assertStringContainsString( 'used already', $this->text( $retry ) );
		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );
	}

	public function test_a_call_the_dashboard_would_refuse_gets_no_token_and_a_waiting_token_is_kept(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		$this->driver->customer_actions = array( 'reboot' => 'Reboot' );
		$none                           = $this->tool( 'delete_snapshot', $this->args( $r ), $token );
		$this->assertTrue( $none['isError'] );
		$this->assertSame( array(), $this->rows(), 'The service does not offer it: nothing to confirm.' );

		$this->driver->customer_actions = array( 'snapshot_delete' => 'x' );
		$first                          = $this->json( $this->tool( 'delete_snapshot', $this->args( $r ), $token ) );

		// It stops being offered before the yes arrives: said at once, and the token is not spent by that.
		$this->driver->customer_actions = array( 'reboot' => 'Reboot' );
		$this->assertTrue( $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token )['isError'] );

		$this->driver->customer_actions = array( 'snapshot_delete' => 'x' );
		$this->assertFalse( $this->tool( 'delete_snapshot', $this->args( $r ) + array( 'confirm' => $first['confirm'] ), $token )['isError'] );
	}

	public function test_the_token_is_spent_by_one_call_only_even_when_two_arrive_together(): void {
		$r    = $this->offering( array( 'snapshot_delete' ) );
		$user = $this->owner( $r );

		wp_set_current_user( $user );
		Tokens::use_grant( array( 'user' => $user, 'scope' => Tokens::FULL, 'family' => 'fam1', 'client_id' => 'c' ) );
		$args   = $this->args( $r );
		$issued = Confirmations::issue( 'delete_snapshot', $args, 'state' );
		$this->assertIsArray( $issued );

		// Two calls with the same token, one after the other in the same instant: the single UPDATE lets only the first through.
		$this->assertTrue( Confirmations::consume( $issued['token'], 'delete_snapshot', $args, 'state' ) );
		$this->assertSame( 'used', Confirmations::consume( $issued['token'], 'delete_snapshot', $args, 'state' ) );
	}

	public function test_a_change_since_the_summary_voids_the_token(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, '12.00', true );
		$row  = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		Resource::create( $row, $this->fake_driver(), array(), array( 'state' => 'ready' ) );
		$token = $this->oauth( $user, Tokens::FULL );
		$first = $this->json( $this->tool( 'cancel_subscription', array( 'subscription_id' => $sub->get_id() ), $token ) );
		$this->assertTrue( $first['requires_confirmation'] );

		// The subscription went on hold meanwhile: what the summary said no longer holds.
		wcs_get_subscription( $sub->get_id() )->update_status( 'on-hold' );
		$result = $this->tool( 'cancel_subscription', array( 'subscription_id' => $sub->get_id(), 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'Things changed', $this->text( $result ) );
		$this->assertTrue( wcs_get_subscription( $sub->get_id() )->has_status( 'on-hold' ), 'Nothing was cancelled.' );

		// And the spent token does not come back.
		$again = $this->tool( 'cancel_subscription', array( 'subscription_id' => $sub->get_id(), 'confirm' => $first['confirm'] ), $token );
		$this->assertStringContainsString( 'used already', $this->text( $again ) );
	}

	public function test_levels_decide_who_can_even_ask(): void {
		$r      = $this->offering( array( 'snapshot_delete', 'restore', 'reinstall' ) );
		$user   = $this->owner( $r );
		$read   = $this->oauth( $user, Tokens::READ );
		$action = $this->oauth( $user, Tokens::ACTIONS );
		$full   = $this->oauth( $user, Tokens::FULL );
		$names  = array( 'delete_snapshot', 'rollback_snapshot', 'restore_backup', 'reinstall_server', 'replace_ssh_key', 'reset_firewall', 'reset_server_password', 'set_database_eviction_policy', 'cancel_subscription', 'remove_addon', 'set_default_payment_method', 'reveal_server_password', 'reveal_database_password', 'reveal_app_login', 'get_database_ca_certificate' );

		foreach ( array( $read, $action ) as $low ) {
			$listed = array_column( $this->rpc( 'tools/list', array(), $low )['result']['tools'], 'name' );

			foreach ( $names as $name ) {
				$this->assertNotContains( $name, $listed, $name );
				$result = $this->tool( $name, $this->dummy( \BeltoftServices\Mcp\Tools::definitions()[ $name ]['schema'], $r->id() ), $low );
				$this->assertTrue( $result['isError'], $name );
				$this->assertStringContainsString( 'Full access', $this->text( $result ), $name );
			}
		}

		$this->assertSame( array(), $this->rows(), 'No token was made for a connection without Full access.' );

		$listed = array_column( $this->rpc( 'tools/list', array(), $full )['result']['tools'], 'name' );

		foreach ( $names as $name ) {
			$this->assertContains( $name, $listed, $name );
		}

		// A personal token never reaches Full, whatever it is told.
		$personal = $this->personal( $user, Tokens::ACTIONS );
		$this->assertTrue( $this->tool( 'delete_snapshot', $this->args( $r ), $personal )['isError'] );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_a_full_grant_of_an_older_consent_cannot_ask_either(): void {
		global $wpdb;

		$r     = $this->offering( array( 'snapshot_delete' ) );
		$user  = $this->owner( $r );
		$token = $this->oauth( $user, Tokens::FULL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->update( Schema::tables()['grants'], array( 'scope' => 'full:' . ( Tokens::FULL_CONSENT - 1 ) ), array( 'user_id' => $user ) );

		$result = $this->tool( 'delete_snapshot', $this->args( $r ), $token );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'Full access', $this->text( $result ) );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( 2, Tokens::FULL_CONSENT, 'Phase B bumped the consent version.' );
	}

	public function test_the_descriptions_tell_the_model_to_show_the_summary_and_wait_for_the_yes(): void {
		$full = \BeltoftServices\Mcp\Tools::definitions();

		foreach ( $full as $name => $tool ) {
			if ( ! $tool['confirm'] ) {
				continue;
			}

			$this->assertStringContainsString( 'Show the summary to the customer exactly as written', $tool['description'], $name );
			$this->assertStringContainsString( 'clearly said yes', $tool['description'], $name );
			$this->assertStringContainsString( 'Never confirm on your own', $tool['description'], $name );
			$this->assertArrayHasKey( 'confirm', (array) $tool['schema']['properties'], $name );
		}

		// The everyday tools that can be escalated take the argument too.
		foreach ( array( 'set_firewall', 'set_database_access', 'domain_settings' ) as $name ) {
			$this->assertArrayHasKey( 'confirm', (array) $full[ $name ]['schema']['properties'], $name );
			$this->assertStringContainsString( 'confirm', $full[ $name ]['description'], $name );
		}
	}
}
