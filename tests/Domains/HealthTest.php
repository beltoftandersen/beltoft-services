<?php
/** Registrar balance alert tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Health;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class HealthTest extends TestCase {
	public function test_low_balance_alerts_once_per_day(): void {
		delete_option( Health::LOW_ALERT );
		$this->settings( array( 'domain_low_balance' => 50, 'admin_email' => 'admin@example.com' ) );
		$op = new FakeClient();
		$op->reply( 'GET', '/resellers', array( 'balance' => 12.5, 'currency' => 'EUR' ) );
		$op->reply( 'GET', '/resellers', array( 'balance' => 12.5, 'currency' => 'EUR' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$this->assertSame( 12.5, Health::balance() );
		$this->assertSame( 12.5, Health::balance() );
		$this->assertSame( gmdate( 'Y-m-d' ), get_option( Health::LOW_ALERT ) );
	}

	public function test_dns_check_reports_orphan_zone(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		$dns->create_zone( 'orphan-example.net', array( 'ns1.example.net', 'ns2.example.net' ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$issues = Health::dns_check();
		$this->assertContains( 'orphan-example.net: orphan zone', $issues );
	}

	public function test_dns_check_skips_the_sites_own_zones(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_infrastructure_zones' => 'Example.net., own-ns.example' ) );
		$dns = new FakeDns();
		$dns->create_zone( 'example.net', array( 'ns1.example.net', 'ns2.example.net' ) );
		$dns->create_zone( 'own-ns.example', array( 'ns1.example.net', 'ns2.example.net' ) );
		$dns->create_zone( 'stray-example.net', array( 'ns1.example.net', 'ns2.example.net' ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$issues = Health::dns_check();
		$this->assertSame( array( 'stray-example.net: orphan zone' ), $issues );
	}

	public function test_dns_check_reports_old_serial_mismatch(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		$dns->create_zone( 'mismatch-example.net', array( 'ns1.example.net', 'ns2.example.net' ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$product = $this->service_product( 'domain' );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'state' => 'ready', 'domain' => 'mismatch-example.net', 'zone_claimed' => true, 'dns_mismatch_since' => time() - 16 * MINUTE_IN_SECONDS ) );
		$this->assertInstanceOf( Resource::class, $r );
		add_filter( 'beltoft_services_dns_query', static fn( $value, $server, $name, $type ) => 'mismatch-example.net' === $name && 6 === $type ? 2 : $value, 10, 4 );
		$this->assertContains( 'mismatch-example.net: SOA serial mismatch for over 15 minutes', Health::dns_check() );
	}
}
