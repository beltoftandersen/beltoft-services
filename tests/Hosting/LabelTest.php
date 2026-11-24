<?php
/**
 * The hosting label has a filter (moved here from CardListItemTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Hosting\Config;
use BeltoftServices\Tests\TestCase;

/**
 * Label.
 */
final class LabelTest extends TestCase {

	public function test_hosting_label_has_a_filter(): void {
		$this->settings( array( 'hosting_label' => 'Webhotel' ) );
		add_filter( 'beltoft_services_hosting_label', static fn() => 'Web hosting' );
		$this->assertSame( 'Web hosting', Config::label() );
	}
}
