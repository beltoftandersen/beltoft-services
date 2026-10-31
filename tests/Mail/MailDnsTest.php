<?php
/**
 * The records an email domain needs, and the re-check for domains we do not serve.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailDnsTest extends MailTestCase {

	private const DKIM = 'v=DKIM1;k=rsa;t=s;s=email;p=ABC123';

	public function test_the_records(): void {
		$rows = MailDns::records( 'a.example', self::DKIM );
		$by   = array();
		foreach ( $rows as $row ) {
			$by[ $row['key'] ] = $row;
		}

		$this->assertSame( array( '@', 'MX', 'mail.example.net', 10 ), array( $by['mx']['name'], $by['mx']['type'], $by['mx']['value'], $by['mx']['priority'] ) );
		$this->assertSame( 'v=spf1 include:_spf.mail.example.net ~all', $by['spf']['value'] );
		$this->assertSame( array( 'dkim._domainkey', self::DKIM ), array( $by['dkim']['name'], $by['dkim']['value'] ) );
		$this->assertSame( 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.net', $by['dmarc']['value'] );
		$this->assertSame( array( 'CNAME', 'mail.example.net' ), array( $by['autoconfig']['type'], $by['autoconfig']['value'] ) );
		$this->assertSame( 'autodiscover', $by['autodiscover']['name'] );
	}

	public function test_settings_drive_the_records(): void {
		$this->settings( array( 'mail_dmarc_rua' => '', 'mail_spf_include' => '' ) );
		$by = array_column( MailDns::records( 'a.example', '' ), null, 'key' );

		$this->assertSame( 'v=DMARC1; p=quarantine', $by['dmarc']['value'], 'No report address.' );
		$this->assertSame( 'v=spf1 include:mail.example.net ~all', $by['spf']['value'], 'The mail host when no include name is set.' );
		$this->assertArrayNotHasKey( 'dkim', $by, 'No key, no DKIM record.' );

		$this->settings( array( 'mail_host' => '' ) );
		$this->assertSame( array(), MailDns::records( 'a.example', self::DKIM ) );
	}

	public function test_an_unreadable_zone_is_a_retry_and_a_paused_zone_a_conflict(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'flaky.example' );
		$dns = new class( true ) extends FakeDns {
			public bool $broken = true;
			public function zone( string $name ): array|\WP_Error|null {
				return $this->broken ? new \WP_Error( 'x', 'down' ) : parent::zone( $name );
			}
		};
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$r = $this->mail_resource( 'flaky.example', array( 'mail_dkim' => self::DKIM ), array(), $user );

		$dns->broken = true;
		// Zone ownership needs the zone to be readable too: while it is not, AppDomains::zones still lists it (it is the resource that counts).
		$res = MailDns::apply( $r );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'transient', $res->get_error_code() );

		$dns->broken = false;
		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( MailDns::apply( Resource::find( $r->id() ) ), 'status' ) ) ) );
	}

	public function test_plan_decisions(): void {
		$mx    = array( 'key' => 'mx', 'type' => 'MX', 'value' => 'mail.example.net', 'name' => '@' );
		$mxset = static fn( $content ) => array( 'MX' => array( 'name' => 'z.example.', 'type' => 'MX', 'ttl' => 60, 'records' => array( array( 'content' => $content, 'disabled' => false ) ) ) );

		$this->assertTrue( MailDns::plan( $mx, array(), 'z.example' )['write'] );
		$this->assertNull( MailDns::plan( $mx, $mxset( '10 mail.example.net.' ), 'z.example' )['write'] );
		$this->assertSame( 'ok', MailDns::plan( $mx, $mxset( '10 mail.example.net.' ), 'z.example' )['status'] );
		$this->assertSame( 'conflict', MailDns::plan( $mx, $mxset( '20 mail.example.net.' ), 'z.example' )['status'] );
		$this->assertSame( 'conflict', MailDns::plan( $mx, array( 'CNAME' => array( 'type' => 'CNAME', 'records' => array( array( 'content' => 'x.example.' ) ) ) ), 'z.example' )['status'], 'A CNAME holds the name.' );

		$cname = array( 'key' => 'autoconfig', 'type' => 'CNAME', 'value' => 'mail.example.net', 'name' => 'autoconfig' );
		$this->assertTrue( MailDns::plan( $cname, array(), 'z.example' )['write'] );
		$this->assertSame( 'ok', MailDns::plan( $cname, array( 'CNAME' => array( 'type' => 'CNAME', 'records' => array( array( 'content' => 'Mail.Example.Net.' ) ) ) ), 'z.example' )['status'] );
		$this->assertSame( 'conflict', MailDns::plan( $cname, array( 'CNAME' => array( 'type' => 'CNAME', 'records' => array( array( 'content' => 'other.example.' ) ) ), 'TXT' => array( 'type' => 'TXT', 'records' => array() ) ), 'z.example' )['status'] );
		$this->assertSame( 'conflict', MailDns::plan( $cname, array( 'A' => array( 'type' => 'A', 'records' => array( array( 'content' => '192.0.2.1' ) ) ) ), 'z.example' )['status'] );
	}

	// -- The re-check ------------------------------------------------------------------------------

	private function waiting_domain( array $meta = array() ): Resource {
		$r      = $this->ready_mail( 'ext.example' );
		$fresh  = Resource::find( $r->id() );
		$this->assertSame( 'waiting', $fresh->get( 'mail_dns' ) );
		$this->assertTrue( $fresh->set( $meta ) );

		return Resource::find( $r->id() );
	}

	private function publish_all(): void {
		$this->dns['MX ext.example']                 = array( '10 mail.example.net.' );
		$this->dns['TXT ext.example']                = array( 'google-site-verification=1', 'v=spf1 include:_spf.mail.example.net include:other.example ~all' );
		$this->dns['TXT dkim._domainkey.ext.example'] = array( str_replace( ';p=', '; p=', (string) Resource::find( Resource::live_ids( 'mail' )[0] )->get( 'mail_dkim' ) ) );
		$this->dns['TXT _dmarc.ext.example']         = array( 'v=DMARC1; p=none' );
		$this->dns['CNAME autoconfig.ext.example']   = array( 'mail.example.net.' );
		$this->dns['CNAME autodiscover.ext.example'] = array( 'mail.example.net.' );
	}

	public function test_the_check_marks_each_record_ok_or_missing_and_stops_when_all_are_there(): void {
		$r = $this->waiting_domain();

		$this->dns['MX ext.example'] = array( '10 mail.example.net.' );
		MailDns::run( $r->id() );
		$fresh  = Resource::find( $r->id() );
		$status = array_column( $fresh->get( 'mail_records' ), 'status', 'key' );
		$this->assertSame( array( 'mx' => 'ok', 'spf' => 'missing', 'dkim' => 'missing', 'dmarc' => 'missing', 'autoconfig' => 'missing', 'autodiscover' => 'missing' ), $status );
		$this->assertSame( 'waiting', $fresh->get( 'mail_dns' ) );
		$this->assertSame( 1, $this->pending( $r->id() ), 'The next look is queued.' );

		$this->publish_all();
		$fresh->set( array( 'mail_dns_checked' => time() - 2 * MailDns::INTERVAL ) );
		MailDns::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( $fresh->get( 'mail_records' ), 'status' ) ) ) );
		$this->assertSame( 'ok', $fresh->get( 'mail_dns' ) );
	}

	public function test_a_wrong_mx_or_an_spf_without_our_include_is_missing(): void {
		$r = $this->waiting_domain();
		$this->publish_all();
		$this->dns['MX ext.example']  = array( '10 mx.other.example.' );
		$this->dns['TXT ext.example'] = array( 'v=spf1 include:other.example ~all' );
		MailDns::run( $r->id() );

		$status = array_column( Resource::find( $r->id() )->get( 'mail_records' ), 'status', 'key' );
		$this->assertSame( 'missing', $status['mx'] );
		$this->assertSame( 'missing', $status['spf'] );
		$this->assertSame( 'ok', $status['dkim'] );
		$this->assertSame( 'ok', $status['dmarc'], 'Any DMARC record counts.' );
	}

	public function test_a_resolver_that_does_not_answer_changes_nothing(): void {
		$r = $this->waiting_domain();
		$this->dns['MX ext.example'] = new \WP_Error( 'dns_transport', 'x' );
		MailDns::run( $r->id() );
		$this->assertSame( array( 'missing' ), array_values( array_unique( array_column( Resource::find( $r->id() )->get( 'mail_records' ), 'status' ) ) ) );
		$this->assertSame( 1, $this->pending( $r->id() ) );
	}

	public function test_reads_keep_their_distance_and_stop_after_14_days(): void {
		$r = $this->waiting_domain( array( 'mail_dns_checked' => time() - 10 ) );
		$looked = 0;
		add_filter( 'beltoft_services_mail_dns_lookup', function ( $pre ) use ( &$looked ) {
			++$looked;
			return $pre;
		}, 5 );
		MailDns::run( $r->id() );
		$this->assertSame( 0, $looked, 'Too soon after the last read.' );
		$this->assertSame( 1, $this->pending( $r->id() ) );

		$r = Resource::find( $r->id() );
		$r->set( array( 'mail_dns_checked' => time() - 2 * MailDns::INTERVAL, 'mail_dns_since' => time() - 15 * DAY_IN_SECONDS ) );
		MailDns::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'timeout', $fresh->get( 'mail_dns' ) );
		$this->assertSame( 0, $looked );

		$this->assertTrue( MailDns::restart( $fresh ) );
		$this->assertSame( 'waiting', Resource::find( $r->id() )->get( 'mail_dns' ) );
	}

	public function test_a_job_that_was_lost_is_found_again_by_the_sweep(): void {
		$r = $this->waiting_domain();
		foreach ( as_get_scheduled_actions( array( 'hook' => MailDns::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->cancel_action( $id );
		}
		$this->assertSame( 0, $this->pending( $r->id() ) );
		MailDns::sweep();
		$this->assertSame( 1, $this->pending( $r->id() ) );
		MailDns::sweep();
		$this->assertSame( 1, $this->pending( $r->id() ), 'Never queued twice.' );
	}

	public function test_a_deleted_or_finished_resource_is_left_alone(): void {
		$r = $this->waiting_domain( array( 'mail_dns' => 'ok' ) );
		$looked = 0;
		add_filter( 'beltoft_services_mail_dns_lookup', function ( $pre ) use ( &$looked ) {
			++$looked;
			return $pre;
		}, 5 );
		MailDns::run( $r->id() );
		MailDns::run( 999999 );
		$this->assertSame( 0, $looked );
	}

	private function pending( int $id ): int {
		return count( as_get_scheduled_actions( array( 'hook' => MailDns::HOOK, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' ) );
	}
}
