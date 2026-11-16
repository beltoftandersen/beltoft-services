<?php
/**
 * The database page waits for an action by reading the status (assets/db.js). When every read is rejected it must not stop
 * silently with the service busy and locked: it says so and frees the buttons. Run in node with stubs, no browser.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

final class DbWaitScriptTest extends TestCase {

	public function test_the_wait_says_so_when_every_status_read_is_rejected(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->environment_missing( 'node is not installed.' );
		}

		$out = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/js/db-wait-check.js' ) . ' ' . escapeshellarg( BELTOFT_SERVICES_PATH ) . ' 2>&1' );

		$this->assertStringContainsString( 'DB WAIT OK', $out, $out );
		$this->assertStringNotContainsString( 'FAIL', $out, $out );
	}

	public function test_the_card_has_the_text_for_a_lost_wait(): void {
		$card = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Proxmox/Db/DbCard.php' );

		$this->assertStringContainsString( "'lost'", $card );
	}
}
