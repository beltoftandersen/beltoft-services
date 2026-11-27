<?php
/**
 * Helpers of the email DNS edge case tests: public DNS answers and a domain whose records were found once.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailDns;
use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Mail\MailHold;
use BeltoftServices\Resource;

trait DnsFixtures {

	/**
	 * Answers public DNS for a domain with the records named.
	 *
	 * @param Resource $r    Resource.
	 * @param string[] $only Record keys that are there.
	 */
	protected function found( Resource $r, array $only ): void {
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
	protected function found_once( string $domain ): Resource {
		$r = $this->ready_mail( $domain );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );
		$this->assertIsArray( $this->create_box( Resource::find( $r->id() ), 'anna' ) );

		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );
		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertFalse( MailHold::held( $r ) );
		$this->mails = array();

		return $r;
	}

	/**
	 * A domain whose SPF record disappeared and whose loss is confirmed (the customer was mailed).
	 */
	protected function confirmed_loss( string $domain ): Resource {
		$r = $this->found_once( $domain );
		$this->found( $r, array( 'mx', 'dkim' ) );
		MailDns::recheck( $r->id() );
		MailDns::confirm( $r->id() );

		return Resource::find( $r->id() );
	}

	/**
	 * The subjects of the mails sent so far.
	 *
	 * @return string[]
	 */
	protected function subjects(): array {
		return array_map( 'strval', array_column( $this->mails, 'subject' ) );
	}

	/**
	 * The mails to the customer so far (not the alerts to staff).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function customer_mails(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => ! str_contains( (string) $m['subject'], 'Mail domain' ) ) );
	}

	/**
	 * Mails to the customer whose subject starts with the text.
	 *
	 * @param string $start Start of the subject.
	 */
	protected function count_mails( string $start ): int {
		return count( array_filter( $this->subjects(), static fn( $s ) => str_starts_with( $s, $start ) ) );
	}
}
