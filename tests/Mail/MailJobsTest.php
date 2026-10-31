<?php
/**
 * The daily mail usage check.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailJobs;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailJobsTest extends MailTestCase {

	public function test_a_mailbox_that_is_nearly_full_is_reported_once_until_it_drops_again(): void {
		$r = $this->ready_mail( 'use.example' );
		Mailboxes::create( $r, 'a' );
		Mailboxes::create( $r, 'b' );
		$this->mailcow->set_usage( 'a@use.example', (int) ( 10240 * 1048576 * 0.95 ) );
		$this->mailcow->set_usage( 'b@use.example', 1048576 );

		$this->assertSame( array( 'a@use.example' ), MailJobs::check( Resource::find( $r->id() ) ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'a@use.example: 95 percent of 10240 MB', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'b@use.example', $this->mails[0]['message'] );
		$this->assertSame( 'christian.beltoft@gmail.com', $this->mails[0]['to'] );

		MailJobs::check( Resource::find( $r->id() ) );
		$this->assertCount( 1, $this->mails, 'Not again.' );

		$this->mailcow->set_usage( 'a@use.example', 1 );
		MailJobs::check( Resource::find( $r->id() ) );
		$this->mailcow->set_usage( 'a@use.example', 10240 * 1048576 );
		MailJobs::check( Resource::find( $r->id() ) );
		$this->assertCount( 2, $this->mails, 'Again after it had dropped below.' );
	}

	public function test_the_threshold_is_a_setting_and_a_server_that_is_down_is_skipped(): void {
		$r = $this->ready_mail( 'thr.example' );
		Mailboxes::create( $r, 'a' );
		$this->mailcow->set_usage( 'a@thr.example', (int) ( 10240 * 1048576 * 0.5 ) );
		$this->assertSame( array(), MailJobs::check( Resource::find( $r->id() ) ) );

		$this->settings( array( 'mail_quota_alert_percent' => 50 ) );
		$this->assertSame( array( 'a@thr.example' ), MailJobs::check( Resource::find( $r->id() ) ) );

		$this->mailcow->fail_next( 'list_mailboxes', new \WP_Error( 'mailcow_transport', 'x' ) );
		$this->assertSame( array(), MailJobs::check( Resource::find( $r->id() ) ) );
		$this->assertSame( array( 'a@thr.example' ), Resource::find( $r->id() )->get( 'mail_usage_alerted' ), 'What was known is kept.' );
	}

	public function test_the_run_looks_at_ready_domains_only(): void {
		$ready  = $this->ready_mail( 'rdy.example' );
		$paused = $this->ready_mail( 'pau.example' );
		$paused->set( array( 'state' => 'paused' ) );
		MailJobs::run();
		$this->assertSame( array( 'list_mailboxes', 'rdy.example' ), $this->mailcow->calls_of( 'list_mailboxes' )[0] );
		$this->assertCount( 1, $this->mailcow->calls_of( 'list_mailboxes' ) );
		$this->assertNotNull( $ready );
	}

	public function test_a_daily_job_is_scheduled(): void {
		as_unschedule_all_actions( MailJobs::HOOK, array(), 'beltoft-services' );
		MailJobs::schedule();
		$this->assertTrue( as_has_scheduled_action( MailJobs::HOOK, array(), 'beltoft-services' ) );
	}
}
