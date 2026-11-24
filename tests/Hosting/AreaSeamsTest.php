<?php
/**
 * Where hosting sits among the My Account areas and what its activity sentences say (moved here from Account\AreaSeamsTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Account\ActivityMap;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Tests\TestCase;

/**
 * The seams between the platform and the hosting feature.
 */
final class AreaSeamsTest extends TestCase {

	public function test_hosting_is_the_third_area_in_tab_order(): void {
		$keys = array_map( static fn( ServiceArea $a ): string => $a->key_name(), ServiceArea::all() );

		$this->assertSame( array( 'domains', 'databases', 'hosting', 'apps', 'email', 'ssl', 'licences', 'storage', 'dedicated' ), $keys );
	}

	public function test_hosting_answers_the_activity_sentence_and_the_other_messages_stay_as_they_were(): void {
		$this->assertSame( 'Cache cleared', ActivityMap::sentence( 'Hosting change "purge_cache" done' )['text'] ?? '' );
		$this->assertNull( ActivityMap::sentence( 'Hosting change "bogus" done' ) );
		$this->assertNull( ActivityMap::sentence( 'Something else' ) );

		add_filter( 'beltoft_services_activity_sentence', static fn( $s, $message ) => 'Another feature said so' === $message ? 'Said' : $s, 20, 2 );

		$this->assertSame( 'Said', ActivityMap::sentence( 'Another feature said so' )['text'] ?? '' );
	}
}
