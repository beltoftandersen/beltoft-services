<?php
/**
 * What the email feature tells the DNS watch.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\DnsWatchFeature;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The email feature's part of the shared DNS watch (Domains\DnsWatch): which records an email domain needs (MX, SPF and DKIM; DMARC,
 * autoconfig and autodiscover are recommended), how a found value is read, how a record is written into a zone of ours, the words of
 * its emails, and its one reaction to a change: the sending hold follows the records. The meta keys and the hook names are the ones
 * email used before the watch was shared (queued jobs and stored rows hang on them).
 */
final class MailDnsWatch implements DnsWatchFeature {

	public const ID = 'mail';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Mail';
	}

	public function noun(): string {
		return 'email DNS';
	}

	public function kind(): string {
		return MailDriver::KIND;
	}

	public function prefix(): string {
		return 'mail_dns';
	}

	public function rows_key(): string {
		return 'mail_records';
	}

	public function mode_key(): string {
		return 'mail_mode';
	}

	public function hook( string $job ): string {
		return array(
			'look'        => MailDns::HOOK,
			'recheck_all' => MailDns::RECHECK_ALL,
			'recheck'     => MailDns::RECHECK,
			'confirm'     => MailDns::CONFIRM,
		)[ $job ] ?? 'beltoft_services_dns_watch_' . $job . '_' . self::ID;
	}

	public function shows_status(): bool {
		return true;
	}

	public function owns( Resource $r ): bool {
		return MailDriver::ID === $r->get( 'driver' );
	}

	public function active( Resource $r ): bool {
		return 'ready' === (string) $r->get( 'state' );
	}

	public function domain( Resource $r ): string {
		return MailGuard::domain( $r );
	}

	public function zone_user( Resource $r ): int {
		return (int) ( $r->license()['user_id'] ?? 0 );
	}

	public function on_our_servers( Resource $r ): bool {
		return MailDomain::on_our_servers( $this->domain( $r ), $this->zone_user( $r ) );
	}

	public function needed(): array {
		return MailDns::NEEDED;
	}

	public function required( string $key ): bool {
		return MailDns::required( $key );
	}

	public function read_row( array $row, string $domain ): ?array {
		return MailDns::read_row( $row, $domain );
	}

	public function publish( int $user_id, string $domain, array $row ) {
		return MailDns::publish( $user_id, $domain, $row );
	}

	public function missing_rows( Resource $r, bool $needed_only = false ): array {
		return MailDns::missing_rows( $r, $needed_only );
	}

	public function partial_state(): string {
		return MailDnsState::PARTIAL_SEND;
	}

	public function partial_word(): string {
		return __( 'Sending is held', 'beltoft-services' );
	}

	public function partial_applies( Resource $r, array $ok_keys ): bool {
		return in_array( 'mx', $ok_keys, true ) && array() !== array_diff( array( 'spf', 'dkim' ), $ok_keys );
	}

	public function held( Resource $r ): bool {
		return MailHold::held( $r );
	}

	public function copy( string $event, Resource $r ): array {
		$license = $r->license();
		$effects = array(
			'reminder' => __( 'Mail arrives once the MX record is in place. Your mailboxes cannot send until the SPF and DKIM records are found.', 'beltoft-services' ),
			'lost'     => __( 'Mail may not reach your mailboxes, and sending can be paused.', 'beltoft-services' ),
			'back'     => __( 'Email works as before.', 'beltoft-services' ),
			'timeout'  => __( 'Your mailboxes and mail are kept.', 'beltoft-services' ),
		);
		$link    = '';

		if ( $license && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			$link = \BeltoftServices\Account\ServiceArea::get( Email::class )->url( $license );
		}

		return array(
			/* translators: %s: domain name. */
			'dns_subject'    => sprintf( __( 'Email for %s', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $this->domain( $r ) ) ),
			'dns_effect'     => $effects[ $event ] ?? '',
			'dns_where'      => __( 'the Email area', 'beltoft-services' ),
			'dns_link'       => $link,
			'dns_open_text'  => __( 'Open your Email area', 'beltoft-services' ),
			'dns_guide_url'  => 'reminder' === $event ? MailSetup::page_url() : '',
			'dns_guide_text' => __( 'Setup guide for mail programs', 'beltoft-services' ),
		);
	}

	public function alert_lines( string $event, Resource $r, array $ctx = array() ): array {
		switch ( $event ) {
			case 'left':
				return array( ! empty( $ctx['lost'] ) ? 'Its SPF or DKIM record was not found elsewhere: sending is held and the customer was emailed.' : 'Its records were found at the new DNS: sending is not held.' );
			case 'lost':
				return array(
					(int) $r->get( 'mail_dns_moved' ) > 0 ? 'The name servers no longer point to us.' : 'The records are not found at the DNS of the domain.',
					'held' === (string) $r->get( 'mail_hold' ) ? 'Sending is held (SPF or DKIM). The customer was emailed.' : 'Sending is not held. The customer was emailed.',
				);
			case 'no_dns':
				return array( 'The records are still not found. Mailboxes and mail are kept; nothing is paused or deleted by this.' );
		}

		return array();
	}

	public function on_event( string $event, Resource $r, array $ctx = array() ): void {
		unset( $ctx );

		// Sending is held or lifted from the records just stored (the system mailbox of Email lite included). Nothing else is done
		// because of a DNS finding.
		if ( 'changed' === $event ) {
			MailHold::sync( $r );
		}
	}
}
