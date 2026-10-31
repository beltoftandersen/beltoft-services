<?php
/**
 * What the customer sees of an email domain: one plain array for the account page and the REST route.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

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
			'kind'      => $kind,
			'ready'     => $ready,
			'limits'    => $limits,
			'mailboxes' => null,
			'aliases'   => null,
			'errors'    => array(),
			'dns'       => self::dns( $r, $kind ),
			'connect'   => self::connect( MailGuard::domain( $r ) ),
			'send'      => array(
				'mailbox_day'   => (int) Settings::get( 'mail_rl_day' ),
				'domain_minute' => (int) Settings::get( 'mail_rl_minute' ),
			),
			'forward_external' => (bool) Settings::get( 'mail_allow_external_forward' ),
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

		return $out;
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
				'name'     => '@' === ( $row['name'] ?? '' ) ? $domain : (string) ( $row['name'] ?? '' ) . '.' . $domain,
				'type'     => (string) ( $row['type'] ?? '' ),
				'priority' => isset( $row['priority'] ) ? (int) $row['priority'] : null,
				'value'    => (string) ( $row['value'] ?? '' ),
				'status'   => in_array( $row['status'] ?? '', array( 'ok', 'conflict' ), true ) ? (string) $row['status'] : 'missing',
				'reason'   => (string) ( $row['reason'] ?? '' ),
			);
		}

		// The proof record, while the customer has not shown that the domain is theirs.
		$proof = null;

		if ( 'external' === $mode && ! in_array( 'proof', (array) $r->get( 'steps' ), true ) && '' !== (string) $r->get( MailDomain::META_TOKEN ) && in_array( $kind, array( 'building' ), true ) ) {
			$proof = MailDomain::proof_record( $domain, (string) $r->get( MailDomain::META_TOKEN ) );
		}

		$checked = (int) $r->get( 'mail_dns_checked' );
		$status  = (string) $r->get( 'mail_dns' );

		return array(
			'mode'      => $mode,
			'status'    => in_array( $status, array( 'ok', 'timeout' ), true ) ? $status : ( array() === $rows ? '' : 'waiting' ),
			'rows'      => $rows,
			'proof'     => $proof,
			'can_check' => 'ready' === $kind && array() !== $rows && 'ok' !== $status,
			'checked'   => $checked > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked ) : '',
			'days'      => MailDns::WAIT_DAYS,
		);
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
			'domain'  => $domain,
		);
	}
}
