<?php
/**
 * The sticky progress line is not started while the overview's order card shows the progress itself (assets/thankyou.js).
 * Run in node with stubs, no browser.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

final class OrderBarScriptTest extends TestCase {

	public function test_the_top_line_does_not_start_when_the_order_card_shows_the_progress(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->environment_missing( 'node is not installed.' );
		}

		$out = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/js/order-bar-check.js' ) . ' ' . escapeshellarg( BELTOFT_SERVICES_PATH ) . ' 2>&1' );

		$this->assertStringContainsString( 'ORDER BAR OK', $out, $out );
		$this->assertStringNotContainsString( 'FAIL', $out, $out );
	}
}
