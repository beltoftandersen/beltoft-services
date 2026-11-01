<?php
/** Methods code outside this plugin calls on Account\Servers (the theme's account shell). @package BeltoftServices */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Account\Servers;
use BeltoftServices\Tests\TestCase;

final class ServersCompatTest extends TestCase {
	public function test_the_moved_methods_still_answer_on_servers(): void {
		foreach ( array( 'tabs', 'slug', 'url', 'url_new', 'is_new', 'page', 'requested', 'renewal' ) as $method ) {
			$this->assertTrue( method_exists( Servers::class, $method ), $method );
		}
		$this->assertSame( ServerLinks::slug(), Servers::slug() );
		$this->assertSame( ServerLinks::requested(), Servers::requested() );
		$this->assertSame( ServerLinks::TABS, Servers::TABS );
		$this->assertSame( ServerLinks::NEW_VALUE, Servers::NEW_VALUE );
	}
}
