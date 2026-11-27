<?php
/**
 * The checkout's IPv6 test (assets/ipv6-test.js): the decision and the notice, in node with stubs, no browser.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftServices\Tests\TestCase;

final class Ipv6TestScriptTest extends TestCase {

	public function test_the_notice_shows_only_when_the_page_does_not_load_and_adds_nothing_by_itself(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->environment_missing( 'node is not installed.' );
		}

		$out = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/js/ipv6-test-check.js' ) . ' ' . escapeshellarg( BELTOFT_SERVICES_PATH ) . ' 2>&1' );

		$this->assertStringContainsString( 'IPV6 TEST OK', $out, $out );
		$this->assertStringNotContainsString( 'FAIL', $out, $out );
	}
}
