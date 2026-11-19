<?php
/** What the registrar says an extension allows: DNSSEC and WHOIS privacy. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Read from the extension list the daily price sync already fetches (Prices::sync, GET /tlds) and kept in the price table, so a page never
 * calls the registrar to know what an extension allows. Every field is the registrar's tldTld definition (Openprovider API spec, swagger
 * definition tldTld):
 *
 * - dnssec_allowed: "Indicates, if DNSSEC is supported by entension"
 * - dnssec_keys_allowed: "Indicates, if DNSSEC keys are supported by entension"
 * - dnssec_digests_allowed: "Indicates, if DNSSEC digests are supported by entension"
 * - dnssec_algorithms: "List of supported DNSSEC algorithms" (integers)
 * - dnssec_max_records_amount: "Indicates, how many DNSSEC records are allowed for the extension"
 * - is_private_whois_allowed: "Indicates, if whois privacy protection service is available for the extension"
 *
 * A field the registrar did not send counts as not allowed, never as allowed: nothing is offered on a guess. The stored list may lack the fields
 * (the recorded list answer in tests/fixtures/openprovider/tlds-page.json has none), so the extensions in use are read one by one.
 */
final class TldFacts {

	/**
	 * The facts of one entry, stored beside its prices. An entry that carries none of the fields gives nothing (unknown, so nothing is
	 * offered and refresh_missing() asks the registrar for that extension alone), unless $known says the registrar was asked and the
	 * missing fields mean "not allowed".
	 *
	 * @param array<string, mixed> $entry One tldTld entry (GET /tlds or GET /tlds/{name}).
	 * @param bool                 $known The entry answers for the extension itself.
	 * @return array{dnssec?: array{allowed: bool, keys: bool, digests: bool, algs: int[], max: int}, whois?: bool}
	 */
	public static function from_entry( array $entry, bool $known = false ): array {
		$fields = array( 'dnssec_allowed', 'dnssec_keys_allowed', 'dnssec_digests_allowed', 'dnssec_algorithms', 'dnssec_max_records_amount', 'is_private_whois_allowed' );
		if ( ! $known && array() === array_intersect_key( $entry, array_flip( $fields ) ) ) { return array(); }
		$algs = array();
		foreach ( (array) ( $entry['dnssec_algorithms'] ?? array() ) as $alg ) {
			if ( is_int( $alg ) || ( is_string( $alg ) && ctype_digit( $alg ) ) ) { $algs[] = (int) $alg; }
		}
		return array(
			'dnssec' => array(
				'allowed' => true === ( $entry['dnssec_allowed'] ?? null ),
				'keys'    => true === ( $entry['dnssec_keys_allowed'] ?? null ),
				'digests' => true === ( $entry['dnssec_digests_allowed'] ?? null ),
				'algs'    => array_values( array_unique( $algs ) ),
				'max'     => max( 0, (int) ( $entry['dnssec_max_records_amount'] ?? 0 ) ),
			),
			'whois'  => true === ( $entry['is_private_whois_allowed'] ?? null ),
		);
	}

	/**
	 * Asks the registrar for the extensions that matter (the featured ones and those of live domains) when the list did not say what they
	 * allow: GET /tlds/{name} (Openprovider swagger path /v1/tlds/{name}, answer tldGetTldResponse with data tldTld). Never throws; an
	 * extension that cannot be read is tried again at the next price refresh.
	 *
	 * @return int Extensions filled in.
	 */
	public static function refresh_missing(): int {
		$table = get_option( Prices::OPTION, array() );
		if ( ! is_array( $table ) ) { return 0; }
		$want  = array_map( static fn( $t ) => strtolower( trim( $t, " .\t\r\n" ) ), explode( ',', (string) \BeltoftServices\Settings::get( 'domain_featured_tlds' ) ) );
		foreach ( \BeltoftServices\Resource::live_ids( 'domain' ) as $id ) {
			$r = \BeltoftServices\Resource::find( $id );
			$t = $r ? Prices::tld_of( strtolower( (string) $r->get( 'domain' ) ) ) : '';
			if ( '' !== $t ) { $want[] = $t; }
		}
		$done = 0;
		foreach ( array_unique( array_filter( $want ) ) as $tld ) {
			if ( ! is_array( $table[ $tld ] ?? null ) || isset( $table[ $tld ]['dnssec'] ) ) { continue; }
			$reply = Clients::registrar()->request( 'GET', '/tlds/' . rawurlencode( $tld ) );
			if ( is_wp_error( $reply ) || ! is_array( $reply ) ) { continue; }
			$table[ $tld ] += self::from_entry( $reply, true );
			++$done;
		}
		if ( $done > 0 ) { update_option( Prices::OPTION, $table, false ); }
		return $done;
	}

	/**
	 * Does the extension of this domain allow DNSSEC (tldTld.dnssec_allowed)?
	 *
	 * @param string $domain Full domain name.
	 */
	public static function dnssec_allowed( string $domain ): bool {
		$row = Prices::row( $domain );
		return null !== $row && true === ( $row['dnssec']['allowed'] ?? false );
	}

	/**
	 * What a customer may enter as DNSSEC key data when the DNS is hosted elsewhere, or null when nothing may be entered. Needs DNSSEC
	 * allowed, keys allowed (domainDnssecKey carries the key, not a digest), a list of algorithms and a record limit above zero.
	 *
	 * @param string $domain Full domain name.
	 * @return array{algs: int[], max: int}|null
	 */
	public static function external_keys( string $domain ): ?array {
		$row = Prices::row( $domain );
		$d   = is_array( $row ) ? (array) ( $row['dnssec'] ?? array() ) : array();
		if ( true !== ( $d['allowed'] ?? false ) || true !== ( $d['keys'] ?? false ) ) { return null; }
		$algs = array_values( array_filter( array_map( 'intval', (array) ( $d['algs'] ?? array() ) ), static fn( $a ) => $a >= 0 ) );
		$max  = (int) ( $d['max'] ?? 0 );
		return array() !== $algs && $max > 0 ? array( 'algs' => $algs, 'max' => $max ) : null;
	}

	/**
	 * Is WHOIS privacy available for the extension (tldTld.is_private_whois_allowed)? The domain's own answer after registration
	 * (domainGetDomainResponseData.is_private_whois_allowed) is stored on the service and wins there; see WhoisPrivacy::allowed().
	 *
	 * @param string $domain Full domain name.
	 */
	public static function whois_allowed( string $domain ): bool {
		$row = Prices::row( $domain );
		return null !== $row && true === ( $row['whois'] ?? false );
	}
}
