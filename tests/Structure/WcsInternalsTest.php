<?php
/**
 * The WooCommerce Subscriptions internals are each named in one file.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

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
			'switch query'         => array( "['switch-subscription']", 'SwitchLink.php' ),
		);
	}

	/**
	 * @dataProvider names
	 */
	public function test_name_is_in_one_file( string $needle, string $owner ): void {
		$root  = dirname( __DIR__, 2 ) . '/src/';
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() && str_contains( (string) file_get_contents( $file->getPathname() ), $needle ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) );
			}
		}

		$this->assertSame( array( $owner ), $found, $needle );
	}
}
