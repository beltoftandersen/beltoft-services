<?php
/**
 * "Check DNS now" as a shared action: queued by the route, done by a job that stores every record as it is published.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Jobs;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailDnsCheckJobTest extends MailTestCase {

	private const DKIM = 'v=DKIM1;k=rsa;t=s;s=email;p=ABC123';

	/** A domain at another provider that waits for its records. */
	private function waiting_domain(): Resource {
		$r = $this->ready_mail( 'ext.example' );
		$this->assertSame( 'waiting', Resource::find( $r->id() )->get( 'mail_dns' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * A domain on our name servers whose records are not published yet.
	 *
	 * @param FakeDns $dns The DNS server the plugin uses.
	 */
	private function our_waiting_domain( FakeDns $dns ): Resource {
		$user = self::factory()->user->create();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$this->our_zone( $user, 'ours.example' );
		$rows = array_map( static fn( $row ) => $row + array( 'status' => 'missing', 'reason' => '' ), MailDns::records( 'ours.example', self::DKIM ) );
		$r    = $this->mail_resource( 'ours.example', array( 'mail_dkim' => self::DKIM, 'mail_records' => $rows, 'mail_dns' => 'waiting', 'mail_dns_since' => time() ), array(), $user );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	public function test_the_check_is_a_shared_action_of_the_mail_driver_and_never_offered_in_a_list(): void {
		$driver = \BeltoftServices\Drivers\Registry::get( 'mail' );
		$this->assertSame( array( 'mailbox_create', 'mailbox_password', 'mail_dns_check' ), $driver->internal_actions() );
		$this->assertSame( array(), $driver->customer_actions( $this->waiting_domain() ) );
		foreach ( Mailboxes::ACTIONS as $action ) {
			$this->assertTrue( (bool) apply_filters( 'beltoft_services_action_resumable', false, 'mail', $action ), $action );
		}
		$this->assertFalse( (bool) apply_filters( 'beltoft_services_action_resumable', false, 'mail', 'start' ) );
	}

	public function test_the_job_reads_the_records_and_leaves_an_outcome(): void {
		$r = $this->waiting_domain();
		$this->dns['MX ext.example'] = array( '10 mail.example.net.' );
		$this->assertTrue( Jobs::start_action( $r, Mailboxes::DNS_CHECK, 'Checking' ) );
		$this->assertSame( Mailboxes::DNS_CHECK, Progress::pending( Resource::find( $r->id() ) )['action'] );

		$this->run_jobs();
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ok', array_column( $fresh->get( 'mail_records' ), 'status', 'key' )['mx'] );
		$this->assertSame( 'waiting', $fresh->get( 'mail_dns' ) );
		$this->assertTrue( $fresh->get( 'last_result' )['ok'] );
		$this->assertSame( Mailboxes::DNS_CHECK, $fresh->get( 'last_result' )['action'] );
		$this->assertNull( Progress::pending( $fresh ) );
	}

	public function test_a_second_check_while_one_is_pending_is_refused(): void {
		$r = $this->waiting_domain();
		$this->assertTrue( Jobs::start_action( $r, Mailboxes::DNS_CHECK, 'Checking' ) );
		$second = Jobs::start_action( Resource::find( $r->id() ), Mailboxes::DNS_CHECK, 'Checking' );
		$this->assertWPError( $second );
		$this->assertSame( 'busy', $second->get_error_code() );
	}

	public function test_a_job_that_stops_part_way_has_stored_what_it_published_and_the_replay_finishes(): void {
		$dns = new class( true ) extends FakeDns {
			public int $writes     = 0;
			public int $explode_at = 0;
			public function replace_rrsets( string $zone, array $rrsets ): bool|\WP_Error {
				++$this->writes;
				if ( $this->explode_at > 0 && $this->writes === $this->explode_at ) {
					throw new \RuntimeException( 'The worker was stopped.' );
				}
				return parent::replace_rrsets( $zone, $rrsets );
			}
		};
		$r   = $this->our_waiting_domain( $dns );
		// Writes made while the fixture was built do not count.
		$dns->writes     = 0;
		$dns->explode_at = 3;

		$this->assertTrue( Jobs::start_action( $r, Mailboxes::DNS_CHECK, 'Checking' ) );
		// The job alone (the look that it schedules in the background is not run here).
		Jobs::act( $r->id(), Mailboxes::DNS_CHECK, array() );

		$mid    = Resource::find( $r->id() );
		$status = array_column( $mid->get( 'mail_records' ), 'status', 'key' );
		$this->assertSame( 2, count( array_filter( $status, static fn( $s ) => 'ok' === $s ) ), 'The two records that were published are stored, not lost with the loop.' );
		$this->assertNull( $mid->get( 'last_result' ), 'Not over: it is tried again.' );
		$this->assertNotNull( Progress::pending( $mid ) );

		$dns->explode_at = 0;
		Jobs::act( $r->id(), Mailboxes::DNS_CHECK, array() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( $fresh->get( 'mail_records' ), 'status' ) ) ) );
		$this->assertSame( 'ok', $fresh->get( 'mail_dns' ) );
		$this->assertTrue( $fresh->get( 'last_result' )['ok'] );

		// Replayed again once it is all done: nothing is published twice.
		$writes = $dns->writes;
		Jobs::act( $r->id(), Mailboxes::DNS_CHECK, array() );
		$this->assertSame( $writes, $dns->writes );
	}

	public function test_a_check_for_a_service_on_its_way_out_is_dropped_with_a_result_and_reads_nothing(): void {
		$r = $this->waiting_domain();
		$this->assertTrue( Jobs::start_action( $r, Mailboxes::DNS_CHECK, 'Checking' ) );
		$looked = 0;
		add_filter( 'beltoft_services_mail_dns_lookup', function ( $pre ) use ( &$looked ) { ++$looked; return $pre; }, 5 );
		Resource::find( $r->id() )->set( array( 'desired' => 'deleted' ) );

		Jobs::act( $r->id(), Mailboxes::DNS_CHECK, array() );
		$fresh = Resource::find( $r->id() );
		$this->assertFalse( $fresh->get( 'last_result' )['ok'] );
		$this->assertNull( Progress::pending( $fresh ) );
		$this->assertSame( 0, $looked );
	}
}
