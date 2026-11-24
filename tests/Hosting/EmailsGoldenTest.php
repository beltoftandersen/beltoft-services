<?php
/**
 * The hosting part of the golden emails (fixtures/emails-golden.json); the rest is in tests/EmailRenderGoldenTest.php.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Tests\Support\EmailsGolden;
use BeltoftServices\Tests\TestCase;

require_once BELTOFT_SERVICES_TESTS_DIR . '/Support/EmailsGolden.php';

/**
 * What the hosting emails print, and what every email prints for a hosting account.
 */
final class EmailsGoldenTest extends TestCase {

	use EmailsGolden;

	public function test_every_hosting_email_prints_what_it_printed_before_it_shared_parts(): void {
		$file = __DIR__ . '/fixtures/emails-golden.json';
		$now  = array_filter(
			$this->render_all( array( 'proxmox', 'domain', 'mail', 'hosting', 'ssl' ) ),
			static fn( $key ) => false !== strpos( $key, '|hosting|' ) || 0 === strpos( $key, 'beltoft_services_hosting_' ),
			ARRAY_FILTER_USE_KEY
		);

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
