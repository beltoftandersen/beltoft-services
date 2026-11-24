<?php
/**
 * The hosting part of the email fixtures: the values, the value keys and the definitions of the hosting emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Emails\Definitions;
use BeltoftServices\Tests\Support\EmailValuesGolden;
use BeltoftServices\Tests\TestCase;

require_once BELTOFT_SERVICES_TESTS_DIR . '/Support/EmailValuesGolden.php';

/**
 * What the hosting feature adds to the values of a service email, and to the list of emails.
 */
final class EmailValuesGoldenTest extends TestCase {

	use EmailValuesGolden;

	public function test_the_hosting_values_are_the_ones_the_old_code_gave(): void {
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/email-values.json' ), true );
		$now      = $this->normalised_values( array( 'proxmox', 'domain', 'mail', 'hosting', 'ssl' ) );
		$keys     = array_keys( $expected['proxmox'] );

		foreach ( $now as $driver => $values ) {
			if ( 'hosting' !== $driver ) {
				$values = array_intersect_key( $values, array_flip( $keys ) );
			}

			$this->assertSame( $expected[ $driver ], $values, $driver );
		}
	}

	public function test_hosting_adds_exactly_its_value_keys_to_every_driver(): void {
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/email-values.json' ), true );

		foreach ( array( 'proxmox', 'domain', 'mail', 'hosting', 'ssl' ) as $driver ) {
			$mine = array_values( array_filter( array_keys( $this->values_for( $driver ) ), static fn( $key ) => 0 === strpos( $key, 'hosting' ) || 'monitor_hosting' === $key ) );
			sort( $mine );
			$want = array_keys( $expected['proxmox'] );
			sort( $want );

			$this->assertSame( $want, $mine, $driver );
		}
	}

	public function test_every_hosting_value_key_has_a_default(): void {
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/email-values.json' ), true );

		$this->assertSame( array(), array_values( array_diff( array_keys( $expected['proxmox'] ), array_keys( \BeltoftServices\Emails\ValueDefaults::all() ) ) ) );
	}

	public function test_the_hosting_definitions_match_the_fixture_word_for_word_and_come_last(): void {
		$fixture = require __DIR__ . '/fixtures/email-definitions.php';
		$core    = require BELTOFT_SERVICES_TESTS_DIR . '/fixtures/email-definitions.php';

		$this->assertSame( array_merge( array_keys( $core ), array_keys( $fixture ) ), Definitions::ids() );

		foreach ( $fixture as $id => $expected ) {
			$this->assertSame( $expected, Definitions::get( $id ), $id );
		}
	}
}
