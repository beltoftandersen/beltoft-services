<?php
/**
 * What the customer sees of an email domain: one plain array for the account page and the REST route.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\DnsRecordsGuide;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Domains\Ownership;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing secret is in the array: a mailbox only says whether a password can still be shown. The mailboxes and the
 * aliases are read from the mail server, so a caller asks for them with $want; the rest comes from what the site stored.
 */
final class MailView {

	/** The ports a mail program uses. */
	public const IMAP_PORT = 993;
	public const SMTP_SSL  = 465;
	public const SMTP_TLS  = 587;
	public const POP_PORT  = 995;

	/**
	 * The view.
	 *
	 * @param Resource           $r    Resource.
	 * @param array<int, string> $want Parts to read from the mail server: mailboxes, aliases.
	 * @return array<string, mixed>
	 */
	public static function data( Resource $r, array $want = array( 'mailboxes', 'aliases' ) ): array {
		$state  = (string) $r->get( 'state' );
		$ready  = null === MailGuard::check( $r );
		$kind   = $ready ? 'ready' : ( 'paused' === $state ? 'paused' : ( 'failed' === $state ? 'failed' : ( in_array( $state, array( 'deleting', 'deleted' ), true ) ? 'removed' : 'building' ) ) );
		$limits = MailGuard::limits( $r );
		$out    = array(
			'id'        => $r->id(),
			'domain'    => MailGuard::domain( $r ),
			'included'  => (string) apply_filters( 'beltoft_services_mail_included_label', '', $r ),
			'kind'      => $kind,
			'ready'     => $ready,
			'limits'    => $limits,
			'mailboxes' => null,
			'aliases'   => null,
			'errors'    => array(),
			'dns'       => self::dns( $r, $kind ),
			'connect'   => self::connect( MailGuard::domain( $r ) ),
			'send'      => array(
				'mailbox_day'   => MailSettings::override( $r, 'day', (int) Settings::get( 'mail_rl_day' ) ),
				'domain_minute' => MailSettings::override( $r, 'minute', (int) Settings::get( 'mail_rl_minute' ) ),
			),
			'forward_external' => (bool) Settings::get( 'mail_allow_external_forward' ),
			'plan'      => self::plan( $r ),
			'counts'    => array( 'mailboxes' => (int) $r->get( 'mail_boxes' ), 'aliases' => 0 ),
			'delete_word' => MailRoutes::CONFIRM_DELETE,
		);

		if ( ! $ready ) {
			return $out;
		}

		if ( in_array( 'mailboxes', $want, true ) ) {
			$rows = Mailboxes::all( $r );

			if ( is_wp_error( $rows ) ) {
				$out['errors']['mailboxes'] = $rows->get_error_message();
			} else {
				$out['mailboxes']           = $rows;
				$out['counts']['mailboxes'] = count( $rows );
				self::remember( $r, count( $rows ) );
			}
		}

		if ( in_array( 'aliases', $want, true ) ) {
			$rows = Aliases::all( $r );

			if ( is_wp_error( $rows ) ) {
				$out['errors']['aliases'] = $rows->get_error_message();
			} else {
				$out['aliases']           = $rows;
				$out['counts']['aliases'] = count( $rows );
			}
		}

		$out['plan']['used'] = MailPlan::used( MailPlan::root( $r ) );

		// The way to a larger plan is only looked up when the plan is full: it is all the page uses it for.
		if ( $out['plan']['used']['mailboxes'] >= $limits['mailboxes'] ) {
			$out['plan']['upgrade'] = MailPlan::larger_plan_url( MailPlan::root( $r ) );
		}

		return $out;
	}

	/**
	 * The plan the domain belongs to: counts, limits, its domains, whether this is the first one, and the plan root.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	private static function plan( Resource $r ): array {
		$root    = MailPlan::root( $r );
		$members = MailPlan::counted( $root );

		return array(
			'used'       => MailPlan::cached( $root ),
			'upgrade'    => '',
			'limits'     => MailPlan::limits( $r ),
			'domains'    => array_values( array_filter( array_map( array( MailGuard::class, 'domain' ), $members ) ) ),
			'is_root'    => $root->id() === $r->id(),
			'root'       => array( 'id' => $root->id(), 'domain' => MailGuard::domain( $root ), 'name' => \BeltoftLicenses\Account\View::product_name( $root->license() ) ),
			'root_ready' => 'ready' === (string) $root->get( 'state' ) && 'ready' === (string) $root->get( 'desired' ),
			'members'    => array_map(
				static fn( Resource $m ): array => array( 'id' => $m->id(), 'domain' => MailGuard::domain( $m ), 'is_root' => $m->id() === $root->id() ),
				$members
			),
		);
	}

	/**
	 * Remembers how many mailboxes the domain has, for the list of domains (which does not ask the mail server).
	 *
	 * @param Resource $r     Resource.
	 * @param int      $count Mailboxes.
	 */
	public static function remember( Resource $r, int $count ): void {
		if ( (int) $r->get( 'mail_boxes' ) === $count ) {
			return;
		}

		$r->locked(
			static function () use ( $r, $count ): bool {
				$fresh = Resource::find( $r->id() );

				return $fresh && $fresh->set( array( 'mail_boxes' => $count ) );
			}
		);
	}

	/**
	 * The DNS records with their status, and what the customer is waiting for.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $kind ready, building, paused, failed or removed.
	 * @return array<string, mixed>
	 */
	private static function dns( Resource $r, string $kind ): array {
		$domain = MailGuard::domain( $r );
		$mode   = (string) $r->get( 'mail_mode' );
		$mode   = '' !== $mode ? $mode : ( MailDomain::on_our_servers( $domain, (int) ( $r->license()['user_id'] ?? 0 ) ) ? 'ours' : 'external' );
		$rows   = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = array(
				'key'      => (string) ( $row['key'] ?? '' ),
				'seen'     => array_map( 'strval', array_slice( (array) ( $row['seen'] ?? array() ), 0, 3 ) ),
				'merged'   => (string) ( $row['merged'] ?? '' ),
				'name'     => '@' === ( $row['name'] ?? '' ) ? $domain : (string) ( $row['name'] ?? '' ) . '.' . $domain,
				'type'     => (string) ( $row['type'] ?? '' ),
				'priority' => isset( $row['priority'] ) ? (int) $row['priority'] : null,
				'value'    => (string) ( $row['value'] ?? '' ),
				'status'   => in_array( $row['status'] ?? '', array( 'ok', 'conflict' ), true ) ? (string) $row['status'] : 'missing',
				'reason'   => MailDns::reason_text( (string) ( $row['reason'] ?? '' ) ),
			);
		}

		// The proof record, while the customer has not shown that the domain is theirs.
		$proof = null;

		// Not shown when the customer has proven the domain already (a zone of ours, a record for another service).
		$user = (int) ( $r->license()['user_id'] ?? 0 );

		if ( 'external' === $mode && ! in_array( 'proof', (array) $r->get( 'steps' ), true ) && ( (int) $r->get( 'mail_proof_until' ) > 0 || '' !== MailDomain::legacy_token( $r ) ) && in_array( $kind, array( 'building' ), true ) && ! Ownership::proven( $user, $domain ) ) {
			$proof = MailDomain::proof_record( $domain, $user );
		}

		$checked = (int) $r->get( 'mail_dns_checked' );
		$status  = (string) $r->get( 'mail_dns' );
		$state   = in_array( $status, array( 'ok', 'timeout' ), true ) ? $status : ( array() === $rows ? '' : 'waiting' );
		$can     = 'ready' === $kind && array() !== $rows && 'ok' !== $status;

		return array(
			'mode'       => $mode,
			'status'     => $state,
			'rows'       => $rows,
			'proof'      => $proof,
			'can_check'  => $can,
			'checked'    => $checked > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked ) : '',
			'checked_at' => $checked,
			'days'       => MailDns::WAIT_DAYS,
			'guide'      => self::guide( $domain, $mode, $rows, $proof, $state, $can, $checked ),
		);
	}

	/**
	 * The DNS guide of the domain as the shared renderer takes it (Domains\DnsRecordsGuide), or null when there is nothing
	 * to show yet: the proof record first, then the records of the domain.
	 *
	 * @param string                               $domain  Domain.
	 * @param string                               $mode    ours or external.
	 * @param array<int, array<string, mixed>>     $rows    Rows of dns().
	 * @param array{name:string,type:string,value:string}|null $proof The proof record while it is asked for.
	 * @param string                               $state   ok, timeout, waiting or ''.
	 * @param bool                                 $can     The customer can start a check.
	 * @param int                                  $checked Time of the last check, 0 for never.
	 * @return array<string, mixed>|null
	 */
	private static function guide( string $domain, string $mode, array $rows, ?array $proof, string $state, bool $can, int $checked ): ?array {
		$shown = HostName::display( $domain );

		if ( null !== $proof ) {
			return array(
				'id'       => 'proof',
				'domain'   => $domain,
				'title'    => sprintf( /* translators: %s: domain name. */ __( 'Show that %s is yours', 'beltoft-services' ), $shown ),
				'why'      => __( 'We set up email for the domain after we find this record. Nothing else works until then.', 'beltoft-services' ),
				'records'  => array(
					array(
						'key'      => 'proof',
						'type'     => $proof['type'],
						'name'     => $proof['name'],
						'value'    => $proof['value'],
						'required' => true,
						'purpose'  => DnsRecordsGuide::proof_purpose(),
						'status'   => 'unchecked',
					),
				),
				'check'    => array( 'attrs' => array( 'data-mail-proof-check' => '' ) ),
				'interval' => MailDns::INTERVAL,
				'days'     => MailDns::WAIT_DAYS,
			);
		}

		if ( array() === $rows ) {
			return null;
		}

		$records = array();

		foreach ( $rows as $row ) {
			$key    = (string) $row['key'];
			$note   = '';
			$status = 'missing' === $row['status'] && 0 === $checked && 'external' === $mode ? 'unchecked' : $row['status'];

			if ( 'mx' === $key && array() !== $row['seen'] && 'ok' !== $row['status'] ) {
				$note  = __( 'This domain has MX records for another mail provider. Remove them, or mail keeps going there.', 'beltoft-services' );
			}

			if ( 'spf' === $key && array() !== $row['seen'] && 'ok' !== $row['status'] ) {
				$note = '' !== $row['merged']
					? __( 'Your domain already has an SPF record, and a domain may have only one. Change the existing record to the value below instead of adding a second one.', 'beltoft-services' )
					: sprintf( /* translators: %s: the SPF include to add. */ __( 'Your domain already has an SPF record, and a domain may have only one. Add include:%s to it instead of adding a second one.', 'beltoft-services' ), MailSettings::spf_include() );
			}

			$records[] = array(
				'key'      => $key,
				'type'     => $row['type'],
				'name'     => $row['name'],
				'value'    => $row['value'],
				'priority' => $row['priority'],
				'required' => MailDns::required( $key ),
				'purpose'  => MailDns::purpose( $key ),
				'status'   => $status,
				'seen'     => $row['seen'],
				'reason'   => $row['reason'],
				'merged'   => $row['merged'],
				'note'     => $note,
			);
		}

		$notes = array();

		if ( 'timeout' === $state ) {
			$notes[] = __( 'We stopped looking for the records. Add them, then check again.', 'beltoft-services' );
		}

		$model = array(
			'id'        => 'mail',
			'domain'    => $domain,
			'title'     => sprintf( /* translators: %s: domain name. */ __( 'Make email work on %s', 'beltoft-services' ), $shown ),
			'why'       => sprintf( /* translators: %s: domain name. */ __( 'These records send email for %s to our mail server and let it send email as you. Mail does not arrive until the needed records are in place.', 'beltoft-services' ), $shown ),
			'records'   => $records,
			'notes'     => $notes,
			'done_text' => sprintf( /* translators: %s: domain name. */ __( 'Email DNS for %s is set up. All needed records were found.', 'beltoft-services' ), $shown ),
			'check'     => $can ? array( 'attrs' => array( 'data-mail-dns-check' => '' ) ) : null,
			'checked'   => $checked,
			'interval'  => 'external' === $mode && 'timeout' !== $state && 'ok' !== $state ? MailDns::INTERVAL : 0,
			'days'      => MailDns::WAIT_DAYS,
		);

		if ( 'ours' === $mode ) {
			$model['steps'] = array(
				__( 'The DNS of this domain is on our name servers, so we add these records for you.', 'beltoft-services' ),
				__( 'A record marked Another value is there now was not added, because something else is already there. Change or remove that record, then press Check now.', 'beltoft-services' ),
			);
		}

		return $model;
	}

	/**
	 * The settings for a mail program.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>
	 */
	private static function connect( string $domain ): array {
		$webmail = esc_url_raw( trim( (string) Settings::get( 'mail_webmail_url' ) ), array( 'https' ) );

		return array(
			'host'    => MailSettings::host(),
			'imap'    => self::IMAP_PORT,
			'smtp'    => array( self::SMTP_SSL, self::SMTP_TLS ),
			'pop'     => self::POP_PORT,
			'webmail' => $webmail,
			'guide'   => MailSetup::page_url(),
			'dav'     => MailSetup::dav_url(),
			'domain'  => $domain,
		);
	}
}
