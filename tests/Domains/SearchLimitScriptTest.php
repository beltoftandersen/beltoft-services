<?php
/**
 * A search the server refuses with 429 shows the limit line and counts down on the button (assets/domain-search.js). Run in node with stubs, no browser.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Tests\TestCase;

final class SearchLimitScriptTest extends TestCase {

	public function test_a_refused_search_counts_down_on_the_button(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->environment_missing( 'node is not installed.' );
		}

		$out = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/js/domain-search-limit-check.js' ) . ' ' . escapeshellarg( BELTOFT_SERVICES_PATH ) . ' 2>&1' );

		$this->assertStringContainsString( 'DOMAIN SEARCH LIMIT OK', $out, $out );
		$this->assertStringNotContainsString( 'FAIL', $out, $out );
	}
}
