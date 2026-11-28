<?php
/**
 * The generic emails of the DNS watch: the new ids, the saved texts of the four replaced ids, and the values email gives them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsWatchMail;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Mail\DnsFixtures;
use BeltoftServices\Tests\Mail\MailTestCase;

require_once dirname( __DIR__ ) . '/Mail/MailTestCase.php';
require_once dirname( __DIR__ ) . '/Mail/DnsFixtures.php';

final class DnsWatchMailTest extends MailTestCase {

	use DnsFixtures;

	public function tear_down(): void {
		// A saved text was read into the mailer's email objects: they must not carry it into the next test.
		delete_option( 'woocommerce_beltoft_services_dns_lost_settings' );
		WC()->mailer()->init();
		parent::tear_down();
	}

	public function test_the_four_generic_ids_exist_and_the_four_mail_ids_are_gone(): void {
		foreach ( DnsWatchMail::EVENTS as $event ) {
			$this->assertContains( DnsWatchMail::id( $event ), Emails::ids() );
		}

		foreach ( array( 'reminder', 'lost', 'back', 'timeout' ) as $event ) {
			$this->assertNotContains( 'beltoft_services_mail_dns_' . $event, Emails::ids() );
		}
	}

	public function test_a_text_saved_for_an_old_id_moves_to_the_new_id_and_a_new_text_is_never_overwritten(): void {
		update_option( 'woocommerce_beltoft_services_mail_dns_lost_settings', array( 'subject' => 'Records gone for {domain_name}', 'enabled' => 'yes', 'additional_content' => 'Ring os.' ) );
		update_option( 'woocommerce_beltoft_services_mail_dns_back_settings', array( 'subject' => 'Old back' ) );
		update_option( 'woocommerce_beltoft_services_dns_back_settings', array( 'subject' => 'Already new' ) );
		delete_option( 'woocommerce_beltoft_services_dns_lost_settings' );

		$this->assertSame( 1, DnsWatchMail::migrate_saved_texts() );

		$this->assertSame( 'Records gone for {domain_name}', get_option( 'woocommerce_beltoft_services_dns_lost_settings' )['subject'] );
		$this->assertSame( 'Ring os.', get_option( 'woocommerce_beltoft_services_dns_lost_settings' )['additional_content'] );
		$this->assertSame( 'Already new', get_option( 'woocommerce_beltoft_services_dns_back_settings' )['subject'] );
		$this->assertFalse( get_option( 'woocommerce_beltoft_services_mail_dns_lost_settings', false ), 'The old settings are removed.' );
		$this->assertFalse( get_option( 'woocommerce_beltoft_services_mail_dns_back_settings', false ) );
		$this->assertSame( 0, DnsWatchMail::migrate_saved_texts(), 'Nothing is left to copy.' );
	}

	public function test_the_saved_subject_is_used_by_the_loss_mail(): void {
		update_option( 'woocommerce_beltoft_services_dns_lost_settings', array( 'subject' => 'Records gone for {domain_name}', 'enabled' => 'yes' ) );
		WC()->mailer()->init();
		$this->confirmed_loss( 'saved.example' );

		$this->assertContains( 'Records gone for saved.example', $this->subjects() );
	}

	public function test_email_gives_the_generic_mail_its_own_words_and_records(): void {
		$r = $this->found_once( 'words.example' );
		$this->found( $r, array( 'mx', 'dkim' ) );
		MailDns::recheck( $r->id() );
		MailDns::confirm( $r->id() );

		$mail = array_values( array_filter( $this->customer_mails(), static fn( $m ) => str_starts_with( (string) $m['subject'], 'DNS records are missing' ) ) )[0];
		$text = wp_strip_all_tags( (string) $mail['message'] );

		$this->assertStringContainsString( 'We found the DNS records of words.example before. These are not found now: SPF.', $text );
		$this->assertStringContainsString( 'Mail may not reach your mailboxes, and sending can be paused. Nothing is deleted.', $text );
		$this->assertStringContainsString( 'v=spf1 include:_spf.mail.example.net ~all', $text );
		$this->assertStringContainsString( 'Open your Email area', $text );
		$this->assertStringNotContainsString( "\u{2014}", $text );
		$this->assertStringNotContainsString( "\u{00B7}", $text );
	}

	public function test_a_feature_with_many_subjects_gives_the_values_of_one_subject_through_the_filter(): void {
		$r    = $this->mail_resource( 'many.example' );
		$seen = array();

		add_filter(
			'beltoft_services_dns_watch_subject_values',
			static function ( $pre, $event, $data ) use ( &$seen ) {
				$seen[] = array( $event, $data['dns_feature'] ?? '', $data['dns_host'] ?? '' );

				return array( 'dns_name' => (string) $data['dns_host'], 'dns_lost_names' => 'A', 'dns_lost_rows' => array( array( 'type' => 'A', 'name' => (string) $data['dns_host'], 'value' => '192.0.2.1', 'priority' => 0 ) ) );
			},
			10,
			3
		);

		$values = DnsWatchMail::values( array(), $r, array( 'dns_feature' => 'subjects', 'dns_host' => 'a.example' ), (object) array( 'id' => DnsWatchMail::id( 'lost' ) ) );

		$this->assertSame( array( array( 'lost', 'subjects', 'a.example' ) ), $seen );
		$this->assertSame( 'a.example', $values['dns_name'] );
		$this->assertSame( 'A', $values['dns_lost_names'] );
		$this->assertArrayHasKey( 'dns_effect', $values, 'The keys the template needs are there.' );

		// Mail is not asked: no feature id in the data, the one-subject path answers.
		$seen   = array();
		$values = DnsWatchMail::values( array(), $r, array(), (object) array( 'id' => DnsWatchMail::id( 'lost' ) ) );
		$this->assertSame( array(), $seen );
		$this->assertSame( 'many.example', $values['dns_name'] );
		remove_all_filters( 'beltoft_services_dns_watch_subject_values' );
	}

	public function test_the_timeout_mail_says_the_days_of_the_setting(): void {
		$this->settings( array( 'dns_watch_wait_days' => 5 ) );
		$r = $this->mail_resource( 'late.example' );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );
		$ready = \BeltoftServices\Drivers\Registry::get( 'mail' )->provision( $r );
		$this->assertTrue( $r->set( array_merge( $ready->meta(), array( 'state' => 'ready', 'desired' => 'ready', 'mail_dns' => 'waiting', 'mail_dns_since' => time() - 6 * DAY_IN_SECONDS, 'mail_dns_checked' => null ) ) ) );
		$this->mails = array();

		MailDns::run( $r->id() );

		$this->assertSame( 'timeout', Resource::find( $r->id() )->get( 'mail_dns' ) );
		$this->assertStringContainsString( 'after 5 days', wp_strip_all_tags( (string) $this->mails[0]['message'] ) );
		$this->assertStringContainsString( 'Your mailboxes and mail are kept.', wp_strip_all_tags( (string) $this->mails[0]['message'] ) );
	}
}
