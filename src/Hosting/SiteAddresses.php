<?php
/**
 * The addresses of a website: a name with and without www, and which one is the main address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers over the name records of a site (SiteDomains rows). A registrable domain is connected with and without
 * `www.`; a subdomain only as typed. When both `host` and `www.host` belong to a site they are a pair, and the pair's
 * main address is the one stored in the `www_mode` column of the row of `host`: `apex` (host is main, www redirects),
 * `www` (www.host is main, host redirects) or '' (nothing redirects, both show the site). A redirect is only sent to the
 * node while both names of the pair are active, so a name that does not point here yet never loses a working name.
 * Nothing here changes the WordPress address of the website: that is SiteWordPress, which asks these helpers which name is the main one.
 */
final class SiteAddresses {

	public const APEX = 'apex';
	public const WWW  = 'www';

	/**
	 * The names to connect for what the customer typed: the typed name first, then its pair when it is a registrable
	 * domain with or without www. Not validated beyond the shape: SiteDns::add_domain checks each.
	 *
	 * @param string $typed What the customer typed.
	 * @return string[]
	 */
	public static function names_for( string $typed ): array {
		$host = strtolower( rtrim( trim( $typed ), '.' ) );

		if ( ! SiteDomains::valid_host( $host ) ) {
			return array( $host );
		}

		$base = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;

		if ( ! SiteDomains::valid_host( $base ) || Addons::registrable( $base ) !== $base ) {
			return array( $host );
		}

		return $host === $base ? array( $base, 'www.' . $base ) : array( 'www.' . $base, $base );
	}

	/**
	 * The pairs of a site: base name => array( base row, www row ). Preview names are never in a pair.
	 *
	 * @param array<int, array<string, mixed>> $rows The site's name rows.
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function pairs( array $rows ): array {
		$by_host = array();

		foreach ( $rows as $row ) {
			if ( 'preview' !== $row['kind'] ) {
				$by_host[ $row['host'] ] = $row;
			}
		}

		$pairs = array();

		foreach ( $by_host as $host => $row ) {
			if ( 0 !== strpos( $host, 'www.' ) && isset( $by_host[ 'www.' . $host ] ) ) {
				$pairs[ $host ] = array( $row, $by_host[ 'www.' . $host ] );
			}
		}

		return $pairs;
	}

	/**
	 * The main name of a pair, '' when none is chosen.
	 *
	 * @param string               $base Base name.
	 * @param array<string, mixed> $apex Row of the base name.
	 */
	public static function main_of_pair( string $base, array $apex ): string {
		$mode = (string) ( $apex['www_mode'] ?? '' );

		return self::APEX === $mode ? $base : ( self::WWW === $mode ? 'www.' . $base : '' );
	}

	/**
	 * The redirects to send to the node: from, to, for every pair with a main address whose two names are both active.
	 *
	 * @param array<int, array<string, mixed>> $rows The site's name rows.
	 * @return array<int, array{from: string, to: string}>
	 */
	public static function redirects( array $rows ): array {
		$out = array();

		foreach ( self::pairs( $rows ) as $base => $pair ) {
			$main = self::main_of_pair( $base, $pair[0] );

			if ( '' === $main || 'active' !== $pair[0]['state'] || 'active' !== $pair[1]['state'] ) {
				continue;
			}

			$out[] = array(
				'from' => $main === $base ? 'www.' . $base : $base,
				'to'   => $main,
			);
		}

		return $out;
	}

	/**
	 * The name without a leading www.
	 *
	 * @param string $host Host name.
	 */
	public static function base_of( string $host ): string {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Is the main address settled: a name that is no part of a with-and-without-www pair, or a name of a pair whose two names
	 * are both active and one of them is chosen? WordPress is only moved to a settled main address, so it never lands on a
	 * name of a pair while the other one is not working.
	 *
	 * @param array<int, array<string, mixed>> $rows The site's name rows.
	 * @param string                           $main The main address.
	 */
	public static function settled( array $rows, string $main ): bool {
		$pairs = self::pairs( $rows );
		$base  = self::base_of( $main );

		if ( ! isset( $pairs[ $base ] ) ) {
			return true;
		}

		return 'active' === $pairs[ $base ][0]['state'] && 'active' === $pairs[ $base ][1]['state'] && '' !== self::main_of_pair( $base, $pairs[ $base ][0] );
	}

	/**
	 * The main address of a site: the chosen main of a pair whose names are both active, else the first active
	 * name of the customer, else the temporary address.
	 *
	 * @param array<int, array<string, mixed>> $rows    The site's name rows.
	 * @param string                           $preview The temporary address.
	 */
	public static function main_address( array $rows, string $preview ): string {
		$pairs = self::pairs( $rows );

		foreach ( $rows as $row ) {
			if ( 'preview' === $row['kind'] || 'active' !== $row['state'] ) {
				continue;
			}

			$base = 0 === strpos( $row['host'], 'www.' ) ? substr( $row['host'], 4 ) : $row['host'];

			if ( isset( $pairs[ $base ] ) && 'active' === $pairs[ $base ][0]['state'] && 'active' === $pairs[ $base ][1]['state'] ) {
				$main = self::main_of_pair( $base, $pairs[ $base ][0] );

				if ( '' !== $main ) {
					return $main;
				}
			}

			return $row['host'];
		}

		return $preview;
	}
}
