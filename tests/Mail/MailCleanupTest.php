<?php
/**
 * Deleting an email domain removes the records we made in a zone of ours, and only those, only with their exact values. A stale
 * DKIM key of ours does not block the same domain when it is set up again. FakeDns only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\Records;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';
require_once __DIR__ . '/DnsFixtures.php';

final class MailCleanupTest extends MailTestCase {

	use DnsFixtures;

	/**
	 * A mail domain whose records we wrote into a zone of ours.
	 *
	 * @param array<int, array<string, mixed>> $before Records the customer had before.
	 * @return array{0: Resource, 1: int}
	 */
	private function ours( string $domain, array $before = array() ): array {
		$user = self::factory()->user->create();
		$this->our_zone( $user, $domain, $before );
		$r = $this->ready_mail( $domain, array(), $user );
		$this->assertSame( 'ours', $r->get( 'mail_mode' ) );

		return array( $r, $user );
	}

	private function types( string $zone ): array {
		return array_map( static fn( $row ) => $row['type'] . ' ' . $row['name'], $this->zone_rows( $zone ) );
	}

	private function delete( Resource $r ): void {
		$res = Registry::get( 'mail' )->delete( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_done(), $res->error() );
	}

	public function test_the_records_we_wrote_are_flagged_and_the_ones_that_were_there_already_are_not(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'flag.example', array( array( 'name' => '@', 'type' => 'MX', 'ttl' => 3600, 'value' => 'mail.example.net', 'priority' => 10 ) ) );
		$r     = $this->ready_mail( 'flag.example', array(), $user );
		$flags = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			$flags[ $row['key'] ] = ! empty( $row['written_by_us'] );
		}

		$this->assertFalse( $flags['mx'], 'The customer had this MX before: not ours to remove.' );
		foreach ( array( 'spf', 'dkim', 'dmarc', 'autoconfig', 'autodiscover' ) as $key ) {
			$this->assertTrue( $flags[ $key ], $key );
		}
	}

	public function test_delete_removes_our_records_and_leaves_everything_else(): void {
		list( $r ) = $this->ours(
			'clean.example',
			array(
				array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.10' ),
				array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'google-site-verification=abc' ),
			)
		);
		$this->assertContains( 'MX @', $this->types( 'clean.example' ) );

		$this->delete( $r );

		$after = $this->zone_rows( 'clean.example' );
		$this->assertSame( array( 'A www', 'TXT @' ), array_map( static fn( $row ) => $row['type'] . ' ' . $row['name'], $after ), 'Only the customer records are left.' );
		$this->assertSame( 'google-site-verification=abc', array_values( array_filter( $after, static fn( $row ) => 'TXT' === $row['type'] ) )[0]['value'], 'The verification record in the same set stays.' );
	}

	public function test_a_record_the_customer_changed_is_not_removed(): void {
		list( $r ) = $this->ours( 'changed.example' );
		$this->assertTrue( Records::change( 'changed.example', '_dmarc', 'TXT', array( array( 'name' => '_dmarc', 'type' => 'TXT', 'ttl' => 300, 'value' => 'v=DMARC1; p=none' ) ) ) );
		$this->assertTrue( Records::change( 'changed.example', '@', 'MX', array( array( 'name' => '@', 'type' => 'MX', 'ttl' => 300, 'value' => 'mx.other.example', 'priority' => 5 ) ) ) );

		$this->delete( $r );

		$rows = $this->zone_rows( 'changed.example' );
		$this->assertContains( 'TXT _dmarc', $this->types( 'changed.example' ), 'Their DMARC policy stays.' );
		$this->assertContains( 'MX @', $this->types( 'changed.example' ), 'Their MX stays.' );
		$this->assertNotContains( 'TXT dkim._domainkey', $this->types( 'changed.example' ), 'Our DKIM, still with our value, goes.' );
		$this->assertCount( 2, $rows );
	}

	public function test_it_is_a_setting_and_off_removes_nothing(): void {
		$this->settings( array( 'dns_watch_cleanup_on_delete' => 0 ) );
		list( $r ) = $this->ours( 'keep.example' );
		$before = $this->types( 'keep.example' );

		$this->delete( $r );

		$this->assertSame( $before, $this->types( 'keep.example' ) );
	}

	public function test_a_domain_at_another_provider_never_touches_a_zone(): void {
		$r = $this->ready_mail( 'external.example' );
		$this->assertSame( 'external', $r->get( 'mail_mode' ) );
		$dns = DnsClients::dns();
		$dns->create_zone( 'external.example', array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->assertTrue( Records::replace( 'external.example', array( array( 'name' => '@', 'type' => 'MX', 'ttl' => 3600, 'value' => 'mail.example.net', 'priority' => 10 ) ) ) );

		$this->delete( $r );

		$this->assertContains( 'MX @', $this->types( 'external.example' ), 'A zone that is not the customer\'s with us is never written.' );
	}

	public function test_a_zone_that_left_our_servers_is_left_alone(): void {
		list( $r, $user ) = $this->ours( 'left.example' );
		$zone = Resource::find( \BeltoftServices\Domains\DnsAssignments::get( 'left.example' )['resource_id'] ?? 0 );
		$before = $this->types( 'left.example' );
		// The customer's domain resource that held the zone is gone: the zone is not theirs on our servers any more.
		foreach ( \BeltoftLicenses\Licenses::where( 'user_id', $user ) as $license ) {
			$other = Resource::for_license( (int) $license['id'] );
			if ( $other && 'domain' === $other->get( 'driver' ) ) {
				$other->set( array( 'state' => 'deleted' ) );
			}
		}
		$this->assertNull( $zone );

		$this->delete( $r );

		$this->assertSame( $before, $this->types( 'left.example' ) );
	}

	public function test_a_stale_dkim_key_of_ours_is_replaced_when_the_domain_is_set_up_again(): void {
		$this->settings( array( 'dns_watch_cleanup_on_delete' => 0 ) );
		list( $old, $user ) = $this->ours( 'again.example' );

		// The key we wrote earlier was another one (the mail server made a new key since): the old record and the old service remember it.
		$stale = 'v=DKIM1;k=rsa;t=s;s=email;p=OLDKEYOLDKEY';
		$rows  = (array) $old->get( 'mail_records' );
		foreach ( $rows as $i => $row ) {
			if ( 'dkim' === $row['key'] ) {
				$rows[ $i ]['value'] = $stale;
			}
		}
		$old->set( array( 'mail_records' => $rows ) );
		update_option( MailDns::WRITTEN_OPTION, array( 'again.example' => array( 'dkim' => array( $stale ) ) ), false );
		$this->assertTrue( Records::change( 'again.example', 'dkim._domainkey', 'TXT', array( array( 'name' => 'dkim._domainkey', 'type' => 'TXT', 'ttl' => 3600, 'value' => $stale ) ) ) );
		$this->delete( $old );
		$old->set( array( 'state' => 'deleted' ) );

		$new = $this->ready_mail( 'again.example', array(), $user );

		$this->assertNotSame( $stale, (string) $new->get( 'mail_dkim' ) );
		$rows = array_column( (array) $new->get( 'mail_records' ), null, 'key' );
		$this->assertSame( 'ok', $rows['dkim']['status'], 'Replaced, not a dkim_other conflict.' );
		$dkim = array_values( array_filter( $this->zone_rows( 'again.example' ), static fn( $row ) => 'dkim._domainkey' === $row['name'] ) );
		$this->assertCount( 1, $dkim );
		$this->assertSame( (string) $new->get( 'mail_dkim' ), $dkim[0]['value'] );
	}

	public function test_a_dkim_key_the_customer_made_is_never_replaced(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'theirs.example', array( array( 'name' => 'dkim._domainkey', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=DKIM1; k=rsa; p=THEIRS' ) ) );
		$r    = $this->ready_mail( 'theirs.example', array(), $user );
		$rows = array_column( (array) $r->get( 'mail_records' ), null, 'key' );

		$this->assertSame( 'conflict', $rows['dkim']['status'] );
		$this->assertSame( 'dkim_other', $rows['dkim']['reason'] );
		$this->assertStringContainsString( 'THEIRS', (string) array_values( array_filter( $this->zone_rows( 'theirs.example' ), static fn( $row ) => 'dkim._domainkey' === $row['name'] ) )[0]['value'] );
	}
}
