<?php
/**
 * The activity sentences of hosting changes (moved here from Account\OverviewActivityTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Account\ActivityMap;
use BeltoftServices\Tests\TestCase;

/**
 * What a customer reads for a hosting change.
 */
final class ActivitySentenceTest extends TestCase {

	public function test_every_listed_hosting_text_gives_its_sentence(): void {
		$expected = array(
			'Hosting change "add_site" done'    => 'Website added',
			'Hosting change "remove_site" done' => 'Website removed',
			'Hosting change "set_php" done'     => 'PHP version changed',
			'Hosting change "purge_cache" done' => 'Cache cleared',
		);

		foreach ( $expected as $message => $sentence ) {
			$said = ActivityMap::sentence( $message );
			$this->assertNotNull( $said, $message );
			$this->assertSame( $sentence, $said['text'], $message );
		}
	}
}
