<?php
/** PowerDNS client tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\LiveDns;
use BeltoftServices\Tests\TestCase;

class LiveDnsTest extends TestCase {
	public function test_powerdns_sends_x_api_key_to_localhost_only(): void {
		$this->expectException( \InvalidArgumentException::class );
		new LiveDns( 'http://example.com:8081', 'secret', true );
	}

	public function test_secure_returns_dnskey(): void {
		$dns = new LiveDns( 'http://127.0.0.1:8081', 'secret', true );
		$this->respond_with( function ( $url, $args ) {
			$this->assertStringStartsWith( 'http://127.0.0.1:8081/', $url );
			$this->assertSame( 'secret', $args['headers']['X-API-Key'] );
			if ( str_ends_with( $url, '/cryptokeys' ) ) {
				$body = array( array( 'dnskey' => '257 3 13 aaaa', 'active' => true, 'keytype' => 'ksk' ) );
			} elseif ( 'PUT' === $args['method'] ) {
				$this->assertSame( array( 'dnssec' => true, 'api_rectify' => true ), json_decode( $args['body'], true ) );
				$body = array( 'dnssec' => true );
			} else {
				$body = array( 'dnssec' => false );
			}
			return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
		} );
		$this->assertSame( array( array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'aaaa' ) ), $dns->secure( 'example.com' ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: bool}> */
	public static function key_types(): array {
		return array(
			'a combined signing key (what PowerDNS reports for one key)' => array( 'csk', '257 3 13 bbbb', true ),
			'a key signing key' => array( 'ksk', '257 3 13 bbbb', true ),
			'a zone signing key only' => array( 'zsk', '256 3 13 bbbb', false ),
		);
	}

	/** @dataProvider key_types */
	public function test_secure_accepts_a_combined_or_key_signing_key_but_not_a_zone_signing_key( string $type, string $dnskey, bool $accepted ): void {
		$dns = new LiveDns( 'http://127.0.0.1:8081', 'secret', true );
		$this->respond_with( function ( $url, $args ) use ( $type, $dnskey ) {
			$body = str_ends_with( $url, '/cryptokeys' ) ? array( array( 'dnskey' => $dnskey, 'active' => true, 'keytype' => $type ) ) : array( 'dnssec' => true, 'api_rectify' => true );
			return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
		} );
		$result = $dns->secure( 'example.com' );
		if ( $accepted ) {
			$this->assertSame( 'bbbb', $result[0]['pub_key'] );
		} else {
			$this->assertWPError( $result );
		}
	}

	public function test_a_page_client_waits_a_few_seconds_and_a_job_client_thirty(): void {
		$dns = new LiveDns( 'http://127.0.0.1:8081', 'secret', true );
		$seen = array();
		$this->respond_with( function ( $url, $args ) use ( &$seen ) {
			$seen[] = $args['timeout'];
			return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
		} );
		$dns->zone( 'example.com' );
		$dns->with_timeout( 5 )->zone( 'example.com' );
		$dns->with_timeout( 500 )->zone( 'example.com' );
		$this->assertSame( array( 30, 5, 30 ), $seen, 'The short client is a copy; the cap is thirty.' );
	}
}
