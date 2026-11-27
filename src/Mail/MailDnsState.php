<?php
/**
 * Where an email domain stands with its DNS records, in one word, derived from the stored meta.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Resource;
use BeltoftServices\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing is stored here: the state follows from mail_dns (waiting, ok, timeout), mail_dns_lost_at, mail_dns_lost_pending,
 * mail_dns_moved and the stored records. One place names the words of the list row, the badge of the page and the notice on
 * the page, so they never differ.
 */
final class MailDnsState {

	public const OK           = 'ok';
	public const SETTING_UP   = 'setting_up';
	public const LOST         = 'lost';
	public const PARTIAL_SEND = 'partial_send';
	public const STOPPED      = 'stopped_looking';
	public const MOVED        = 'moved';

	/**
	 * Hooks: the words reach the shared status of a service area through its filter.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_area_state', array( __CLASS__, 'area_state' ), 10, 2 );
	}

	/**
	 * The status of a ready email domain: the DNS word when something is wrong, else what the area said.
	 *
	 * @param mixed $state    array( label, tone ).
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function area_state( $state, $resource ) {
		if ( ! is_array( $state ) || ! $resource instanceof Resource || MailDriver::ID !== $resource->get( 'driver' ) || 'ready' !== (string) $resource->get( 'state' ) || ! $resource->is_live() ) {
			return $state;
		}

		$word = self::word( $resource );

		return '' === $word ? $state : array( $word, self::tone( $resource ) );
	}

	/**
	 * The state.
	 *
	 * @param Resource $r Resource.
	 * @return string One of the constants, or '' when the domain has no records yet.
	 */
	public static function of( Resource $r ): string {
		$status = (string) $r->get( 'mail_dns' );

		if ( '' === $status || array() === (array) $r->get( 'mail_records' ) ) {
			return '';
		}

		if ( 'ok' === $status ) {
			return self::OK;
		}

		if ( 'timeout' === $status ) {
			return self::STOPPED;
		}

		if ( (int) $r->get( 'mail_dns_moved' ) > 0 ) {
			return self::MOVED;
		}

		if ( (int) $r->get( 'mail_dns_lost_at' ) > 0 ) {
			return self::LOST;
		}

		$ok = self::ok_keys( $r );

		if ( in_array( 'mx', $ok, true ) && array() !== array_diff( array( 'spf', 'dkim' ), $ok ) ) {
			return self::PARTIAL_SEND;
		}

		// Confirming a loss takes minutes: nothing is said before it is confirmed, except that sending is held.
		if ( (int) $r->get( 'mail_dns_lost_pending' ) > 0 ) {
			return MailHold::held( $r ) ? self::PARTIAL_SEND : self::OK;
		}

		return self::SETTING_UP;
	}

	/**
	 * The keys of the records that are found.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	public static function ok_keys( Resource $r ): array {
		$ok = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			if ( is_array( $row ) && 'ok' === ( $row['status'] ?? '' ) ) {
				$ok[] = (string) ( $row['key'] ?? '' );
			}
		}

		return $ok;
	}

	/**
	 * The words of the list row and the badge, '' when there is no DNS word to say.
	 *
	 * @param Resource $r Resource.
	 */
	public static function word( Resource $r ): string {
		switch ( self::of( $r ) ) {
			case self::SETTING_UP:
				return __( 'DNS records needed', 'beltoft-services' );
			case self::LOST:
				$since = (int) $r->get( 'mail_dns_lost_at' );

				return $since > 0
					? sprintf( /* translators: %s: a date such as 12 December */ __( 'DNS records missing since %s', 'beltoft-services' ), wp_date( 'j F', $since ) )
					: __( 'DNS records missing', 'beltoft-services' );
			case self::PARTIAL_SEND:
				return __( 'Sending is held', 'beltoft-services' );
			case self::STOPPED:
				return __( 'We stopped checking DNS', 'beltoft-services' );
			case self::MOVED:
				return __( 'DNS is at another provider', 'beltoft-services' );
		}

		return '';
	}

	/**
	 * The colour group of the word: amber, and red once a loss is old.
	 *
	 * @param Resource $r Resource.
	 * @return string busy (amber), bad (red) or ok.
	 */
	public static function tone( Resource $r ): string {
		$state = self::of( $r );

		if ( in_array( $state, array( self::LOST, self::MOVED ), true ) ) {
			$since = (int) $r->get( 'mail_dns_lost_at' );

			return $since > 0 && time() >= $since + MailSettings::dns_red_after() ? 'bad' : 'busy';
		}

		return in_array( $state, array( self::SETTING_UP, self::PARTIAL_SEND, self::STOPPED ), true ) ? 'busy' : 'ok';
	}

	/**
	 * The records that were found before and are gone now.
	 *
	 * @param Resource $r Resource.
	 * @return string[] Keys.
	 */
	public static function lost_keys( Resource $r ): array {
		return array_values( array_map( 'strval', (array) $r->get( 'mail_dns_lost_keys' ) ) );
	}

	/**
	 * Record keys in words for a sentence: MX, SPF.
	 *
	 * @param string[] $keys Keys.
	 */
	public static function names( array $keys ): string {
		return implode( ', ', array_map( static fn( $k ) => strtoupper( (string) $k ), $keys ) );
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
