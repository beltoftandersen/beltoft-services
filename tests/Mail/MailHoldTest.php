<?php
/**
 * Needed and recommended DNS records, and sending held until SPF and DKIM are found. Fake mail client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailDns;
use BeltoftServices\Mail\MailHold;
use BeltoftServices\Mail\MailView;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailHoldTest extends MailTestCase {

	private function found( string $domain, array $only ): void {
		$r   = Resource::find( Resource::live_ids( 'mail' )[0] );
		$map = array(
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

	private function check( Resource $r ): Resource {
		$r->set( array( 'mail_dns_checked' => time() - 2 * MailDns::INTERVAL ) );
		MailDns::run( $r->id() );

		return Resource::find( $r->id() );
	}

	public function test_needed_and_recommended(): void {
		$this->assertSame( array( true, true, true, false, false, false ), array_map( array( MailDns::class, 'required' ), array( 'mx', 'spf', 'dkim', 'dmarc', 'autoconfig', 'autodiscover' ) ) );
	}

	public function test_complete_with_only_the_needed_three(): void {
		$row = static fn( $key, $status ) => array( 'key' => $key, 'status' => $status );

		$this->assertTrue( MailDns::complete( array( $row( 'mx', 'ok' ), $row( 'spf', 'ok' ), $row( 'dkim', 'ok' ), $row( 'dmarc', 'missing' ), $row( 'autoconfig', 'conflict' ), $row( 'autodiscover', 'missing' ) ) ) );
		$this->assertFalse( MailDns::complete( array( $row( 'mx', 'ok' ), $row( 'spf', 'missing' ), $row( 'dkim', 'ok' ), $row( 'dmarc', 'ok' ) ) ) );
		$this->assertFalse( MailDns::complete( array( $row( 'mx', 'ok' ), $row( 'spf', 'ok' ) ) ), 'No DKIM row is not complete.' );
		$this->assertFalse( MailDns::complete( array() ) );
	}

	public function test_the_check_finishes_on_the_needed_three_and_still_reports_the_recommended(): void {
		$r = $this->ready_mail( 'ext.example' );
		$this->found( 'ext.example', array( 'mx', 'spf', 'dkim' ) );
		$r    = $this->check( $r );
		$rows = array_column( (array) $r->get( 'mail_records' ), 'status', 'key' );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertSame( array( 'mx' => 'ok', 'spf' => 'ok', 'dkim' => 'ok', 'dmarc' => 'missing', 'autoconfig' => 'missing', 'autodiscover' => 'missing' ), $rows );
		$this->assertSame( array(), MailDns::missing_rows( $r, true ) );
		$this->assertCount( 3, MailDns::missing_rows( $r ) );

		$guide = array_column( MailView::data( $r, array() )['dns']['guide']['records'], 'required', 'key' );
		$this->assertSame( array( 'mx' => true, 'spf' => true, 'dkim' => true, 'dmarc' => false, 'autoconfig' => false, 'autodiscover' => false ), $guide );
	}

	public function test_a_missing_needed_record_keeps_it_waiting_and_the_reminder_names_only_needed_ones(): void {
		$r = $this->ready_mail( 'rem.example' );
		$this->found( 'rem.example', array( 'mx', 'dmarc' ) );
		$r = $this->check( $r );
		$this->assertSame( 'waiting', $r->get( 'mail_dns' ) );

		$r->set( array( 'mail_dns_since' => time() - DAY_IN_SECONDS - 60 ) );
		MailDns::remind( $r->id() );

		$this->assertCount( 1, $this->mails );
		$text = (string) $this->mails[0]['message'];
		$this->assertStringContainsString( 'dkim._domainkey.rem.example', $text );
		$this->assertStringContainsString( 'v=spf1', $text );
		$this->assertStringNotContainsString( '_dmarc.rem.example', $text );
		$this->assertStringNotContainsString( 'autoconfig.rem.example', $text );
		$this->assertStringContainsString( 'cannot send until the SPF and DKIM records are found', (string) preg_replace( '/\s+/', ' ', $text ) ); // A plain-text mail wraps its lines.
	}

	public function test_no_reminder_when_only_recommended_records_are_missing(): void {
		$r = $this->ready_mail( 'rec.example' );
		$this->found( 'rec.example', array( 'mx', 'spf', 'dkim' ) );
		$r = $this->check( $r );
		$r->set( array( 'mail_dns' => 'waiting', 'mail_dns_since' => time() - 3 * DAY_IN_SECONDS ) );
		MailDns::remind( $r->id() );

		$this->assertCount( 0, $this->mails );
	}

	public function test_a_new_mailbox_on_a_domain_elsewhere_is_held_and_every_other_domain_is_left_alone(): void {
		$this->mailcow->add_domain( array( 'domain' => 'other.example', 'mailboxes' => '5', 'maxquota' => '1000' ) );
		$this->mailcow->add_mailbox( array( 'local_part' => 'bob', 'domain' => 'other.example', 'quota' => '10' ) );
		$r = $this->ready_mail( 'held.example' );

		$this->assertTrue( MailHold::held( $r ) );
		$this->assertIsArray( Mailboxes::create( $r, 'anna' ) );
		$this->assertIsArray( Mailboxes::create( $r, 'carl' ) );

		$this->assertFalse( $this->mailcow->mailbox( 'anna@held.example' )['smtp'] );
		$this->assertFalse( $this->mailcow->mailbox( 'carl@held.example' )['smtp'] );
		$this->assertTrue( $this->mailcow->mailbox( 'bob@other.example' )['smtp'], 'Another domain is never touched.' );
		$this->assertSame( '1', $this->mailcow->mailbox( 'anna@held.example' )['active'], 'Still active: receiving works.' );
		$this->assertSame( 'held', Resource::find( $r->id() )->get( MailHold::META ) );

		foreach ( $this->mailcow->calls_of( 'set_smtp_access' ) as $call ) {
			$this->assertStringEndsWith( '@held.example', (string) $call[1] );
		}
	}

	public function test_the_check_holds_existing_mailboxes_and_lifts_them_when_spf_and_dkim_are_found(): void {
		$r = $this->ready_mail( 'life.example' );
		$this->assertSame( '', (string) $r->get( MailHold::META ) );
		$this->mailcow->add_mailbox( array( 'local_part' => 'old', 'domain' => 'life.example', 'quota' => '10' ) );

		$this->found( 'life.example', array( 'mx' ) );
		$r = $this->check( $r );
		$this->assertFalse( $this->mailcow->mailbox( 'old@life.example' )['smtp'], 'Held at the next check.' );
		$this->assertSame( 'held', $r->get( MailHold::META ) );

		$this->found( 'life.example', array( 'mx', 'spf' ) );
		$r = $this->check( $r );
		$this->assertFalse( $this->mailcow->mailbox( 'old@life.example' )['smtp'], 'DKIM is still missing.' );

		$this->found( 'life.example', array( 'mx', 'spf', 'dkim' ) );
		$r = $this->check( $r );
		$this->assertTrue( $this->mailcow->mailbox( 'old@life.example' )['smtp'] );
		$this->assertSame( 'lifted', $r->get( MailHold::META ) );
		$this->assertFalse( MailHold::held( $r ) );

		$later = Mailboxes::create( $r, 'new' );
		$this->assertIsArray( $later );
		$this->assertTrue( $this->mailcow->mailbox( 'new@life.example' )['smtp'], 'Not held once lifted.' );
	}

	public function test_a_domain_on_our_name_servers_is_never_held(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'zone.example' );
		$r = $this->ready_mail( 'zone.example', array(), $user );

		$this->assertFalse( MailHold::held( $r ) );
		Mailboxes::create( $r, 'anna' );
		$this->assertTrue( $this->mailcow->mailbox( 'anna@zone.example' )['smtp'] );
		$this->assertSame( array(), $this->mailcow->calls_of( 'set_smtp_access' ) );
	}

	public function test_a_domain_that_is_complete_already_makes_no_call(): void {
		$r = $this->ready_mail( 'calm.example' );
		$this->found( 'calm.example', array( 'mx', 'spf', 'dkim' ) );
		$this->check( $r );

		$this->assertSame( array(), $this->mailcow->calls_of( 'set_smtp_access' ), 'Never held, nothing to lift.' );
	}

	public function test_a_failed_hold_removes_the_new_mailbox_and_a_failed_lift_is_tried_again(): void {
		$r = $this->ready_mail( 'fail.example' );
		$this->mailcow->fail_next( 'set_smtp_access', new \WP_Error( 'mailcow_transport', 'down' ) );
		$res = Mailboxes::create( $r, 'anna' );

		$this->assertWPError( $res );
		$this->assertNull( $this->mailcow->mailbox( 'anna@fail.example' ), 'No mailbox that could send.' );

		$this->assertIsArray( Mailboxes::create( $r, 'anna' ) );
		$this->found( 'fail.example', array( 'mx', 'spf', 'dkim' ) );
		$this->mailcow->fail_next( 'set_smtp_access', new \WP_Error( 'mailcow_transport', 'down' ) );
		$r = $this->check( Resource::find( $r->id() ) );

		$this->assertSame( 'held', $r->get( MailHold::META ), 'Not marked lifted.' );
		MailHold::retry( $r->id() );
		$this->assertTrue( $this->mailcow->mailbox( 'anna@fail.example' )['smtp'] );
		$this->assertSame( 'lifted', Resource::find( $r->id() )->get( MailHold::META ) );
	}

	public function test_the_view_tells_the_customer_while_held_and_stops_when_lifted(): void {
		$r = $this->ready_mail( 'say.example' );
		Mailboxes::create( $r, 'anna' );
		$line = 'Sending is held until the SPF and DKIM records are found. Receiving works once the MX record points here.';

		$view = MailView::data( Resource::find( $r->id() ) );
		$this->assertTrue( $view['send_held'] );
		$this->assertSame( $line, $view['hold_text'] );

		$this->found( 'say.example', array( 'mx', 'spf', 'dkim' ) );
		$r    = $this->check( Resource::find( $r->id() ) );
		$view = MailView::data( $r );
		$this->assertFalse( $view['send_held'] );
		$this->assertSame( '', $view['hold_text'] );
	}
}
