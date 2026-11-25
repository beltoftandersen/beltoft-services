<?php
/**
 * The WooCommerce Subscriptions internals are each named in one file.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Tests\Support\Scan;

use PHPUnit\Framework\TestCase;

/**
 * A change in WooCommerce Subscriptions is then fixed in one place.
 */
class WcsInternalsTest extends TestCase {

	/**
	 * Name in code => the one src file that may contain it.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function names(): array {
		return array(
			'gateway renewal hook' => array( 'trigger_gateway_renewal_payment_hook', 'Billing/Subscriptions.php' ),
			'status handler'       => array( 'WCS_User_Change_Status_Handler::', 'Billing/Subscriptions.php' ),
			'switch query'         => array( 'switch-subscription', 'SwitchLink.php' ),
		);
	}

	/**
	 * @dataProvider names
	 */
	public function test_name_is_in_one_file( string $needle, string $owner ): void {
		$root  = Scan::root() . '/src/';
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() && str_contains( $this->code_without_comments( (string) file_get_contents( $file->getPathname() ) ), $needle ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) );
			}
		}

		// The owner file of a plugin that is not under the scan root (this test runs over Beltoft Hosting too) holds nothing.
		$this->assertSame( file_exists( $root . $owner ) ? array( $owner ) : array(), $found, $needle );
	}

	/**
	 * The file's code with comments and docblocks left out: a name in a comment is not a use.
	 */
	private function code_without_comments( string $code ): string {
		$out = '';

		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$out .= is_array( $token ) ? $token[1] : $token;
		}

		return $out;
	}
}
