<?php
/**
 * Renders every email for each driver kind; shared by the golden test and the hosting part of its fixture.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;

/**
 * Needs the methods of BeltoftServices\Tests\TestCase.
 */
trait EmailsGolden {

	/**
	 * @param string[] $drivers Driver kinds.
	 * @return array<string, string>
	 */
	private function render_all( array $drivers ): array {
		$out = array();

		foreach ( $drivers as $driver ) {
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
}
