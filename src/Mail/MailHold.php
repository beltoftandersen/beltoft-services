<?php
/**
 * Sending held for a mail domain whose DNS is elsewhere, until SPF and DKIM are found.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The mechanism is one mailbox attribute of the mail server, smtp_access (MailcowClient::set_smtp_access): a mailbox
 * with it off cannot log in to send, and still receives and can be read over IMAP. It is set one mailbox at a time, only
 * on the mailboxes of this domain, taken from the list that LiveMailcowClient::list_mailboxes() filters by domain. No
 * domain-wide or server-wide call is made.
 *
 * The rule: a domain whose DNS is not on our name servers is held while its SPF or DKIM record is not found. A domain on
 * our name servers is never held (the records are placed by us). The meta mail_hold records what was done to the
 * mailboxes: 'held' (every mailbox of the domain had sending switched off), 'lifted' (switched on again) or ''.
 * It is applied to every new mailbox, to every mailbox that exists at the next DNS check, and lifted by the DNS check
 * (and "Check now") as soon as both records are found. It is applied again by a later DNS check (a customer who
 * restarts the look after it timed out) and by the daily look MailDns::recheck() at a domain whose records were found
 * once: when a needed record is gone it sets the domain back to waiting and calls sync().
 */
final class MailHold {

	public const META = 'mail_hold';
	public const HOOK = 'beltoft_services_mail_hold';

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'retry' ) );
	}

	/**
	 * Does the rule say sending is held now?
	 *
	 * @param Resource $r Resource.
	 */
	public static function held( Resource $r ): bool {
		if ( 'external' !== self::mode( $r ) ) {
			return false;
		}

		$ok = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			if ( is_array( $row ) && 'ok' === ( $row['status'] ?? '' ) ) {
				$ok[] = (string) ( $row['key'] ?? '' );
			}
		}

		return array() !== array_diff( array( 'spf', 'dkim' ), $ok );
	}

	/**
	 * Where the domain's DNS is: ours (our name servers) or external. The stored mode wins; an older resource without one
	 * is worked out from the customer's zones.
	 *
	 * @param Resource $r Resource.
	 */
	public static function mode( Resource $r ): string {
		$mode = (string) $r->get( 'mail_mode' );

		if ( '' !== $mode ) {
			return $mode;
		}

		return MailDomain::on_our_servers( MailGuard::domain( $r ), (int) ( $r->license()['user_id'] ?? 0 ) ) ? 'ours' : 'external';
	}

	/**
	 * What the customer is told while sending is held, or '' when it is not.
	 *
	 * @param Resource $r Resource.
	 */
	public static function notice( Resource $r ): string {
		return self::held( $r ) ? __( 'Sending is held until the SPF and DKIM records are found. Receiving works once the MX record points here.', 'beltoft-services' ) : '';
	}

	/**
	 * A mailbox was just made (or is the system mailbox): hold its sending when the rule says so. The first time this is
	 * needed it is applied to every mailbox of the domain, so the mark mail_hold is true for all of them.
	 *
	 * @param Resource      $r      Resource.
	 * @param MailcowClient $client Client.
	 * @param string        $email  The mailbox, which exists on the mail server.
	 * @return true|\WP_Error
	 */
	public static function on_new_mailbox( Resource $r, MailcowClient $client, string $email ) {
		if ( ! self::held( $r ) ) {
			return true;
		}

		if ( 'held' !== (string) $r->get( self::META ) ) {
			return self::apply_all( $r, $client, true );
		}

		return $client->set_smtp_access( $email, false );
	}

	/**
	 * Brings the mailboxes in line with the rule. Called after every DNS check; does nothing when they are already.
	 *
	 * @param Resource $r Resource.
	 * @return true|\WP_Error
	 */
	public static function sync( Resource $r ) {
		if ( ! $r->is_live() || self::held( $r ) === ( 'held' === (string) $r->get( self::META ) ) ) {
			return true;
		}

		$result = new \WP_Error( 'busy', 'busy' );
		$ran    = MailPlan::locked(
			$r,
			static function () use ( $r, &$result ): bool {
				$fresh = Resource::find( $r->id() ) ?? $r;
				$held  = self::held( $fresh );

				if ( $held === ( 'held' === (string) $fresh->get( self::META ) ) ) {
					$result = true;
					return false;
				}

				$result = self::apply_all( $fresh, MailClients::for(), $held );

				return true === $result;
			}
		);

		if ( null === $ran ) {
			$result = new \WP_Error( 'busy', 'busy' );
		}

		if ( is_wp_error( $result ) ) {
			Jobs::schedule_once( time() + 300, self::HOOK, array( $r->id() ) );
		}

		return $result;
	}

	/**
	 * The job that tries again after a call failed.
	 *
	 * @param int $id Resource id.
	 */
	public static function retry( int $id ): void {
		$r = Resource::find( $id );

		if ( $r && MailDriver::ID === $r->get( 'driver' ) ) {
			self::sync( $r );
		}
	}

	/**
	 * Holds or lifts sending on every mailbox of the domain, then stores the mark. The caller holds the lock.
	 *
	 * @param Resource      $r      Resource.
	 * @param MailcowClient $client Client.
	 * @param bool          $hold   True to hold, false to lift.
	 * @return true|\WP_Error
	 */
	private static function apply_all( Resource $r, MailcowClient $client, bool $hold ) {
		$domain = strtolower( MailGuard::domain( $r ) );

		if ( '' === $domain ) {
			return new \WP_Error( 'invalid', 'no domain' );
		}

		// The filtered list: the server answers with every mailbox it has when it does not know the domain part.
		$rows = $client->list_mailboxes( $domain );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		foreach ( $rows as $row ) {
			$email = strtolower( (string) ( is_array( $row ) ? ( $row['username'] ?? '' ) : '' ) );
			$at    = strrpos( $email, '@' );

			if ( false === $at || substr( $email, $at + 1 ) !== $domain ) {
				continue;
			}

			$done = $client->set_smtp_access( $email, ! $hold );

			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		return $r->set( array( self::META => $hold ? 'held' : 'lifted' ) ) ? true : new \WP_Error( 'unavailable', 'The mark could not be saved.' );
	}
}
