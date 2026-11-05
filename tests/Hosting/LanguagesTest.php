<?php
/**
 * The language list of the hosting checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Hosting\Languages;
use BeltoftServices\Tests\TestCase;

/**
 * Languages: bundled list, stored refresh, groups, validation. No test reaches the network.
 */
final class LanguagesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Languages::OPTION );
	}

	public function tear_down(): void {
		delete_option( Languages::OPTION );
		parent::tear_down();
	}

	public function test_the_bundled_list_has_native_names_and_the_default(): void {
		$all = Languages::all();

		$this->assertGreaterThan( 100, count( $all ) );
		$this->assertSame( 'English (United States)', $all['en_US'] );
		$this->assertSame( 'Dansk', $all['da_DK'] );
		$this->assertSame( 'Deutsch', $all['de_DE'] );
		$this->assertTrue( Languages::offered( 'de_DE_formal' ) );
		$this->assertFalse( Languages::offered( 'xx_XX' ) );
		$this->assertSame( 'Dansk', Languages::name( 'da_DK' ) );
		$this->assertSame( 'xx_XX', Languages::name( 'xx_XX' ) );

		foreach ( array_keys( $all ) as $locale ) {
			$this->assertTrue( Languages::valid( $locale ), $locale );
		}
	}

	public function test_valid_accepts_wordpress_locales_only(): void {
		foreach ( array( 'en_US', 'da_DK', 'ast', 'de_DE_formal', 'ca_valencia', 'pt_PT_ao90' ) as $ok ) {
			$this->assertTrue( Languages::valid( $ok ), $ok );
		}

		foreach ( array( '', 'english', 'EN_us', 'en-US', 'en_US ', "en_US\n", 'e' ) as $bad ) {
			$this->assertFalse( Languages::valid( $bad ), $bad );
		}
	}

	public function test_groups_put_the_common_languages_first_and_all_by_name(): void {
		$groups = Languages::groups();

		$this->assertSame( 'en_US', array_key_first( $groups['common'] ) );
		$this->assertArrayHasKey( 'da_DK', $groups['common'] );
		$this->assertArrayHasKey( 'da_DK', $groups['all'] );
		$names = array_values( $groups['all'] );
		$sorted = $names;
		natcasesort( $sorted );
		$this->assertSame( array_values( $sorted ), $names );
	}

	public function test_a_stored_list_replaces_the_bundled_one_and_a_short_one_is_ignored(): void {
		$stored = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$stored[ 'a' . chr( 97 + ( $i % 26 ) ) . chr( 97 + ( intdiv( $i, 26 ) ) ) ] = 'Name ' . $i;
		}

		update_option( Languages::OPTION, $stored );
		$all = Languages::all();
		$this->assertSame( 'Name 0', $all['aaa'] );
		$this->assertArrayHasKey( 'en_US', $all, 'The default is always offered.' );
		$this->assertArrayNotHasKey( 'da_DK', $all );

		update_option( Languages::OPTION, array( 'da_DK' => 'x' ) );
		$this->assertSame( 'Dansk', Languages::all()['da_DK'] );
	}

	public function test_refresh_stores_the_answer_without_a_live_call_and_keeps_the_list_on_a_bad_answer(): void {
		$rows = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$rows[ 'b' . chr( 97 + ( $i % 26 ) ) ] = array( 'native_name' => 'Sprog ' . $i );
		}

		set_site_transient( 'available_translations', $rows, HOUR_IN_SECONDS );
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;

				return new \WP_Error( 'blocked', 'No network in tests.' );
			}
		);

		$this->assertTrue( Languages::refresh() );
		$this->assertSame( 'Sprog 0', Languages::all()['ba'] );
		$this->assertSame( 0, $calls, 'The translations cache answered.' );

		update_option( Languages::OPTION, array() );
		set_site_transient( 'available_translations', array( 'da_DK' => array( 'native_name' => 'Dansk' ) ), HOUR_IN_SECONDS );
		$this->assertFalse( Languages::refresh() );
		$this->assertSame( 'Dansk', Languages::all()['da_DK'] );

		delete_site_transient( 'available_translations' );
	}
}
