<?php
/**
 * The hosting settings fields (fixtures/settings-fields.json); the rest is in tests/SettingsTest.php.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Settings;
use BeltoftServices\Tests\TestCase;

/**
 * Keys, defaults, tab, section, type, label and position of the hosting fields.
 */
final class SettingsFieldsTest extends TestCase {

	public function test_the_hosting_fields_are_unchanged_and_sit_where_they_sat(): void {
		$golden = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/settings-fields.json' ), true );
		$fields = Settings::fields();
		$keys   = array_keys( $golden['fields'] );

		foreach ( $golden['fields'] as $key => $definition ) {
			$this->assertSame( $definition, $fields[ $key ], $key );
		}

		// Together in one block, right after the field the fixture names.
		$all   = array_keys( $fields );
		$start = array_search( $golden['after'], $all, true );
		$this->assertNotFalse( $start );
		$this->assertSame( $keys, array_slice( $all, $start + 1, count( $keys ) ) );

		// Nothing else is on the hosting tab.
		$tab = array_keys( array_filter( $fields, static fn( $f ) => 'hosting' === ( $f['tab'] ?? '' ) ) );
		$this->assertSame( $keys, $tab );
	}
}
