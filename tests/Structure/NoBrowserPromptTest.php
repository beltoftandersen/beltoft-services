<?php
/**
 * No browser prompt in the account area: a typed confirmation is a danger block on the page (like deleting a server), and a
 * question is the account area's own dialog (beltoftStatus.confirm).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Tests\TestCase;

final class NoBrowserPromptTest extends TestCase {

	public function test_no_script_uses_window_prompt_or_alert(): void {
		foreach ( glob( BELTOFT_SERVICES_PATH . 'assets/*.js' ) as $file ) {
			$js = (string) file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/window\.(prompt|alert)\s*\(/', $js, basename( $file ) );
		}
	}
}
