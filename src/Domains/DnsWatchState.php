<?php
/**
 * Where a watched service stands with its DNS records, in one word, derived from the stored meta, and how that word reaches the screens.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing is stored here: the state follows from the status (waiting, ok, timeout), `_lost_at`, `_lost_pending`, `_moved` and the stored
 * rows, all under the feature's meta prefix. One place names the words of the list row, the badge and the status poll, so they never differ.
 * A feature adds one word of its own (partial: mail's "Sending is held") through DnsWatchFeature.
 */
final class DnsWatchState {

	public const OK         = 'ok';
	public const SETTING_UP = 'setting_up';
	public const LOST       = 'lost';
	public const STOPPED    = 'stopped_looking';
	public const MOVED      = 'moved';

	/**
	 * Hooks: the words reach the shared status of a service area and the status poll through their filters.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_area_state', array( __CLASS__, 'area_state' ), 10, 2 );
		add_filter( 'beltoft_services_status_words', array( __CLASS__, 'status_words' ), 10, 2 );
	}

	/**
	 * The words of the status poll: the same word as the page, so the badge is never "Ready" while the records are broken.
	 *
	 * @param mixed $words    array( label, message ).
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function status_words( $words, $resource ) {
		$feature = $resource instanceof Resource ? DnsWatch::feature_of( $resource ) : null;

		if ( ! is_array( $words ) || null === $feature || ! $feature->shows_status( $resource ) ) {
			return $words;
		}

		$state = self::area_state( array( (string) ( $words['label'] ?? '' ), '' ), $resource );

		if ( is_array( $state ) && $state[0] !== $words['label'] ) {
			$words['label'] = $state[0];

			// A failed proof that can be tried again has no "contact us" sentence: the page says what to do.
			if ( 'failed' === (string) $resource->get( 'state' ) ) {
				$words['message'] = '';
			}
		}

		return $words;
	}

	/**
	 * The status of a ready service: the DNS word when something is wrong, else what the area said.
	 *
	 * @param mixed $state    array( label, tone ).
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function area_state( $state, $resource ) {
		$feature = $resource instanceof Resource ? DnsWatch::feature_of( $resource ) : null;

		if ( ! is_array( $state ) || null === $feature || ! $feature->shows_status( $resource ) || ! $resource->is_live() ) {
			return $state;
		}

		/**
		 * A feature's own status before the DNS word (mail: a failed proof that may be tried again). Return an array( label, tone ) or null.
		 *
		 * @param array<int, string>|null $own      Null.
		 * @param Resource                $resource Resource.
		 * @param string                  $feature  Feature id.
		 */
		$own = apply_filters( 'beltoft_services_dns_watch_own_state', null, $resource, $feature->id() );

		if ( is_array( $own ) ) {
			return $own;
		}

		if ( 'ready' !== (string) $resource->get( 'state' ) ) {
			return $state;
		}

		$word = self::word( $feature, $resource );

		return '' === $word ? $state : array( $word, self::tone( $feature, $resource ) );
	}

	/**
	 * The state.
	 *
	 * @param DnsWatchFeature $feature  Feature.
	 * @param Resource        $r        Resource.
	 * @return string One of the constants, the feature's partial state, or '' when the service has no records yet.
	 */
	public static function of( DnsWatchFeature $feature, Resource $r ): string {
		$p      = $feature->prefix();
		$status = DnsWatch::status( $feature, $r );

		if ( '' === $status || array() === $feature->rows( $r ) ) {
			return '';
		}

		if ( 'ok' === $status ) {
			return self::OK;
		}

		if ( 'timeout' === $status ) {
			return self::STOPPED;
		}

		if ( (int) $r->get( $p . '_moved' ) > 0 ) {
			return self::MOVED;
		}

		if ( (int) $r->get( $p . '_lost_at' ) > 0 ) {
			return self::LOST;
		}

		$ok = self::ok_keys( $feature, $r );

		if ( '' !== $feature->partial_state() && $feature->partial_applies( $r, $ok ) ) {
			return $feature->partial_state();
		}

		// Confirming a loss takes minutes: nothing is said before it is confirmed, except that something is held.
		if ( (int) $r->get( $p . '_lost_pending' ) > 0 ) {
			return '' !== $feature->partial_state() && $feature->held( $r ) ? $feature->partial_state() : self::OK;
		}

		return self::SETTING_UP;
	}

	/**
	 * The keys of the records that are found.
	 *
	 * @param DnsWatchFeature $feature Feature.
	 * @param Resource        $r       Resource.
	 * @return string[]
	 */
	public static function ok_keys( DnsWatchFeature $feature, Resource $r ): array {
		return self::keys_ok( $feature->rows( $r ) );
	}

	/**
	 * The keys of the rows that are ok.
	 *
	 * @param array<int, mixed> $rows Rows.
	 * @return string[]
	 */
	public static function keys_ok( array $rows ): array {
		$ok = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && 'ok' === ( $row['status'] ?? '' ) ) {
				$ok[] = (string) ( $row['key'] ?? '' );
			}
		}

		return $ok;
	}

	/**
	 * The words of the list row and the badge, '' when there is no DNS word to say.
	 *
	 * @param DnsWatchFeature $feature Feature.
	 * @param Resource        $r       Resource.
	 */
	public static function word( DnsWatchFeature $feature, Resource $r ): string {
		$state = self::of( $feature, $r );

		return self::word_of( $state, (int) $r->get( $feature->prefix() . '_lost_at' ), $feature );
	}

	/**
	 * The words of a state, from its parts: for a feature whose state is not kept in one resource's meta (hosting keeps one per domain).
	 *
	 * @param string               $state   One of the constants, or a feature's partial state.
	 * @param int                  $lost_at When the loss was confirmed, 0 for none.
	 * @param DnsWatchFeature|null $feature Feature, for its partial word.
	 */
	public static function word_of( string $state, int $lost_at, ?DnsWatchFeature $feature = null ): string {
		switch ( $state ) {
			case self::SETTING_UP:
				return __( 'DNS records needed', 'beltoft-services' );
			case self::LOST:
				return $lost_at > 0
					? sprintf( /* translators: %s: a date such as 12 December */ __( 'DNS records missing since %s', 'beltoft-services' ), wp_date( _x( 'j F', 'a day and a month, such as 12 December', 'beltoft-services' ), $lost_at ) )
					: __( 'DNS records missing', 'beltoft-services' );
			case self::STOPPED:
				return __( 'We stopped checking DNS', 'beltoft-services' );
			case self::MOVED:
				return __( 'DNS is at another provider', 'beltoft-services' );
		}

		return null !== $feature && '' !== $state && $state === $feature->partial_state() ? $feature->partial_word() : '';
	}

	/**
	 * The state from its parts, for a feature without a partial state: ok, stopped, moved, lost, confirming (ok until it is confirmed) or setting up.
	 *
	 * @param string $status       waiting, ok or timeout.
	 * @param bool   $has_rows     Whether records are known at all.
	 * @param int    $moved        Time the name servers moved away, 0 for none.
	 * @param int    $lost_at      Time the loss was confirmed, 0 for none.
	 * @param int    $lost_pending Time a loss awaits its second read, 0 for none.
	 * @return string One of the constants, or '' when there are no records yet.
	 */
	public static function state_of( string $status, bool $has_rows, int $moved, int $lost_at, int $lost_pending ): string {
		if ( '' === $status || ! $has_rows ) {
			return '';
		}

		if ( 'ok' === $status ) {
			return self::OK;
		}

		if ( 'timeout' === $status ) {
			return self::STOPPED;
		}

		if ( $moved > 0 ) {
			return self::MOVED;
		}

		if ( $lost_at > 0 ) {
			return self::LOST;
		}

		return $lost_pending > 0 ? self::OK : self::SETTING_UP;
	}

	/**
	 * The colour group of the word: amber, and red once a loss is old.
	 *
	 * @param DnsWatchFeature $feature Feature.
	 * @param Resource        $r       Resource.
	 * @return string busy (amber), bad (red) or ok.
	 */
	public static function tone( DnsWatchFeature $feature, Resource $r ): string {
		return self::tone_of( self::of( $feature, $r ), (int) $r->get( $feature->prefix() . '_lost_at' ), $feature->id() );
	}

	/**
	 * The colour group of a state, from its parts.
	 *
	 * @param string $state      State.
	 * @param int    $lost_at    When the loss was confirmed, 0 for none.
	 * @param string $feature_id Feature id (the days after which a loss is red are a setting per feature).
	 * @return string busy (amber), bad (red) or ok.
	 */
	public static function tone_of( string $state, int $lost_at, string $feature_id ): string {
		if ( in_array( $state, array( self::LOST, self::MOVED ), true ) ) {
			return $lost_at > 0 && time() >= $lost_at + DnsWatchSettings::red_after( $feature_id ) ? 'bad' : 'busy';
		}

		return '' !== $state && self::OK !== $state ? 'busy' : 'ok';
	}

	/**
	 * The records that were found before and are gone now.
	 *
	 * @param DnsWatchFeature $feature Feature.
	 * @param Resource        $r       Resource.
	 * @return string[] Keys.
	 */
	public static function lost_keys( DnsWatchFeature $feature, Resource $r ): array {
		return array_values( array_map( 'strval', (array) $r->get( $feature->prefix() . '_lost_keys' ) ) );
	}

	/**
	 * Record keys in words for a sentence: MX, SPF.
	 *
	 * @param string[] $keys Keys.
	 */
	public static function names( array $keys ): string {
		return implode( ', ', array_map( static fn( $k ) => strtoupper( (string) $k ), $keys ) );
	}
}
