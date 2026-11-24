<?php
/**
 * Characterisation of every customer email: for each email id and each driver kind, the html and the plain text that the
 * templates print must not change when the templates share parts. Whitespace between tags is not compared. Regenerate
 * with BS_WRITE_GOLDEN=1 only on purpose.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

require_once __DIR__ . '/Support/EmailsGolden.php';

use BeltoftServices\Tests\Support\EmailsGolden;

final class EmailRenderGoldenTest extends TestCase {

	use EmailsGolden;

	public function test_every_email_prints_what_it_printed_before_it_shared_parts(): void {
		$file = BELTOFT_SERVICES_PATH . 'tests/fixtures/golden/emails.json';
		// What an optional feature (hosting) adds is pinned by that feature's own tests: its driver kind and its emails.
		$now  = $this->without_optional( $this->render_all( array( 'proxmox', 'domain', 'mail', 'ssl' ) ), 'email_golden_keys' );

		if ( getenv( 'BS_WRITE_GOLDEN' ) ) {
			file_put_contents( $file, wp_json_encode( $now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$golden = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( array_keys( $golden ), array_keys( $now ) );

		foreach ( $golden as $key => $text ) {
			$this->assertSame( $text, $now[ $key ], $key );
		}
	}
}
