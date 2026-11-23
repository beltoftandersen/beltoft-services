<?php
/**
 * A quick availability answer that does not ask the registrar: name servers in public DNS mean taken; the registry's own
 * RDAP answers 404 (free) or 200 (taken). Anything else stays 'checking' until the registrar answers. The page shows it
 * while the registrar lookups run; an order always asks the registrar again (Search::find with $fresh), so a quick answer
 * is never what a domain is sold on.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class QuickCheck {
	/** IANA's list of RDAP servers per extension. */
	public const BOOTSTRAP     = 'https://data.iana.org/rdap/dns.json';
	public const BOOTSTRAP_KEY = 'beltoft_services_rdap_bootstrap';

	/**
	 * Per domain (lowercase ASCII): 'taken', 'free' or 'checking'.
	 *
	 * @param list<string> $domains Full names.
	 * @return array<string, string>
	 */
	public static function status( array $domains ): array {
		$out  = array();
		$urls = array();
		foreach ( $domains as $domain ) {
			$ns = PublicDns::resolve( $domain, 'NS' );
			if ( is_array( $ns ) && $ns ) { $out[ $domain ] = 'taken'; continue; }
			$base = self::server( Search::tld_of( $domain ) );
			if ( '' === $base ) { $out[ $domain ] = 'checking'; continue; }
			$urls[ $domain ] = $base . 'domain/' . rawurlencode( $domain );
		}
		foreach ( self::codes( $urls ) as $domain => $code ) {
			$out[ $domain ] = 404 === $code ? 'free' : ( 200 === $code ? 'taken' : 'checking' );
		}
		return $out;
	}

	/** The RDAP base URL (with a trailing slash) of an extension, or '' when it has none. The list is kept a day. */
	private static function server( string $tld ): string {
		$map = get_transient( self::BOOTSTRAP_KEY );
		if ( ! is_array( $map ) ) {
			$map      = array();
			$response = wp_remote_get( self::BOOTSTRAP, array( 'timeout' => 3 ) );
			$data     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
			foreach ( (array) ( is_array( $data ) ? ( $data['services'] ?? array() ) : array() ) as $service ) {
				$url = is_array( $service ) ? (string) ( $service[1][0] ?? '' ) : '';
				if ( ! str_starts_with( $url, 'https://' ) ) { continue; }
				foreach ( (array) ( $service[0] ?? array() ) as $ext ) { $map[ strtolower( (string) $ext ) ] = trailingslashit( $url ); }
			}
			// A failed fetch is tried again after ten minutes, not after a day.
			set_transient( self::BOOTSTRAP_KEY, $map, $map ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		}
		return (string) ( $map[ strtolower( $tld ) ] ?? '' );
	}

	/**
	 * The HTTP status of each URL, asked at the same time (0 for no answer). A request that pre_http_request answers (as
	 * WordPress's own HTTP calls allow) is not sent, so tests never reach the network.
	 *
	 * @param array<string, string> $urls Domain => URL.
	 * @return array<string, int>
	 */
	private static function codes( array $urls ): array {
		$codes = array();
		$send  = array();
		$args  = array( 'timeout' => 2, 'headers' => array( 'Accept' => 'application/rdap+json' ) );
		foreach ( $urls as $domain => $url ) {
			$pre = apply_filters( 'pre_http_request', false, $args, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own short-circuit, honoured for the parallel requests.
			if ( false === $pre ) { $send[ $domain ] = array( 'url' => $url, 'headers' => $args['headers'] ); continue; }
			$codes[ $domain ] = is_wp_error( $pre ) ? 0 : (int) wp_remote_retrieve_response_code( $pre );
		}
		if ( $send ) {
			$replies = \WpOrg\Requests\Requests::request_multiple( $send, array( 'timeout' => 2, 'connect_timeout' => 1, 'useragent' => 'beltoft-services' ) );
			foreach ( $send as $domain => $unused ) {
				$reply            = $replies[ $domain ] ?? null;
				$codes[ $domain ] = $reply instanceof \WpOrg\Requests\Response ? (int) $reply->status_code : 0;
			}
		}
		return $codes;
	}
}
