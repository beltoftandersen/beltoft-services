<?php
/**
 * Characterisation of every customer email: for each email id and each driver kind, the html and the plain text that the
 * templates print must not change when the templates share parts. Whitespace between tags is not compared. Regenerate
 * with BS_WRITE_GOLDEN=1 only on purpose.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;

final class EmailRenderGoldenTest extends TestCase {

	/**
	 * @return array<string, string>
	 */
	private function render_all(): array {
		$out = array();

		foreach ( array( 'proxmox', 'domain', 'mail', 'hosting', 'ssl' ) as $driver ) {
			$this->fake_driver( $driver );
			$product = $this->service_product( $driver, array(), array( 'slug' => 'golden-' . $driver ) );
			$product->set_name( 'Fixture product' );
			$product->save();
			$license = $this->service_license( $product, array( 'fire' => false ) );
			wp_update_user( array( 'ID' => $license['user_id'], 'display_name' => 'Fixture Customer' ) );
			$resource = Resource::find( Resource::create( $license, Registry::get( $driver ), array(), array( 'state' => 'ready' ) )->id() );

			foreach ( Emails::ids() as $id ) {
				$email = Emails::get( $id );

				if ( $email instanceof \BeltoftServices\Emails\StepUpCode ) {
					continue;
				}

				$email->trigger( $resource, array( 'deletes_at' => 1900000000, 'used' => 3, 'limit' => 5 ) );

				foreach ( array( 'html' => (string) $email->get_content_html(), 'plain' => (string) $email->get_content_plain() ) as $format => $text ) {
					$text = (string) preg_replace( '/(?<!\d)' . (int) $resource->id() . '(?!\d)/', '{resource}', $text );
					$text = (string) preg_replace( '/(?<!\d)' . (int) $license['id'] . '(?!\d)/', '{license}', $text );
					$text = (string) preg_replace( '/\b20\d\d\b/', '{year}', $text );
					$out[ $id . '|' . $driver . '|' . $format ] = $this->normal( $text, 'html' === $format );
				}
			}
		}

		ksort( $out );

		return $out;
	}

	private function normal( string $text, bool $html ): string {
		if ( ! $html ) {
			return trim( (string) preg_replace( "/[ \t]+\n/", "\n", $text ) );
		}

		// Whitespace between tags and before a closing tag is not shown by a browser: not compared.
		return trim( (string) preg_replace( '/\s+(<\/)/', '$1', (string) preg_replace( '/>\s+</', '><', (string) preg_replace( '/\s+/', ' ', $text ) ) ) );
	}

	public function test_every_email_prints_what_it_printed_before_it_shared_parts(): void {
		$file = BELTOFT_SERVICES_PATH . 'tests/fixtures/golden/emails.json';
		$now  = $this->render_all();

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
