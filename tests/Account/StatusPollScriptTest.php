<?php
/**
 * The status poll of the account page (PERF-06): there is no JavaScript test runner, so these read the scripts. Manual check:
 * open an account page with a service that is working, watch the Network tab (one status read every 5 seconds), switch to
 * another tab for a minute (no reads), switch back (one read at once), and open a settled service (one read, then none).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

final class StatusPollScriptTest extends TestCase {

	private function script( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/' . $name );
	}

	public function test_a_settled_panel_stops_asking_and_a_hidden_tab_does_not_ask(): void {
		$account = $this->script( 'account.js' );

		// The chain goes on only while the state differs from the desired one or work is shown, and never for a failed service.
		$this->assertStringContainsString( "( data.state !== data.desired || active ) && 'failed' !== data.state", $account );
		// Every wait of the poll chain and of the settle loop goes through the visibility check.
		$this->assertStringContainsString( 'document.hidden', $account );
		$this->assertStringContainsString( "addEventListener( 'visibilitychange'", $account );
		$this->assertSame( 1, substr_count( $account, 'window.setTimeout(' ), 'Only later() sets a timer.' );
		$this->assertGreaterThanOrEqual( 4, substr_count( $account, 'later(' ) );
	}

	public function test_following_an_action_pauses_while_the_tab_is_hidden(): void {
		$api = $this->script( 'api.js' );

		$this->assertStringContainsString( 'function later(', $api );
		$this->assertStringContainsString( "'visibilitychange'", $api );
		$this->assertStringContainsString( 'later( look,', $api );
		$this->assertStringNotContainsString( 'window.setTimeout( look', $api, 'No look is timed outside later().' );
		$this->assertStringContainsString( 'later: later', $api );
	}

	public function test_an_action_started_by_the_customer_polls_at_once(): void {
		$account = $this->script( 'account.js' );

		$this->assertStringContainsString( "addEventListener( 'beltoft-services:queued'", $account );
		$this->assertMatchesRegularExpression( "/beltoft-services:queued'[^}]*poll\\( panel \\)/s", $account );
	}
}
