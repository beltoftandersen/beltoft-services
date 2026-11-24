<?php
/**
 * The hosting part of the progress step tables (fixtures/progress-golden.json); the rest is in tests/ProgressTest.php.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Tests\Support\ProgressGolden;
use BeltoftServices\Tests\TestCase;

require_once BELTOFT_SERVICES_TESTS_DIR . '/Support/ProgressGolden.php';

/**
 * Every step table, title and text of a hosting account, by state.
 */
final class ProgressGoldenTest extends TestCase {

	use ProgressGolden;

	public function test_the_hosting_step_tables_are_unchanged(): void {
		$file = __DIR__ . '/fixtures/progress-golden.json';
		$got  = json_decode( (string) wp_json_encode( $this->golden_payloads( array( 'hosting' ) ) ), true );

		if ( getenv( 'BELTOFT_WRITE_GOLDEN' ) ) {
			file_put_contents( $file, wp_json_encode( $got, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$want = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( array_keys( $want ), array_keys( $got ) );

		foreach ( $want as $name => $payload ) {
			$this->assertSame( $payload, $got[ $name ], $name );
		}
	}
}
