<?php
/**
 * How many of the lookups of one daily sweep failed: a resolver that is down must not make a service lose its records.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * One counter per feature, in an option: the sweep resets it, every daily look adds the lookups it made and how many of them failed.
 * The engine asks tripped() before it starts a loss.
 */
final class DnsWatchHealth {

	private static function key( DnsWatchFeature $f ): string {
		return 'beltoft_services_dns_watch_health_' . $f->id();
	}

	/**
	 * Starts a new count (the sweep begins).
	 *
	 * @param DnsWatchFeature $f Feature.
	 */
	public static function reset( DnsWatchFeature $f ): void {
		update_option( self::key( $f ), array( 'domains' => array(), 'at' => time() ), false );
	}

	/**
	 * Adds the lookups of one domain to the count. The count is by distinct domain: a domain whose own name servers fail (SERVFAIL)
	 * is one failing domain however many records or looks it has, and says nothing about the resolver.
	 *
	 * @param DnsWatchFeature $f      Feature.
	 * @param string          $domain Domain the lookups were about.
	 * @param int             $bad    Lookups that failed.
	 * @param int             $total  Lookups made.
	 */
	public static function note( DnsWatchFeature $f, string $domain, int $bad, int $total ): void {
		if ( $total <= 0 || '' === $domain ) {
			return;
		}

		$now     = (array) get_option( self::key( $f ), array() );
		$domains = is_array( $now['domains'] ?? null ) ? $now['domains'] : array();
		$domain  = strtolower( $domain );

		if ( count( $domains ) < 5000 || isset( $domains[ $domain ] ) ) {
			$domains[ $domain ] = (int) ( $bad > 0 || ! empty( $domains[ $domain ] ) );
		}

		$now['domains'] = $domains;
		$now['at']      = (int) ( $now['at'] ?? time() );

		update_option( self::key( $f ), $now, false );
	}

	/**
	 * Is the resolver down? Decided on the resolver itself, not on how many customer domains failed: only when some lookup failed, and a
	 * lookup of a name that is known to be healthy (setting dns_watch_health_probe_name) fails too. With the setting empty the share of
	 * failing domains decides, and only when at least the setting's number of distinct domains failed.
	 *
	 * @param DnsWatchFeature $f Feature.
	 */
	public static function tripped( DnsWatchFeature $f ): bool {
		return (bool) ( self::counts( $f )[2] );
	}

	/**
	 * The count.
	 *
	 * @param DnsWatchFeature $f Feature.
	 * @return array{0:int,1:int,2:bool} Failing domains, domains looked at, tripped.
	 */
	public static function counts( DnsWatchFeature $f ): array {
		$now     = (array) get_option( self::key( $f ), array() );
		$domains = is_array( $now['domains'] ?? null ) ? $now['domains'] : array();
		$total   = count( $domains );
		$bad     = count( array_filter( $domains ) );

		if ( $bad <= 0 ) {
			return array( 0, $total, false );
		}

		$probe = DnsWatchSettings::health_probe_name( $f->id() );

		if ( '' !== $probe ) {
			return array( $bad, $total, ! self::probe_ok( $f, $probe ) );
		}

		return array( $bad, $total, $bad >= DnsWatchSettings::health_min_domains( $f->id() ) && $bad * 100 > DnsWatchSettings::sweep_fail_percent( $f->id() ) * $total );
	}

	/**
	 * One lookup of a name that is known to resolve. The answer is kept for a minute, so a sweep with many losses asks once.
	 *
	 * @param DnsWatchFeature $f     Feature.
	 * @param string          $probe Name.
	 */
	private static function probe_ok( DnsWatchFeature $f, string $probe ): bool {
		$now  = (array) get_option( self::key( $f ), array() );
		$seen = is_array( $now['probe'] ?? null ) ? $now['probe'] : array();

		if ( ( $seen['name'] ?? '' ) === $probe && time() - (int) ( $seen['at'] ?? 0 ) < 60 ) {
			return (bool) ( $seen['ok'] ?? false );
		}

		$found = DnsQuery::addresses( $probe, 'A' );
		$ok    = ! is_wp_error( $found ) && array() !== $found;

		$now['probe'] = array( 'name' => $probe, 'at' => time(), 'ok' => $ok );
		update_option( self::key( $f ), $now, false );

		return $ok;
	}
}
