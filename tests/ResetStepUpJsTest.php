<?php
/**
 * The dashboard scripts ask for the emailed code before they send a password reset (read from the source: no browser here).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

final class ResetStepUpJsTest extends TestCase {

	public function test_the_server_script_asks_for_the_code_and_sends_it(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/vm.js' );

		$this->assertStringContainsString( "stepUp( 'password_reset'", $js );
		$this->assertStringContainsString( 'step_code', $js );
	}

	public function test_the_database_script_asks_for_the_code_and_sends_it(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/db.js' );

		$this->assertStringContainsString( "stepUp( 'password_reset'", $js );
		$this->assertStringContainsString( 'step_code', $js );
	}

	public function test_the_generic_card_button_asks_for_the_code_of_a_reset(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/account.js' );

		$this->assertStringContainsString( "reset_password: 'password_reset'", $js );
		$this->assertStringContainsString( "db_reset_password: 'password_reset'", $js );
		$this->assertStringContainsString( 'step_code', $js );
	}
}
