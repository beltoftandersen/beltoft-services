<?php
/** Production DNS needs the live constant AND the live registrar mode. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\LiveDns;
use BeltoftServices\Tests\TestCase;

class DomainsLiveWiringTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sandbox_with_the_live_constant_gives_fake_dns(): void {
		define( 'BELTOFT_SERVICES_DOMAINS_LIVE', true );
		$this->settings( array( 'domain_api_mode' => 'sandbox' ) );
		$this->assertTrue( Clients::live_allowed() );
		$this->assertSame( 'sandbox', Clients::mode() );
		$this->assertInstanceOf( FakeDns::class, Clients::dns() );

		$live = new LiveDns( 'http://127.0.0.1:8081', 'not-a-key', true );
		add_filter( 'beltoft_services_domains_dns', static fn() => $live );
		$this->assertNotSame( $live, Clients::dns() );
		$this->assertInstanceOf( FakeDns::class, Clients::dns() );
		$this->assertSame( array(), $this->http_calls() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_live_mode_with_the_constant_gives_live_dns(): void {
		define( 'BELTOFT_SERVICES_DOMAINS_LIVE', true );
		$this->settings( array( 'domain_api_mode' => 'live' ) );
		$this->assertSame( 'live', Clients::mode() );
		$this->assertInstanceOf( LiveDns::class, Clients::dns() );

		$live = new LiveDns( 'http://127.0.0.1:8081', 'not-a-key', true );
		add_filter( 'beltoft_services_domains_dns', static fn() => $live );
		$this->assertSame( $live, Clients::dns() );
		$this->assertSame( array(), $this->http_calls() );
	}
}
