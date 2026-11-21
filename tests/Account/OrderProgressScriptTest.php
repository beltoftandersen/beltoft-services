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

	public function test_a_ready_service_updates_its_row_in_the_server_list_and_the_open_button_keeps_white_text(): void {
		$js = $this->script();

		$this->assertStringContainsString( "'#beltoft-service-' + row.id + ' .beltoft-services-state.is-busy, [data-service-row=\"' + row.id + '\"] .beltoft-services-state.is-busy'", $js );
		$this->assertStringContainsString( "el.className = 'beltoft-services-state is-ok';", $js );
		$this->assertStringContainsString( 'data-service-row=', (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/account/overview/rows.php' ) );

		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/account.css' );
		$this->assertStringContainsString( 'body.woocommerce-account .woocommerce-MyAccount-content .bs-order .bs-flow .beltoft-service__open a { color: var(--bs-on-dark);', $css );
	}

	public function test_a_finished_order_is_one_compact_card_with_named_buttons(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/account.css' );

		$this->assertStringContainsString( '.bs-order[data-state="ready"] .beltoft-next__services { display: none; }', $css );
		$this->assertStringContainsString( '.woocommerce-MyAccount-content .bs-flow .beltoft-service__head { max-width: none; }', $css );
		$this->assertStringNotContainsString( 'div.bs-order', $css, 'The card is a section: a div selector never matched.' );
		$this->assertStringContainsString( '.bs-order .bs-flow .beltoft-services-progress__steps > li:not(.is-current) { display: none; }', $css, 'Only the current step on the overview.' );
		$this->assertStringContainsString( '.bs-order .bs-flow .beltoft-services-progress__leave { display: none; }', $css );

		foreach ( array( 'db' => 'Open your database', 'storage' => 'Open your online backup', 'mail' => 'Open your email', 'ssl' => 'Open your certificate' ) as $kind => $label ) {
			$this->assertSame( $label, \BeltoftServices\ThankYou::open_label( $kind ) );
		}
	}
}
