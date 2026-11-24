<?php
/**
 * Parked domains and the hosting edge (moved here from Domains\ParkingTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Parking;
use BeltoftServices\Hosting\ParkedEdge;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A hosting site on a parked domain replaces the parking; the edge queue and the push command.
 */
final class ParkingTest extends TestCase {

	private FakeDns $dns;
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'parking_ipv4' => '192.0.2.77', 'parking_ipv6' => '2001:db8::77' ) );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_domains_client', static fn() => new FakeClient() );
		add_filter( 'beltoft_services_domains_ns_answer', static fn() => array( 'elsewhere.example.net' ) );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		$this->user = self::factory()->user->create();
	}

	private function dns_service( string $name, array $draft = array() ): Resource {
		$product = $this->service_product( 'dns' );
		$order   = wc_create_order( array( 'customer_id' => $this->user ) );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user, 'order_id' => $order->get_id() ) );
		$claim   = DnsClaims::create( $this->user, $name );
		DnsClaims::set_status( $claim['id'], 'consumed', $draft ? array( 'draft_records' => $draft ) : array() );
		$this->assertTrue( DnsAssignments::reserve( $this->user, $claim['id'], $name ) );
		DnsAssignments::bind_order( $name, $this->user, $order->get_id(), 1 );
		$r = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	private function parked_service( string $name ): Resource {
		$r = $this->dns_service( $name );
		$this->assertTrue( ( new DnsDriver() )->provision( $r )->is_done() );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		return $r;
	}

	private function addresses( string $zone ): array {
		$out = array();
		foreach ( (array) $this->dns->zone( $zone )['rrsets'] as $set ) {
			$out[ rtrim( $set['name'], '.' ) . '|' . $set['type'] ] = array_column( $set['records'], 'content' );
		}
		ksort( $out );
		return $out;
	}

	public function test_adding_a_hosting_site_on_the_domain_replaces_parking_with_the_edge_addresses(): void {
		$this->parked_service( 'site-a.example' );
		$node = array( 'edge_ip' => '203.0.113.10', 'edge_ipv6' => '' );
		$this->assertTrue( SiteDns::publish_ours( $this->user, 'site-a.example', $node ) );
		$this->assertNull( Parking::state( 'site-a.example' ) );
		$this->assertSame( array( 'site-a.example|A' => array( '203.0.113.10' ) ), $this->addresses( 'site-a.example' ) );
		$this->assertTrue( SiteDns::publish_ours( $this->user, 'www.site-a.example', $node ) );
		$this->assertSame( array( 'site-a.example|A', 'www.site-a.example|A' ), array_keys( $this->addresses( 'site-a.example' ) ) );
	}

	public function test_a_hosting_site_on_a_subdomain_leaves_parking_alone(): void {
		$this->parked_service( 'site-b.example' );
		$this->assertTrue( SiteDns::publish_ours( $this->user, 'blog.site-b.example', array( 'edge_ip' => '203.0.113.10', 'edge_ipv6' => '' ) ) );
		$this->assertNotNull( Parking::state( 'site-b.example' ) );
		$this->assertSame( 5, count( $this->addresses( 'site-b.example' ) ) );
	}

	public function test_nothing_is_queued_for_the_edge_when_parking_is_off(): void {
		delete_option( \BeltoftServices\Settings::OPTION );
		$this->assertSame( 'off', Parking::park( 'unconfigured.example' ) );
		$this->assertFalse( get_option( ParkedEdge::PENDING, false ) );
		$this->assertCount( 0, as_get_scheduled_actions( array( 'hook' => ParkedEdge::HOOK, 'status' => 'pending', 'per_page' => 10 ) ) );
	}

	public function test_the_push_command_reports_when_hosting_answers(): void {
		require_once BELTOFT_SERVICES_TESTS_DIR . '/Support/WpCliStub.php';
		$cli = new \BeltoftServices\Domains\ParkingCommand();
		\WP_CLI::reset();
		$cli->push( array(), array() );
		$this->assertStringContainsString( 'names sent to', wp_json_encode( \WP_CLI::$lines ) );
	}
}
