<?php
/**
 * The order progress script (assets/thankyou.js): there is no JavaScript test runner, so these read the script, as
 * StatusPollScriptTest does. Manual check on staging: an order whose services appear, an order that never gets any (the wait ends
 * after three minutes with a link), a failed service (the dashboard bar fails), a paused one (the bar ends, no 5 s polling).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

final class OrderProgressScriptTest extends TestCase {

	private function script( string $name = 'thankyou.js' ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/' . $name );
	}

	public function test_the_closing_line_is_filled_before_the_bar_reads_it_and_a_failure_is_not_reported_as_done(): void {
		$js = $this->script();

		$this->assertLessThan( strpos( $js, "\t\t\tbar( rows );" ), strpos( $js, "\t\t\tclosing( rows );" ), 'closing() runs before bar().' );
		$this->assertStringContainsString( 'window.beltoftStatus.fail( handle', $js );
		$this->assertMatchesRegularExpression( "/'failed' === stateOf\\( rows \\) \\|\\| 'mixed' === stateOf\\( rows \\)/", $js );
	}

	public function test_a_paused_service_or_a_waiting_step_is_not_running_in_the_script_either(): void {
		$js = $this->script();

		$this->assertStringContainsString( "return !! row.waits || 'paused' === row.group || 'removed' === row.group;", $js );
		$this->assertStringContainsString( "return waiting ? 'waiting' : 'ready';", $js );
		$this->assertStringContainsString( "'waiting' === state", $js );
	}

	public function test_the_wait_for_services_ends_after_three_minutes_with_a_link(): void {
		$js = $this->script();

		$this->assertStringContainsString( 'var WAIT_LIMIT = 180000;', $js );
		$this->assertStringContainsString( 'Date.now() - started > WAIT_LIMIT', $js );
		$this->assertStringContainsString( 'giveUp()', $js );
		$this->assertStringContainsString( 'data-order-gaveup', $js );
		$this->assertStringContainsString( 'data-order-gaveup', (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/account/overview/new-order.php' ) );
	}

	public function test_the_poll_backs_off_on_429_and_survives_blocked_storage(): void {
		$js = $this->script();

		$this->assertStringContainsString( '429 === response.status ? Math.min( slow * 2, 8 ) : 1', $js );
		$this->assertStringContainsString( '5000 * slow', $js );
		$this->assertStringContainsString( "'function' === typeof interval ? interval() : interval", $this->script( 'progress.js' ) );
		$this->assertMatchesRegularExpression( '/catch \( error \) \{\s*\/\/ Storage is blocked/', $js );
	}
}
