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
}
