<?php
/** Domain client factory tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Tests\TestCase;

class ClientsTest extends TestCase {
	public function test_default_clients_are_fakes(): void {
		$this->assertSame( 'fake', Clients::mode() );
		$this->assertInstanceOf( FakeClient::class, Clients::registrar() );
		$this->assertInstanceOf( FakeDns::class, Clients::dns() );
	}

	public function test_live_mode_without_constant_falls_back_to_fake(): void {
		$this->settings( array( 'domain_api_mode' => 'live' ) );
		$this->assertFalse( Clients::live_allowed() );
		$this->assertSame( 'fake', Clients::mode() );
		$this->assertInstanceOf( FakeClient::class, Clients::registrar() );
	}

	public function test_sandbox_mode_uses_sandbox_url_without_constant_and_dns_stays_fake(): void {
		$this->settings( array( 'domain_api_mode' => 'sandbox' ) );
		$this->assertSame( 'sandbox', Clients::mode() );
		$this->assertSame( 'https://api.sandbox.openprovider.nl/v1beta', Clients::base_url( 'sandbox' ) );
		$this->assertInstanceOf( LiveClient::class, Clients::registrar() );
		$this->assertInstanceOf( FakeDns::class, Clients::dns() );
	}

	public function test_filter_cannot_bypass_live_guard(): void {
		add_filter( 'beltoft_services_domains_client', static fn() => new FakeClient() );
		$this->assertInstanceOf( FakeClient::class, Clients::registrar() );
		$this->assertFalse( Clients::live_allowed() );
	}
}
