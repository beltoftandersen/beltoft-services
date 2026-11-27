<?php
/**
 * A second MX record at another mail provider next to ours. Public DNS answers come from $this->dns.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailDns;
use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Mail\MailView;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';
require_once __DIR__ . '/DnsFixtures.php';

final class MailDnsMxTest extends MailTestCase {

	use DnsFixtures;

	private const ROW = array( 'key' => 'mx', 'name' => '@', 'type' => 'MX', 'value' => 'mail.example.net', 'priority' => 10 );

	public function test_the_verdict_compares_priorities(): void {
		$this->assertSame( array( 'missing', array() ), MailDns::mx_verdict( self::ROW, array( '10 mx.other.example.' ) ) );
		$this->assertSame( array( 'ok', array() ), MailDns::mx_verdict( self::ROW, array( '10 mail.example.net.' ) ) );
		$this->assertSame( array( 'ok_note', array( '20 mx.other.example' ) ), MailDns::mx_verdict( self::ROW, array( '10 mail.example.net.', '20 mx.other.example.' ) ) );
		$this->assertSame( array( 'wrong', array( '5 mx.other.example' ) ), MailDns::mx_verdict( self::ROW, array( '10 mail.example.net.', '5 mx.other.example.' ) ) );
		$this->assertSame( array( 'wrong', array( '10 mx.other.example' ) ), MailDns::mx_verdict( self::ROW, array( '10 mail.example.net.', '10 mx.other.example.' ) ), 'The same priority splits the mail.' );
		$this->assertSame( array( 'wrong', array( '5 mx.other.example' ) ), MailDns::mx_verdict( self::ROW, array( '30 mail.example.net.', '5 mx.other.example.' ) ), 'Ours is compared by the priority found, not the one we ask for.' );
	}

	public function test_a_better_second_mx_is_a_problem_with_its_words_and_stops_the_domain_being_done(): void {
		$r = $this->ready_mail( 'split.example' );
		$this->found( $r, array( 'spf', 'dkim' ) );
		$this->dns['MX split.example'] = array( '10 mail.example.net.', '5 mx.other.example.' );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );

		MailDns::run( $r->id() );
		$r    = Resource::find( $r->id() );
		$rows = array_column( (array) $r->get( 'mail_records' ), null, 'key' );

		$this->assertSame( 'conflict', $rows['mx']['status'] );
		$this->assertSame( 'mx_second', $rows['mx']['reason'] );
		$this->assertSame( array( '5 mx.other.example' ), $rows['mx']['seen'] );
		$this->assertSame( 'waiting', $r->get( 'mail_dns' ), 'Mail would not reliably arrive here.' );

		$html = $this->guide_html( $r );
		$this->assertStringContainsString( 'Another mail server has the same or a better priority: 5 mx.other.example. Remove it.', $html );
	}

	public function test_a_worse_second_mx_is_only_a_note(): void {
		$r = $this->ready_mail( 'note.example' );
		$this->found( $r, array( 'spf', 'dkim' ) );
		$this->dns['MX note.example'] = array( '10 mail.example.net.', '20 mx.other.example.' );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );

		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertStringContainsString( 'Some mail may also go to 20 mx.other.example.', $this->guide_html( $r ) );
	}

	public function test_the_strict_check_is_a_setting(): void {
		$this->settings( array( 'mail_dns_mx_strict' => 0 ) );
		$r = $this->ready_mail( 'lax.example' );
		$this->found( $r, array( 'spf', 'dkim' ) );
		$this->dns['MX lax.example'] = array( '10 mail.example.net.', '5 mx.other.example.' );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );

		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ), 'Off: a note only.' );
		$this->assertStringContainsString( 'Some mail may also go to 5 mx.other.example.', $this->guide_html( $r ) );
	}

	public function test_an_address_where_an_alias_should_be_shows_the_proxy_hint(): void {
		$r = $this->ready_mail( 'proxy.example' );
		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		$this->dns['A autoconfig.proxy.example'] = array( '104.16.1.1' );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );

		MailDns::run( $r->id() );
		$r    = Resource::find( $r->id() );
		$rows = array_column( (array) $r->get( 'mail_records' ), null, 'key' );

		$this->assertSame( array( '104.16.1.1' ), $rows['autoconfig']['seen'] );
		$this->assertSame( array(), (array) ( $rows['autodiscover']['seen'] ?? array() ) );
		$this->assertStringContainsString( 'Another value is there now: 104.16.1.1', $this->guide_html( $r ) );
		$this->assertStringContainsString( 'This name has an address record, not an alias. Using Cloudflare? Set it to DNS only', $this->guide_html( $r ) );
	}

	/**
	 * The guide of the domain as the page prints it.
	 */
	private function guide_html( Resource $r ): string {
		return \BeltoftServices\Domains\DnsRecordsGuide::html( MailView::data( $r, array() )['dns']['guide'] );
	}
}
