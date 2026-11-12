<?php
/**
 * The daily look at an email domain hosted elsewhere after its records were found. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Mail\MailHold;
use BeltoftServices\Mail\MailView;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailDnsRecheckTest extends MailTestCase {

	/**
	 * Answers public DNS for a domain with the records named (see MailHoldTest::found).
	 *
	 * @param Resource $r      Resource.
	 * @param string[] $only   Record keys that are there.
	 */
	private function found( Resource $r, array $only ): void {
		$domain = (string) $r->get( 'mail_domain' );
		$map    = array(
			'mx'           => array( 'MX ' . $domain, array( '10 mail.example.net.' ) ),
			'spf'          => array( 'TXT ' . $domain, array( 'v=spf1 include:_spf.mail.example.net ~all' ) ),
			'dkim'         => array( 'TXT dkim._domainkey.' . $domain, array( (string) $r->get( 'mail_dkim' ) ) ),
			'dmarc'        => array( 'TXT _dmarc.' . $domain, array( 'v=DMARC1; p=none' ) ),
			'autoconfig'   => array( 'CNAME autoconfig.' . $domain, array( 'mail.example.net.' ) ),
			'autodiscover' => array( 'CNAME autodiscover.' . $domain, array( 'mail.example.net.' ) ),
		);

		$this->dns = array();

		foreach ( $only as $key ) {
			$this->dns[ $map[ $key ][0] ] = $map[ $key ][1];
		}
	}

	/**
	 * A ready domain whose records were found once and whose mailbox can send.
	 */
	private function found_once( string $domain ): Resource {
		$r = $this->ready_mail( $domain );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );
		$this->assertIsArray( Mailboxes::create( Resource::find( $r->id() ), 'anna' ) );

		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		$r->set( array( 'mail_dns_checked' => time() - 2 * MailDns::INTERVAL ) );
		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertSame( 'lifted', $r->get( 'mail_hold' ) );
		$this->assertTrue( $this->mailcow->mailbox( 'anna@' . $domain )['smtp'] );
		$this->mails = array();

		return $r;
	}

	public function test_the_daily_sweep_queues_a_look_only_for_domains_whose_records_were_found(): void {
		$ok      = $this->found_once( 'one.example' );
		$waiting = $this->ready_mail( 'two.example' );

		MailDns::recheck_all();

		$this->assertTrue( Jobs::pending_action( MailDns::RECHECK, array( $ok->id() ) ) );
		$this->assertFalse( Jobs::pending_action( MailDns::RECHECK, array( $waiting->id() ) ), 'The ten minute look has it.' );
	}

	public function test_the_daily_look_is_scheduled_by_the_job_registry(): void {
		Jobs::force_recurring();

		$hooks = array_column( Jobs::declared(), 'hook' );
		$this->assertContains( MailDns::RECHECK_ALL, $hooks );
	}

	public function test_a_healthy_domain_is_read_once_and_the_recommended_records_show_their_real_state(): void {
		$r = $this->found_once( 'good.example' );
		$this->found( $r, array( 'mx', 'spf', 'dkim', 'dmarc' ) );

		MailDns::recheck( $r->id() );

		$r    = Resource::find( $r->id() );
		$rows = array_column( (array) $r->get( 'mail_records' ), 'status', 'key' );
		$this->assertSame( array( 'mx' => 'ok', 'spf' => 'ok', 'dkim' => 'ok', 'dmarc' => 'ok', 'autoconfig' => 'missing', 'autodiscover' => 'missing' ), $rows );
		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertCount( 0, $this->mails );
		$this->assertGreaterThan( 0, (int) $r->get( 'mail_dns_rechecked' ) );
	}

	public function test_at_most_one_look_a_day(): void {
		$r = $this->found_once( 'once.example' );
		$looks = 0;
		add_filter(
			'beltoft_services_mail_dns_lookup',
			static function ( $answer ) use ( &$looks ) {
				++$looks;

				return $answer;
			},
			5
		);

		MailDns::recheck( $r->id() );
		$first = $looks;
		$this->assertSame( 6, $first, 'One lookup per record.' );

		MailDns::recheck( $r->id() );
		$this->assertSame( $first, $looks, 'The second call the same day reads nothing.' );

		remove_all_filters( 'beltoft_services_mail_dns_lookup' );
	}

	public function test_a_lost_spf_record_holds_sending_mails_the_customer_once_and_goes_back_to_waiting(): void {
		$r = $this->found_once( 'lost.example' );
		$this->found( $r, array( 'mx', 'dkim' ) );

		MailDns::recheck( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'waiting', $r->get( 'mail_dns' ) );
		$this->assertTrue( MailHold::held( $r ) );
		$this->assertSame( 'held', $r->get( 'mail_hold' ) );
		$this->assertFalse( $this->mailcow->mailbox( 'anna@lost.example' )['smtp'], 'Sending is switched off again.' );
		$this->assertNotSame( '', MailHold::notice( $r ), 'The page shows the held line.' );
		$this->assertTrue( Jobs::pending_action( MailDns::HOOK, array( $r->id() ) ), 'The ten minute look takes over.' );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'DNS records are still missing for lost.example', $this->mails[0]['subject'] );
		$this->assertSame( array( 'christian.beltoft@gmail.com' ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'v=spf1', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'cannot send until the SPF and DKIM records are found', (string) $this->mails[0]['message'] );

		$this->assertTrue( MailView::data( $r, array() )['send_held'] );

		// The ten minute look sends no second mail, even a day later.
		$r->set( array( 'mail_dns_since' => time() - 2 * DAY_IN_SECONDS ) );
		MailDns::remind( $r->id() );
		$this->assertCount( 1, $this->mails );
	}

	public function test_when_the_record_comes_back_the_existing_look_lifts_the_hold(): void {
		$r = $this->found_once( 'back.example' );
		$this->found( $r, array( 'mx', 'dkim' ) );
		MailDns::recheck( $r->id() );
		$this->assertTrue( MailHold::held( Resource::find( $r->id() ) ) );

		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		$r = Resource::find( $r->id() );
		$r->set( array( 'mail_dns_checked' => time() - 2 * MailDns::INTERVAL ) );
		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertSame( 'lifted', $r->get( 'mail_hold' ) );
		$this->assertTrue( $this->mailcow->mailbox( 'anna@back.example' )['smtp'] );
	}

	public function test_a_flaky_first_answer_does_not_hold_anything(): void {
		$r = $this->found_once( 'flaky.example' );
		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		$seen = 0;

		// The SPF name answers empty once, then holds again.
		add_filter(
			'beltoft_services_mail_dns_lookup',
			function ( $answer, $fqdn, $type ) use ( &$seen ) {
				if ( 'TXT' === $type && 'flaky.example' === $fqdn && 0 === $seen++ ) {
					return array();
				}

				return $answer;
			},
			5,
			3
		);

		MailDns::recheck( $r->id() );
		remove_all_filters( 'beltoft_services_mail_dns_lookup' );

		$r = Resource::find( $r->id() );
		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_a_failed_lookup_changes_nothing(): void {
		$r = $this->found_once( 'down.example' );
		$this->dns = array(
			'MX down.example'                  => new \WP_Error( 'down', 'down' ),
			'TXT down.example'                 => new \WP_Error( 'down', 'down' ),
			'TXT dkim._domainkey.down.example' => new \WP_Error( 'down', 'down' ),
		);

		MailDns::recheck( $r->id() );

		$r = Resource::find( $r->id() );
		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertSame( 'ok', array_column( (array) $r->get( 'mail_records' ), 'status', 'key' )['spf'] );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_a_domain_on_our_servers_is_never_looked_at_this_way(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'ours.example' );
		$r = $this->ready_mail( 'ours.example', array(), $user );
		$r->set( array( 'mail_dns' => 'ok', 'mail_mode' => 'ours' ) );
		$looks = 0;
		add_filter(
			'beltoft_services_mail_dns_lookup',
			static function ( $answer ) use ( &$looks ) {
				++$looks;

				return $answer;
			},
			5
		);

		MailDns::recheck( $r->id() );

		remove_all_filters( 'beltoft_services_mail_dns_lookup' );
		$this->assertSame( 0, $looks );
		$this->assertSame( 'ok', Resource::find( $r->id() )->get( 'mail_dns' ) );
		$this->assertFalse( MailHold::held( Resource::find( $r->id() ) ) );
	}

	public function test_a_domain_that_is_waiting_or_not_ready_is_left_to_the_other_jobs(): void {
		$waiting = $this->ready_mail( 'wait.example' );
		$this->assertSame( 'waiting', $waiting->get( 'mail_dns' ) );
		$this->dns = array();
		MailDns::recheck( $waiting->id() );
		$this->assertSame( 0, (int) Resource::find( $waiting->id() )->get( 'mail_dns_rechecked' ) );

		$ok = $this->found_once( 'paused.example' );
		$ok->set( array( 'state' => 'paused' ) );
		$this->dns = array();
		MailDns::recheck( $ok->id() );
		$this->assertSame( 0, (int) Resource::find( $ok->id() )->get( 'mail_dns_rechecked' ) );
	}
}
