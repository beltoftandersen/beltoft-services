<?php
/**
 * A service that hands a registrar contact (with a phone number) to a third party says so with the marker NeedsPhone,
 * and BillingRequirements::cart_needs_phone() answers for a cart. On 2026-11-16 an SSL order was paid and then waited
 * for "complete billing details": the site showed the phone field for domain orders only, from a list of its own.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\BillingRequirements;
use BeltoftServices\Drivers\NeedsPhone;
use BeltoftServices\Drivers\Registry;

final class NeedsPhoneTest extends TestCase {

	/** Source file that asks for the registrar contact => the driver that must carry the marker. */
	private const CALLERS = array(
		'Domains/DomainDriver.php' => 'domain',
		'Ssl/Provision.php'        => 'ssl',
	);

	public function test_every_service_that_asks_for_the_registrar_contact_is_marked(): void {
		$src   = dirname( __DIR__ ) . '/src/';
		$found = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$path = str_replace( $src, '', (string) $file );

			if ( 'php' === $file->getExtension() && 'Domains/Contacts.php' !== $path && false !== strpos( (string) file_get_contents( (string) $file ), 'Contacts::handle_for(' ) ) {
				$found[] = $path;
			}
		}

		sort( $found );
		$this->assertSame( array_keys( self::CALLERS ), $found, 'A new caller of Contacts::handle_for(): mark its driver NeedsPhone and add it to CALLERS, or its orders are paid and then wait for a phone number.' );

		foreach ( self::CALLERS as $driver ) {
			$this->assertInstanceOf( NeedsPhone::class, Registry::get( $driver ), "$driver needs the phone number" );
		}
	}

	public function test_a_cart_with_such_a_service_needs_the_phone_and_another_cart_does_not(): void {
		WC()->cart->empty_cart();
		$this->assertFalse( BillingRequirements::cart_needs_phone() );

		WC()->cart->add_to_cart( $this->service_product( 'fake' )->get_id() );
		$this->assertFalse( BillingRequirements::cart_needs_phone(), 'A service without the marker.' );

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->service_product( 'ssl' )->get_id() );
		$this->assertTrue( BillingRequirements::cart_needs_phone(), 'An SSL certificate.' );
		WC()->cart->empty_cart();
	}
}
