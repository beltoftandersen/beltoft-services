<?php
/**
 * The texts of the one-click app definitions are translatable and filterable, and the list stays in step with app.json.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppTexts;
use BeltoftServices\Apps\Apps;
use BeltoftServices\LineNames;
use BeltoftServices\Tests\TestCase;

/**
 * App texts.
 */
class AppTextsTest extends TestCase {

	public function test_every_summary_and_getting_started_text_of_every_app_json_is_in_the_list(): void {
		$missing = array();

		foreach ( glob( BELTOFT_SERVICES_PATH . 'apps/*/app.json' ) as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );

			foreach ( array( 'summary', 'getting_started' ) as $field ) {
				if ( isset( $data[ $field ] ) && ! array_key_exists( $data[ $field ], AppTexts::all() ) ) {
					$missing[] = basename( dirname( $file ) ) . ' ' . $field;
				}
			}
		}

		$this->assertSame( array(), $missing, 'Add the text to src/Apps/AppTexts.php (one __() call each).' );
	}

	public function test_the_list_has_no_text_that_no_app_json_has(): void {
		$have = array();

		foreach ( glob( BELTOFT_SERVICES_PATH . 'apps/*/app.json' ) as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			foreach ( array( 'summary', 'getting_started' ) as $field ) {
				if ( isset( $data[ $field ] ) ) {
					$have[ $data[ $field ] ] = true;
				}
			}
		}

		$this->assertSame( array(), array_values( array_diff( array_keys( AppTexts::all() ), array_keys( $have ) ) ), 'A stale text in the list.' );
	}

	public function test_a_translation_reaches_the_definition_and_the_filter_sees_the_app_and_the_field(): void {
		$summary = json_decode( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/docker/app.json' ), true )['summary'];
		$this->assertSame( $summary, Apps::definition( 'docker' )['summary'], 'English is the source text.' );

		$translate = static fn( $t, $text, $domain ) => 'beltoft-services' === $domain && $text === $summary ? 'Docker Engine og Compose' : $t;
		add_filter( 'gettext', $translate, 10, 3 );
		$this->assertSame( 'Docker Engine og Compose', Apps::definition( 'docker' )['summary'] );
		remove_filter( 'gettext', $translate, 10 );

		$seen = array();
		$f    = static function ( $text, $id, $field ) use ( &$seen ) {
			$seen[] = array( $id, $field );
			return 'summary' === $field ? 'X ' . $text : $text;
		};
		add_filter( 'beltoft_services_app_text', $f, 10, 3 );
		$def = Apps::definition( 'docker' );
		remove_filter( 'beltoft_services_app_text', $f, 10 );

		$this->assertSame( 'X ' . $summary, $def['summary'] );
		$this->assertContains( array( 'docker', 'getting_started' ), $seen );
	}

	public function test_a_billing_period_word_is_a_plugin_string(): void {
		$this->assertSame( 'monthly', LineNames::period_word( 'monthly' ) );
		$this->assertSame( 'fortnightly', LineNames::period_word( 'fortnightly' ), 'An unknown word stays as it is.' );

		$translate = static fn( $t, $text, $domain ) => 'beltoft-services' === $domain && 'monthly' === $text ? 'månedlig' : $t;
		add_filter( 'gettext', $translate, 10, 3 );
		$this->assertSame( 'månedlig', LineNames::period_word( 'monthly' ) );
		remove_filter( 'gettext', $translate, 10 );
	}
}
