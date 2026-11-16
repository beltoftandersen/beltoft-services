<?php
/**
 * A domain action that ends with ok false (the registrar refuses a lock) shows the outcome text and frees the buttons; it
 * does not reload the page (assets/domain.js). Run in node with stubs, no browser.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

final class DomainSettleScriptTest extends TestCase {

	public function test_a_failed_domain_action_shows_its_outcome_and_does_not_reload(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->environment_missing( 'node is not installed.' );
		}

		$out = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/js/domain-settle-check.js' ) . ' ' . escapeshellarg( BELTOFT_SERVICES_PATH ) . ' 2>&1' );

		$this->assertStringContainsString( 'DOMAIN SETTLE OK', $out, $out );
		$this->assertStringNotContainsString( 'FAIL', $out, $out );
	}
}
