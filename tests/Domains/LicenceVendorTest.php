<?php
/** The vendor licence layer shared by the Plesk app licence and the standalone keys. @package BeltoftServices */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\LicenceVendor;
use BeltoftServices\Tests\TestCase;

/**
 * No network. The layer goes through Clients::registrar(), so the fake client of a test answers it.
 */
final class LicenceVendorTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_client' );
		parent::tear_down();
	}

	public function test_the_client_is_the_one_clients_gives_so_a_test_or_staging_gets_the_fake(): void {
		$fake = new FakeClient();
		add_filter( 'beltoft_services_domains_client', fn() => $fake );

		$this->assertSame( $fake, LicenceVendor::client() );
	}

	public function test_a_licence_that_does_not_exist_is_gone_and_an_unknown_error_is_not(): void {
		$this->assertTrue( LicenceVendor::gone( new \WP_Error( 'op_3108', 'x' ) ) );
		$this->assertTrue( LicenceVendor::gone( new \WP_Error( 'http_404', 'x' ) ) );
		$this->assertTrue( LicenceVendor::gone( new \WP_Error( 'other', 'x', array( 'status' => 404 ) ) ) );
		$this->assertFalse( LicenceVendor::gone( new \WP_Error( 'op_199', 'x', array( 'status' => 500 ) ) ) );
		$this->assertFalse( LicenceVendor::gone( new \WP_Error( 'transport', 'x' ) ) );
	}

	public function test_transient_errors_are_the_ones_a_later_try_may_cure(): void {
		foreach ( array( new \WP_Error( 'transport', 'x' ), new \WP_Error( 'bad_response', 'x' ), new \WP_Error( 'credentials_missing', 'x' ), new \WP_Error( 'http_503', 'x', array( 'status' => 503 ) ), new \WP_Error( 'op_1', 'x', array( 'status' => 429 ) ) ) as $error ) {
			$this->assertTrue( LicenceVendor::transient( $error ), $error->get_error_code() );
		}

		foreach ( array( new \WP_Error( 'op_199', 'x', array( 'status' => 400 ) ), new \WP_Error( 'op_3108', 'x', array( 'status' => 404 ) ) ) as $error ) {
			$this->assertFalse( LicenceVendor::transient( $error ), $error->get_error_code() );
		}
	}

	public function test_a_base64_key_file_is_decoded_and_a_code_or_other_text_is_left_alone(): void {
		$xml = '<?xml version="1.0"?><plesk-key>' . str_repeat( 'x', 100 ) . '</plesk-key>';

		$this->assertSame( $xml, LicenceVendor::normalise_key( base64_encode( $xml ) ) );
		$this->assertSame( $xml, LicenceVendor::normalise_key( chunk_split( base64_encode( $xml ), 76, "\n" ) ) );
		$this->assertSame( $xml, LicenceVendor::normalise_key( ' ' . $xml . ' ' ) );
		$this->assertSame( 'ABCD-1234-EFGH', LicenceVendor::normalise_key( 'ABCD-1234-EFGH' ) );
		$plain = base64_encode( str_repeat( 'not a key file. ', 6 ) );
		$this->assertSame( $plain, LicenceVendor::normalise_key( $plain ) );
		$this->assertSame( '', LicenceVendor::normalise_key( '  ' ) );
	}

	public function test_the_layer_reaches_the_registrar_only_through_the_clients_factory(): void {
		$source = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Domains/LicenceVendor.php' );
		$code   = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $source );

		$this->assertStringNotContainsString( 'new LiveClient', $code );
		$this->assertStringNotContainsString( 'new FakeClient', $code );
		$this->assertStringNotContainsString( 'new Sandbox', $code );
		$this->assertSame( 1, substr_count( $code, 'LiveClient::' ), 'LiveClient::is_transient is the one use.' );
		$this->assertStringContainsString( 'Clients::registrar()', $code );
		$this->assertStringNotContainsString( 'Apps\\', $code );
		$this->assertStringNotContainsString( 'Licences\\', $code );
	}
}
