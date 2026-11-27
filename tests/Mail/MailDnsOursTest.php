<?php
/**
 * Email domains on our own name servers: the daily look reads the zone, writes back what is missing and free, and notices a
 * DNS-only zone whose name servers were moved away. FakeDns only; nothing reaches a real DNS server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\Delegation;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\Sync;
use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Mail\MailDnsState;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailHold;
use BeltoftServices\Mail\MailMoved;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';
require_once __DIR__ . '/DnsFixtures.php';

final class MailDnsOursTest extends MailTestCase {

	use DnsFixtures;

	/**
	 * A DNS hosting service (DNS only, the domain is registered elsewhere) that holds the zone for a customer, and the zone.
	 */
	private function dns_holder( int $user, string $name ): Resource {
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->save();
		$license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$claim   = DnsClaims::create( $user, $name );
		$this->assertTrue( DnsAssignments::reserve( $user, $claim['id'], $name ) );
		$r = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => $name, 'mode' => 'dns', 'claim_id' => $claim['id'] ) );
		$this->assertInstanceOf( Resource::class, $r );
		DnsAssignments::bind_order( $name, $user, $order->get_id(), 1 );
		$this->assertTrue( DnsAssignments::activate( $name, $user, (int) $license['id'], $r->id() ) );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'zone_claimed' => true, 'delegation' => 'ours', 'delegation_checked' => time() ) ) );
		$this->assertTrue( DnsClients::dns()->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * A mail domain on a zone of ours whose records were written and found.
	 *
	 * @return array{0: Resource, 1: Resource, 2: int}
	 */
	private function ours( string $domain ): array {
		$user = self::factory()->user->create();
		$zone = $this->dns_holder( $user, $domain );
		$r    = $this->ready_mail( $domain, array(), $user );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );

		$this->assertSame( 'ours', $r->get( 'mail_mode' ) );
		$this->assertSame( 'ok', $r->get( 'mail_dns' ), 'The records were written into the zone at set up.' );
		$this->mails = array();

		return array( $r, $zone, $user );
	}

	public function test_a_record_the_customer_deleted_in_our_editor_is_written_again_without_a_word(): void {
		list( $r ) = $this->ours( 'rewrite.example' );
		$this->assertTrue( Records::delete_set( 'rewrite.example', '@', 'MX' ) );
		$this->assertCount( 0, array_filter( $this->zone_rows( 'rewrite.example' ), static fn( $row ) => 'MX' === $row['type'] ) );

		MailDns::recheck( $r->id() );

		$mx = array_values( array_filter( $this->zone_rows( 'rewrite.example' ), static fn( $row ) => 'MX' === $row['type'] ) );
		$this->assertCount( 1, $mx, 'The MX is back in the zone.' );
		$this->assertSame( 'mail.example.net', $mx[0]['value'] );
		$this->assertSame( 'ok', Resource::find( $r->id() )->get( 'mail_dns' ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_an_mx_the_customer_changed_to_another_provider_is_a_loss_like_any_other_and_never_overwritten(): void {
		list( $r ) = $this->ours( 'changed.example' );
		$this->assertTrue( Records::change( 'changed.example', '@', 'MX', array( array( 'name' => '@', 'type' => 'MX', 'ttl' => 300, 'value' => 'mx.other.example', 'priority' => 5 ) ) ) );

		MailDns::recheck( $r->id() );
		$this->assertSame( 'waiting', Resource::find( $r->id() )->get( 'mail_dns' ) );
		$this->assertCount( 0, $this->customer_mails(), 'Not before the second read.' );
		MailDns::confirm( $r->id() );

		$r = Resource::find( $r->id() );
		$this->assertSame( MailDnsState::LOST, MailDnsState::of( $r ) );
		$this->assertSame( array( 'mx' ), MailDnsState::lost_keys( $r ) );
		$this->assertFalse( MailHold::held( $r ), 'A domain on our servers is never held.' );
		$this->assertSame( 1, $this->count_mails( 'DNS records are missing for changed.example' ) );
		$mx = array_values( array_filter( $this->zone_rows( 'changed.example' ), static fn( $row ) => 'MX' === $row['type'] ) );
		$this->assertSame( 'mx.other.example', $mx[0]['value'], 'The customer value was left alone.' );
	}

	public function test_a_zone_whose_name_servers_moved_away_counts_as_external_and_the_customer_is_told(): void {
		list( $r, $zone, $user ) = $this->ours( 'gone.example' );
		$this->assertTrue( MailDomain::on_our_servers( 'gone.example', $user ) );

		$zone->set( array( 'delegation' => 'other', 'delegation_checked' => time() ) );
		$this->assertTrue( MailMoved::away( 'gone.example', $user ) );
		$this->assertFalse( MailDomain::on_our_servers( 'gone.example', $user ), 'The name servers point elsewhere.' );
		$this->assertSame( 'external', MailHold::mode( Resource::find( $r->id() ) ) );

		$this->dns = array();
		MailDns::recheck( $r->id() );
		MailDns::confirm( $r->id() );

		$r = Resource::find( $r->id() );
		$this->assertSame( 'external', $r->get( 'mail_mode' ) );
		$this->assertGreaterThan( 0, (int) $r->get( 'mail_dns_moved' ) );
		$this->assertSame( MailDnsState::MOVED, MailDnsState::of( $r ) );
		$this->assertSame( 'DNS is at another provider', MailDnsState::word( $r ) );
		$this->assertStringContainsString( 'The name servers of gone.example no longer point to us.', MailDnsState::notice( $r, 'gone.example' ) );
		$this->assertTrue( MailHold::held( $r ), 'Records of another provider are looked for like any other external domain.' );

		$mail  = $this->customer_mails();
		$plain = (string) preg_replace( '/\s+/', ' ', (string) $mail[0]['message'] );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'The name servers of gone.example no longer point to us, so the records we made for you are not used.', $plain );
		$this->assertCount( 1, array_filter( $this->subjects(), static fn( $s ) => str_contains( $s, 'Mail domain left our name servers: gone.example' ) ) );
	}

	public function test_a_moved_zone_whose_records_were_made_again_elsewhere_is_no_event(): void {
		list( $r, $zone ) = $this->ours( 'fine.example' );
		$zone->set( array( 'delegation' => 'other', 'delegation_checked' => time() ) );
		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );

		MailDns::recheck( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertSame( 'external', $r->get( 'mail_mode' ) );
		$this->assertCount( 0, $this->customer_mails() );
	}

	public function test_the_delegation_of_a_zone_of_ours_is_read_again_after_the_days_of_the_setting(): void {
		list( $r, $zone ) = $this->ours( 'weekly.example' );
		$calls = 0;
		add_filter(
			'beltoft_services_dns_proof_result',
			function ( $pre, $call ) use ( &$calls ) {
				if ( 'delegation' === $call ) {
					++$calls;
					return array( 'status' => 'ok', 'reason' => 'ok', 'ds' => null, 'ours' => false );
				}

				return $pre;
			},
			5,
			2
		);

		MailMoved::watch( $r );
		$this->assertSame( 0, $calls, 'Checked a moment ago.' );

		$zone->set( array( 'delegation_checked' => time() - 8 * DAY_IN_SECONDS ) );
		MailMoved::watch( $r );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'other', Resource::find( $zone->id() )->get( 'delegation' ) );

		$this->settings( array( 'dns_delegation_recheck_days' => 30 ) );
		Resource::find( $zone->id() )->set( array( 'delegation_checked' => time() - 8 * DAY_IN_SECONDS ) );
		MailMoved::watch( $r );
		$this->assertSame( 1, $calls, 'The days are a setting.' );
	}

	public function test_the_daily_delegation_job_reads_a_zone_of_ours_again_when_it_is_old_enough(): void {
		list( , $zone ) = $this->ours( 'daily.example' );
		$this->proof['delegation'] = array( 'status' => 'ok', 'reason' => 'ok', 'ds' => null, 'ours' => false );

		Sync::delegation();
		$this->assertSame( 'ours', Resource::find( $zone->id() )->get( 'delegation' ), 'Looked at a moment ago: left alone.' );

		$zone->set( array( 'delegation_checked' => time() - 8 * DAY_IN_SECONDS ) );
		Sync::delegation();
		$this->assertSame( 'other', Resource::find( $zone->id() )->get( 'delegation' ) );
	}

	public function test_a_name_that_became_ours_gets_the_records_written_into_the_new_zone(): void {
		$user = self::factory()->user->create();
		$r    = $this->found_once( 'adopt.example' );
		// found_once made the service for a new customer: give it the customer who now moves the DNS to us.
		$this->assertSame( 'external', $r->get( 'mail_mode' ) );
		$license = $r->license();
		\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'user_id' => $user ) );
		$this->mails = array();

		$zone = $this->our_zone( $user, 'adopt.example' );
		do_action( 'beltoft_services_state_changed', $zone, 'provisioning', 'ready' );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ours', $r->get( 'mail_mode' ) );
		$this->assertSame( 'waiting', $r->get( 'mail_dns' ) );
		$this->assertTrue( Jobs::pending_action( MailDns::HOOK, array( $r->id() ) ) );

		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'ok', $r->get( 'mail_dns' ), 'Every record was written into the new zone.' );
		$types = array_column( $this->zone_rows( 'adopt.example' ), 'type' );
		$this->assertContains( 'MX', $types );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertSame( 0, $this->count_mails( 'DNS records' ), 'No mail about DNS: nothing was lost.' );
	}
}
