<?php
/**
 * The one answer to "has this customer shown that this domain is theirs?"
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Hosting, mail and DNS hosting claims all ask for the same proof: a TXT record at `_beltoft-verify.<name>`. This class
 * is the only place that says what that record holds and whether a customer has proven a name. Rules:
 *
 * - A proof belongs to one customer (user id). Another customer's proof never counts.
 * - Scope: a proof of `example.com` covers `www.example.com` and `shop.example.com`; a proof of `shop.example.com`
 *   covers only itself and the names under it. Never a name of another registrable domain (`covers()`).
 * - Sources, answered from stored state without any DNS lookup: a zone the customer holds on our name servers
 *   (ZoneAccess), a verified DNS claim whose proof was the record itself, and the filter
 *   `beltoft_services_domain_proven` that features answer from their own state (see `source()`).
 * - "The address points at us" is never a proof.
 * - The DNS lookup is `check()`: it runs only when a feature asks for the name it is working on.
 *
 * Token: ONE per customer and registrable domain, made again from the site's secret keys (`token()`), not stored. Tokens
 * that features showed before this class existed (a hosting token per account and name, a mail token per service, a
 * claim token) are still accepted by `check()` for the name that feature passes in, for as long as the feature keeps
 * passing it: it costs nothing and no customer has to change a record.
 */
final class Ownership {

	/** Filter: features answer for the names they hold a live proof for. */
	public const FILTER = 'beltoft_services_domain_proven';

	/** Start of the value of the record. */
	public const PREFIX = 'beltoft-owner=';

	/**
	 * A name as the checks take it, or null when it is not a host name.
	 *
	 * @param mixed $name Typed name.
	 */
	public static function normalise( $name ): ?string {
		return HostName::normalise( $name );
	}

	/**
	 * The registrable domain of a name, or null when the name is a public suffix itself (co.uk) or not a name. A name
	 * with an ending the public suffix list does not know (a test name, a new ending) takes its last two labels, the
	 * same rule as the hosting domain count.
	 *
	 * @param string $name Normalised name.
	 */
	public static function registrable( string $name ): ?string {
		$name = strtolower( rtrim( trim( $name ), '.' ) );

		if ( '' === $name || false === strpos( $name, '.' ) ) {
			return null;
		}

		$found = PublicSuffix::registrable( $name );

		if ( null !== $found ) {
			return $found;
		}

		$labels = explode( '.', $name );

		// A known ending with no label in front of it is a public suffix: nobody owns it.
		if ( null !== PublicSuffix::registrable( 'x.' . end( $labels ) ) ) {
			return null;
		}

		return implode( '.', array_slice( $labels, -2 ) );
	}

	/**
	 * Does a proof of $proven cover $name? The name itself, or a name below it, in the same registrable domain.
	 *
	 * @param string $proven The name that was proven.
	 * @param string $name   The name asked about.
	 */
	public static function covers( string $proven, string $name ): bool {
		$proven = strtolower( rtrim( trim( $proven ), '.' ) );
		$name   = strtolower( rtrim( trim( $name ), '.' ) );
		$reg    = self::registrable( $proven );

		return '' !== $proven && null !== $reg && ( $name === $proven || str_ends_with( $name, '.' . $proven ) ) && self::registrable( $name ) === $reg;
	}

	/**
	 * The name and every name above it up to its registrable domain: the names whose proof covers it.
	 *
	 * @param string $name Normalised name.
	 * @return string[]
	 */
	public static function chain( string $name ): array {
		$reg = self::registrable( $name );

		if ( null === $reg ) {
			return array();
		}

		$out = array();

		while ( '' !== $name ) {
			$out[] = $name;

			if ( $name === $reg ) {
				break;
			}

			$dot  = strpos( $name, '.' );
			$name = false === $dot ? '' : substr( $name, $dot + 1 );
		}

		return $out;
	}

	/**
	 * The value of the record: one per customer and registrable domain, the same for every feature. A keyed hash of the
	 * customer and the domain with the site's secret key, so nobody can work out another customer's value or make
	 * their own for a domain, and nothing is stored.
	 *
	 * @param int    $user_id Customer.
	 * @param string $name    Any name of the domain.
	 * @return string '' when there is no customer or the name is not a domain.
	 */
	public static function token( int $user_id, string $name ): string {
		$name = self::normalise( $name );
		$reg  = null === $name ? null : self::registrable( $name );

		if ( $user_id <= 0 || null === $reg ) {
			return '';
		}

		return self::PREFIX . substr( hash_hmac( 'sha256', 'domain-ownership|' . $user_id . '|' . $reg, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * The name of the record for a name.
	 *
	 * @param string $name Name.
	 */
	public static function record_name( string $name ): string {
		return ProofResolver::TXT_LABEL . '.' . strtolower( rtrim( trim( $name ), '.' ) );
	}

	/**
	 * The record a customer adds, as a table row.
	 *
	 * @param int    $user_id Customer.
	 * @param string $name    Name.
	 * @return array{name:string,type:string,value:string}
	 */
	public static function record( int $user_id, string $name ): array {
		return array(
			'name'  => self::record_name( $name ),
			'type'  => 'TXT',
			'value' => self::token( $user_id, $name ),
		);
	}

	/**
	 * Which stored proof covers the name for this customer, or '' when none does. No DNS lookup.
	 *
	 * @param int      $user_id Customer.
	 * @param string   $name    Name.
	 * @param string[] $skip    Sources to leave out: zone, claim, feature. A check that renews a stored proof leaves its own
	 *                          source out, so a proof cannot keep itself alive.
	 * @return string zone, claim, feature or ''.
	 */
	public static function source( int $user_id, string $name, array $skip = array() ): string {
		$name = self::normalise( $name );

		if ( $user_id <= 0 || null === $name || array() === self::chain( $name ) ) {
			return '';
		}

		if ( ! in_array( 'zone', $skip, true ) ) {
			$zone = ZoneAccess::zone_of( $user_id, $name );

			if ( null !== $zone && self::covers( $zone, $name ) ) {
				return 'zone';
			}
		}

		if ( ! in_array( 'claim', $skip, true ) ) {
			foreach ( DnsClaims::proven_domains( $user_id ) as $domain ) {
				if ( self::covers( $domain, $name ) ) {
					return 'claim';
				}
			}
		}

		if ( ! in_array( 'feature', $skip, true ) ) {
			/**
			 * Does a feature hold a live proof of this name for this customer? A feature answers true only from its own
			 * stored state (no DNS lookup), only for this customer, only for a proof whose record it saw itself (never one it
			 * took from another feature), and only for the name or a name above it in the same registrable domain
			 * (`Ownership::covers()`).
			 *
			 * @param bool   $proven  False.
			 * @param string $name    Normalised name.
			 * @param int    $user_id Customer.
			 */
			if ( true === apply_filters( self::FILTER, false, $name, $user_id ) ) {
				return 'feature';
			}
		}

		return '';
	}

	/**
	 * Has the customer proven the name (any stored source)?
	 *
	 * @param int    $user_id Customer.
	 * @param string $name    Name.
	 */
	public static function proven( int $user_id, string $name ): bool {
		return '' !== self::source( $user_id, $name );
	}

	/**
	 * Looks for the record, once. Only a feature that is working on the name calls this.
	 *
	 * Method: the delegated name servers of the registrable domain are asked for the TXT record (ProofResolver). When they
	 * cannot be asked (status unknown) and the caller allows it, the system resolver is read instead: the way hosting
	 * checked before. A name that is not delegated, is delegated to our own servers, or cannot be proven gets no fallback.
	 *
	 * @param int      $user_id  Customer.
	 * @param string   $name     The name the feature is working on.
	 * @param string[] $legacy   Older values this feature showed for exactly this name; accepted next to the central one.
	 * @param bool     $fallback Allow the system resolver when the name servers cannot be asked.
	 * @return array{status:string,reason:string} found, missing, unknown (try again) or support (cannot be proven).
	 */
	public static function check( int $user_id, string $name, array $legacy = array(), bool $fallback = false ): array {
		$name = self::normalise( $name );
		$reg  = null === $name ? null : self::registrable( $name );

		if ( $user_id <= 0 || null === $reg ) {
			return array( 'status' => 'support', 'reason' => 'unsupported_name' );
		}

		$delegation = DnsClaimJobs::proof( 'delegation', array( $reg ) );

		return self::check_with_delegation( $user_id, $name, $delegation, $legacy, $fallback );
	}

	/**
	 * The same, for a caller that read the delegation already (the DNS claim job).
	 *
	 * @param int                  $user_id    Customer.
	 * @param string               $name       Name.
	 * @param array<string, mixed> $delegation ProofResolver::delegation() result of the registrable domain.
	 * @param string[]             $legacy     Older values.
	 * @param bool                 $fallback   Allow the system resolver.
	 * @return array{status:string,reason:string}
	 */
	public static function check_with_delegation( int $user_id, string $name, array $delegation, array $legacy = array(), bool $fallback = false ): array {
		$central = self::token( $user_id, $name );

		if ( '' === $central ) {
			return array( 'status' => 'support', 'reason' => 'unsupported_name' );
		}

		$values = array_values( array_unique( array_filter( array_map( 'strval', array_merge( array( $central ), $legacy ) ), static fn( $v ) => '' !== $v ) ) );
		$status = (string) ( $delegation['status'] ?? 'unknown' );
		$reason = (string) ( $delegation['reason'] ?? '' );
		$extra  = array();

		if ( 'ok' === $status ) {
			$txt    = DnsClaimJobs::proof( 'check_txt', array( $name, $values, $delegation ) );
			$status = (string) ( $txt['status'] ?? 'unknown' );
			$reason = (string) ( $txt['reason'] ?? '' );
			$extra  = array( 'servers' => (array) ( $txt['servers'] ?? array() ) );
		}

		if ( 'unknown' === $status && $fallback ) {
			return self::resolver( $name, $values );
		}

		return array( 'status' => $status, 'reason' => $reason ) + $extra;
	}

	/**
	 * The system resolver's answer for the record.
	 *
	 * @param string   $name   Name.
	 * @param string[] $values Accepted values.
	 * @return array{status:string,reason:string}
	 */
	private static function resolver( string $name, array $values ): array {
		$texts = DnsQuery::texts( self::record_name( $name ) );

		if ( is_wp_error( $texts ) ) {
			return array( 'status' => 'unknown', 'reason' => 'resolver' );
		}

		$seen = array_map( static fn( $t ) => trim( (string) $t, " \t\"" ), $texts );

		return array() !== array_intersect( $values, $seen )
			? array( 'status' => 'found', 'reason' => 'found' )
			: array( 'status' => 'missing', 'reason' => 'not_found' );
	}
}
