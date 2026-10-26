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
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'dnskey' => '257 3 13 aaaa', 'active' => true ) ), 'response' => array( 'code' => 201, 'message' => 'Created' ), 'cookies' => array() );
		} );
		$this->assertSame( array( array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'aaaa' ) ), $dns->secure( 'example.com' ) );
	}
}
