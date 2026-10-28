<?php
/** DNS-only subscriptions end at once. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DnsCancelTest extends TestCase {
	private function subscription( array $drivers, string $price = '0' ): \WC_Subscription {
		$user = self::factory()->user->create();
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'active' ) );
		foreach ( $drivers as $driver ) {
			$product = $this->service_product( $driver );
			$sub->add_product( $product, 1, array( 'subtotal' => $price, 'total' => $price ) );
		}
		$sub->calculate_totals();
		$sub->save();
		$sub->update_status( 'active' );
		return $sub;
	}

	public function test_a_free_dns_subscription_is_cancelled_at_once(): void {
		$sub = $this->subscription( array( 'dns' ) );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'cancelled', wcs_get_subscription( $sub->get_id() )->get_status() );
		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( $note ) => str_contains( $note, 'DNS hosting ends at once when cancelled.' ) ) );
	}

	public function test_a_paid_dns_subscription_is_left_alone(): void {
		$sub = $this->subscription( array( 'dns' ), '10' );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_a_mixed_subscription_is_left_alone(): void {
		$sub = $this->subscription( array( 'dns', 'domain' ) );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_another_driver_is_left_alone(): void {
		$sub = $this->subscription( array( 'fake' ) );
		$sub->update_status( 'pending-cancel' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_cancel_expires_the_license_at_once(): void {
		$sub = $this->subscription( array( 'dns' ) );
		$this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => (int) $sub->get_user_id(), 'subscription_id' => $sub->get_id() ) );
		$this->assertNotEmpty( Licenses::where( 'subscription_id', $sub->get_id() ), 'The subscription holds a license.' );
		$sub->update_status( 'pending-cancel' );
		foreach ( Licenses::where( 'subscription_id', $sub->get_id() ) as $license ) {
			$this->assertNotEmpty( $license['expires_at'] );
			$this->assertLessThan( time(), strtotime( $license['expires_at'] . ' UTC' ) );
			$this->assertSame( Licenses::STATUS_EXPIRED, Licenses::effective_status( $license ) );
		}
	}

	public function test_cancel_pauses_the_service_and_switches_the_records_off_at_once(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$name = 'cancel-' . wp_generate_password( 6, false, false ) . '.com';
		$user = self::factory()->user->create();
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'active' ) );
		$sub->add_product( $this->service_product( 'dns' ), 1, array( 'subtotal' => '0', 'total' => '0' ) );
		$sub->calculate_totals();
		$sub->save();
		$license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$claim   = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, $claim['id'], $name ) );
		$r = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertInstanceOf( Resource::class, $r );
		DnsAssignments::bind_order( $name, $user, 1, 1 );
		$this->assertTrue( DnsAssignments::activate( $name, $user, (int) $license['id'], $r->id() ) );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'zone_claimed' => true ) ) );
		$dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		$dns->replace_rrsets( $name, array( array( 'name' => $name . '.', 'type' => 'A', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '192.0.2.10', 'disabled' => false ) ) ) ) );

		$sub->update_status( 'pending-cancel' );

		$this->assertSame( 'cancelled', wcs_get_subscription( $sub->get_id() )->get_status() );
		$this->assertSame( Licenses::STATUS_EXPIRED, Licenses::effective_status( Licenses::find( (int) $license['id'] ) ) );
		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'desired' ) );
		$this->run_jobs();
		$this->assertTrue( $dns->zone( $name )['rrsets'][0]['records'][0]['disabled'] );
	}

	public function test_an_expiry_already_at_now_minus_one_still_fires_the_action_and_pauses(): void {
		$sub = $this->subscription( array( 'dns' ) );
		$license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => (int) $sub->get_user_id(), 'subscription_id' => $sub->get_id() ) );
		$r = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => 'same-second.example.com', 'mode' => 'dns' ) );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready' ) ) );
		$fired = 0;
		add_action( 'beltoft_licenses_expiry_changed', static function () use ( &$fired ) { ++$fired; } );
		// Licenses::ended() already stored a value equal to the one the old code wrote.
		add_action( 'woocommerce_subscription_status_pending-cancel', function () use ( $license ) {
			Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 1 ) );
		}, 15 );
		$sub->update_status( 'pending-cancel' );
		$this->assertGreaterThan( 0, $fired );
		$this->assertLessThanOrEqual( time() - 1, strtotime( Licenses::find( (int) $license["id"] )["expires_at"] . " UTC" ) );
		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'desired' ) );
	}
}
