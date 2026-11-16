<?php
/**
 * One button height on every account page (ASSETS-08, owner decision 2026-11-16: 40 px). The hosting button takes its height
 * from the shared token, not from the input height token.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

final class ButtonHeightTest extends TestCase {

	public function test_the_hosting_button_uses_the_shared_button_height(): void {
		$account = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$hosting = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/hosting.css' );

		$this->assertStringContainsString( '--bs-btn-h: 40px;', $account );

		foreach ( preg_split( '/\R/', $hosting ) as $line ) {
			if ( false !== strpos( $line, 'button.beltoft-hosting-btn' ) && false !== strpos( $line, 'min-height:' ) && false === strpos( $line, '.bsh-btn--sm' ) ) {
				$this->assertStringContainsString( 'min-height: var(--bs-btn-h', $line );
				$this->assertStringNotContainsString( 'min-height: var(--bs-input-h', $line );
			}
		}
	}
}
