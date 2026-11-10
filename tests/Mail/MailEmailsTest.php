<?php
/**
 * The customer emails of a Business email domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Emails\Emails;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailEmailsTest extends MailTestCase {

	private function mine( string $domain, int $user = 0 ): Resource {
		$r = $this->ready_mail( $domain, array(), $user );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );

		return Resource::find( $r->id() );
	}

	private function text( array $mail ): string {
		return wp_strip_all_tags( (string) $mail['message'] );
	}

	public function test_the_ready_email_lists_the_records_for_a_domain_hosted_elsewhere(): void {
		$this->settings( array( 'mail_guide_url' => 'https://example.net/email-setup/' ) );
		$r = $this->mine( 'elsewhere.example' );
		Emails::on_state_changed( $r, 'provisioning', 'ready' );

		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$this->assertSame( array( 'christian.beltoft@gmail.com' ), (array) $mail['to'] );
		$this->assertSame( 'Your email domain elsewhere.example is ready', $mail['subject'] );
		$text = $this->text( $mail );
		$this->assertStringContainsString( 'elsewhere.example', $text );
		$this->assertStringContainsString( 'mail.example.net', $text );
		$this->assertStringContainsString( 'dkim._domainkey.elsewhere.example', $text );
		$this->assertStringContainsString( '_dmarc.elsewhere.example', $text );
		$this->assertStringContainsString( 'https://example.net/email-setup/', (string) $mail['message'] );
		$this->assertStringContainsString( 'port 993', $text );
		$this->assertStringContainsString( 'port 465', $text );
		$this->assertStringContainsString( 'Settings to enter by hand', $text );
		$this->assertStringNotContainsString( "\u{2014}", (string) $mail['message'] );
	}

	public function test_the_ready_email_never_holds_a_password_and_is_sent_once(): void {
		$r = $this->mine( 'quiet.example' );
		$this->assertTrue( $r->set( array( 'mail_secret_probe' => 'pw-should-never-show' ) ) );
		Emails::on_state_changed( $r, 'provisioning', 'ready' );
		Emails::on_state_changed( Resource::find( $r->id() ), 'paused', 'ready' );

		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( 'pw-should-never-show', (string) $this->mails[0]['message'] );
		$this->assertStringNotContainsStringIgnoringCase( 'password:', $this->text( $this->mails[0] ) );
	}

	public function test_the_ready_email_for_a_zone_of_ours_says_the_records_are_in_place(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'ours.example' );
		$r = $this->mine( 'ours.example', $user );
		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		Emails::on_state_changed( $r, 'provisioning', 'ready' );

		$text = $this->text( $this->mails[0] );
		$this->assertStringContainsString( 'The DNS records are in place', (string) preg_replace( '/\s+/', ' ', $text ) );
		$this->assertStringNotContainsString( 'dkim._domainkey', $text );
	}

	public function test_the_plain_text_version_has_the_records_and_the_links(): void {
		$r = $this->mine( 'plain.example' );
		$email = Emails::get( 'beltoft_services_mail_ready' );
		$this->assertNotNull( $email );
		$email->trigger( $r );
		$html  = $email->get_content_html();
		$plain = $email->get_content_plain();

		$this->assertStringContainsString( '<table', $html );
		$this->assertStringNotContainsString( '<', $plain );
		$this->assertStringContainsString( 'MX plain.example 10 mail.example.net', $plain );
		$this->assertStringContainsString( wc_get_account_endpoint_url( 'email' ), $plain );
		$this->assertStringNotContainsString( "\u{2014}", $plain );
	}

	public function test_the_ready_email_lists_the_next_steps_and_the_settings_as_a_table(): void {
		$this->settings( array( 'mail_host' => 'mail.example.net', 'mail_guide_url' => 'https://example.net/email-setup/' ) );
		$r = $this->mine( 'listed.example' );
		$email = Emails::get( 'beltoft_services_mail_ready' );
		$email->trigger( $r );
		$html  = $email->get_content_html();
		$plain = $email->get_content_plain();

		$this->assertStringContainsString( '<ol', $html );
		$this->assertSame( 3, substr_count( $html, '<li' ) );
		$this->assertStringContainsString( 'Incoming mail (IMAP)', $html );
		$this->assertStringContainsString( 'mail.example.net, port 993, SSL/TLS', $html );
		$this->assertStringContainsString( 'mail.example.net, port 465, SSL/TLS', $html );
		$this->assertStringContainsString( 'https://example.net/email-setup/', $html );
		$this->assertStringContainsString( "1. Create your mailboxes", $plain );
		$this->assertStringContainsString( '- Incoming mail (IMAP): mail.example.net, port 993, SSL/TLS', $plain );
		$this->assertStringNotContainsString( "\u{2014}", $html . $plain );
	}

	public function test_reminder_after_a_day_once_for_a_domain_hosted_elsewhere(): void {
		$r = $this->mine( 'late.example' );
		$id = $r->id();

		MailDns::remind( $id );
		$this->assertCount( 0, $this->mails, 'Too early.' );

		$r->set( array( 'mail_dns_since' => time() - DAY_IN_SECONDS - 60 ) );
		MailDns::remind( $id );
		MailDns::remind( $id );

		$this->assertCount( 1, $this->mails, 'Once.' );
		$this->assertSame( 'DNS records are still missing for late.example', $this->mails[0]['subject'] );
		$text = $this->text( $this->mails[0] );
		$this->assertStringContainsString( 'dkim._domainkey.late.example', $text );
		$this->assertGreaterThan( 0, (int) Resource::find( $id )->get( 'mail_dns_reminded' ) );
		$this->assertStringNotContainsString( "\u{2014}", (string) $this->mails[0]['message'] );
	}

	public function test_no_reminder_when_the_records_are_in_place_or_the_domain_is_ours(): void {
		$r = $this->mine( 'done.example' );
		$rows = (array) $r->get( 'mail_records' );
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['status'] = 'ok';
		}
		$r->set( array( 'mail_records' => $rows, 'mail_dns' => 'ok', 'mail_dns_since' => time() - 3 * DAY_IN_SECONDS ) );
		MailDns::remind( $r->id() );
		$this->assertCount( 0, $this->mails );

		$user = self::factory()->user->create();
		$this->our_zone( $user, 'zone.example' );
		$ours = $this->mine( 'zone.example', $user );
		$ours->set( array( 'mail_dns' => 'waiting', 'mail_dns_since' => time() - 3 * DAY_IN_SECONDS ) );
		MailDns::remind( $ours->id() );
		$this->assertCount( 0, $this->mails, 'A zone of ours is not the customer\'s to fix.' );
	}

	public function test_a_reminder_is_part_of_a_dns_check_run(): void {
		$r = $this->mine( 'run.example' );
		$r->set( array( 'mail_dns_since' => time() - DAY_IN_SECONDS - 60, 'mail_dns_checked' => null ) );
		MailDns::run( $r->id() );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'beltoft_services_mail_dns_reminder', array_values( array_filter( Emails::ids(), static fn( $id ) => 'beltoft_services_mail_dns_reminder' === $id ) )[0] );
	}

	public function test_paused_and_deleted_emails_cover_mail_too(): void {
		$r = $this->mine( 'gone.example' );
		Emails::on_state_changed( $r, 'provisioning', 'ready' );
		Emails::on_state_changed( Resource::find( $r->id() ), 'ready', 'paused' );
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'is paused', $this->mails[1]['subject'] );
		$this->assertStringContainsString( wc_get_account_endpoint_url( 'email' ), (string) $this->mails[1]['message'] );
	}
}
