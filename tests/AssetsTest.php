<?php
/** Asset versions. @package BeltoftServices */
namespace BeltoftServices\Tests;

use BeltoftServices\Assets;

class AssetsTest extends TestCase {
	public function test_version_follows_the_file_and_falls_back_to_the_plugin_version(): void {
		$this->assertSame( (string) filemtime( BELTOFT_SERVICES_PATH . 'assets/account.css' ), Assets::version( 'assets/account.css' ), 'A changed file gets a new URL, so the long browser cache cannot serve it stale.' );
		$this->assertSame( BELTOFT_SERVICES_VERSION, Assets::version( 'assets/does-not-exist.css' ) );
	}
}
