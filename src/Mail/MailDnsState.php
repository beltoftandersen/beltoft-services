<?php
/**
 * Where an email domain stands with its DNS records, in one word, derived from the stored meta.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\DnsWatchState;
use BeltoftServices\Resource;
use BeltoftServices\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * The words themselves belong to the shared DNS watch (Domains\DnsWatchState); these are email's names for them, plus the sentences of
 * the Email page, which are email's own.
 */
final class MailDnsState {

	public const OK           = DnsWatchState::OK;
	public const SETTING_UP   = DnsWatchState::SETTING_UP;
	public const LOST         = DnsWatchState::LOST;
	public const PARTIAL_SEND = 'partial_send';
	public const STOPPED      = DnsWatchState::STOPPED;
	public const MOVED        = DnsWatchState::MOVED;

	/**
	 * Hooks: a failed proof that may be tried again has its own word, before the DNS word (the shared state asks).
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_dns_watch_own_state', array( __CLASS__, 'own_state' ), 10, 3 );
	}

	/**
	 * A failed proof that can still be tried again is not a failure to contact us about.
	 *
	 * @param mixed  $own      Null.
	 * @param mixed  $resource Resource.
	 * @param string $feature  Feature id.
	 * @return mixed
	 */
	public static function own_state( $own, $resource, $feature = '' ) {
		if ( MailDnsWatch::ID !== $feature || ! $resource instanceof Resource ) {
			return $own;
		}

		if ( 'failed' === (string) $resource->get( 'state' ) && MailRetry::open( $resource ) ) {
			return array( __( 'Not set up', 'beltoft-services' ), 'busy' );
		}

		return $own;
	}

	/**
	 * The words of the status poll.
	 *
	 * @param mixed $words    array( label, message ).
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function status_words( $words, $resource ) {
		return DnsWatchState::status_words( $words, $resource );
	}

	/**
	 * The status of a ready email domain.
	 *
	 * @param mixed $state    array( label, tone ).
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function area_state( $state, $resource ) {
		return DnsWatchState::area_state( $state, $resource );
	}

	/**
	 * The state.
	 *
	 * @param Resource $r Resource.
	 * @return string One of the constants, or '' when the domain has no records yet.
	 */
	public static function of( Resource $r ): string {
		return DnsWatchState::of( new MailDnsWatch(), $r );
	}

	/**
	 * The keys of the records that are found.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	public static function ok_keys( Resource $r ): array {
		return DnsWatchState::ok_keys( new MailDnsWatch(), $r );
	}

	/**
	 * The words of the list row and the badge, '' when there is no DNS word to say.
	 *
	 * @param Resource $r Resource.
	 */
	public static function word( Resource $r ): string {
		return DnsWatchState::word( new MailDnsWatch(), $r );
	}

	/**
	 * The colour group of the word: amber, and red once a loss is old.
	 *
	 * @param Resource $r Resource.
	 * @return string busy (amber), bad (red) or ok.
	 */
	public static function tone( Resource $r ): string {
		return DnsWatchState::tone( new MailDnsWatch(), $r );
	}

	/**
	 * The records that were found before and are gone now.
	 *
	 * @param Resource $r Resource.
	 * @return string[] Keys.
	 */
	public static function lost_keys( Resource $r ): array {
		return DnsWatchState::lost_keys( new MailDnsWatch(), $r );
	}

	/**
	 * Record keys in words for a sentence: MX, SPF.
	 *
	 * @param string[] $keys Keys.
	 */
	public static function names( array $keys ): string {
		return DnsWatchState::names( $keys );
	}

	/**
	 * The sentence on the page above the mailboxes and in the DNS tab.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $domain The domain as shown.
	 */
	public static function notice( Resource $r, string $domain ): string {
		switch ( self::of( $r ) ) {
			case self::SETTING_UP:
				/* translators: %s: domain name. */
				return sprintf( __( 'Email works on %s when the DNS records below are in place. You can make mailboxes now.', 'beltoft-services' ), $domain );
			case self::LOST:
				return sprintf(
					/* translators: %s: record names such as MX, SPF. */
					__( 'These DNS records were found before but are not found now: %s. Mail may not reach you. Add the records again below. Nothing has been deleted: your mailboxes and your mail are kept.', 'beltoft-services' ),
					self::names( self::lost_keys( $r ) )
				);
			case self::PARTIAL_SEND:
				return __( 'Mail to your addresses arrives. Sending from your mailboxes is paused until the SPF and DKIM records are found.', 'beltoft-services' );
			case self::MOVED:
				/* translators: %s: domain name. */
				return sprintf( __( 'The name servers of %s no longer point to us. Records we made for you are not used any more. Add the records below where the DNS is now.', 'beltoft-services' ), $domain );
			case self::STOPPED:
				return __( 'We stopped checking after 14 days. Add the records, then press Check now and we check again.', 'beltoft-services' );
		}

		return '';
	}

	/**
	 * The date a paused email service keeps its mailboxes and mail until, or '' when it is not paused or no date is known.
	 *
	 * @param Resource $r Resource.
	 */
	public static function kept_until( Resource $r ): string {
		$ends = Retention::end_date( $r );

		return $ends > 0 ? wp_date( get_option( 'date_format' ), $ends ) : '';
	}
}
